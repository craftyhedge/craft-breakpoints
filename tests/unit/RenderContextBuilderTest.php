<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpointimages\Plugin;
use craftyhedge\craftbreakpointimages\services\RenderContextBuilder;

final class RenderContextBuilderTest extends Unit
{
    private const TRANSPARENT_PIXEL_DATA_URI = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

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
            'transformName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
        ], $asset);

        $this->assertIsArray($context);
        $this->assertArrayHasKey('config', $context);
        $this->assertArrayHasKey('pictureTemplatePath', $context);
        $this->assertArrayHasKey('pictureAttributes', $context);
        $this->assertArrayHasKey('imgAttributes', $context);
        $this->assertArrayHasKey('breakpoints', $context);

        $this->assertSame('default', $context['pictureAttributes']['data-transform'] ?? null);
    }

    public function testGetImageAttributesUsesTransparentPixelWhenFirstImageIsDisabled(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();

        $attributes = $builder->getImageAttributes([
            'transformName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'disableBreakpoints' => ['xs' => true],
            'secondaryFormat' => 'none',
        ], $this->createMockAsset());

        $this->assertIsArray($attributes);
        $this->assertSame(self::TRANSPARENT_PIXEL_DATA_URI, $attributes['src'] ?? null);
        $this->assertSame(1, $attributes['width'] ?? null);
        $this->assertSame(1, $attributes['height'] ?? null);
    }

    public function testGetPictureAttributesIncludesTransformExistenceFlag(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();

        $exists = $builder->getPictureAttributes([
            'transformName' => 'default',
            'imageId' => 123,
            'breakpoints' => ['xs' => 480],
        ]);
        $missing = $builder->getPictureAttributes([
            'transformName' => 'missing-transform-name',
            'imageId' => 123,
            'breakpoints' => ['xs' => 480],
        ]);

        $this->assertSame('true', $exists['data-transform-exists'] ?? null);
        $this->assertSame('false', $missing['data-transform-exists'] ?? null);
    }

    public function testGetImageAttributesOmitsLoadingWhenNativeLazyLoadingDisabled(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();

        $attributes = $builder->getImageAttributes([
            'transformName' => 'default',
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

    public function testGetImageAttributesUsesAssetTitleAsFallbackAltAndAllowsOverride(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();
        $asset = $this->createMockAsset();

        $fallbackAltAttributes = $builder->getImageAttributes([
            'transformName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
        ], $asset);

        $overrideAltAttributes = $builder->getImageAttributes([
            'transformName' => 'default',
            'breakpoints' => ['xs' => 480],
            'escapeWidth' => 0,
            'secondaryFormat' => 'none',
            'alt' => 'Explicit alt',
        ], $asset);

        $this->assertSame('Mock asset', $fallbackAltAttributes['alt'] ?? null);
        $this->assertSame('Explicit alt', $overrideAltAttributes['alt'] ?? null);
    }

    public function testGetPictureAttributesExposeBreakpointStatesAsJsonObject(): void
    {
        $builder = Plugin::getInstance()->getRenderContextBuilder();

        $attributes = $builder->getPictureAttributes([
            'transformName' => 'default',
            'imageId' => 123,
            'breakpoints' => [
                'xs' => 480,
                'md' => 768,
            ],
            'disableBreakpoints' => [
                'md' => true,
            ],
        ]);

        $decoded = json_decode((string)($attributes['data-breakpoint-states'] ?? ''), true);

        $this->assertIsArray($decoded);
        $this->assertSame('enabled', $decoded['xs'] ?? null);
        $this->assertSame('disabled', $decoded['md'] ?? null);
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
