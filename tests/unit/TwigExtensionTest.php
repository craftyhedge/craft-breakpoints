<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\web\twig\Extension;
use Twig\TwigFunction;

final class TwigExtensionTest extends Unit
{
    public function testExtensionRegistersImageFunction(): void
    {
        $extension = new Extension();

        $functions = $extension->getFunctions();

        $this->assertCount(1, $functions);
        $this->assertInstanceOf(TwigFunction::class, $functions[0]);
        $this->assertSame('image', $functions[0]->getName());
    }

    public function testImageFunctionCallableDelegatesToPluginImagesService(): void
    {
        $extension = new Extension();
        $function = $extension->getFunctions()[0];

        $callable = $function->getCallable();
        $result = $callable(null, 'default', []);

        $this->assertSame('<!-- Breakpoints: no image provided -->', (string)$result);
    }

    public function testImageFunctionCallablePassesThroughTransformAndConfig(): void
    {
        $extension = new Extension();
        $function = $extension->getFunctions()[0];
        $callable = $function->getCallable();

        $asset = $this->createMockAsset();
        $config = [
            'pictureTemplatePath' => 'craft-breakpoints/does-not-exist.twig',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'imgClass' => 'twig-extension-pass-through',
            'secondaryFormat' => 'none',
        ];

        $expected = Plugin::getInstance()->getImages()->render($asset, 'default', $config);
        $actual = $callable($asset, 'default', $config);

        $this->assertSame((string)$expected, (string)$actual);
    }

    private function createMockAsset(): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUrl', 'getWidth', 'getHeight'])
            ->getMock();

        $asset->id = 123;
        $asset->title = 'Mock asset';

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
