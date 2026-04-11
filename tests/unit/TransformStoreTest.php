<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpointimages\Plugin;
use InvalidArgumentException;

final class TransformStoreTest extends Unit
{
    public function testReplaceTransformsForRuntimeRejectsInvalidStructure(): void
    {
        $store = Plugin::getInstance()->getTransformStore();

        $this->expectException(InvalidArgumentException::class);

        $store->replaceTransformsForRuntime([
            'invalid-transform' => [
                'name' => 'invalid-transform',
                'includeEscapeWidth' => false,
                'config' => [],
            ],
        ]);
    }

    public function testReplaceTransformsForRuntimeResetsImageTransformCaches(): void
    {
        $plugin = Plugin::getInstance();
        $store = $plugin->getTransformStore();
        $service = $plugin->getImageTransforms();
        $asset = $this->createMockAsset();

        $service->getTransformedImages($asset, 'default', 'primary', [
            'transformName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
        ]);

        $service->getBreakpointData(0, 480, [
            'transformName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
        ], $asset);

        $transformedCacheProperty = new \ReflectionProperty($service, '_transformedImagesCache');
        $breakpointCacheProperty = new \ReflectionProperty($service, '_breakpointDataCache');

        $this->assertNotEmpty($transformedCacheProperty->getValue($service));
        $this->assertNotEmpty($breakpointCacheProperty->getValue($service));

        $store->replaceTransformsForRuntime($store->getTransforms());

        $this->assertSame([], $transformedCacheProperty->getValue($service));
        $this->assertSame([], $breakpointCacheProperty->getValue($service));
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
