<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpointimages\models\Settings;
use craftyhedge\craftbreakpointimages\Plugin;

final class PluginRegistrationTest extends Unit
{
    public function testPluginInstanceIsAvailable(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertSame('Breakpoint Images', $plugin->name);
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
        $this->assertArrayHasKey('transforms', $cpNavItem['subnav']);
        $this->assertSame('craft-breakpoint-images/settings', $cpNavItem['subnav']['settings']['url']);
        $this->assertSame('craft-breakpoint-images/transforms', $cpNavItem['subnav']['transforms']['url']);
    }

    public function testTransformsConfigFileIsCreatedAndLoaded(): void
    {
        $plugin = Plugin::getInstance();
        $configPath = Craft::$app->getPath()->getConfigPath() . '/craft-breakpoint-images/transforms.json';
        $transforms = $plugin->getTransformStore()->getTransforms();

        $this->assertFileExists($configPath);
        $this->assertIsArray($transforms);
        $this->assertArrayHasKey('default', $transforms);

        $defaultTransform = $plugin->getTransforms()->getTransform('default');
        $this->assertIsArray($defaultTransform);
        $this->assertArrayHasKey('transforms', $defaultTransform);
        $this->assertIsArray($defaultTransform['transforms']);
    }

}
