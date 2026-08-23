<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\ConfigService;

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

    public function testBreakpointsIncludeDefaultEscapeWhenEscapeWidthIsUnset(): void
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
            'escape' => 1920,
        ], $breakpoints);
    }

    public function testPictureTemplatePathFallsBackWhenBlank(): void
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getConfigService();

        $templatePath = $service->getPictureTemplatePath([
            'pictureTemplatePath' => '   ',
        ]);

        $this->assertSame('breakpoints/picture.twig', $templatePath);
    }

    public function testSvgTemplatePathFallsBackWhenBlank(): void
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getConfigService();

        $templatePath = $service->getSvgTemplatePath([
            'svgTemplatePath' => '   ',
        ]);

        $this->assertSame('breakpoints/svg.twig', $templatePath);
    }

    public function testDefaultPluginConfigIsNotOverriddenByUntouchedSettingsDefaults(): void
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getConfigService();

        $pluginConfigRoot = require dirname(__DIR__, 2) . '/src/config.php';
        $pluginConfig = is_array($pluginConfigRoot['*'] ?? null) ? $pluginConfigRoot['*'] : $pluginConfigRoot;
        $this->assertIsArray($pluginConfig);

        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('breakpoints');
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

    public function testProcessingDiagnosticsFlagUsesConfigAndCanBeOverriddenByEnv(): void
    {
        $service = Plugin::getInstance()->getConfigService();

        $this->assertFalse($service->isProcessingDiagnosticsEnabled([
            'processingDiagnosticsEnabled' => false,
        ]));

        $this->assertTrue($service->isProcessingDiagnosticsEnabled([
            'processingDiagnosticsEnabled' => true,
        ]));

        $previous = getenv('CRAFT_BREAKPOINTS_PROCESSING_DIAGNOSTICS');
        putenv('CRAFT_BREAKPOINTS_PROCESSING_DIAGNOSTICS=true');

        try {
            $this->assertTrue($service->isProcessingDiagnosticsEnabled([
                'processingDiagnosticsEnabled' => false,
            ]));
        } finally {
            if ($previous === false) {
                putenv('CRAFT_BREAKPOINTS_PROCESSING_DIAGNOSTICS');
            } else {
                putenv('CRAFT_BREAKPOINTS_PROCESSING_DIAGNOSTICS=' . $previous);
            }
        }
    }

    public function testTransformsDeveloperActionsFlagUsesConfig(): void
    {
        $service = Plugin::getInstance()->getConfigService();

        $this->assertFalse($service->areTransformsDeveloperActionsEnabled([
            'transformsDeveloperActionsEnabled' => false,
        ]));

        $this->assertTrue($service->areTransformsDeveloperActionsEnabled([
            'transformsDeveloperActionsEnabled' => true,
        ]));
    }

    public function testPriorityExpandsToImageLoadingAndPreloadHints(): void
    {
        $service = Plugin::getInstance()->getConfigService();

        $config = $service->getConfig([
            'priority' => true,
        ]);

        $this->assertTrue($config['preload'] ?? false);
        $this->assertSame('eager', $config['loading'] ?? null);
        $this->assertSame('high', $config['fetchpriority'] ?? null);

        $overridden = $service->getConfig([
            'priority' => true,
            'preload' => false,
            'loading' => 'lazy',
            'fetchpriority' => 'auto',
        ]);

        $this->assertFalse($overridden['preload'] ?? true);
        $this->assertSame('lazy', $overridden['loading'] ?? null);
        $this->assertSame('auto', $overridden['fetchpriority'] ?? null);
    }

    public function testProcessingLazyLoadingConfigFallsBackUnknownAdaptersToNone(): void
    {
        $service = Plugin::getInstance()->getConfigService();

        foreach (['attributes', 'custom', 'vanilla-lazyload', 'lozad', 'automatic'] as $adapter) {
            $config = $service->getProcessingLazyLoadingConfig([
                'nativeLazyLoadingEnabled' => false,
                'processingLazyLoadingAdapter' => $adapter,
            ]);

            $this->assertSame('none', $config['adapter'], $adapter);
            $this->assertSame(['adapter'], array_keys($config));
        }
    }

    public function testProcessingLazyLoadingConfigUsesLazysizesOnlyWhenNativeLazyLoadingIsOff(): void
    {
        $service = Plugin::getInstance()->getConfigService();

        $nativeOn = $service->getProcessingLazyLoadingConfig([
            'nativeLazyLoadingEnabled' => true,
            'processingLazyLoadingAdapter' => 'lazysizes',
        ]);
        $this->assertSame('none', $nativeOn['adapter']);

        $nativeOff = $service->getProcessingLazyLoadingConfig([
            'nativeLazyLoadingEnabled' => false,
            'processingLazyLoadingAdapter' => 'lazysizes',
        ]);
        $this->assertSame('lazysizes', $nativeOff['adapter']);
        $this->assertSame(['adapter'], array_keys($nativeOff));
    }
}
