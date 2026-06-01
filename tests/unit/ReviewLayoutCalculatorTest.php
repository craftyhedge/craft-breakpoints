<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\services\transformeditor\ReviewLayoutCalculator;

final class ReviewLayoutCalculatorTest extends Unit
{
    public function testPickPreviewRowPreservesPriorityOrder(): void
    {
        $rows = [
            [
                'src' => 'https://example.test/only-src.jpg',
                'loaded' => false,
                'enabled' => false,
                'isVisible' => false,
            ],
            [
                'src' => 'https://example.test/loaded-src.jpg',
                'loaded' => true,
                'enabled' => false,
                'isVisible' => false,
            ],
            [
                'src' => 'https://example.test/loaded-enabled-src.jpg',
                'loaded' => true,
                'enabled' => true,
                'isVisible' => false,
            ],
            [
                'src' => 'https://example.test/loaded-visible-enabled-src.jpg',
                'loaded' => true,
                'enabled' => true,
                'isVisible' => true,
            ],
        ];

        $selected = ReviewLayoutCalculator::pickPreviewRow($rows);

        $this->assertIsArray($selected);
        $this->assertSame('https://example.test/loaded-visible-enabled-src.jpg', (string)($selected['src'] ?? ''));
    }

    public function testPickPreviewRowFallsBackToFirstRowWhenNoSrc(): void
    {
        $rows = [
            ['loaded' => false, 'enabled' => false, 'isVisible' => false],
            ['loaded' => true, 'enabled' => true, 'isVisible' => true],
        ];

        $selected = ReviewLayoutCalculator::pickPreviewRow($rows);

        $this->assertIsArray($selected);
        $this->assertSame($rows[0], $selected);
    }

    public function testResolvePreviewDisplayDimensionsUsesPreviewRenderedDimensions(): void
    {
        $rows = [[
            'enabled' => true,
            'isVisible' => true,
            'loaded' => true,
            'src' => 'https://example.test/preview.jpg',
            'rendered' => ['width' => 640, 'height' => 360],
            'transformDimensions' => ['width' => 1200, 'height' => null, 'autoDimension' => 'height'],
        ]];

        $resolved = ReviewLayoutCalculator::resolvePreviewDisplayDimensions($rows, 768);

        $this->assertSame(640, $resolved['width']);
        $this->assertSame(360, $resolved['height']);
    }

    public function testResolvePreviewDisplayDimensionsFallsBackToInitialPlaceholderBoxWhenPreviewHasNoDimensions(): void
    {
        $rows = [[
            'enabled' => true,
            'isVisible' => true,
            'loaded' => false,
            'src' => 'https://example.test/preview.jpg',
            'rendered' => ['width' => 0, 'height' => 0],
            'transformDimensions' => ['width' => null, 'height' => null, 'autoDimension' => null],
        ]];

        $resolved = ReviewLayoutCalculator::resolvePreviewDisplayDimensions($rows, 480);

        $this->assertSame(1200, $resolved['width']);
        $this->assertSame(800, $resolved['height']);
    }

    public function testPreviewLockHeightsIgnoreExcludedBreakpoints(): void
    {
        // Breakpoint 1 is short; breakpoint 2 is very tall.
        $rowsByAssetByBreakpoint = [
            'asset-1' => [
                1 => [[
                    'enabled' => true,
                    'isVisible' => true,
                    'loaded' => true,
                    'src' => 'https://example.test/short.jpg',
                    'rendered' => ['width' => 100, 'height' => 50],
                ]],
                2 => [[
                    'enabled' => true,
                    'isVisible' => true,
                    'loaded' => true,
                    'src' => 'https://example.test/tall.jpg',
                    'rendered' => ['width' => 100, 'height' => 400],
                ]],
            ],
        ];
        $breakpoints = [1, 2];
        $columnWidths = ['1' => 120.0, '2' => 120.0];
        $referenceWidths = ['1' => 100, '2' => 100];

        $withTall = ReviewLayoutCalculator::calculateBreakpointPreviewLockHeights(
            $rowsByAssetByBreakpoint,
            $breakpoints,
            $columnWidths,
            $referenceWidths,
        );

        // Excluding the tall breakpoint yields a smaller shared lock height.
        $withoutTall = ReviewLayoutCalculator::calculateBreakpointPreviewLockHeights(
            $rowsByAssetByBreakpoint,
            $breakpoints,
            $columnWidths,
            $referenceWidths,
            [2],
        );

        $this->assertGreaterThan($withoutTall['1'], $withTall['1']);
        $this->assertSame($withoutTall['1'], $withoutTall['2']);
    }
}
