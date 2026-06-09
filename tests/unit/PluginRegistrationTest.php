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

        $this->assertTrue($plugin->hasCpSection);
        $this->withMergedConfigValue('allowTransformEditing', true, function() use ($plugin): void {
            $cpNavItem = $plugin->getCpNavItem();

            $this->assertIsArray($cpNavItem);
            $this->assertArrayHasKey('subnav', $cpNavItem);
            $this->assertArrayHasKey('settings', $cpNavItem['subnav']);
            $this->assertArrayHasKey('docs', $cpNavItem['subnav']);
            $this->assertArrayHasKey('processing', $cpNavItem['subnav']);
            $this->assertArrayNotHasKey('transforms', $cpNavItem['subnav']);
            $this->assertSame('breakpoints/settings', $cpNavItem['subnav']['settings']['url']);
            $this->assertSame('breakpoints/docs', $cpNavItem['subnav']['docs']['url']);
            $this->assertSame('breakpoints/processing', $cpNavItem['subnav']['processing']['url']);
        });
    }

    public function testSettingsAndDocsNavItemsAreHiddenWhenTransformEditingIsDisabled(): void
    {
        $plugin = Plugin::getInstance();

        $this->withMergedConfigValue('allowTransformEditing', false, function() use ($plugin): void {
            $cpNavItem = $plugin->getCpNavItem();

            $this->assertIsArray($cpNavItem);
            $this->assertArrayHasKey('subnav', $cpNavItem);
            $this->assertArrayHasKey('processing', $cpNavItem['subnav']);
            $this->assertArrayNotHasKey('settings', $cpNavItem['subnav']);
            $this->assertArrayNotHasKey('docs', $cpNavItem['subnav']);
        });
    }

    public function testTransformsConfigFileIsCreatedAndLoaded(): void
    {
        $plugin = Plugin::getInstance();
        $configPath = Craft::$app->getPath()->getConfigPath() . '/breakpoints/transform-sets.json';
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

    private function withMergedConfigValue(string $key, mixed $value, callable $callback): void
    {
        $configService = Plugin::getInstance()->getConfigService();
        $property = new \ReflectionProperty($configService, '_mergedConfig');
        $previous = $property->getValue($configService);

        $nextConfig = is_array($previous) ? $previous : $configService->getConfig();
        $nextConfig[$key] = $value;
        $property->setValue($configService, $nextConfig);

        try {
            $callback();
        } finally {
            $property->setValue($configService, $previous);
        }
    }

}
