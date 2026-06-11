<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Asset;
use craftyhedge\craftbreakpoints\Plugin;
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
