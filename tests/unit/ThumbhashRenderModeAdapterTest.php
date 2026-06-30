<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craft\elements\Asset;
use craftyhedge\craftbreakpoints\services\ThumbhashRenderModeAdapter;

final class ThumbhashRenderModeAdapterTest extends Unit
{
    public function testBgModeAddsPictureHashAndLazyPlaceholderAttributes(): void
    {
        $adapter = $this->createAvailableAdapter();
        $context = $adapter->apply([
            'image' => $this->createMockAsset(100),
            'config' => [
                'thumbhash' => true,
                'thumbhashMode' => 'bg',
                'nativeLazyLoadingEnabled' => false,
            ],
            'pictureAttributes' => [],
            'imgAttributes' => [
                'src' => 'https://example.test/fallback.jpg',
                'width' => 480,
                'height' => 270,
                'data-src' => 'https://example.test/fallback.jpg',
            ],
            'breakpointData' => [
                'base' => [
                    'asset' => $this->createMockAsset(200),
                    'primarySourceAttributes' => [
                        'data-srcset' => 'https://example.test/mobile.jpg',
                    ],
                    'secondarySourceAttributes' => [],
                ],
            ],
        ]);

        $this->assertSame('hash-100', $context['pictureAttributes']['data-thumbhash'] ?? null);
        $this->assertSame('bg', $context['pictureAttributes']['data-thumbhash-render'] ?? null);
        $this->assertSame('https://example.test/mobile.jpg', $context['breakpointData']['base']['primarySourceAttributes']['data-srcset'] ?? null);
        $this->assertArrayNotHasKey('srcset', $context['breakpointData']['base']['primarySourceAttributes']);
        $this->assertSame('https://example.test/fallback.jpg', $context['imgAttributes']['data-src'] ?? null);
        $this->assertSame('data:image/svg+xml,480x270', $context['imgAttributes']['src'] ?? null);
        $this->assertArrayNotHasKey('data-thumbhash', $context['breakpointData']['base']['primarySourceAttributes']);
    }

    public function testSrcsetModeAddsPerSourceAndImageHashes(): void
    {
        $adapter = $this->createAvailableAdapter();
        $context = $adapter->apply([
            'image' => $this->createMockAsset(100),
            'config' => [
                'thumbhashEnabled' => true,
                'thumbhashMode' => 'srcset',
                'nativeLazyLoadingEnabled' => false,
            ],
            'pictureAttributes' => [],
            'imgAttributes' => [
                'data-src' => 'https://example.test/fallback.jpg',
            ],
            'breakpointData' => [
                'base' => [
                    'asset' => $this->createMockAsset(200),
                    'primarySourceAttributes' => [
                        'data-srcset' => 'https://example.test/mobile.jpg',
                    ],
                    'secondarySourceAttributes' => [
                        'data-srcset' => 'https://example.test/mobile.webp',
                    ],
                ],
            ],
        ]);

        $this->assertSame('srcset', $context['pictureAttributes']['data-thumbhash-render'] ?? null);
        $this->assertArrayNotHasKey('data-thumbhash', $context['pictureAttributes']);
        $this->assertSame('hash-200', $context['breakpointData']['base']['primarySourceAttributes']['data-thumbhash'] ?? null);
        $this->assertSame('hash-200', $context['breakpointData']['base']['secondarySourceAttributes']['data-thumbhash'] ?? null);
        $this->assertSame('https://example.test/mobile.jpg', $context['breakpointData']['base']['primarySourceAttributes']['data-srcset'] ?? null);
        $this->assertSame('hash-100', $context['imgAttributes']['data-thumbhash'] ?? null);
        $this->assertSame('https://example.test/fallback.jpg', $context['imgAttributes']['data-src'] ?? null);
        $this->assertSame('data:image/svg+xml,4x4', $context['imgAttributes']['src'] ?? null);
    }

    public function testDisabledOrUnavailableAdapterLeavesContextUntouched(): void
    {
        $adapter = new class extends ThumbhashRenderModeAdapter {
            protected function isAvailable(): bool
            {
                return false;
            }
        };

        $context = [
            'image' => $this->createMockAsset(100),
            'config' => ['thumbhashEnabled' => true],
            'pictureAttributes' => [],
            'imgAttributes' => ['src' => 'https://example.test/fallback.jpg'],
        ];

        $this->assertSame($context, $adapter->apply($context));
    }

    public function testEnabledAdapterRegistersScriptEvenWhenImageAttributesAreSkipped(): void
    {
        $adapter = new class extends ThumbhashRenderModeAdapter {
            public int $scriptRegistrations = 0;

            protected function isAvailable(): bool
            {
                return true;
            }

            protected function registerScript(): void
            {
                $this->scriptRegistrations++;
            }
        };

        $context = [
            'image' => null,
            'config' => [
                'thumbhashEnabled' => true,
                'nativeLazyLoadingEnabled' => false,
            ],
            'pictureAttributes' => [],
            'imgAttributes' => ['src' => 'https://example.test/fallback.jpg'],
        ];

        $this->assertSame($context, $adapter->apply($context));
        $this->assertSame(1, $adapter->scriptRegistrations);
    }

    public function testEagerRenderSkipsThumbhashAttributes(): void
    {
        $adapter = $this->createAvailableAdapter();
        $context = [
            'image' => $this->createMockAsset(100),
            'config' => [
                'thumbhashEnabled' => true,
                'thumbhashMode' => 'srcset',
                'loading' => 'eager',
            ],
            'pictureAttributes' => [],
            'imgAttributes' => [
                'src' => 'https://example.test/fallback.jpg',
                'loading' => 'eager',
            ],
            'breakpointData' => [
                'base' => [
                    'asset' => $this->createMockAsset(200),
                    'primarySourceAttributes' => [
                        'srcset' => 'https://example.test/mobile.jpg',
                    ],
                    'secondarySourceAttributes' => [],
                ],
            ],
        ];

        $this->assertSame($context, $adapter->apply($context));
    }

    public function testNativeLazyLoadingSkipsThumbhashAttributes(): void
    {
        $adapter = $this->createAvailableAdapter();
        $context = [
            'image' => $this->createMockAsset(100),
            'config' => [
                'thumbhashEnabled' => true,
                'nativeLazyLoadingEnabled' => true,
            ],
            'pictureAttributes' => [],
            'imgAttributes' => [
                'src' => 'https://example.test/fallback.jpg',
                'loading' => 'lazy',
            ],
            'breakpointData' => [
                'base' => [
                    'asset' => $this->createMockAsset(200),
                    'primarySourceAttributes' => [
                        'srcset' => 'https://example.test/mobile.jpg',
                    ],
                    'secondarySourceAttributes' => [],
                ],
            ],
        ];

        $this->assertSame($context, $adapter->apply($context));
    }

    private function createAvailableAdapter(): ThumbhashRenderModeAdapter
    {
        return new class extends ThumbhashRenderModeAdapter {
            protected function isAvailable(): bool
            {
                return true;
            }

            protected function getThumbhash(Asset $asset): ?string
            {
                return 'hash-' . $asset->id;
            }

            protected function registerScript(): void
            {
            }

            protected function transparentSvgDataUrl(int $width, int $height): string
            {
                return sprintf('data:image/svg+xml,%dx%d', $width, $height);
            }
        };
    }

    private function createMockAsset(int $id): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtension'])
            ->getMock();

        $asset->id = $id;
        $asset->method('getExtension')->willReturn('jpg');

        return $asset;
    }
}
