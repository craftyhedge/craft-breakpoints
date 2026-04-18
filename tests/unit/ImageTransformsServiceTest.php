<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpointimages\Plugin;

final class ImageTransformsServiceTest extends Unit
{
    public function testSourceMediaQueryGeneration(): void
    {
        $service = Plugin::getInstance()->getImageTransforms();

        $this->assertSame('', $service->sourceMediaQuery(480, null, false));
        $this->assertSame('(max-width: 39.9375rem)', $service->sourceMediaQuery(640, 1024, false));
        $this->assertSame('(min-width: 63.9375rem)', $service->sourceMediaQuery(1536, 1024, true));
    }

    public function testBreakpointsExcludeEscapeByDefault(): void
    {
        $service = Plugin::getInstance()->getImageTransforms();

        $breakpoints = $service->getBreakpointsForTemplate([
            'transformName' => 'default',
            'breakpoints' => [
                'xs' => 480,
                'md' => 768,
            ],
            'escapeWidth' => 1920,
        ]);

        $this->assertSame([
            'xs' => 480,
            'md' => 768,
        ], $breakpoints);
    }

    public function testBreakpointsIncludeEscapeWhenTemplateOptsIn(): void
    {
        $service = Plugin::getInstance()->getImageTransforms();

        $breakpoints = $service->getBreakpointsForTemplate([
            'transformName' => 'default',
            'breakpoints' => [
                'xs' => 480,
                'md' => 768,
            ],
            'escapeWidth' => 1920,
            'includeEscapeWidth' => true,
        ]);

        $this->assertSame([
            'xs' => 480,
            'md' => 768,
            'escape' => 1920,
        ], $breakpoints);
    }

    public function testBreakpointsIncludeEscapeWhenNamedTransformEnablesIt(): void
    {
        $this->withTemporaryTransforms([
            'include-escape-test' => [
                'name' => 'include-escape-test',
                'includeEscapeWidth' => true,
                'transforms' => [
                    [
                        'width' => 480,
                        'height' => null,
                        'enabled' => true,
                        'autoDimension' => null,
                    ],
                    [
                        'width' => 768,
                        'height' => null,
                        'enabled' => true,
                        'autoDimension' => null,
                    ],
                ],
                'config' => [],
            ],
        ], function(Plugin $plugin): void {
            $service = $plugin->getImageTransforms();

            $breakpoints = $service->getBreakpointsForTemplate([
                'transformName' => 'include-escape-test',
                'breakpoints' => [
                    'xs' => 480,
                    'md' => 768,
                ],
                'escapeWidth' => 1920,
            ]);

            $this->assertSame([
                'xs' => 480,
                'md' => 768,
                'escape' => 1920,
            ], $breakpoints);
        });
    }

    public function testDisabledBreakpointIsParkedAsPlaceholderForFrontendVisibilityContract(): void
    {
        $service = Plugin::getInstance()->getImageTransforms();
        $asset = $this->createMockAsset();

        $breakpointData = $service->getBreakpointData(0, 480, [
            'transformName' => 'default',
            'breakpoints' => [
                'xs' => 480,
                'md' => 768,
            ],
            'escapeWidth' => 0,
            'disableBreakpoints' => [
                'xs' => true,
            ],
            'secondaryFormat' => 'webp',
        ], $asset);

        $this->assertTrue($breakpointData['disabled']);
        $this->assertSame('false', $breakpointData['primarySourceAttributes']['data-bp-enabled']);
        $this->assertSame('data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', $breakpointData['primarySourceAttributes']['srcset']);
        $this->assertSame('image/gif', $breakpointData['primarySourceAttributes']['type']);
        $this->assertSame('gif', $breakpointData['primaryFormat']['format']);
        $this->assertSame('(max-width: 29.9375rem)', $breakpointData['primarySourceAttributes']['media']);
        $this->assertSame('image/gif', $breakpointData['secondarySourceAttributes']['type']);
    }

    public function testDprSrcsetContainsHigherDensityCandidate(): void
    {
        $service = Plugin::getInstance()->getImageTransforms();
        $asset = $this->createMockAsset();

        $breakpointData = $service->getBreakpointData(0, 480, [
            'transformName' => 'default',
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'dpr' => [1, 2],
            'format' => 'jpg',
            'secondaryFormat' => 'none',
        ], $asset);

        $srcset = $breakpointData['primarySourceAttributes']['srcset'] ?? '';

        $this->assertStringContainsString(' 1x', $srcset);
        $this->assertStringContainsString(' 2x', $srcset);
        $this->assertStringContainsString(', ', $srcset);
    }

    public function testDprRatiosIgnoreNonFiniteValuesAtRuntime(): void
    {
        $service = Plugin::getInstance()->getImageTransforms();
        $method = new \ReflectionMethod($service, 'getDprRatios');

        $ratios = $method->invoke($service, [
            'dpr' => [INF, NAN, 2, 'text', -1, 0, 1],
        ]);

        $this->assertSame([1.0, 2.0], $ratios);
    }

    public function testSourceAttributesContainWidthAndHeight(): void
    {
        $service = Plugin::getInstance()->getImageTransforms();
        $asset = $this->createMockAsset();

        $breakpointData = $service->getBreakpointData(0, 480, [
            'transformName' => 'default',
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'dpr' => [1],
            'format' => 'jpg',
            'secondaryFormat' => 'none',
        ], $asset);

        $this->assertSame(480, $breakpointData['primarySourceAttributes']['width']);
        $this->assertSame(270, $breakpointData['primarySourceAttributes']['height']);
    }

    public function testAutoDimensionHeightUsesDerivedHeight(): void
    {
        $this->withTemporaryTransforms([
            'auto-height-test' => [
                'name' => 'auto-height-test',
                'includeEscapeWidth' => false,
                'transforms' => [
                    [
                        'width' => 500,
                        'height' => 333,
                        'autoDimension' => 'height',
                        'enabled' => true,
                    ],
                ],
                'config' => [],
            ],
        ], function(Plugin $plugin): void {
            $service = $plugin->getImageTransforms();
            $asset = $this->createMockAsset();

            $breakpointData = $service->getBreakpointData(0, 480, [
                'transformName' => 'auto-height-test',
                'breakpoints' => [
                    'xs' => 480,
                ],
                'escapeWidth' => 0,
                'format' => 'jpg',
                'secondaryFormat' => 'none',
            ], $asset);

            $this->assertSame(500, $breakpointData['primarySourceAttributes']['width']);
            $this->assertSame(281, $breakpointData['primarySourceAttributes']['height']);
        });
    }

    public function testInitWidthAndHeightOverrideNamedTransformDimensions(): void
    {
        $service = Plugin::getInstance()->getImageTransforms();
        $asset = $this->createMockAsset();

        $breakpointData = $service->getBreakpointData(0, 480, [
            'transformName' => 'default',
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'initWidth' => 320,
            'initHeight' => 200,
            'format' => 'jpg',
            'secondaryFormat' => 'none',
        ], $asset);

        $this->assertSame(320, $breakpointData['primarySourceAttributes']['width']);
        $this->assertSame(200, $breakpointData['primarySourceAttributes']['height']);
    }

    public function testInitHeightDerivesWidthFromAspectRatio(): void
    {
        $service = Plugin::getInstance()->getImageTransforms();
        $asset = $this->createMockAsset();

        $breakpointData = $service->getBreakpointData(0, 480, [
            'transformName' => 'default',
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'initHeight' => 300,
            'format' => 'jpg',
            'secondaryFormat' => 'none',
        ], $asset);

        $this->assertSame(533, $breakpointData['primarySourceAttributes']['width']);
        $this->assertSame(300, $breakpointData['primarySourceAttributes']['height']);
    }

    public function testSecondaryFormatUsesNamedTransformConfigAndDprSrcset(): void
    {
        $this->withTemporaryTransforms([
            'secondary-dpr-test' => [
                'name' => 'secondary-dpr-test',
                'includeEscapeWidth' => false,
                'transforms' => [
                    [
                        'width' => 480,
                        'height' => 270,
                        'enabled' => true,
                        'autoDimension' => null,
                    ],
                ],
                'config' => [
                    'format' => 'jpg',
                    'secondaryFormat' => 'webp',
                    'quality' => 82,
                ],
            ],
        ], function(Plugin $plugin): void {
            $service = $plugin->getImageTransforms();
            $asset = $this->createMockAsset();

            $breakpointData = $service->getBreakpointData(0, 480, [
                'transformName' => 'secondary-dpr-test',
                'breakpoints' => [
                    'xs' => 480,
                ],
                'escapeWidth' => 0,
                'dpr' => [1, 2],
                'format' => 'png',
                'secondaryFormat' => 'none',
            ], $asset);

            $this->assertNotNull($breakpointData['secondaryFormat']);
            $this->assertSame('image/webp', $breakpointData['secondarySourceAttributes']['type']);
            $this->assertStringContainsString('.webp 1x', $breakpointData['secondarySourceAttributes']['srcset']);
            $this->assertStringContainsString('.webp 2x', $breakpointData['secondarySourceAttributes']['srcset']);
        });
    }

    public function testNamedTransformFormatOverridesInlineFormatOptions(): void
    {
        $this->withTemporaryTransforms([
            'format-override-test' => [
                'name' => 'format-override-test',
                'includeEscapeWidth' => false,
                'transforms' => [
                    [
                        'width' => 640,
                        'height' => 360,
                        'enabled' => true,
                        'autoDimension' => null,
                    ],
                ],
                'config' => [
                    'format' => 'webp',
                    'secondaryFormat' => 'jpg',
                ],
            ],
        ], function(Plugin $plugin): void {
            $service = $plugin->getImageTransforms();
            $asset = $this->createMockAsset();

            $primary = $service->getTransformedImages($asset, 'format-override-test', 'primary', [
                'transformName' => 'format-override-test',
                'breakpoints' => ['sm' => 640],
                'escapeWidth' => 0,
                'format' => 'png',
                'secondaryFormat' => 'none',
            ]);

            $secondary = $service->getTransformedImages($asset, 'format-override-test', 'secondary', [
                'transformName' => 'format-override-test',
                'breakpoints' => ['sm' => 640],
                'escapeWidth' => 0,
                'format' => 'png',
                'secondaryFormat' => 'none',
            ]);

            $this->assertSame('webp', $primary[0]['format']);
            $this->assertSame('jpg', $secondary[0]['format']);
        });
    }

    private function withTemporaryTransforms(array $transforms, callable $callback): void
    {
        $plugin = Plugin::getInstance();
        $store = $plugin->getTransformStore();
        $previousTransforms = $store->getTransforms();

        $store->replaceTransformsForRuntime($transforms);

        try {
            $callback($plugin);
        } finally {
            $store->replaceTransformsForRuntime($previousTransforms);
        }
    }

    private function createMockAsset(): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUrl', 'getWidth', 'getHeight'])
            ->getMock();

        $asset->id = 123;

        $asset->method('getWidth')->willReturn(1600);
        $asset->method('getHeight')->willReturn(900);
        $asset->method('getUrl')->willReturnCallback(static function(...$args): string {
            $transform = $args[0] ?? [];

            if (!is_array($transform)) {
                return 'https://example.test/original.jpg';
            }

            $width = (int)($transform['width'] ?? 0);
            $height = (int)($transform['height'] ?? 0);
            $format = (string)($transform['format'] ?? 'jpg');

            return "https://example.test/{$width}x{$height}.{$format}";
        });

        return $asset;
    }
}
