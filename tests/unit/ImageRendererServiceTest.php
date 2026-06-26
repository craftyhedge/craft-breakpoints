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

    public function testRenderAddsTransformSetHandleToPictureWhenEditingAllowed(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();
        $asset = $this->createMockAsset();

        $markup = $renderer->render($asset, 'default', [
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'allowTransformEditing' => true,
        ]);

        $html = (string)$markup;

        $this->assertStringContainsString('<picture data-set="default">', $html);
        $this->assertStringNotContainsString('data-picture-id=', $html);
        $this->assertStringNotContainsString('data-breakpoint-states=', $html);
    }

    public function testRenderOmitsTransformSetHandleWhenEditingDisabled(): void
    {
        $renderer = Plugin::getInstance()->getImageRenderer();
        $asset = $this->createMockAsset();

        $markup = $renderer->render($asset, 'default', [
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'allowTransformEditing' => false,
        ]);

        $html = (string)$markup;

        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringNotContainsString('data-set=', $html);
    }

    public function testRenderRethrowsWhenCustomTemplateFails(): void
    {
        // A failure in a developer-supplied custom template is their bug to fix,
        // so the exception must propagate (surfacing the full Twig/Craft trace)
        // rather than silently degrading to a fallback <img>.
        $renderer = Plugin::getInstance()->getImageRenderer();
        $asset = $this->createMockAsset();

        $this->expectException(\Throwable::class);

        $renderer->render($asset, 'default', [
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
    }

    public function testRenderRethrowsWhenCustomSvgTemplateFails(): void
    {
        // SVG assets resolve to `svgTemplatePath`; a failing custom override
        // there must propagate just like the picture-template case, rather than
        // silently falling back. This also proves the SVG branch is taken — the
        // failure originates from the configured svgTemplatePath, not the
        // picture path.
        $renderer = Plugin::getInstance()->getImageRenderer();
        $asset = $this->createMockSvgAsset();

        $this->expectException(\Throwable::class);

        $renderer->render($asset, 'default', [
            'svgTemplatePath' => 'breakpoints/does-not-exist.twig',
            'breakpoints' => [
                'xs' => 480,
            ],
            'escapeWidth' => 0,
            'imgClass' => 'svg-fallback',
        ]);
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

        $this->assertStringContainsString('<picture class="svg-image" data-set="default">', $html);
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

        $this->assertStringContainsString('<picture class="svg-picture" data-set="default">', $html);
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
