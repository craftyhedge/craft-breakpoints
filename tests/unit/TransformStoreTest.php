<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpoints\Plugin;

final class TransformStoreTest extends Unit
{
    public function testReplaceSetsForRuntimeNormalizesNotes(): void
    {
        $store = Plugin::getInstance()->getTransformStore();
        $previousSets = $store->getSets();

        try {
            $store->replaceSetsForRuntime([
                'notes-transform' => [
                    'name' => 'notes-transform',
                    'includeEscapeWidth' => false,
                    'notes' => "  First line\r\nSecond line  ",
                    'variants' => [
                        'xs' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                    ],
                    'config' => [],
                ],
            ]);

            $normalized = $store->getSet('notes-transform');
            $this->assertNotNull($normalized);
            $this->assertSame("First line\nSecond line", $normalized['notes'] ?? null);
        } finally {
            $store->replaceSetsForRuntime($previousSets);
        }
    }

    public function testPersistSetsNormalizesRenderedFrom(): void
    {
        $store = Plugin::getInstance()->getTransformStore();
        $longName = 'site/' . str_repeat('a', 200);

        $result = $store->persistSets([
            'stack' => $this->buildRenderedFromSet('stack', [['template' => 'page.twig', 'extends' => ['_layout.twig'], 'in' => 'page'], ' site/includes/cards/menu.twig ']),
            'trimmed' => $this->buildRenderedFromSet('trimmed', '  site/includes/cards/menu.twig  '),
            'capped' => $this->buildRenderedFromSet('capped', $longName),
            'empty' => $this->buildRenderedFromSet('empty', '   '),
            'string-template' => $this->buildRenderedFromSet('string-template', '__string_template__abc123'),
            'missing' => $this->buildRenderedFromSet('missing', null),
        ], $store->getCurrentVersion());

        $this->assertTrue($result['persisted']);

        $store->reload();
        $this->assertSame([['template' => 'page', 'extends' => ['_layout']], ['template' => 'site/includes/cards/menu']], $store->getSet('stack')['renderedFrom'] ?? null);
        $this->assertSame([['template' => 'site/includes/cards/menu']], $store->getSet('trimmed')['renderedFrom'] ?? null);
        $this->assertSame([['template' => mb_substr($longName, 0, 160)]], $store->getSet('capped')['renderedFrom'] ?? null);
        $this->assertArrayNotHasKey('renderedFrom', $store->getSet('empty') ?? []);
        $this->assertArrayNotHasKey('renderedFrom', $store->getSet('string-template') ?? []);
        $this->assertArrayNotHasKey('renderedFrom', $store->getSet('missing') ?? []);
    }

    public function testGetTransformsCarriesRenderedFromWhenSet(): void
    {
        $store = Plugin::getInstance()->getTransformStore();

        $store->persistSets([
            'with-origin' => $this->buildRenderedFromSet('with-origin', [['template' => 'page', 'extends' => ['_layout']], ['template' => 'site/includes/cards/menu']]),
            'without-origin' => $this->buildRenderedFromSet('without-origin', null),
        ], $store->getCurrentVersion());

        $transforms = $store->getTransforms();
        $this->assertSame([['template' => 'page', 'extends' => ['_layout']], ['template' => 'site/includes/cards/menu']], $transforms['with-origin']['renderedFrom'] ?? null);
        $this->assertArrayNotHasKey('renderedFrom', $transforms['without-origin'] ?? []);
    }

    public function testReplaceSetsForRuntimeKeepsOnlyAllowedSavedOptions(): void
    {
        $store = Plugin::getInstance()->getTransformStore();
        $previousSets = $store->getSets();

        try {
            $store->replaceSetsForRuntime([
                'quality-transform' => [
                    'name' => 'quality-transform',
                    'includeEscapeWidth' => false,
                    'variants' => [
                        'base' => [
                            'width' => 640,
                            'height' => 360,
                            'enabled' => true,
                            'autoDimension' => null,
                            'ratioWidth' => 16,
                            'ratioHeight' => 9,
                            'ratioSourceDimension' => 'width',
                            'ratioLocked' => true,
                            'mode' => 'fit',
                            'position' => 'top-center',
                            'quality' => 61,
                            'loading' => 'eager',
                        ],
                    ],
                    'config' => [
                        'format' => 'webp',
                        'secondaryFormat' => 'avif',
                        'mode' => 'crop',
                        'position' => 'center-center',
                        'passHeightWhenRenderedLteSaved' => true,
                        'allowAnyHeight' => false,
                        'quality' => 72,
                        'preload' => true,
                        'fetchpriority' => 'high',
                        'sources' => [],
                    ],
                ],
            ]);

            $normalized = $store->getSet('quality-transform');
            $this->assertNotNull($normalized);
            $this->assertSame([
                'format' => 'webp',
                'secondaryFormat' => 'avif',
                'mode' => 'crop',
                'position' => 'center-center',
                'passHeightWhenRenderedLteSaved' => true,
                'allowAnyHeight' => false,
            ], $normalized['config'] ?? []);
            $this->assertSame([
                'width' => 640,
                'height' => 360,
                'enabled' => true,
                'autoDimension' => null,
                'ratioWidth' => 16,
                'ratioHeight' => 9,
                'ratioSourceDimension' => 'width',
                'ratioLocked' => true,
                'mode' => 'fit',
                'position' => 'top-center',
            ], $normalized['variants']['base'] ?? []);

            $legacy = $store->getTransform('quality-transform');
            $this->assertNotNull($legacy);
            $this->assertSame($normalized['config'] ?? [], $legacy['config'] ?? []);
            $this->assertSame($normalized['variants']['base'] ?? [], $legacy['transforms'][0] ?? []);
        } finally {
            $store->replaceSetsForRuntime($previousSets);
        }
    }

    public function testReplaceTransformsForRuntimeKeepsOnlyAllowedSavedOptions(): void
    {
        $store = Plugin::getInstance()->getTransformStore();
        $previousTransforms = $store->getTransforms();

        try {
            $store->replaceTransformsForRuntime([
                'legacy-quality-transform' => [
                    'name' => 'legacy-quality-transform',
                    'includeEscapeWidth' => false,
                    'transforms' => [
                        [
                            'width' => 640,
                            'height' => 360,
                            'enabled' => true,
                            'mode' => 'fit',
                            'position' => 'top-center',
                            'quality' => 61,
                            'loading' => 'eager',
                        ],
                    ],
                    'config' => [
                        'format' => 'webp',
                        'mode' => 'crop',
                        'quality' => 72,
                        'priority' => true,
                    ],
                ],
            ]);

            $normalized = $store->getSet('legacy-quality-transform');
            $this->assertNotNull($normalized);
            $this->assertSame([
                'format' => 'webp',
                'mode' => 'crop',
            ], $normalized['config'] ?? []);
            $this->assertSame([
                'width' => 640,
                'height' => 360,
                'enabled' => true,
                'mode' => 'fit',
                'position' => 'top-center',
            ], $normalized['variants']['base'] ?? []);
        } finally {
            $store->replaceTransformsForRuntime($previousTransforms);
        }
    }

    public function testReplaceTransformsForRuntimeNormalizesMissingTransformsToEmptyEntries(): void
    {
        $store = Plugin::getInstance()->getTransformStore();
        $previousTransforms = $store->getTransforms();

        try {
            $store->replaceTransformsForRuntime([
                'invalid-transform' => [
                    'name' => 'invalid-transform',
                    'includeEscapeWidth' => false,
                    'config' => [],
                ],
            ]);

            $normalized = $store->getTransform('invalid-transform');
            $this->assertNotNull($normalized);
            $this->assertSame('invalid-transform', $normalized['name'] ?? null);
            $this->assertSame(false, $normalized['includeEscapeWidth'] ?? null);
            $this->assertSame([], $normalized['config'] ?? null);
            $this->assertArrayHasKey('transforms', $normalized);
            $this->assertIsArray($normalized['transforms']);
        } finally {
            $store->replaceTransformsForRuntime($previousTransforms);
        }
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

    /**
     * @param string|array<int, mixed>|null $renderedFrom
     * @return array<string, mixed>
     */
    private function buildRenderedFromSet(string $name, string|array|null $renderedFrom): array
    {
        $set = [
            'name' => $name,
            'includeEscapeWidth' => false,
            'variants' => [
                'xs' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
            ],
            'config' => [],
        ];

        if ($renderedFrom !== null) {
            $set['renderedFrom'] = $renderedFrom;
        }

        return $set;
    }
}
