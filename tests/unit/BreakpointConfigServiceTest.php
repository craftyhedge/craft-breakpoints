<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpointimages\Plugin;
use craftyhedge\craftbreakpointimages\services\ConfigService;

final class BreakpointConfigServiceTest extends Unit
{
    public function testBreakpointsAreNormalizedAndEscapeWidthIsAdjusted(): void
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getConfigService();

        $breakpoints = $service->getBreakpoints([
            'breakpoints' => [
                'lg' => 1024,
                'bad' => 'nope',
                'sm' => 640,
                'zero' => 0,
                'xs' => 480,
            ],
            'escapeWidth' => 700,
        ]);

        $this->assertSame([
            'xs' => 480,
            'sm' => 640,
            'lg' => 1024,
            'escape' => 1025,
        ], $breakpoints);
    }

    public function testBreakpointsDoNotIncludeEscapeWhenEscapeWidthIsUnset(): void
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getConfigService();

        $breakpoints = $service->getBreakpoints([
            'breakpoints' => [
                'xs' => 480,
                'sm' => 640,
                '2xl' => 1536,
            ],
        ]);

        $this->assertSame([
            'xs' => 480,
            'sm' => 640,
            '2xl' => 1536,
        ], $breakpoints);
    }

    public function testPictureTemplatePathFallsBackWhenBlank(): void
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getConfigService();

        $templatePath = $service->getPictureTemplatePath([
            'pictureTemplatePath' => '   ',
        ]);

        $this->assertSame('craft-breakpoint-images/picture.twig', $templatePath);
    }

    public function testDefaultPluginConfigIsNotOverriddenByUntouchedSettingsDefaults(): void
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getConfigService();

        $pluginConfig = require CRAFT_ROOT_PATH . '/src/config.php';
        $this->assertIsArray($pluginConfig);

        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('craft-breakpoint-images');
        if (is_array($projectConfig) && array_key_exists('secondaryFormat', $projectConfig)) {
            $this->assertSame($projectConfig['secondaryFormat'], $service->get('secondaryFormat'));
            return;
        }

        $this->assertArrayHasKey('secondaryFormat', $pluginConfig);
        $this->assertSame($pluginConfig['secondaryFormat'], $service->get('secondaryFormat'));
    }

    public function testDprNormalizationRejectsNonFiniteAndInvalidRatios(): void
    {
        $service = new ConfigService();
        $method = new \ReflectionMethod(ConfigService::class, 'normalizeDpr');

        $normalized = $method->invoke($service, [
            0,
            -1,
            'text',
            INF,
            NAN,
            2,
            1,
            2.0,
            '3',
        ]);

        $this->assertSame([1.0, 2.0, 3.0], $normalized);
    }
}
