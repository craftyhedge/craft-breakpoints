<?php

namespace craftyhedge\craftbreakpoints\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Application as WebApplication;
use craftyhedge\craftbreakpoints\helpers\ProcessingRequest;
use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

class TelemetryService extends Component
{
    private const RUN_STATUS_COMPLETED = 'completed';
    private const RUN_STATUS_FAILED = 'failed';
    private const RUN_STATUS_CANCELLED = 'cancelled';
    private const RUN_SNAPSHOT_TABLE = '{{%bpi_processing_run_snapshot}}';
    private const RUN_SNAPSHOT_ROWS_TABLE = '{{%bpi_processing_run_snapshot_breakpoints}}';
    private const RUN_SNAPSHOT_DIMENSIONS_TABLE = '{{%bpi_processing_run_snapshot_dimensions}}';
    private const PREVIEW_CACHE_TABLE = '{{%bpi_preview_cache}}';
    private const SOURCE_URL_MAX_LENGTH = 255;
    private const DISPLAY_ASSET_URL_MAX_LENGTH = 1024;
    private const ASSET_ID_MAX_LENGTH = 255;
    private const AUTO_DIMENSION_MAX_LENGTH = 16;
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

    public function recordUsage(string $transformHandle, ?InitOptions $initOptions = null, ?bool $includeEscapeWidth = null): void
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
            if (ProcessingRequest::isActive()) {
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
            $this->upsertUsage($handle, $sourceElementId, $sourceUrl, $initOptions, $includeEscapeWidth);

            return;
        }

        // Queue/console runtimes have no web request lifecycle; write immediately.
        $this->upsertUsage($handle, $sourceElementId, $sourceUrl, $initOptions, $includeEscapeWidth);
    }

    public function flushPendingUsage(): void
    {
        // Writes happen immediately on first observation per request.
        $this->_seenHandles = [];
    }

    private function upsertUsage(string $handle, ?int $sourceElementId, ?string $sourceUrl, ?InitOptions $initOptions = null, ?bool $includeEscapeWidth = null): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $normalizedSourceUrl = $sourceUrl;
        if (is_string($normalizedSourceUrl) && $normalizedSourceUrl !== '') {
            $normalizedSourceUrl = mb_substr($normalizedSourceUrl, 0, self::SOURCE_URL_MAX_LENGTH);
        }

        $row = [
            'transformHandle' => $handle,
            'sourceElementId' => $sourceElementId,
            'sourceUrl' => $normalizedSourceUrl,
            'lastSeenAt' => $now,
            'initWidth' => null,
            'initHeight' => null,
            'initRatio' => null,
            'initWidthAuto' => null,
            'initHeightAuto' => null,
            'includeEscapeWidth' => $includeEscapeWidth === null ? null : ($includeEscapeWidth ? 1 : 0),
        ];

        if ($initOptions !== null) {
            $hasAnyInit = $initOptions->width !== null
                || $initOptions->height !== null
                || $initOptions->ratio !== null
                || $initOptions->widthAuto
                || $initOptions->heightAuto;

            if ($hasAnyInit) {
                $row['initWidth'] = $initOptions->width;
                $row['initHeight'] = $initOptions->height;
                $row['initRatio'] = $initOptions->ratio !== null
                    ? ($initOptions->ratioRaw ?? rtrim(rtrim(number_format($initOptions->ratio, 8, '.', ''), '0'), '.'))
                    : null;
                $row['initWidthAuto'] = $initOptions->widthAuto ? 1 : 0;
                $row['initHeightAuto'] = $initOptions->heightAuto ? 1 : 0;
            }
        }

        try {
            Db::upsert('{{%bpi_transform_last_processed}}', $row);
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
     * observed entry reference and persisted init options for that handle.
     *
     * @return array<string, array{handle: string, entryId: ?int, sourceUrl: ?string, lastSeenAt: string, initWidth: ?int, initHeight: ?int, initRatio: ?string, initWidthAuto: ?bool, initHeightAuto: ?bool, includeEscapeWidth: ?bool}>
     */
    public function getMostRecentByHandle(): array
    {
        try {
            $rows = (new Query())
                ->select([
                    'transformHandle',
                    'sourceElementId',
                    'sourceUrl',
                    'lastSeenAt',
                    'initWidth',
                    'initHeight',
                    'initRatio',
                    'initWidthAuto',
                    'initHeightAuto',
                    'includeEscapeWidth',
                ])
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
            $initWidth = $row['initWidth'] ?? null;
            $initHeight = $row['initHeight'] ?? null;
            $initRatio = $row['initRatio'] ?? null;
            $initWidthAuto = $row['initWidthAuto'] ?? null;
            $initHeightAuto = $row['initHeightAuto'] ?? null;
            $includeEscapeWidth = $row['includeEscapeWidth'] ?? null;

            $byHandle[$handle] = [
                'handle' => $handle,
                'entryId' => $entryId !== null ? (int)$entryId : null,
                'sourceUrl' => isset($row['sourceUrl']) ? (string)$row['sourceUrl'] : null,
                'lastSeenAt' => (string)($row['lastSeenAt'] ?? ''),
                'initWidth' => $initWidth !== null && $initWidth !== '' ? (int)$initWidth : null,
                'initHeight' => $initHeight !== null && $initHeight !== '' ? (int)$initHeight : null,
                'initRatio' => $initRatio !== null && $initRatio !== '' ? (string)$initRatio : null,
                'initWidthAuto' => $initWidthAuto === null || $initWidthAuto === '' ? null : ((int)$initWidthAuto === 1),
                'initHeightAuto' => $initHeightAuto === null || $initHeightAuto === '' ? null : ((int)$initHeightAuto === 1),
                'includeEscapeWidth' => $includeEscapeWidth === null || $includeEscapeWidth === '' ? null : ((int)$includeEscapeWidth === 1),
            ];
        }

        return $byHandle;
    }

    /**
     * Returns one row per observed transform handle whose handle is not present
     * in $configuredHandles. The row carries the most-recently observed entry
     * reference and persisted init options for that handle.
     *
     * @param array<int, string> $configuredHandles
     * @return array<int, array{handle: string, entryId: ?int, sourceUrl: ?string, lastSeenAt: string, initWidth: ?int, initHeight: ?int, initRatio: ?string, initWidthAuto: ?bool, initHeightAuto: ?bool, includeEscapeWidth: ?bool}>
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
        $failureReasonCountsJson = json_encode($failureReasonCounts, JSON_UNESCAPED_SLASHES);
        if (!is_string($failureReasonCountsJson)) {
            $failureReasonCountsJson = '{}';
        }
        $snapshotRows = $this->normalizeSnapshotRowsBySlot($payload['rowsBySlot'] ?? []);
        $savedDimensionsByTransform = $this->collectSavedDimensionsAtPersistTime();

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
                'failureReasonCounts' => $failureReasonCountsJson,
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
                        $row['slotKey'],
                        $row['slotIndex'],
                        $row['breakpointWidth'],
                        $row['measureWidth'],
                        $row['assetId'],
                        $row['displayAssetUrl'],
                        $row['rowStatus'],
                        $row['renderedWidth'],
                        $row['renderedHeight'],
                        $row['autoDimension'],
                        $now,
                        $now,
                    ];
                }

                $db->createCommand()
                    ->batchInsert(
                        self::RUN_SNAPSHOT_ROWS_TABLE,
                        ['snapshotId', 'transformHandle', 'slotKey', 'slotIndex', 'breakpointWidth', 'measureWidth', 'assetId', 'displayAssetUrl', 'rowStatus', 'renderedWidth', 'renderedHeight', 'autoDimension', 'dateCreated', 'dateUpdated'],
                        $batchRows
                    )
                    ->execute();
            }

            if ($db->tableExists(self::RUN_SNAPSHOT_DIMENSIONS_TABLE)) {
                $db->createCommand()
                    ->delete(self::RUN_SNAPSHOT_DIMENSIONS_TABLE, ['snapshotId' => 1])
                    ->execute();

                $dimensionRows = [];
                $now = $now ?? Db::prepareDateForDb(new \DateTimeImmutable());
                foreach ($savedDimensionsByTransform as $transformHandle => $bySlot) {
                    if (!is_string($transformHandle) || $transformHandle === '' || !is_array($bySlot)) {
                        continue;
                    }
                    foreach ($bySlot as $slotKey => $entry) {
                        if (!is_string($slotKey) || $slotKey === '' || !is_array($entry)) {
                            continue;
                        }
                        $slotIndex = isset($entry['slotIndex']) && is_numeric($entry['slotIndex']) ? (int)$entry['slotIndex'] : -1;
                        $breakpointWidth = isset($entry['breakpointWidth']) && is_numeric($entry['breakpointWidth']) ? (int)$entry['breakpointWidth'] : 0;
                        $measureWidth = isset($entry['measureWidth']) && is_numeric($entry['measureWidth']) ? (int)$entry['measureWidth'] : $breakpointWidth;
                        if ($slotIndex < 0 || $breakpointWidth <= 0 || $measureWidth <= 0) {
                            continue;
                        }

                        $savedWidth = isset($entry['w']) && is_numeric($entry['w']) && (int)$entry['w'] > 0 ? (int)$entry['w'] : null;
                        $savedHeight = isset($entry['h']) && is_numeric($entry['h']) && (int)$entry['h'] > 0 ? (int)$entry['h'] : null;
                        $dimensionRows[] = [
                            1,
                            $transformHandle,
                            $slotKey,
                            $slotIndex,
                            $breakpointWidth,
                            $measureWidth,
                            $savedWidth,
                            $savedHeight,
                            $now,
                            $now,
                        ];
                    }
                }

                if ($dimensionRows !== []) {
                    $db->createCommand()
                        ->batchInsert(
                            self::RUN_SNAPSHOT_DIMENSIONS_TABLE,
                            ['snapshotId', 'transformHandle', 'slotKey', 'slotIndex', 'breakpointWidth', 'measureWidth', 'savedWidth', 'savedHeight', 'dateCreated', 'dateUpdated'],
                            $dimensionRows
                        )
                        ->execute();
                }
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
                    $payload['rowsBySlot'] ?? [],
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
        $perAssetRows = [];
        if ($snapshotId > 0) {
            $perAssetRows = (new Query())
                ->select(['transformHandle', 'slotKey', 'slotIndex', 'breakpointWidth', 'measureWidth', 'assetId', 'displayAssetUrl', 'rowStatus', 'renderedWidth', 'renderedHeight', 'autoDimension'])
                ->from(self::RUN_SNAPSHOT_ROWS_TABLE)
                ->where(['snapshotId' => $snapshotId])
                ->orderBy(['transformHandle' => SORT_ASC, 'slotIndex' => SORT_ASC, 'id' => SORT_ASC])
                ->all();
        }

        $rowsPayload = [];
        $rowsByKey = [];
        foreach ($perAssetRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $transformHandle = (string)($row['transformHandle'] ?? '');
            $slotKey = trim((string)($row['slotKey'] ?? ''));
            $slotIndex = (int)($row['slotIndex'] ?? -1);
            $breakpointWidth = (int)($row['breakpointWidth'] ?? 0);
            $measureWidth = (int)($row['measureWidth'] ?? $breakpointWidth);
            if ($transformHandle === '' || $slotKey === '' || $slotIndex < 0 || $breakpointWidth <= 0) {
                continue;
            }

            $rowsPayload[] = [
                'transformHandle' => $transformHandle,
                'slotKey' => $slotKey,
                'slotIndex' => $slotIndex,
                'breakpointWidth' => $breakpointWidth,
                'measureWidth' => $measureWidth,
                'assetId' => (string)($row['assetId'] ?? ''),
                'displayAssetUrl' => $row['displayAssetUrl'] !== null ? (string)$row['displayAssetUrl'] : null,
                'rowStatus' => (string)($row['rowStatus'] ?? 'unprocessed'),
                'renderedWidth' => max(0, (int)($row['renderedWidth'] ?? 0)),
                'renderedHeight' => max(0, (int)($row['renderedHeight'] ?? 0)),
                'autoDimension' => $row['autoDimension'] !== null && $row['autoDimension'] !== '' ? (string)$row['autoDimension'] : null,
            ];

            $dedupeKey = $transformHandle . '|' . $slotKey;
            if (!isset($rowsByKey[$dedupeKey])) {
                $rowsByKey[$dedupeKey] = [
                    'transformHandle' => $transformHandle,
                    'slotKey' => $slotKey,
                    'slotIndex' => $slotIndex,
                    'breakpointWidth' => $breakpointWidth,
                    'measureWidth' => $measureWidth,
                    'displayAssetUrl' => $row['displayAssetUrl'] !== null ? (string)$row['displayAssetUrl'] : null,
                    'rowStatus' => (string)($row['rowStatus'] ?? 'unprocessed'),
                ];
            }
        }

        $savedDimensionsByTransform = [];
        if ($snapshotId > 0 && $db->tableExists(self::RUN_SNAPSHOT_DIMENSIONS_TABLE)) {
            $dimensionRows = (new Query())
                ->select(['transformHandle', 'slotKey', 'slotIndex', 'breakpointWidth', 'measureWidth', 'savedWidth', 'savedHeight'])
                ->from(self::RUN_SNAPSHOT_DIMENSIONS_TABLE)
                ->where(['snapshotId' => $snapshotId])
                ->all();

            foreach ($dimensionRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $transformHandle = (string)($row['transformHandle'] ?? '');
                $slotKey = trim((string)($row['slotKey'] ?? ''));
                $slotIndex = isset($row['slotIndex']) && is_numeric($row['slotIndex']) ? (int)$row['slotIndex'] : -1;
                $breakpointWidth = (int)($row['breakpointWidth'] ?? 0);
                $measureWidth = isset($row['measureWidth']) && is_numeric($row['measureWidth']) ? (int)$row['measureWidth'] : $breakpointWidth;
                if ($transformHandle === '' || $slotKey === '' || $slotIndex < 0 || $breakpointWidth <= 0) {
                    continue;
                }
                $savedDimensionsByTransform[$transformHandle][$slotKey] = [
                    'slotKey' => $slotKey,
                    'slotIndex' => $slotIndex,
                    'breakpointWidth' => $breakpointWidth,
                    'measureWidth' => $measureWidth,
                    'w' => $row['savedWidth'] !== null ? (int)$row['savedWidth'] : null,
                    'h' => $row['savedHeight'] !== null ? (int)$row['savedHeight'] : null,
                ];
            }
        }

        $snapshot['failureReasonCounts'] = $this->decodeFailureReasonCountsColumn($snapshot['failureReasonCounts'] ?? null);
        $snapshot['rowsPayload'] = $rowsPayload;
        $snapshot['savedDimensionsByTransform'] = $savedDimensionsByTransform;
        $snapshot['rows'] = array_values($rowsByKey);

        return $snapshot;
    }

    /**
     * @return array<string, int>
     */
    private function decodeFailureReasonCountsColumn(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return $this->normalizeFailureReasonCounts(is_array($decoded) ? $decoded : []);
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
    private function normalizeSnapshotRowsBySlot(mixed $rawRowsBySlot): array
    {
        if (!is_array($rawRowsBySlot)) {
            return [];
        }

        $normalizedRows = [];
        foreach ($rawRowsBySlot as $slotKeyFromMap => $rows) {
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $slotKey = trim((string)($row['slotKey'] ?? (is_string($slotKeyFromMap) ? $slotKeyFromMap : '')));
                $slotIndex = is_numeric($row['slotIndex'] ?? null) ? (int)$row['slotIndex'] : -1;
                $breakpointWidth = is_numeric($row['mediaWidth'] ?? null)
                    ? (int)$row['mediaWidth']
                    : (is_numeric($slotKeyFromMap) ? (int)$slotKeyFromMap : 0);
                $measureWidth = is_numeric($row['measureWidth'] ?? null) ? (int)$row['measureWidth'] : $breakpointWidth;
                if ($slotKey === '' || $slotIndex < 0 || $breakpointWidth <= 0) {
                    continue;
                }

                $transformHandle = trim((string)($row['transform'] ?? ''));
                if ($transformHandle === '') {
                    continue;
                }

                $assetId = trim((string)($row['assetId'] ?? ''));
                if ($assetId !== '' && mb_strlen($assetId) > self::ASSET_ID_MAX_LENGTH) {
                    $assetId = mb_substr($assetId, 0, self::ASSET_ID_MAX_LENGTH);
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

                $renderedWidth = max(0, (int)($row['rendered']['width'] ?? 0));
                $renderedHeight = max(0, (int)($row['rendered']['height'] ?? 0));

                $autoDimension = $this->normalizeSnapshotAutoDimension(
                    $row['transformDimensions']['autoDimension'] ?? ($row['autoDimension'] ?? null),
                );

                $rowStatus = $this->resolveSnapshotRowStatus($enabled, $loaded, $broken, $unresolved);

                $normalizedRows[] = [
                    'transformHandle' => $transformHandle,
                    'slotKey' => $slotKey,
                    'slotIndex' => $slotIndex,
                    'breakpointWidth' => $breakpointWidth,
                    'measureWidth' => $measureWidth,
                    'assetId' => $assetId !== '' ? $assetId : null,
                    'displayAssetUrl' => $displayAssetUrl,
                    'rowStatus' => $rowStatus,
                    'renderedWidth' => $renderedWidth,
                    'renderedHeight' => $renderedHeight,
                    'autoDimension' => $autoDimension,
                ];
            }
        }

        return $normalizedRows;
    }

    private function normalizeSnapshotAutoDimension(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = strtolower(trim($raw));
        if ($value !== 'width' && $value !== 'height') {
            return null;
        }
        return mb_substr($value, 0, self::AUTO_DIMENSION_MAX_LENGTH);
    }

    /**
     * @return array<string, array<string, array{slotKey: string, slotIndex: int, breakpointWidth: int, measureWidth: int, w: int|null, h: int|null}>>
     */
    private function collectSavedDimensionsAtPersistTime(): array
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return [];
        }

        try {
            return $plugin->getTransformEditor()->buildSavedDimensionsByTransformAndSlot();
        } catch (\Throwable $e) {
            Plugin::warning('Failed to collect saved transform dimensions for snapshot: ' . $e->getMessage());
            return [];
        }
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
        /** @var array<string, array<string, true>> transform handle → set of active slot keys */
        $activeSlotsByTransform = [];

        foreach ($rawRowsByBreakpoint as $slotKeyFromMap => $rows) {
            if (!is_array($rows)) {
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

                $slotKey = trim((string)($row['slotKey'] ?? (is_string($slotKeyFromMap) ? $slotKeyFromMap : '')));
                if ($slotKey === '') {
                    continue;
                }

                if (!isset($firstByTransform[$transformHandle])) {
                    $firstByTransform[$transformHandle] = $row;
                    $activeSlotsByTransform[$transformHandle] ??= [];
                    $activeSlotsByTransform[$transformHandle][$slotKey] = true;
                }
            }

            foreach ($firstByTransform as $transformHandle => $row) {
                $slotKey = trim((string)($row['slotKey'] ?? (is_string($slotKeyFromMap) ? $slotKeyFromMap : '')));
                $slotIndex = is_numeric($row['slotIndex'] ?? null) ? (int)$row['slotIndex'] : -1;
                $breakpointWidth = is_numeric($row['mediaWidth'] ?? null) ? (int)$row['mediaWidth'] : 0;
                $measureWidth = is_numeric($row['measureWidth'] ?? null) ? (int)$row['measureWidth'] : $breakpointWidth;
                if ($slotKey === '' || $slotIndex < 0 || $breakpointWidth <= 0) {
                    continue;
                }
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
                    $slotKey,
                    $slotIndex,
                    $breakpointWidth,
                    $measureWidth,
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

        if ($activeSlotsByTransform !== []) {
            $this->pruneObsoletePreviewCacheRows($activeSlotsByTransform);
        }
    }

    /**
     * Upsert a single preview cache row with stale-write protection.
     */
    private function upsertPreviewCacheRow(
        string $transformHandle,
        string $slotKey,
        int $slotIndex,
        int $breakpointWidth,
        int $measureWidth,
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
                'slotKey' => $slotKey,
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
                'slotKey' => $slotKey,
                'slotIndex' => $slotIndex,
                'breakpointWidth' => $breakpointWidth,
                'measureWidth' => $measureWidth,
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
            Plugin::warning('Preview cache upsert failed for "' . $transformHandle . '" slot ' . $slotKey . ': ' . $e->getMessage());
        }
    }

    /**
     * Prune obsolete preview cache rows for touched transforms whose
     * breakpoint definitions have shrunk (breakpoints no longer in the run).
     */
    /**
     * @param array<string, array<string, true>> $activeBreakpointsByTransform
     */
    private function pruneObsoletePreviewCacheRows(array $activeBreakpointsByTransform): void
    {
        $db = Craft::$app->getDb();

        foreach ($activeBreakpointsByTransform as $transformHandle => $activeBreakpoints) {
            $cachedBreakpoints = (new Query())
                ->select(['slotKey'])
                ->from(self::PREVIEW_CACHE_TABLE)
                ->where(['transformHandle' => $transformHandle])
                ->column();

            foreach ($cachedBreakpoints as $cached) {
                $cachedSlotKey = (string)$cached;
                if (!isset($activeBreakpoints[$cachedSlotKey])) {
                    try {
                        $db->createCommand()
                            ->delete(self::PREVIEW_CACHE_TABLE, [
                                'transformHandle' => $transformHandle,
                                'slotKey' => $cachedSlotKey,
                            ])
                            ->execute();
                    } catch (\Throwable $e) {
                        Plugin::warning('Preview cache prune failed for "' . $transformHandle . '" slot ' . $cachedSlotKey . ': ' . $e->getMessage());
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
     * Read all preview cache rows, indexed by transformHandle|slotKey.
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
                'slotKey',
                'slotIndex',
                'breakpointWidth',
                'measureWidth',
                'displayAssetUrl',
                'rowStatus',
                'renderedWidth',
                'renderedHeight',
            ])
            ->from(self::PREVIEW_CACHE_TABLE)
            ->orderBy(['transformHandle' => SORT_ASC, 'slotIndex' => SORT_ASC])
            ->all();

        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transformHandle = trim((string)($row['transformHandle'] ?? ''));
            $slotKey = trim((string)($row['slotKey'] ?? ''));
            $breakpointWidth = isset($row['breakpointWidth']) && is_numeric($row['breakpointWidth'])
                ? (int)$row['breakpointWidth']
                : 0;
            if ($transformHandle === '' || $slotKey === '' || $breakpointWidth <= 0) {
                continue;
            }

            $indexed[$transformHandle . '|' . $slotKey] = $row;
        }

        return $indexed;
    }

}
