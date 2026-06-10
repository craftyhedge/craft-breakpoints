<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpoints\Plugin;

final class ImageRendererServiceTest extends Unit
{
    public function testRenderReturnsCommentWhenImageMissing(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();

        $markup = $renderer->render(null, 'default');

        $this->assertSame('<!-- Breakpoints: no image provided -->', (string)$markup);
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

        // Normal (non-processing) render: the breakpoint-states marker is gated
        // out. Its content is covered by RenderContextBuilderTest via the
        // composePictureMarkers reflection test.
        $this->assertArrayNotHasKey('data-breakpoint-states', $attributes);
    }

    public function testRenderUsesImgFallbackWithComputedAttributesWhenTemplateFails(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();
        $asset = $this->createMockAsset();

        $markup = $renderer->render($asset, 'default', [
            'pictureTemplatePath' => 'breakpoints/does-not-exist.twig',
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
        $this->assertStringContainsString('width="480"', $html);
        $this->assertStringContainsString('height="270"', $html);
        // Normal (non-processing) render: no internal processing markers on <img>.
        $this->assertStringNotContainsString('data-asset-id=', $html);
        $this->assertStringNotContainsString('data-uid=', $html);
    }

    public function testRenderUsesSvgTemplatePathForSvgAssets(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();
        $asset = $this->createMockSvgAsset();

        $markup = $renderer->render($asset, 'default', [
            'svgTemplatePath' => 'breakpoints/does-not-exist.twig',
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'imgClass' => 'svg-fallback',
        ]);

        $html = (string)$markup;

        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('class="svg-fallback"', $html);
        $this->assertStringNotContainsString('<picture', $html);
    }

    public function testRenderWrapsSvgAssetsInPictureWithImageClassFallback(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();
        $asset = $this->createMockSvgAsset();

        $markup = $renderer->render($asset, 'default', [
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'imgClass' => 'svg-image',
        ]);

        $html = (string)$markup;

        $this->assertStringContainsString('<picture class="svg-image">', $html);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('class="svg-image"', $html);
    }

    public function testRenderKeepsExplicitPictureClassForSvgAssets(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();
        $asset = $this->createMockSvgAsset();

        $markup = $renderer->render($asset, 'default', [
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'pictureClass' => 'svg-picture',
            'imgClass' => 'svg-image',
        ]);

        $html = (string)$markup;

        $this->assertStringContainsString('<picture class="svg-picture">', $html);
        $this->assertStringContainsString('class="svg-image"', $html);
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
        $asset->method('getUrl')->willReturnCallback(static function(...$args): string {
            $transform = $args[0] ?? [];

            if (!is_array($transform)) {
                return 'https://example.test/original.svg';
            }

            $width = (int)($transform['width'] ?? 0);
            $height = (int)($transform['height'] ?? 0);
            $format = (string)($transform['format'] ?? 'svg');

            return "https://example.test/{$width}x{$height}.{$format}";
        });

        return $asset;
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
