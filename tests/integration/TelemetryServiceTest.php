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

        if (Craft::$app->getDb()->tableExists(DatabaseService::TABLE_USAGE_OBSERVATIONS)) {
            Craft::$app->getDb()->createCommand()
                ->delete(DatabaseService::TABLE_USAGE_OBSERVATIONS)
                ->execute();
        }

        Plugin::getInstance()->getTelemetry()->flushPendingUsage();
    }

    public function testRecordUsageRoundTripsInitOptionsThroughUsageTrackingRows(): void
    {
        $this->withMergedConfigValues(['enableUsageTracking' => true], function(): void {
            $telemetry = Plugin::getInstance()->getTelemetry();
            $initOptions = InitOptions::fromConfig([
                'initWidth' => 320,
                'initRatio' => '16:9',
                'initWidthAuto' => false,
                'initHeightAuto' => false,
            ], false);

            $telemetry->recordUsage('hero', $initOptions);

            $rows = $telemetry->getUsageObservationRows();

            $this->assertSame('hero', $rows[0]['transformHandle'] ?? null);
            $this->assertSame(320, $rows[0]['initWidth'] ?? null);
            $this->assertNull($rows[0]['initHeight'] ?? null);
            // initRatio round-trips in its preserved raw form, not as a computed float.
            $this->assertSame('16:9', $rows[0]['initRatio'] ?? null);
            $this->assertFalse($rows[0]['initWidthAuto'] ?? null);
            $this->assertFalse($rows[0]['initHeightAuto'] ?? null);
        });
    }

    public function testRecordUsagePersistsAutoFlagsForUnsavedSet(): void
    {
        $this->withMergedConfigValues(['enableUsageTracking' => true], function(): void {
            $telemetry = Plugin::getInstance()->getTelemetry();
            $initOptions = InitOptions::fromConfig([
                'initWidth' => null,
                'initHeight' => 180,
                'initRatio' => null,
                'initWidthAuto' => true,
                'initHeightAuto' => false,
            ], false);

            $telemetry->recordUsage('autoHero', $initOptions);

            $rows = $telemetry->getUsageObservationRows();

            $this->assertSame('autoHero', $rows[0]['transformHandle'] ?? null);
            $this->assertNull($rows[0]['initWidth'] ?? null);
            $this->assertSame(180, $rows[0]['initHeight'] ?? null);
            $this->assertTrue($rows[0]['initWidthAuto'] ?? null);
            $this->assertFalse($rows[0]['initHeightAuto'] ?? null);
        });
    }

    public function testRecordUsageWithoutInitOptionsLeavesInitColumnsNull(): void
    {
        $this->withMergedConfigValues(['enableUsageTracking' => true], function(): void {
            $telemetry = Plugin::getInstance()->getTelemetry();

            $telemetry->recordUsage('plain');

            $rows = $telemetry->getUsageObservationRows();

            $this->assertSame('plain', $rows[0]['transformHandle'] ?? null);
            $this->assertNull($rows[0]['initWidth'] ?? null);
            $this->assertNull($rows[0]['initHeight'] ?? null);
            $this->assertNull($rows[0]['initRatio'] ?? null);
            $this->assertNull($rows[0]['initWidthAuto'] ?? null);
            $this->assertNull($rows[0]['initHeightAuto'] ?? null);
        });
    }

    public function testUsageTrackingPersistsRowsWhenTransformEditingIsDisabled(): void
    {
        $this->withMergedConfigValues([
            'allowTransformEditing' => false,
            'enableUsageTracking' => true,
        ], function(): void {
            $telemetry = Plugin::getInstance()->getTelemetry();
            $telemetry->recordUsage('trackedHero', null, true);

            $rows = $telemetry->getUsageObservationRows();

            $this->assertSame(1, count($rows));
            $this->assertSame('trackedHero', $rows[0]['transformHandle']);
            $this->assertSame(1, $rows[0]['seenCount']);
            $this->assertTrue($rows[0]['includeEscapeWidth']);
        });
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

    /**
     * @param array<string, mixed> $values
     */
    private function withMergedConfigValues(array $values, callable $callback): void
    {
        $configService = Plugin::getInstance()->getConfigService();
        $property = new \ReflectionProperty($configService, '_mergedConfig');
        $previous = $property->getValue($configService);

        $nextConfig = is_array($previous) ? $previous : $configService->getConfig();
        foreach ($values as $key => $value) {
            $nextConfig[$key] = $value;
        }
        $property->setValue($configService, $nextConfig);

        try {
            $callback();
        } finally {
            $property->setValue($configService, $previous);
            Plugin::getInstance()->getTelemetry()->flushPendingUsage();
        }
    }
}
