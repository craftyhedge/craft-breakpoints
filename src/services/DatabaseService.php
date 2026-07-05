<?php

namespace craftyhedge\craftbreakpoints\services;

use Craft;
use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

/**
 * Utilities for the Database tab in plugin settings.
 */
class DatabaseService extends Component
{
    public const TABLE_USAGE_OBSERVATIONS = '{{%bpi_transform_usage_observations}}';
    public const TABLE_RUN_SNAPSHOT = '{{%bpi_processing_run_snapshot}}';
    public const TABLE_RUN_SNAPSHOT_ROWS = '{{%bpi_processing_run_snapshot_breakpoints}}';
    public const TABLE_PREVIEW_CACHE = '{{%bpi_preview_cache}}';

    public function clearPreviewCache(): int
    {
        return $this->truncate(self::TABLE_PREVIEW_CACHE);
    }

    public function clearRunSnapshots(): int
    {
        $rows = $this->truncate(self::TABLE_RUN_SNAPSHOT_ROWS);
        $rows += $this->truncate(self::TABLE_RUN_SNAPSHOT);
        return $rows;
    }

    public function clearUsageTracking(): int
    {
        return $this->truncate(self::TABLE_USAGE_OBSERVATIONS);
    }

    public function clearUsageTrackingRow(int $id): int
    {
        if ($id <= 0) {
            return 0;
        }

        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::TABLE_USAGE_OBSERVATIONS)) {
            return 0;
        }

        try {
            return (int)$db->createCommand()
                ->delete(self::TABLE_USAGE_OBSERVATIONS, ['id' => $id])
                ->execute();
        } catch (\Throwable $e) {
            Plugin::warning('Database row delete failed for ' . self::TABLE_USAGE_OBSERVATIONS . ': ' . $e->getMessage());
            return 0;
        }
    }

    public function clearUsageTrackingHandle(string $transformHandle): int
    {
        $transformHandle = trim($transformHandle);
        if ($transformHandle === '') {
            return 0;
        }

        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::TABLE_USAGE_OBSERVATIONS)) {
            return 0;
        }

        try {
            return (int)$db->createCommand()
                ->delete(self::TABLE_USAGE_OBSERVATIONS, ['transformHandle' => $transformHandle])
                ->execute();
        } catch (\Throwable $e) {
            Plugin::warning('Database handle delete failed for ' . self::TABLE_USAGE_OBSERVATIONS . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @return array<string, int>
     */
    public function clearAll(): array
    {
        return [
            'previewCache' => $this->clearPreviewCache(),
            'runSnapshot' => $this->clearRunSnapshots(),
            'usageTracking' => $this->clearUsageTracking(),
        ];
    }

    private function truncate(string $table): int
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists($table)) {
            return 0;
        }

        try {
            return (int)$db->createCommand()->delete($table)->execute();
        } catch (\Throwable $e) {
            Plugin::warning('Database truncate failed for ' . $table . ': ' . $e->getMessage());
            return 0;
        }
    }
}
