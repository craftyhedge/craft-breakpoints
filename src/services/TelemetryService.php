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
    private const SOURCE_URL_MAX_LENGTH = 255;
    private const RUN_ID_MAX_LENGTH = 64;

    /** @var array<string, bool> */
    private const VALID_RUN_STATUSES = [
        self::RUN_STATUS_COMPLETED => true,
        self::RUN_STATUS_FAILED => true,
        self::RUN_STATUS_CANCELLED => true,
    ];

    /** @var array<string, bool> */
    private array $_seenHandles = [];

    public function isTelemetryEnabled(): bool
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return false;
        }

        return $plugin->getConfigService()->isTelemetryEnabled();
    }

    public function isInsightsCpEnabled(): bool
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return false;
        }

        return $plugin->getConfigService()->isInsightsCpEnabled();
    }

    public function canWriteTelemetry(): bool
    {
        return $this->isTelemetryEnabled();
    }

    public function canEditTransforms(): bool
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return false;
        }

        return $plugin->getConfigService()->allowTransformEditing();
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
            $normalizedSourceUrl = mb_substr($normalizedSourceUrl, 0, 255);
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

        $failureReasonCountsJson = json_encode($failureReasonCounts, JSON_UNESCAPED_SLASHES);
        if (!is_string($failureReasonCountsJson)) {
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
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Plugin::warning('Run snapshot persistence failed: ' . $e->getMessage());
            return false;
        }
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

        $snapshot['failureReasonCounts'] = $this->decodeFailureReasonCounts($snapshot['failureReasonCounts'] ?? null);
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
                } elseif (mb_strlen($displayAssetUrl) > 1024) {
                    $displayAssetUrl = mb_substr($displayAssetUrl, 0, 1024);
                }

                $enabled = ($row['enabled'] ?? true) === true;
                $loaded = ($row['loaded'] ?? false) === true;
                $broken = ($row['broken'] ?? false) === true;
                $unresolved = ($row['unresolved'] ?? false) === true;

                $rowStatus = 'unprocessed';
                if ($enabled === false) {
                    $rowStatus = 'disabled';
                } elseif ($loaded) {
                    $rowStatus = 'loaded';
                } elseif ($broken) {
                    $rowStatus = 'broken';
                } elseif ($unresolved) {
                    $rowStatus = 'unresolved';
                }

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
     * @return array<string, int>
     */
    private function decodeFailureReasonCounts(mixed $rawCounts): array
    {
        if (!is_string($rawCounts) || trim($rawCounts) === '') {
            return [];
        }

        $decoded = json_decode($rawCounts, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeFailureReasonCounts($decoded);
    }
}
