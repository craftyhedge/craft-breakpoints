<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\services\transformeditor\ReviewAssetCollector;

final class ReviewAssetCollectorTest extends Unit
{
    public function testSavedPreviewRowsWithDifferentUrlsStayGroupedByStableAssetId(): void
    {
        $rowsByBreakpoint = [
            1 => [[
                'transform' => 'hero',
                'assetId' => 'saved-preview:hero',
                'sourceUsed' => 'https://example.test/disabled-placeholder.jpg',
                'src' => 'https://example.test/disabled-placeholder.jpg',
                'loaded' => true,
                'enabled' => false,
                'isVisible' => true,
            ]],
            2 => [[
                'transform' => 'hero',
                'assetId' => 'saved-preview:hero',
                'sourceUsed' => 'https://example.test/mobile.jpg',
                'src' => 'https://example.test/mobile.jpg',
                'loaded' => true,
                'enabled' => true,
                'isVisible' => true,
            ]],
            3 => [[
                'transform' => 'hero',
                'assetId' => 'saved-preview:hero',
                'sourceUsed' => 'https://example.test/desktop.jpg',
                'src' => 'https://example.test/desktop.jpg',
                'loaded' => true,
                'enabled' => true,
                'isVisible' => true,
            ]],
        ];

        $collection = ReviewAssetCollector::buildAssetCollectionForTransform($rowsByBreakpoint, 'hero', [1, 2, 3]);
        $selectedAssetKey = ReviewAssetCollector::normalizeSelectedAssetKey(null, $collection['assetKeys']);
        $selectedRows = ReviewAssetCollector::buildSelectedAssetRowsByBreakpoint(
            $collection['rowsByAssetByBreakpoint'],
            $selectedAssetKey,
            [1, 2, 3],
        );

        $this->assertCount(1, $collection['assetKeys']);
        $this->assertSame('https://example.test/mobile.jpg', $selectedRows[2][0]['src'] ?? null);
        $this->assertSame('https://example.test/desktop.jpg', $selectedRows[3][0]['src'] ?? null);
    }
}
