<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\Images;

final class ImagesServiceTest extends Unit
{
    public function testRenderReturnsPluginUnavailableCommentWhenPluginMissing(): void
    {
        $service = new Images();
        $this->setServicePlugin($service, null);

        $markup = $service->render(null, 'default');

        $this->assertSame('<!-- Breakpoints: plugin unavailable -->', (string)$markup);
    }

    public function testGetBreakpointDataReturnsEmptyArrayWhenPluginMissing(): void
    {
        $service = new Images();
        $this->setServicePlugin($service, null);
        $asset = $this->createMockAsset();

        $breakpointData = $service->getBreakpointData(0, 480, [], $asset);

        $this->assertSame([], $breakpointData);
    }

    public function testGetBreakpointDataDelegatesToImageTransformsService(): void
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getImages();
        $asset = $this->createMockAsset();

        $config = [
            'transformName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
        ];

        $expected = $plugin->getImageTransforms()->getBreakpointData(0, 480, $config, $asset);
        $actual = $service->getBreakpointData(0, 480, $config, $asset);

        $this->assertSame($expected, $actual);
    }

    public function testRenderDelegatesToImageRendererService(): void
    {
        $plugin = Plugin::getInstance();
        $service = $plugin->getImages();
        $asset = $this->createMockAsset();

        $config = [
            'pictureTemplatePath' => 'breakpoints/does-not-exist.twig',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'imgClass' => 'images-service-test',
            'secondaryFormat' => 'none',
        ];

        $expected = $plugin->getImageRenderer()->render($asset, 'default', $config);
        $actual = $service->render($asset, 'default', $config);

        $this->assertSame((string)$expected, (string)$actual);
    }

    private function setServicePlugin(Images $service, ?Plugin $plugin): void
    {
        $property = new \ReflectionProperty($service, '_plugin');
        $property->setValue($service, $plugin);
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
