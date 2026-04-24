<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpoints\Plugin;

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
}
