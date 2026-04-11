<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpointimages\Plugin;

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
        $this->assertSame('Breakpoint Images', $plugin->name);
    }
}
