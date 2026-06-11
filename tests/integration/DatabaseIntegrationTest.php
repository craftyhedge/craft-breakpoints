<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\DatabaseService;

final class DatabaseIntegrationTest extends Unit
{
    public function testCraftInstallationSchemaExists(): void
    {
        $usersTable = Craft::$app->getDb()->getSchema()->getTableSchema('{{%users}}', true);

        $this->assertNotNull($usersTable);
    }

    public function testPluginIsInstalledAndBooted(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertSame('Breakpoints', $plugin->name);
    }

    public function testClearObservedUsageOnlyClearsObservationRows(): void
    {
        $db = Craft::$app->getDb();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $db->createCommand()->delete(DatabaseService::TABLE_USAGE)->execute();
        $db->createCommand()->delete(DatabaseService::TABLE_PREVIEW_CACHE)->execute();

        try {
            $db->createCommand()->insert(DatabaseService::TABLE_USAGE, [
                'transformHandle' => 'hero',
                'sourceElementId' => null,
                'sourceUrl' => '/example',
                'lastSeenAt' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ])->execute();

            $db->createCommand()->insert(DatabaseService::TABLE_PREVIEW_CACHE, [
                'transformHandle' => 'hero',
                'slotKey' => 'default',
                'slotIndex' => 0,
                'breakpointWidth' => 768,
                'measureWidth' => 768,
                'rowStatus' => 'processed',
                'lastProcessedAt' => $now,
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ])->execute();

            $deleted = Plugin::getInstance()->getDatabase()->clearObservedUsage();

            $this->assertSame(1, $deleted);
            $this->assertSame(0, (int)(new Query())->from(DatabaseService::TABLE_USAGE)->count('*', $db));
            $this->assertSame(1, (int)(new Query())->from(DatabaseService::TABLE_PREVIEW_CACHE)->count('*', $db));
        } finally {
            $db->createCommand()->delete(DatabaseService::TABLE_USAGE)->execute();
            $db->createCommand()->delete(DatabaseService::TABLE_PREVIEW_CACHE)->execute();
        }
    }
}
