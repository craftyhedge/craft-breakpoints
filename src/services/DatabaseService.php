<?php

namespace craftyhedge\craftbreakpoints\services;

use Craft;
use craft\db\Query;
use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

/**
 * Utilities for the Database tab in plugin settings. Provides row-count
 * inspection and scoped/bulk delete operations across the plugin's tables.
 */
class DatabaseService extends Component
{
    public const TABLE_USAGE = '{{%bpi_transform_last_processed}}';
    public const TABLE_RUN_SNAPSHOT = '{{%bpi_processing_run_snapshot}}';
    public const TABLE_RUN_SNAPSHOT_ROWS = '{{%bpi_processing_run_snapshot_breakpoints}}';
    public const TABLE_PREVIEW_CACHE = '{{%bpi_preview_cache}}';

    /**
     * @return array<int, array{key: string, table: string, label: string, rows: int, exists: bool}>
     */
    public function getTableStats(): array
    {
        $tables = [
            ['key' => 'usage', 'table' => self::TABLE_USAGE, 'label' => 'Observed transform usage'],
            ['key' => 'runSnapshot', 'table' => self::TABLE_RUN_SNAPSHOT, 'label' => 'Last run snapshot'],
            ['key' => 'runSnapshotRows', 'table' => self::TABLE_RUN_SNAPSHOT_ROWS, 'label' => 'Last run snapshot rows'],
            ['key' => 'previewCache', 'table' => self::TABLE_PREVIEW_CACHE, 'label' => 'Preview cache'],
        ];

        $db = Craft::$app->getDb();
        $stats = [];
        foreach ($tables as $meta) {
            $exists = $db->tableExists($meta['table']);
            $rows = 0;
            if ($exists) {
                try {
                    $rows = (int)(new Query())->from($meta['table'])->count('*', $db);
                } catch (\Throwable $e) {
                    Plugin::warning('Database stats count failed for ' . $meta['table'] . ': ' . $e->getMessage());
                }
            }

            $stats[] = [
                'key' => $meta['key'],
                'table' => $meta['table'],
                'label' => $meta['label'],
                'rows' => $rows,
                'exists' => $exists,
            ];
        }

        return $stats;
    }

    public function getLatestRunTimestamp(): ?string
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists(self::TABLE_RUN_SNAPSHOT)) {
            return null;
        }

        $value = (new Query())
            ->from(self::TABLE_RUN_SNAPSHOT)
            ->select(['ranAt'])
            ->orderBy(['ranAt' => SORT_DESC])
            ->scalar($db);

        return is_string($value) && $value !== '' ? $value : null;
    }

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

    /**
     * Deletes rows whose transformHandle is no longer in the saved transform
     * config, and rows whose sourceEntryId points to a non-existent or
     * soft-deleted entry.
     *
     * @return array{orphanedHandles: int, orphanedEntries: int, total: int}
     */
    public function cleanupOrphanedRows(): array
    {
        $configuredHandles = $this->getConfiguredTransformHandles();

        $handlesRemoved = 0;
        $handlesRemoved += $this->deleteRowsWithUnknownHandle(self::TABLE_PREVIEW_CACHE, $configuredHandles);
        $handlesRemoved += $this->deleteRowsWithUnknownHandle(self::TABLE_USAGE, $configuredHandles);
        $handlesRemoved += $this->deleteRowsWithUnknownHandle(self::TABLE_RUN_SNAPSHOT_ROWS, $configuredHandles);

        $entriesRemoved = 0;
        $entriesRemoved += $this->deleteRowsWithMissingEntry(self::TABLE_PREVIEW_CACHE, 'sourceEntryId');
        $entriesRemoved += $this->deleteRowsWithMissingEntry(self::TABLE_USAGE, 'sourceElementId');
        $entriesRemoved += $this->deleteRowsWithMissingEntry(self::TABLE_RUN_SNAPSHOT, 'entryId');

        return [
            'orphanedHandles' => $handlesRemoved,
            'orphanedEntries' => $entriesRemoved,
            'total' => $handlesRemoved + $entriesRemoved,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function getConfiguredTransformHandles(): array
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return [];
        }

        $transforms = $plugin->getTransformStore()->getTransforms();
        $handles = [];
        foreach (array_keys($transforms) as $handle) {
            if (is_string($handle) && $handle !== '') {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * @param array<int, string> $configuredHandles
     */
    private function deleteRowsWithUnknownHandle(string $table, array $configuredHandles): int
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists($table)) {
            return 0;
        }

        try {
            if ($configuredHandles === []) {
                return (int)$db->createCommand()->delete($table)->execute();
            }

            return (int)$db->createCommand()
                ->delete($table, ['not', ['transformHandle' => $configuredHandles]])
                ->execute();
        } catch (\Throwable $e) {
            Plugin::warning('Orphan cleanup (handles) failed for ' . $table . ': ' . $e->getMessage());
            return 0;
        }
    }

    private function deleteRowsWithMissingEntry(string $table, string $column): int
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists($table) || !$db->tableExists('{{%elements}}')) {
            return 0;
        }

        try {
            $orphanIds = (new Query())
                ->select(['t.' . $column])
                ->from($table . ' t')
                ->leftJoin('{{%elements}} e', '[[e.id]] = [[t.' . $column . ']]')
                ->where(['not', ['t.' . $column => null]])
                ->andWhere([
                    'or',
                    ['e.id' => null],
                    ['not', ['e.dateDeleted' => null]],
                ])
                ->column($db);

            $orphanIds = array_values(array_unique(array_map('intval', $orphanIds)));
            if ($orphanIds === []) {
                return 0;
            }

            return (int)$db->createCommand()
                ->delete($table, [$column => $orphanIds])
                ->execute();
        } catch (\Throwable $e) {
            Plugin::warning('Orphan cleanup (entries) failed for ' . $table . ': ' . $e->getMessage());
            return 0;
        }
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
