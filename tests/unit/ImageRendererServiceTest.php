<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpointimages\Plugin;

final class ImageRendererServiceTest extends Unit
{
    public function testRenderReturnsCommentWhenImageMissing(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();

        $markup = $renderer->render(null, 'default');

        $this->assertSame('<!-- Breakpoint Images: no image provided -->', (string)$markup);
    }

    public function testPictureAttributesExposeBreakpointStatesFromTransformService(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();

        $attributes = $renderer->getPictureAttributes([
            'transformName' => 'default',
            'imageId' => 123,
            'assetTitle' => 'Example',
            'breakpoints' => [
                'xs' => 480,
                'md' => 768,
            ],
            'disableBreakpoints' => [
                'md' => true,
            ],
        ]);

        $this->assertArrayHasKey('data-breakpoint-states', $attributes);
        $states = json_decode((string)$attributes['data-breakpoint-states'], true);

        $this->assertIsArray($states);
        $this->assertSame('enabled', $states['xs'] ?? null);
        $this->assertSame('disabled', $states['md'] ?? null);
    }

    public function testRenderUsesImgFallbackWithComputedAttributesWhenTemplateFails(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();
        $asset = $this->createMockAsset();

        $markup = $renderer->render($asset, 'default', [
            'pictureTemplatePath' => 'craft-breakpoint-images/does-not-exist.twig',
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'imgClass' => 'hero-image',
            'loading' => 'eager',
            'decoding' => 'sync',
            'alt' => 'Fallback alt',
        ]);

        $html = (string)$markup;

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('class="hero-image"', $html);
        $this->assertStringContainsString('loading="eager"', $html);
        $this->assertStringContainsString('decoding="sync"', $html);
        $this->assertStringContainsString('alt="Fallback alt"', $html);
        $this->assertStringContainsString('data-asset-id="123"', $html);
        $this->assertStringContainsString('width="480"', $html);
        $this->assertStringContainsString('height="270"', $html);
        $this->assertMatchesRegularExpression('/data-uid="default-123-[a-f0-9]{8}-img"/', $html);
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
