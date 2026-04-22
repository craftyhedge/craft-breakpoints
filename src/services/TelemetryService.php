<?php

namespace craftyhedge\craftbreakpointimages\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Application as WebApplication;
use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class TelemetryService extends Component
{
    private const PROCESSING_QUERY_PARAM = '__bpiProcessing';
    private const RUN_STATUS_COMPLETED = 'completed';
    private const RUN_STATUS_FAILED = 'failed';
    private const RUN_STATUS_CANCELLED = 'cancelled';
    private const RUN_SNAPSHOT_TABLE = '{{%bpi_processing_run_snapshot}}';
    private const RUN_SNAPSHOT_ROWS_TABLE = '{{%bpi_processing_run_snapshot_breakpoints}}';
    private const PREVIEW_CACHE_TABLE = '{{%bpi_preview_cache}}';
    private const SNAPSHOT_FAILURE_COUNTS_KEY = 'counts';
    private const SNAPSHOT_OVERLAY_ROWS_KEY = 'overlayRows';
    private const SNAPSHOT_OVERLAY_STATUS_RELIABLE_KEY = 'overlayStatusReliable';
    private const SNAPSHOT_META_MAX_BYTES = 64000;
    private const SOURCE_URL_MAX_LENGTH = 255;
    private const DISPLAY_ASSET_URL_MAX_LENGTH = 1024;
    private const RUN_ID_MAX_LENGTH = 64;

    /** @var array<string, bool> */
    private const VALID_RUN_STATUSES = [
        self::RUN_STATUS_COMPLETED => true,
        self::RUN_STATUS_FAILED => true,
        self::RUN_STATUS_CANCELLED => true,
    ];

    /** @var array<string, bool> */
    private array $_seenHandles = [];

    private function getConfigService(): ?ConfigService
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return null;
        }

        return $plugin->getConfigService();
    }

    public function isTelemetryEnabled(): bool
    {
        $configService = $this->getConfigService();
        if ($configService === null) {
            return false;
        }

        return $configService->isTelemetryEnabled();
    }

    public function canWriteTelemetry(): bool
    {
        return $this->isTelemetryEnabled();
    }

    public function canEditTransforms(): bool
    {
        $configService = $this->getConfigService();
        if ($configService === null) {
            return false;
        }

        return $configService->allowTransformEditing();
    }

    public function recordUsage(string $transformHandle): void
    {
        if (!$this->canWriteTelemetry()) {
            return;
        }

        $handle = trim($transformHandle);
        if ($handle === '') {
            return;
        }

        $sourceElementId = null;
        $sourceUrl = null;

        if (Craft::$app instanceof WebApplication) {
            if ($this->isProcessingIframeRequest()) {
                return;
            }

            if (isset($this->_seenHandles[$handle])) {
                return;
            }

            $matched = Craft::$app->urlManager->getMatchedElement();
            $sourceElementId = ($matched !== false) ? $matched->id : null;

            try {
                $sourceUrl = Craft::$app->request->getAbsoluteUrl();
            } catch (\Throwable) {
                $sourceUrl = null;
            }

            $this->_seenHandles[$handle] = true;
            $this->upsertUsage($handle, $sourceElementId, $sourceUrl);

            return;
        }

        // Queue/console runtimes have no web request lifecycle; write immediately.
        $this->upsertUsage($handle, $sourceElementId, $sourceUrl);
    }

    private function isProcessingIframeRequest(): bool
    {
        if (!(Craft::$app instanceof WebApplication)) {
            return false;
        }

        $rawFlag = Craft::$app->request->getQueryParam(self::PROCESSING_QUERY_PARAM);
        if ($rawFlag === null) {
            return false;
        }

        if (is_bool($rawFlag)) {
            return $rawFlag;
        }

        if (is_numeric($rawFlag)) {
            return ((int)$rawFlag) !== 0;
        }

        $normalized = strtolower(trim((string)$rawFlag));
        if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
            return false;
        }

        return true;
    }

    public function flushPendingUsage(): void
    {
        // Writes happen immediately on first observation per request.
        $this->_seenHandles = [];
    }

    private function upsertUsage(string $handle, ?int $sourceElementId, ?string $sourceUrl): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $normalizedSourceUrl = $sourceUrl;
        if (is_string($normalizedSourceUrl) && $normalizedSourceUrl !== '') {
            $normalizedSourceUrl = mb_substr($normalizedSourceUrl, 0, self::SOURCE_URL_MAX_LENGTH);
        }

        try {
            Db::upsert('{{%bpi_transform_last_processed}}', [
                'transformHandle' => $handle,
                'sourceElementId' => $sourceElementId,
                'sourceUrl' => $normalizedSourceUrl,
                'lastSeenAt' => $now,
            ]);
        } catch (\Throwable $e) {
            Plugin::warning('Telemetry write failed for handle "' . $handle . '": ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentlySeen(int $limit = 50): array
    {
        return (new Query())
            ->select(['transformHandle', 'sourceElementId', 'sourceUrl', 'lastSeenAt'])
            ->from('{{%bpi_transform_last_processed}}')
            ->orderBy(['lastSeenAt' => SORT_DESC])
            ->limit($limit)
            ->all();
    }

    /**
     * Returns one row per observed transform handle, carrying the most-recently
     * observed entry reference for that handle.
     *
     * @return array<string, array{handle: string, entryId: ?int, sourceUrl: ?string, lastSeenAt: string}>
     */
    public function getMostRecentByHandle(): array
    {
        try {
            $rows = (new Query())
                ->select(['transformHandle', 'sourceElementId', 'sourceUrl', 'lastSeenAt'])
                ->from('{{%bpi_transform_last_processed}}')
                ->orderBy(['lastSeenAt' => SORT_DESC])
                ->all();
        } catch (\Throwable $e) {
            Plugin::warning('Failed to read observed transform handles: ' . $e->getMessage());
            return [];
        }

        $byHandle = [];
        foreach ($rows as $row) {
            $handle = trim((string)($row['transformHandle'] ?? ''));
            if ($handle === '' || isset($byHandle[$handle])) {
                continue;
            }

            $entryId = $row['sourceElementId'] ?? null;
            $byHandle[$handle] = [
                'handle' => $handle,
                'entryId' => $entryId !== null ? (int)$entryId : null,
                'sourceUrl' => isset($row['sourceUrl']) ? (string)$row['sourceUrl'] : null,
                'lastSeenAt' => (string)($row['lastSeenAt'] ?? ''),
            ];
        }

        return $byHandle;
    }

    /**
     * Returns one row per observed transform handle whose handle is not present
     * in $configuredHandles. The row carries the most-recently observed entry
     * reference for that handle.
     *
     * @param array<int, string> $configuredHandles
     * @return array<int, array{handle: string, entryId: ?int, sourceUrl: ?string, lastSeenAt: string}>
     */
    public function getObservedUnsavedHandles(array $configuredHandles): array
    {
        $configuredSet = [];
        foreach ($configuredHandles as $handle) {
            if (is_string($handle) && $handle !== '') {
                $configuredSet[$handle] = true;
            }
        }

        $byHandle = $this->getMostRecentByHandle();
        foreach (array_keys($byHandle) as $handle) {
            if (isset($configuredSet[$handle])) {
                unset($byHandle[$handle]);
            }
        }

        ksort($byHandle, SORT_STRING);

        return array_values($byHandle);
    }

    public function persistRunSnapshot(array $payload): bool
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::RUN_SNAPSHOT_TABLE) || !$db->tableExists(self::RUN_SNAPSHOT_ROWS_TABLE)) {
            Plugin::warning('Run snapshot tables are missing; skipping snapshot persistence.');
            return false;
        }

        $runId = mb_substr(trim((string)($payload['runId'] ?? '')), 0, self::RUN_ID_MAX_LENGTH);
        if ($runId === '') {
            return false;
        }

        $status = strtolower(trim((string)($payload['runStatus'] ?? '')));
        if (!isset(self::VALID_RUN_STATUSES[$status])) {
            return false;
        }

        $rawTimestamp = trim((string)($payload['timestamp'] ?? ''));
        if ($rawTimestamp === '') {
            return false;
        }

        try {
            $ranAt = Db::prepareDateForDb(new \DateTimeImmutable($rawTimestamp));
        } catch (\Throwable) {
            return false;
        }

        $durationMs = max(0, (int)($payload['durationMs'] ?? 0));
        $entryId = null;
        if (is_numeric($payload['entryId'] ?? null)) {
            $normalizedEntryId = (int)$payload['entryId'];
            if ($normalizedEntryId > 0) {
                $entryId = $normalizedEntryId;
            }
        }

        $sourceUrl = $this->normalizeSourceUrl($payload['sourceUrl'] ?? null);
        $failureReasonCounts = $this->normalizeFailureReasonCounts($payload['failureReasonCounts'] ?? []);
        $snapshotRows = $this->normalizeSnapshotRowsByBreakpoint($payload['rowsByBreakpoint'] ?? []);
        $snapshotOverlayRows = $this->normalizeSnapshotOverlayRowsByBreakpoint($payload['rowsByBreakpoint'] ?? []);
        $snapshotOverlayPayload = $this->encodeSnapshotOverlayRows($snapshotOverlayRows, true);

        $snapshotMetaJson = json_encode([
            self::SNAPSHOT_FAILURE_COUNTS_KEY => $failureReasonCounts,
            self::SNAPSHOT_OVERLAY_ROWS_KEY => $snapshotOverlayPayload,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($snapshotMetaJson)) {
            return false;
        }

        if (strlen($snapshotMetaJson) > self::SNAPSHOT_META_MAX_BYTES) {
            $snapshotOverlayPayload = $this->encodeSnapshotOverlayRows($snapshotOverlayRows, false);
            $snapshotMetaJson = json_encode([
                self::SNAPSHOT_FAILURE_COUNTS_KEY => $failureReasonCounts,
                self::SNAPSHOT_OVERLAY_ROWS_KEY => $snapshotOverlayPayload,
            ], JSON_UNESCAPED_SLASHES);
        }

        if (!is_string($snapshotMetaJson) || strlen($snapshotMetaJson) > self::SNAPSHOT_META_MAX_BYTES) {
            return false;
        }

        $transaction = $db->beginTransaction();

        try {
            Db::upsert(self::RUN_SNAPSHOT_TABLE, [
                'id' => 1,
                'ranAt' => $ranAt,
                'runStatus' => $status,
                'durationMs' => $durationMs,
                'entryId' => $entryId,
                'sourceUrl' => $sourceUrl,
                'runId' => $runId,
                'failureReasonCounts' => $snapshotMetaJson,
            ]);

            $db->createCommand()
                ->delete(self::RUN_SNAPSHOT_ROWS_TABLE, ['snapshotId' => 1])
                ->execute();

            if ($snapshotRows !== []) {
                $now = Db::prepareDateForDb(new \DateTimeImmutable());
                $batchRows = [];
                foreach ($snapshotRows as $row) {
                    $batchRows[] = [
                        1,
                        $row['transformHandle'],
                        $row['breakpointWidth'],
                        $row['displayAssetUrl'],
                        $row['rowStatus'],
                        $now,
                        $now,
                    ];
                }

                $db->createCommand()
                    ->batchInsert(
                        self::RUN_SNAPSHOT_ROWS_TABLE,
                        ['snapshotId', 'transformHandle', 'breakpointWidth', 'displayAssetUrl', 'rowStatus', 'dateCreated', 'dateUpdated'],
                        $batchRows
                    )
                    ->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Plugin::warning('Run snapshot persistence failed: ' . $e->getMessage());
            return false;
        }

        if ($status === self::RUN_STATUS_COMPLETED) {
            try {
                $this->updatePreviewCacheFromRun(
                    $payload['rowsByBreakpoint'] ?? [],
                    $ranAt,
                    $runId,
                    $entryId,
                    $sourceUrl,
                );
            } catch (\Throwable $e) {
                Plugin::warning('Preview cache update failed after snapshot commit: ' . $e->getMessage());
            }
        }

        return true;
    }

    public function getLatestRunSnapshot(): ?array
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::RUN_SNAPSHOT_TABLE) || !$db->tableExists(self::RUN_SNAPSHOT_ROWS_TABLE)) {
            return null;
        }

        $snapshot = (new Query())
            ->from(self::RUN_SNAPSHOT_TABLE)
            ->orderBy(['ranAt' => SORT_DESC, 'id' => SORT_DESC])
            ->one();

        if (!is_array($snapshot)) {
            return null;
        }

        $snapshotId = isset($snapshot['id']) ? (int)$snapshot['id'] : 0;
        $rows = [];
        if ($snapshotId > 0) {
            $rows = (new Query())
                ->select(['transformHandle', 'breakpointWidth', 'displayAssetUrl', 'rowStatus'])
                ->from(self::RUN_SNAPSHOT_ROWS_TABLE)
                ->where(['snapshotId' => $snapshotId])
                ->orderBy(['transformHandle' => SORT_ASC, 'breakpointWidth' => SORT_ASC])
                ->all();
        }

        $snapshotMeta = $this->decodeSnapshotMeta($snapshot['failureReasonCounts'] ?? null);
        $snapshot['failureReasonCounts'] = $snapshotMeta[self::SNAPSHOT_FAILURE_COUNTS_KEY];
        $snapshot['rowsPayload'] = $snapshotMeta[self::SNAPSHOT_OVERLAY_ROWS_KEY];
        $snapshot['rowsPayloadStatusReliable'] = $snapshotMeta[self::SNAPSHOT_OVERLAY_STATUS_RELIABLE_KEY];
        $snapshot['rows'] = $rows;

        return $snapshot;
    }

    private function normalizeSourceUrl(mixed $sourceUrl): ?string
    {
        if (!is_string($sourceUrl)) {
            return null;
        }

        $trimmed = trim($sourceUrl);
        if ($trimmed === '') {
            return null;
        }

        try {
            $parts = parse_url($trimmed);
            if (!is_array($parts)) {
                throw new \RuntimeException('Could not parse source URL.');
            }

            $path = isset($parts['path']) ? (string)$parts['path'] : '';
            if ($path === '') {
                $path = '/';
            }

            if (isset($parts['scheme'], $parts['host'])) {
                $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
                $normalized = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'] . $port . $path;
                return mb_substr($normalized, 0, self::SOURCE_URL_MAX_LENGTH);
            }

            return mb_substr($path, 0, self::SOURCE_URL_MAX_LENGTH);
        } catch (\Throwable) {
            $queryStripped = explode('?', $trimmed, 2)[0] ?? $trimmed;
            $fragmentStripped = explode('#', $queryStripped, 2)[0] ?? $queryStripped;
            return mb_substr(trim($fragmentStripped), 0, self::SOURCE_URL_MAX_LENGTH);
        }
    }

    /**
     * @return array<string, int>
     */
    private function normalizeFailureReasonCounts(mixed $rawCounts): array
    {
        if (!is_array($rawCounts)) {
            return [];
        }

        $normalized = [];
        foreach ($rawCounts as $reason => $count) {
            if (!is_string($reason)) {
                continue;
            }

            $reasonKey = trim($reason);
            if ($reasonKey === '') {
                continue;
            }

            $normalized[$reasonKey] = max(0, (int)$count);
        }

        return $normalized;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSnapshotRowsByBreakpoint(mixed $rawRowsByBreakpoint): array
    {
        if (!is_array($rawRowsByBreakpoint)) {
            return [];
        }

        $normalizedRows = [];
        foreach ($rawRowsByBreakpoint as $breakpointKey => $rows) {
            if (!is_array($rows)) {
                continue;
            }

            $breakpointWidth = is_numeric($breakpointKey) ? (int)$breakpointKey : 0;
            if ($breakpointWidth <= 0) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $transformHandle = trim((string)($row['transform'] ?? ''));
                if ($transformHandle === '') {
                    continue;
                }

                $dedupeKey = $transformHandle . '|' . $breakpointWidth;
                if (isset($normalizedRows[$dedupeKey])) {
                    continue;
                }

                $displayAssetUrl = trim((string)($row['src'] ?? ''));
                if ($displayAssetUrl === '') {
                    $displayAssetUrl = null;
                } elseif (mb_strlen($displayAssetUrl) > self::DISPLAY_ASSET_URL_MAX_LENGTH) {
                    $displayAssetUrl = mb_substr($displayAssetUrl, 0, self::DISPLAY_ASSET_URL_MAX_LENGTH);
                }

                $enabled = ($row['enabled'] ?? true) === true;
                $loaded = ($row['loaded'] ?? false) === true;
                $broken = ($row['broken'] ?? false) === true;
                $unresolved = ($row['unresolved'] ?? false) === true;

                $rowStatus = $this->resolveSnapshotRowStatus($enabled, $loaded, $broken, $unresolved);

                $normalizedRows[$dedupeKey] = [
                    'transformHandle' => $transformHandle,
                    'breakpointWidth' => $breakpointWidth,
                    'displayAssetUrl' => $displayAssetUrl,
                    'rowStatus' => $rowStatus,
                ];
            }
        }

        return array_values($normalizedRows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSnapshotOverlayRowsByBreakpoint(mixed $rawRowsByBreakpoint): array
    {
        if (!is_array($rawRowsByBreakpoint)) {
            return [];
        }

        $normalizedRows = [];
        foreach ($rawRowsByBreakpoint as $breakpointKey => $rows) {
            if (!is_array($rows)) {
                continue;
            }

            $breakpointWidth = is_numeric($breakpointKey) ? (int)$breakpointKey : 0;
            if ($breakpointWidth <= 0) {
                continue;
            }

            foreach ($rows as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $transformHandle = trim((string)($row['transform'] ?? ''));
                if ($transformHandle === '') {
                    continue;
                }

                $assetId = trim((string)($row['assetId'] ?? ''));
                if ($assetId !== '') {
                    $assetId = mb_substr($assetId, 0, 255);
                }

                $displayAssetUrl = trim((string)($row['src'] ?? ''));
                if ($displayAssetUrl === '') {
                    $displayAssetUrl = null;
                } elseif (mb_strlen($displayAssetUrl) > self::DISPLAY_ASSET_URL_MAX_LENGTH) {
                    $displayAssetUrl = mb_substr($displayAssetUrl, 0, self::DISPLAY_ASSET_URL_MAX_LENGTH);
                }

                $renderedWidth = max(0, (int)($row['rendered']['width'] ?? 0));
                $renderedHeight = max(0, (int)($row['rendered']['height'] ?? 0));

                $enabled = ($row['enabled'] ?? true) === true;
                $loaded = ($row['loaded'] ?? false) === true;
                $broken = ($row['broken'] ?? false) === true;
                $unresolved = ($row['unresolved'] ?? false) === true;
                $rowStatus = $this->resolveSnapshotRowStatus($enabled, $loaded, $broken, $unresolved);

                $dedupeKey = implode('|', [
                    $transformHandle,
                    (string)$breakpointWidth,
                    $assetId !== '' ? $assetId : ('row-' . (string)$index),
                    (string)($displayAssetUrl ?? ''),
                ]);

                if (isset($normalizedRows[$dedupeKey])) {
                    continue;
                }

                $normalizedRows[$dedupeKey] = [
                    'transformHandle' => $transformHandle,
                    'breakpointWidth' => $breakpointWidth,
                    'renderedWidth' => $renderedWidth,
                    'renderedHeight' => $renderedHeight,
                    'rowStatus' => $rowStatus,
                ];
            }
        }

        return array_values($normalizedRows);
    }

    /**
     * @return array{counts: array<string, int>, overlayRows: array<int, array<string, mixed>>, overlayStatusReliable: bool}
     */
    private function decodeSnapshotMeta(mixed $rawMeta): array
    {
        if (!is_string($rawMeta) || trim($rawMeta) === '') {
            return [
                self::SNAPSHOT_FAILURE_COUNTS_KEY => [],
                self::SNAPSHOT_OVERLAY_ROWS_KEY => [],
                self::SNAPSHOT_OVERLAY_STATUS_RELIABLE_KEY => false,
            ];
        }

        $decoded = json_decode($rawMeta, true);
        if (!is_array($decoded)) {
            return [
                self::SNAPSHOT_FAILURE_COUNTS_KEY => [],
                self::SNAPSHOT_OVERLAY_ROWS_KEY => [],
                self::SNAPSHOT_OVERLAY_STATUS_RELIABLE_KEY => false,
            ];
        }

        $decodedOverlay = $this->decodeSnapshotOverlayRows(
            $decoded[self::SNAPSHOT_OVERLAY_ROWS_KEY] ?? [],
        );

        return [
            self::SNAPSHOT_FAILURE_COUNTS_KEY => $this->normalizeFailureReasonCounts(
                $decoded[self::SNAPSHOT_FAILURE_COUNTS_KEY] ?? [],
            ),
            self::SNAPSHOT_OVERLAY_ROWS_KEY => $decodedOverlay['rows'],
            self::SNAPSHOT_OVERLAY_STATUS_RELIABLE_KEY => $decodedOverlay['statusReliable'],
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, statusReliable: bool}
     */
    private function decodeSnapshotOverlayRows(mixed $rawRows): array
    {
        if (is_array($rawRows)) {
            $decoded = $rawRows;
        } elseif (is_string($rawRows) && trim($rawRows) !== '') {
            $decoded = json_decode($rawRows, true);
        } else {
            return [
                'rows' => [],
                'statusReliable' => false,
            ];
        }

        if (!is_array($decoded)) {
            return [
                'rows' => [],
                'statusReliable' => false,
            ];
        }

        if (($decoded['v'] ?? null) !== 1 || !isset($decoded['rows']) || !is_array($decoded['rows'])) {
            return [
                'rows' => [],
                'statusReliable' => false,
            ];
        }

        $statusReliable = isset($decoded['s'])
            ? ((int)$decoded['s'] === 1)
            : $this->hasSnapshotOverlayStatusEntries($decoded['rows']);

        return [
            'rows' => $this->decodeCompactSnapshotOverlayRows($decoded['rows']),
            'statusReliable' => $statusReliable,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function encodeSnapshotOverlayRows(array $rows, bool $includeStatus): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transformHandle = trim((string)($row['transformHandle'] ?? ''));
            $breakpointWidth = is_numeric($row['breakpointWidth'] ?? null) ? (int)$row['breakpointWidth'] : 0;
            if ($transformHandle === '' || $breakpointWidth <= 0) {
                continue;
            }

            $groupKey = $transformHandle . '|' . $breakpointWidth;
            $grouped[$groupKey] ??= [
                't' => $transformHandle,
                'b' => $breakpointWidth,
                'd' => [],
            ];

            $w = max(0, (int)($row['renderedWidth'] ?? 0));
            $h = max(0, (int)($row['renderedHeight'] ?? 0));

            if ($includeStatus) {
                $status = strtolower(trim((string)($row['rowStatus'] ?? 'unprocessed')));
                $statusCode = $this->encodeSnapshotRowStatus($status);
                $grouped[$groupKey]['d'][] = [$w, $h, $statusCode];
            } else {
                $grouped[$groupKey]['d'][] = [$w, $h];
            }
        }

        $rowsPayload = array_values($grouped);
        usort($rowsPayload, static function(array $left, array $right): int {
            $leftT = (string)($left['t'] ?? '');
            $rightT = (string)($right['t'] ?? '');
            if ($leftT !== $rightT) {
                return strcmp($leftT, $rightT);
            }

            $leftB = (int)($left['b'] ?? 0);
            $rightB = (int)($right['b'] ?? 0);
            return $leftB <=> $rightB;
        });

        return [
            'v' => 1,
            's' => $includeStatus ? 1 : 0,
            'rows' => $rowsPayload,
        ];
    }

    /**
     * @param array<int, mixed> $rowsPayload
     */
    private function hasSnapshotOverlayStatusEntries(array $rowsPayload): bool
    {
        foreach ($rowsPayload as $group) {
            if (!is_array($group)) {
                continue;
            }

            $dimensions = $group['d'] ?? null;
            if (!is_array($dimensions)) {
                continue;
            }

            foreach ($dimensions as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                if (count($entry) >= 3) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $rowsPayload
     * @return array<int, array<string, mixed>>
     */
    private function decodeCompactSnapshotOverlayRows(array $rowsPayload): array
    {
        $decoded = [];

        foreach ($rowsPayload as $group) {
            if (!is_array($group)) {
                continue;
            }

            $transformHandle = trim((string)($group['t'] ?? ''));
            $breakpointWidth = is_numeric($group['b'] ?? null) ? (int)$group['b'] : 0;
            $dimensions = $group['d'] ?? null;

            if ($transformHandle === '' || $breakpointWidth <= 0 || !is_array($dimensions)) {
                continue;
            }

            foreach ($dimensions as $entry) {
                if (!is_array($entry) || count($entry) < 2) {
                    continue;
                }

                $renderedWidth = max(0, (int)($entry[0] ?? 0));
                $renderedHeight = max(0, (int)($entry[1] ?? 0));
                $statusCode = is_numeric($entry[2] ?? null) ? (int)$entry[2] : 0;

                $rowStatus = $this->decodeSnapshotRowStatus($statusCode);

                $decoded[] = [
                    'transformHandle' => $transformHandle,
                    'breakpointWidth' => $breakpointWidth,
                    'assetId' => '',
                    'renderedWidth' => $renderedWidth,
                    'renderedHeight' => $renderedHeight,
                    'rowStatus' => $rowStatus,
                ];
            }
        }

        return $decoded;
    }

    private function encodeSnapshotRowStatus(string $status): int
    {
        return match ($status) {
            'loaded' => 1,
            'broken' => 2,
            'unresolved' => 3,
            'disabled' => 4,
            default => 0,
        };
    }

    private function decodeSnapshotRowStatus(int $statusCode): string
    {
        return match ($statusCode) {
            1 => 'loaded',
            2 => 'broken',
            3 => 'unresolved',
            4 => 'disabled',
            default => 'unprocessed',
        };
    }

    private function resolveSnapshotRowStatus(bool $enabled, bool $loaded, bool $broken, bool $unresolved): string
    {
        if ($enabled === false) {
            return 'disabled';
        }

        if ($loaded) {
            return 'loaded';
        }

        if ($broken) {
            return 'broken';
        }

        if ($unresolved) {
            return 'unresolved';
        }

        return 'unprocessed';
    }

    /**
     * Update preview cache from a completed processing run.
     *
     * For each transform+breakpoint, selects the first asset row. If that row
     * is loaded and has a non-empty URL, upserts the preview cache. Otherwise
     * retains the existing cached row (no fallthrough to later rows).
     *
     * Stale writes are rejected: a cache row is only updated when the incoming
     * lastProcessedAt is newer than (or equal with a newer runId) the existing row.
     */
    private function updatePreviewCacheFromRun(
        mixed $rawRowsByBreakpoint,
        string $ranAt,
        string $runId,
        ?int $entryId,
        ?string $sourceUrl,
    ): void {
        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::PREVIEW_CACHE_TABLE)) {
            return;
        }

        if (!is_array($rawRowsByBreakpoint)) {
            return;
        }

        $now = Db::prepareDateForDb(new \DateTimeImmutable());
        /** @var array<string, array<int, true>> transform handle → set of active breakpoint widths */
        $activeBreakpointsByTransform = [];

        foreach ($rawRowsByBreakpoint as $breakpointKey => $rows) {
            if (!is_array($rows)) {
                continue;
            }

            $breakpointWidth = is_numeric($breakpointKey) ? (int)$breakpointKey : 0;
            if ($breakpointWidth <= 0) {
                continue;
            }

            $firstByTransform = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $transformHandle = trim((string)($row['transform'] ?? ''));
                if ($transformHandle === '') {
                    continue;
                }

                if (!isset($firstByTransform[$transformHandle])) {
                    $firstByTransform[$transformHandle] = $row;
                    $activeBreakpointsByTransform[$transformHandle] ??= [];
                    $activeBreakpointsByTransform[$transformHandle][$breakpointWidth] = true;
                }
            }

            foreach ($firstByTransform as $transformHandle => $row) {
                $enabled = ($row['enabled'] ?? true) === true;
                $loaded = ($row['loaded'] ?? false) === true;
                $broken = ($row['broken'] ?? false) === true;
                $unresolved = ($row['unresolved'] ?? false) === true;
                $rowStatus = $this->resolveSnapshotRowStatus($enabled, $loaded, $broken, $unresolved);

                $displayAssetUrl = trim((string)($row['src'] ?? ''));
                if ($displayAssetUrl !== '' && mb_strlen($displayAssetUrl) > self::DISPLAY_ASSET_URL_MAX_LENGTH) {
                    $displayAssetUrl = mb_substr($displayAssetUrl, 0, self::DISPLAY_ASSET_URL_MAX_LENGTH);
                }

                $renderedWidth = max(0, (int)($row['rendered']['width'] ?? 0));
                $renderedHeight = max(0, (int)($row['rendered']['height'] ?? 0));

                if (!$loaded || $displayAssetUrl === '') {
                    continue;
                }

                $this->upsertPreviewCacheRow(
                    $transformHandle,
                    $breakpointWidth,
                    $displayAssetUrl,
                    $rowStatus,
                    $renderedWidth,
                    $renderedHeight,
                    $ranAt,
                    $runId,
                    $entryId,
                    $sourceUrl,
                    $now,
                );
            }
        }

        if ($activeBreakpointsByTransform !== []) {
            $this->pruneObsoletePreviewCacheRows($activeBreakpointsByTransform);
        }
    }

    /**
     * Upsert a single preview cache row with stale-write protection.
     */
    private function upsertPreviewCacheRow(
        string $transformHandle,
        int $breakpointWidth,
        string $displayAssetUrl,
        string $rowStatus,
        int $renderedWidth,
        int $renderedHeight,
        string $lastProcessedAt,
        string $runId,
        ?int $entryId,
        ?string $sourceUrl,
        string $now,
    ): void {
        $db = Craft::$app->getDb();

        $existing = (new Query())
            ->select(['lastProcessedAt', 'runId'])
            ->from(self::PREVIEW_CACHE_TABLE)
            ->where([
                'transformHandle' => $transformHandle,
                'breakpointWidth' => $breakpointWidth,
            ])
            ->one();

        if (is_array($existing)) {
            $existingTime = $existing['lastProcessedAt'] ?? '';
            if ($existingTime > $lastProcessedAt) {
                return;
            }

            if ($existingTime === $lastProcessedAt) {
                $existingRunId = (string)($existing['runId'] ?? '');
                if ($existingRunId >= $runId) {
                    return;
                }
            }
        }

        try {
            Db::upsert(self::PREVIEW_CACHE_TABLE, [
                'transformHandle' => $transformHandle,
                'breakpointWidth' => $breakpointWidth,
                'displayAssetUrl' => $displayAssetUrl,
                'rowStatus' => $rowStatus,
                'renderedWidth' => $renderedWidth > 0 ? $renderedWidth : null,
                'renderedHeight' => $renderedHeight > 0 ? $renderedHeight : null,
                'lastProcessedAt' => $lastProcessedAt,
                'runId' => $runId,
                'sourceEntryId' => $entryId,
                'sourceUrl' => $sourceUrl,
            ]);
        } catch (\Throwable $e) {
            Plugin::warning('Preview cache upsert failed for "' . $transformHandle . '" at ' . $breakpointWidth . 'px: ' . $e->getMessage());
        }
    }

    /**
     * Prune obsolete preview cache rows for touched transforms whose
     * breakpoint definitions have shrunk (breakpoints no longer in the run).
     */
    /**
     * @param array<string, array<int, true>> $activeBreakpointsByTransform
     */
    private function pruneObsoletePreviewCacheRows(array $activeBreakpointsByTransform): void
    {
        $db = Craft::$app->getDb();

        foreach ($activeBreakpointsByTransform as $transformHandle => $activeBreakpoints) {
            $cachedBreakpoints = (new Query())
                ->select(['breakpointWidth'])
                ->from(self::PREVIEW_CACHE_TABLE)
                ->where(['transformHandle' => $transformHandle])
                ->column();

            foreach ($cachedBreakpoints as $cached) {
                $cachedWidth = (int)$cached;
                if (!isset($activeBreakpoints[$cachedWidth])) {
                    try {
                        $db->createCommand()
                            ->delete(self::PREVIEW_CACHE_TABLE, [
                                'transformHandle' => $transformHandle,
                                'breakpointWidth' => $cachedWidth,
                            ])
                            ->execute();
                    } catch (\Throwable $e) {
                        Plugin::warning('Preview cache prune failed for "' . $transformHandle . '" at ' . $cachedWidth . 'px: ' . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Delete all preview cache rows for a given transform handle.
     */
    public function deletePreviewCacheByTransformHandle(string $transformHandle): void
    {
        $handle = trim($transformHandle);
        if ($handle === '') {
            return;
        }

        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::PREVIEW_CACHE_TABLE)) {
            return;
        }

        try {
            $db->createCommand()
                ->delete(self::PREVIEW_CACHE_TABLE, ['transformHandle' => $handle])
                ->execute();
        } catch (\Throwable $e) {
            Plugin::warning('Preview cache delete failed for "' . $handle . '": ' . $e->getMessage());
        }
    }

    /**
     * Read all preview cache rows, indexed by transformHandle|breakpointWidth.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getPreviewCacheRows(): array
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::PREVIEW_CACHE_TABLE)) {
            return [];
        }

        $rows = (new Query())
            ->select([
                'transformHandle',
                'breakpointWidth',
                'displayAssetUrl',
                'rowStatus',
                'renderedWidth',
                'renderedHeight',
            ])
            ->from(self::PREVIEW_CACHE_TABLE)
            ->orderBy(['transformHandle' => SORT_ASC, 'breakpointWidth' => SORT_ASC])
            ->all();

        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transformHandle = trim((string)($row['transformHandle'] ?? ''));
            $breakpointWidth = isset($row['breakpointWidth']) && is_numeric($row['breakpointWidth'])
                ? (int)$row['breakpointWidth']
                : 0;
            if ($transformHandle === '' || $breakpointWidth <= 0) {
                continue;
            }

            $indexed[$transformHandle . '|' . $breakpointWidth] = $row;
        }

        return $indexed;
    }

}
