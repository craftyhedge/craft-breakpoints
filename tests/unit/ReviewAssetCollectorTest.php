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

    public function testArtDirectedRowsWithDifferentAssetIdsStayGroupedByPictureId(): void
    {
        $rowsByBreakpoint = [
            1 => [[
                'pictureId' => 'hero-default-abc123',
                'transform' => 'hero',
                'assetId' => 'mobile-asset',
                'sourceUsed' => 'https://example.test/mobile.jpg',
                'src' => 'https://example.test/mobile.jpg',
                'loaded' => true,
                'enabled' => true,
                'isVisible' => true,
            ]],
            2 => [[
                'pictureId' => 'hero-default-abc123',
                'transform' => 'hero',
                'assetId' => 'desktop-asset',
                'sourceUsed' => 'https://example.test/desktop.jpg',
                'src' => 'https://example.test/desktop.jpg',
                'loaded' => true,
                'enabled' => true,
                'isVisible' => true,
            ]],
        ];

        $collection = ReviewAssetCollector::buildAssetCollectionForTransform($rowsByBreakpoint, 'hero', [1, 2]);
        $selectedAssetKey = ReviewAssetCollector::normalizeSelectedAssetKey(null, $collection['assetKeys']);
        $selectedRows = ReviewAssetCollector::buildSelectedAssetRowsByBreakpoint(
            $collection['rowsByAssetByBreakpoint'],
            $selectedAssetKey,
            [1, 2],
        );

        $this->assertSame(['picture:hero:hero-default-abc123'], $collection['assetKeys']);
        $this->assertSame('mobile-asset', $selectedRows[1][0]['assetId'] ?? null);
        $this->assertSame('desktop-asset', $selectedRows[2][0]['assetId'] ?? null);
    }

    public function testArtDirectedRowsWithDifferentAssetIdsStayGroupedByInstance(): void
    {
        $rowsByBreakpoint = [
            1 => [[
                'instance' => '4',
                'pictureId' => 'hero-default-abc123',
                'transform' => 'hero',
                'assetId' => 'mobile-asset',
                'sourceUsed' => 'https://example.test/mobile.jpg',
                'src' => 'https://example.test/mobile.jpg',
                'loaded' => true,
                'enabled' => true,
                'isVisible' => true,
            ]],
            2 => [[
                'instance' => '4',
                'pictureId' => 'hero-default-abc123',
                'transform' => 'hero',
                'assetId' => 'desktop-asset',
                'sourceUsed' => 'https://example.test/desktop.jpg',
                'src' => 'https://example.test/desktop.jpg',
                'loaded' => true,
                'enabled' => true,
                'isVisible' => true,
            ]],
        ];

        $collection = ReviewAssetCollector::buildAssetCollectionForTransform($rowsByBreakpoint, 'hero', [1, 2]);
        $selectedAssetKey = ReviewAssetCollector::normalizeSelectedAssetKey(null, $collection['assetKeys']);
        $selectedRows = ReviewAssetCollector::buildSelectedAssetRowsByBreakpoint(
            $collection['rowsByAssetByBreakpoint'],
            $selectedAssetKey,
            [1, 2],
        );

        $this->assertSame(['picture:hero:4'], $collection['assetKeys']);
        $this->assertSame('mobile-asset', $selectedRows[1][0]['assetId'] ?? null);
        $this->assertSame('desktop-asset', $selectedRows[2][0]['assetId'] ?? null);
    }

    public function testDuplicateCopiesWithTheSamePictureIdPageSeparatelyByInstance(): void
    {
        $rowsByBreakpoint = [
            1 => [
                [
                    'instance' => '1',
                    'pictureId' => 'cta-image-893-d2cdcca4',
                    'transform' => 'cta-image',
                    'assetId' => '893',
                    'sourceUsed' => 'https://example.test/hidden.jpg',
                    'src' => 'https://example.test/hidden.jpg',
                    'loaded' => true,
                    'enabled' => true,
                    'isVisible' => false,
                ],
                [
                    'instance' => '2',
                    'pictureId' => 'cta-image-893-d2cdcca4',
                    'transform' => 'cta-image',
                    'assetId' => '893',
                    'sourceUsed' => 'https://example.test/visible.jpg',
                    'src' => 'https://example.test/visible.jpg',
                    'loaded' => true,
                    'enabled' => true,
                    'isVisible' => true,
                ],
            ],
        ];

        $collection = ReviewAssetCollector::buildAssetCollectionForTransform($rowsByBreakpoint, 'cta-image', [1]);

        $this->assertSame(['picture:cta-image:1', 'picture:cta-image:2'], $collection['assetKeys']);
        $this->assertCount(1, $collection['rowsByAssetByBreakpoint']['picture:cta-image:1'][1]);
        $this->assertCount(1, $collection['rowsByAssetByBreakpoint']['picture:cta-image:2'][1]);
    }
}
