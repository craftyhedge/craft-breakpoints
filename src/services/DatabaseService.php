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
    public const TABLE_USAGE = '{{%bpi_transform_last_processed}}';
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

    public function clearObservedUsage(): int
    {
        return $this->truncate(self::TABLE_USAGE);
    }

    /**
     * @return array<string, int>
     */
    public function clearAll(): array
    {
        return [
            'previewCache' => $this->clearPreviewCache(),
            'runSnapshot' => $this->clearRunSnapshots(),
            'usage' => $this->clearObservedUsage(),
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
