<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpoints\models\Settings;
use craftyhedge\craftbreakpoints\Plugin;

final class PluginRegistrationTest extends Unit
{
    public function testPluginInstanceIsAvailable(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertSame('Breakpoints', $plugin->name);
    }

    public function testPluginReturnsSettingsModel(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertInstanceOf(Settings::class, $plugin->getSettings());
    }

    public function testPluginCpSectionNavIsConfigured(): void
    {
        $plugin = Plugin::getInstance();
        $cpNavItem = $plugin->getCpNavItem();

        $this->assertTrue($plugin->hasCpSection);
        $this->assertIsArray($cpNavItem);
        $this->assertArrayHasKey('subnav', $cpNavItem);
        $this->assertArrayHasKey('settings', $cpNavItem['subnav']);
        $this->assertArrayHasKey('processing', $cpNavItem['subnav']);
        $this->assertArrayNotHasKey('transforms', $cpNavItem['subnav']);
        $this->assertSame('craft-breakpoints/settings', $cpNavItem['subnav']['settings']['url']);
        $this->assertSame('craft-breakpoints/processing', $cpNavItem['subnav']['processing']['url']);
    }

    public function testTransformsConfigFileIsCreatedAndLoaded(): void
    {
        $plugin = Plugin::getInstance();
        $configPath = Craft::$app->getPath()->getConfigPath() . '/craft-breakpoints/transform-sets.json';
        $transforms = $plugin->getTransformStore()->getTransforms();
        $sets = $plugin->getTransformSets()->getSets();

        $this->assertFileExists($configPath);
        $this->assertIsArray($transforms);
        $this->assertIsArray($sets);

        foreach ($sets as $setName => $set) {
            $this->assertIsString($setName);
            $this->assertIsArray($set);
            $this->assertArrayHasKey('variants', $set);
            $this->assertArrayHasKey('config', $set);
            $this->assertIsArray($set['variants']);
            $this->assertIsArray($set['config']);
        }
    }

}
