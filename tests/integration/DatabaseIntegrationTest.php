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

    public function testClearUsageTrackingOnlyClearsUsageTrackingRows(): void
    {
        $db = Craft::$app->getDb();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $db->createCommand()->delete(DatabaseService::TABLE_USAGE_OBSERVATIONS)->execute();

        try {
            $db->createCommand()->insert(DatabaseService::TABLE_USAGE_OBSERVATIONS, [
                'transformHandle' => 'hero',
                'sourceKey' => hash('sha256', 'url:/example'),
                'sourceElementId' => null,
                'sourceUrl' => '/example',
                'firstSeenAt' => $now,
                'lastSeenAt' => $now,
                'seenCount' => 1,
                'dateCreated' => $now,
                'dateUpdated' => $now,
            ])->execute();

            $deleted = Plugin::getInstance()->getDatabase()->clearUsageTracking();

            $this->assertSame(1, $deleted);
            $this->assertSame(0, (int)(new Query())->from(DatabaseService::TABLE_USAGE_OBSERVATIONS)->count('*', $db));
        } finally {
            $db->createCommand()->delete(DatabaseService::TABLE_USAGE_OBSERVATIONS)->execute();
        }
    }
}
