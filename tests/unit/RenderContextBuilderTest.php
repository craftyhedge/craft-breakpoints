<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\RenderContextBuilder;

final class RenderContextBuilderTest extends Unit
{
    public function testBuildReturnsNullWhenPluginMissing(): void
    {
        $builder = new RenderContextBuilder();
        $this->setBuilderPlugin($builder, null);

        $context = $builder->build([], $this->createMockAsset());

        $this->assertNull($context);
    }

    public function testBuildReturnsExpectedContextKeys(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();
        $asset = $this->createMockAsset();

        $context = $builder->build([
            'setName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
            'allowTransformEditing' => false,
        ], $asset);

        $this->assertIsArray($context);
        $this->assertArrayHasKey('config', $context);
        $this->assertArrayHasKey('pictureTemplatePath', $context);
        $this->assertArrayHasKey('pictureAttributes', $context);
        $this->assertArrayHasKey('imgAttributes', $context);
        $this->assertArrayHasKey('breakpoints', $context);

        // Normal (non-processing) render with editing disabled: no processing
        // or public editing markers leak out.
        $this->assertArrayNotHasKey('data-set', $context['pictureAttributes']);
        $this->assertArrayNotHasKey('data-picture-id', $context['pictureAttributes']);
    }

    public function testGetPictureAttributesExposeSetHandleWhenTransformEditingAllowed(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();

        $attributes = $builder->getPictureAttributes([
            'setName' => 'hero',
            'allowTransformEditing' => true,
        ]);

        $this->assertSame('hero', $attributes['data-set'] ?? null);
        $this->assertArrayNotHasKey('data-picture-id', $attributes);
        $this->assertArrayNotHasKey('data-breakpoint-states', $attributes);
    }

    public function testGetPictureAttributesOmitSetHandleWhenTransformEditingDisabled(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();

        $attributes = $builder->getPictureAttributes([
            'setName' => 'hero',
            'allowTransformEditing' => false,
        ]);

        $this->assertArrayNotHasKey('data-set', $attributes);
    }

    public function testGetImageAttributesUsesTransparentPixelWhenFirstImageIsDisabled(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();

        $attributes = $builder->getImageAttributes([
            'setName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            // Canonical label for the smallest slot (480px) is `base`.
            'disableBreakpoints' => ['base' => true],
            'secondaryFormat' => 'none',
        ], $this->createMockAsset());

        $this->assertIsArray($attributes);
        $this->assertSame('https://example.test/640x360.jpg', $attributes['src'] ?? null);
        $this->assertSame(640, $attributes['width'] ?? null);
        $this->assertSame(360, $attributes['height'] ?? null);
        // Normal (non-processing) render: no internal processing markers on <img>.
        $this->assertArrayNotHasKey('data-asset-id', $attributes);
        $this->assertArrayNotHasKey('data-uid', $attributes);
    }

    public function testGetImageAttributesOmitsLoadingWhenNativeLazyLoadingDisabled(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();

        $attributes = $builder->getImageAttributes([
            'setName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
            'nativeLazyLoadingEnabled' => false,
            'imgClass' => 'no-loading',
        ], $this->createMockAsset());

        $this->assertIsArray($attributes);
        $this->assertArrayNotHasKey('loading', $attributes);
        $this->assertSame('no-loading', $attributes['class'] ?? null);
    }

    public function testGetImageAttributesUsesAssetAltAsFallbackAltAndAllowsOverride(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();
        $asset = $this->createMockAsset();

        $fallbackAltAttributes = $builder->getImageAttributes([
            'setName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
        ], $asset);

        $overrideAltAttributes = $builder->getImageAttributes([
            'setName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
            'alt' => 'Explicit alt',
        ], $asset);

        $this->assertSame('Native asset alt', $fallbackAltAttributes['alt'] ?? null);
        $this->assertSame('Explicit alt', $overrideAltAttributes['alt'] ?? null);
    }

    public function testGetImageAttributesUsesEmptyAltWhenNoOptionOrAssetAltExists(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();
        $asset = $this->createMockAsset();
        $asset->alt = null;

        $attributes = $builder->getImageAttributes([
            'setName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
        ], $asset);

        $this->assertSame('', $attributes['alt'] ?? null);
    }

    public function testGetPictureAttributesExposeBreakpointStatesAsJsonObject(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();

        // data-breakpoint-states is a processing-only marker gated in
        // getPictureAttributes; test the composition logic directly.
        $compose = new \ReflectionMethod($builder, 'composePictureMarkers');
        $attributes = $compose->invoke($builder, [
            'setName' => 'default',
            'imageId' => 123,
            'breakpoints' => [
                'xs' => 480,
                'md' => 768,
            ],
            // Canonical labels for these slots are `base` (480) and `xs` (768);
            // disable + states both use the canonical names.
            'disableBreakpoints' => [
                'xs' => true,
            ],
        ]);

        $decoded = json_decode((string)($attributes['data-breakpoint-states'] ?? ''), true);

        $this->assertIsArray($decoded);
        $this->assertSame('enabled', $decoded['base'] ?? null);
        $this->assertSame('disabled', $decoded['xs'] ?? null);
    }

    private function setBuilderPlugin(RenderContextBuilder $builder, ?Plugin $plugin): void
    {
        $property = new \ReflectionProperty($builder, '_plugin');
        $property->setValue($builder, $plugin);
    }

    private function createMockAsset(): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUrl', 'getWidth', 'getHeight'])
            ->getMock();

        $asset->id = 123;
        $asset->title = 'Mock asset';
        $asset->alt = 'Native asset alt';

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
