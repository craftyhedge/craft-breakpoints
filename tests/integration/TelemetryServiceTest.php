<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\DatabaseService;
use craftyhedge\craftbreakpoints\services\InitOptions;

final class TelemetryServiceTest extends Unit
{
    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getDb()->createCommand()
            ->delete('{{%bpi_transform_last_processed}}')
            ->execute();
    }

    public function testRecordUsageRoundTripsInitOptionsThroughGetMostRecentByHandle(): void
    {
        $telemetry = Plugin::getInstance()->getTelemetry();
        $initOptions = InitOptions::fromConfig([
            'initWidth' => 320,
            'initRatio' => '16:9',
            'initWidthAuto' => false,
            'initHeightAuto' => false,
        ], false);

        $telemetry->recordUsage('hero', $initOptions);

        $byHandle = $telemetry->getMostRecentByHandle();

        $this->assertArrayHasKey('hero', $byHandle);
        $row = $byHandle['hero'];
        $this->assertSame(320, $row['initWidth']);
        $this->assertNull($row['initHeight']);
        // initRatio round-trips in its preserved raw form, not as a computed float.
        $this->assertSame('16:9', $row['initRatio']);
        $this->assertFalse($row['initWidthAuto']);
        $this->assertFalse($row['initHeightAuto']);
    }

    public function testRecordUsagePersistsAutoFlagsForUnsavedSet(): void
    {
        $telemetry = Plugin::getInstance()->getTelemetry();
        $initOptions = InitOptions::fromConfig([
            'initWidth' => null,
            'initHeight' => 180,
            'initRatio' => null,
            'initWidthAuto' => true,
            'initHeightAuto' => false,
        ], false);

        $telemetry->recordUsage('autoHero', $initOptions);

        $byHandle = $telemetry->getMostRecentByHandle();

        $this->assertArrayHasKey('autoHero', $byHandle);
        $row = $byHandle['autoHero'];
        $this->assertNull($row['initWidth']);
        $this->assertSame(180, $row['initHeight']);
        $this->assertTrue($row['initWidthAuto']);
        $this->assertFalse($row['initHeightAuto']);
    }

    public function testRecordUsageWithoutInitOptionsLeavesInitColumnsNull(): void
    {
        $telemetry = Plugin::getInstance()->getTelemetry();

        $telemetry->recordUsage('plain');

        $byHandle = $telemetry->getMostRecentByHandle();

        $this->assertArrayHasKey('plain', $byHandle);
        $row = $byHandle['plain'];
        $this->assertNull($row['initWidth']);
        $this->assertNull($row['initHeight']);
        $this->assertNull($row['initRatio']);
        $this->assertNull($row['initWidthAuto']);
        $this->assertNull($row['initHeightAuto']);
    }

    public function testRenderingSvgAssetDoesNotRecordObservedUsage(): void
    {
        $plugin = Plugin::getInstance();
        $asset = $this->createMockSvgAsset();

        $markup = $plugin->getImageRenderer()->render($asset, 'svgOnly', [
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'allowTransformEditing' => true,
        ]);

        $this->assertStringContainsString('<picture data-set="svgOnly">', (string)$markup);
        $this->assertArrayNotHasKey('svgOnly', $plugin->getTelemetry()->getMostRecentByHandle());
    }

    public function testPersistRunSnapshotUsesBreakpointSourceForDisplayUrls(): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->delete(DatabaseService::TABLE_PREVIEW_CACHE)->execute();

        $rowsBySlot = [
            'base' => [[
                'slotKey' => 'base',
                'slotIndex' => 0,
                'mediaWidth' => 480,
                'measureWidth' => 480,
                'assetId' => '100',
                'transform' => 'hero',
                'sourceUsed' => 'https://example.test/hero-base.jpg',
                'src' => 'https://example.test/hero-fallback.jpg',
                'enabled' => true,
                'loaded' => true,
                'rendered' => ['width' => 480, 'height' => 270],
            ]],
            'xs' => [[
                'slotKey' => 'xs',
                'slotIndex' => 1,
                'mediaWidth' => 640,
                'measureWidth' => 640,
                'assetId' => '100',
                'transform' => 'hero',
                'sourceUsed' => 'https://example.test/hero-xs.jpg',
                'src' => 'https://example.test/hero-fallback.jpg',
                'enabled' => true,
                'loaded' => true,
                'rendered' => ['width' => 640, 'height' => 360],
            ]],
        ];

        $persisted = Plugin::getInstance()->getTelemetry()->persistRunSnapshot([
            'runId' => 'slot-source-url-test',
            'runStatus' => 'completed',
            'timestamp' => '2026-06-12T12:00:00+00:00',
            'durationMs' => 100,
            'sourceUrl' => 'https://example.test/source',
            'rowsBySlot' => $rowsBySlot,
        ]);

        $this->assertTrue($persisted);

        $snapshotUrls = (new Query())
            ->select(['slotKey', 'displayAssetUrl'])
            ->from(DatabaseService::TABLE_RUN_SNAPSHOT_ROWS)
            ->where(['snapshotId' => 1, 'transformHandle' => 'hero'])
            ->orderBy(['slotIndex' => SORT_ASC])
            ->all($db);
        $previewUrls = (new Query())
            ->select(['slotKey', 'displayAssetUrl'])
            ->from(DatabaseService::TABLE_PREVIEW_CACHE)
            ->where(['transformHandle' => 'hero'])
            ->orderBy(['slotIndex' => SORT_ASC])
            ->all($db);

        $expected = [
            'base' => 'https://example.test/hero-base.jpg',
            'xs' => 'https://example.test/hero-xs.jpg',
        ];
        $this->assertSame($expected, array_column($snapshotUrls, 'displayAssetUrl', 'slotKey'));
        $this->assertSame($expected, array_column($previewUrls, 'displayAssetUrl', 'slotKey'));
    }

    private function createMockSvgAsset(): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUrl', 'getWidth', 'getHeight', 'getMimeType', 'getExtension'])
            ->getMock();

        $asset->id = 123;

        $asset->method('getWidth')->willReturn(1600);
        $asset->method('getHeight')->willReturn(900);
        $asset->method('getExtension')->willReturn('svg');
        $asset->method('getMimeType')->willReturn('image/svg+xml');
        $asset->method('getUrl')->willReturn('https://example.test/original.svg');

        return $asset;
    }
}
