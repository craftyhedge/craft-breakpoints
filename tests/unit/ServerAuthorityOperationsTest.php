<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\TelemetryService;
use craftyhedge\craftbreakpoints\services\transformeditor\OperationsService;
use craftyhedge\craftbreakpoints\services\transformeditor\SnapshotReader;

final class ServerAuthorityOperationsTest extends Unit
{
    public function testApplyRenderedValuesOperationReturnsErrorWhenNoSnapshotExists(): void
    {
        $plugin = Plugin::getInstance();
        $previousTelemetry = $plugin->getTelemetry();

        $plugin->set('telemetry', new class() extends TelemetryService {
            public function getLatestRunSnapshot(): ?array
            {
                return null;
            }
            public function getPreviewCacheRows(): array
            {
                return [];
            }
        });

        try {
            $snapshotReader = new SnapshotReader(
                $plugin->getTransformStore(),
                $plugin->getTelemetry(),
            );
            $service = new OperationsService(
                $plugin->getTransformStore(),
                $plugin->getConfigService(),
                $plugin->getTelemetry(),
                $snapshotReader,
            );

            $result = $service->applyRenderedValuesOperation('hero');

            $this->assertFalse($result['persisted'] ?? true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? false) === true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? false) === true);
            $globalMessages = $result['validation']['global'] ?? [];
            $this->assertNotEmpty($globalMessages);
            $this->assertStringContainsString('No rendered evidence found', implode(' ', $globalMessages));
        } finally {
            $plugin->set('telemetry', $previousTelemetry);
        }
    }

    public function testApplyRenderedValuesOperationResolvesFromServerSnapshot(): void
    {
        $plugin = Plugin::getInstance();
        $previousTelemetry = $plugin->getTelemetry();

        $plugin->set('telemetry', new class() extends TelemetryService {
            public function getLatestRunSnapshot(): ?array
            {
                return [
                    'runStatus' => 'completed',
                    'ranAt' => '2026-05-01 10:00:00',
                    'rowsPayload' => [
                        [
                            'transformHandle' => 'hero',
                            'slotKey' => 'xs',
                            'slotIndex' => 1,
                            'breakpointWidth' => 640,
                            'assetId' => '100',
                            'renderedWidth' => 600,
                            'renderedHeight' => 340,
                            'rowStatus' => 'loaded',
                        ],
                    ],
                    'rows' => [],
                ];
            }
        });

        try {
            $this->withRuntimeSets([
                'hero' => [
                    'name' => 'hero',
                    'includeEscapeWidth' => false,
                    'variants' => [
                        'xs' => ['width' => null, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                    ],
                    'config' => [],
                ],
            ], function () use ($plugin): void {
                $snapshotReader = new SnapshotReader(
                    $plugin->getTransformStore(),
                    $plugin->getTelemetry(),
                );
                $service = new OperationsService(
                    $plugin->getTransformStore(),
                    $plugin->getConfigService(),
                    $plugin->getTelemetry(),
                    $snapshotReader,
                );

                $result = $service->applyRenderedValuesOperation(
                    'hero',
                    null,
                    false,
                    false,
                    $plugin->getTransformStore()->getCurrentVersion(),
                );

                $this->assertTrue(($result['persisted'] ?? false) === true);
                $this->assertFalse(($result['validation']['hasErrors'] ?? true) === true);

                $sets = $plugin->getTransformStore()->getSets();
                $this->assertSame(600, $sets['hero']['variants']['xs']['width'] ?? null);
                $this->assertSame(340, $sets['hero']['variants']['xs']['height'] ?? null);
            });
        } finally {
            $plugin->set('telemetry', $previousTelemetry);
        }
    }

    public function testApplyRenderedValuesOperationIgnoresClientStaleDataAndUsesServerSnapshot(): void
    {
        $plugin = Plugin::getInstance();
        $previousTelemetry = $plugin->getTelemetry();

        $plugin->set('telemetry', new class() extends TelemetryService {
            public function getLatestRunSnapshot(): ?array
            {
                return [
                    'runStatus' => 'completed',
                    'ranAt' => '2026-05-01 10:00:00',
                    'rowsPayload' => [
                        [
                            'transformHandle' => 'hero',
                            'slotKey' => 'xs',
                            'slotIndex' => 1,
                            'breakpointWidth' => 640,
                            'assetId' => '100',
                            'renderedWidth' => 600,
                            'renderedHeight' => 340,
                            'rowStatus' => 'loaded',
                        ],
                    ],
                    'rows' => [],
                ];
            }
        });

        try {
            $this->withRuntimeSets([
                'hero' => [
                    'name' => 'hero',
                    'includeEscapeWidth' => false,
                    'variants' => [
                        'xs' => ['width' => null, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                    ],
                    'config' => [],
                ],
            ], function () use ($plugin): void {
                $snapshotReader = new SnapshotReader(
                    $plugin->getTransformStore(),
                    $plugin->getTelemetry(),
                );
                $service = new OperationsService(
                    $plugin->getTransformStore(),
                    $plugin->getConfigService(),
                    $plugin->getTelemetry(),
                    $snapshotReader,
                );

                $result = $service->applyRenderedValuesOperation(
                    'hero',
                    null,
                    false,
                    false,
                    $plugin->getTransformStore()->getCurrentVersion(),
                );

                $this->assertTrue(($result['persisted'] ?? false) === true);

                $sets = $plugin->getTransformStore()->getSets();
                $this->assertSame(600, $sets['hero']['variants']['xs']['width'] ?? null);
                $this->assertSame(340, $sets['hero']['variants']['xs']['height'] ?? null);
            });
        } finally {
            $plugin->set('telemetry', $previousTelemetry);
        }
    }

    public function testResolveRenderedRatioByBreakpointUsesSavedConfig(): void
    {
        $plugin = Plugin::getInstance();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => 320, 'height' => 180, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($plugin): void {
            $service = new OperationsService(
                $plugin->getTransformStore(),
                $plugin->getConfigService(),
                $plugin->getTelemetry(),
                null,
            );

            $ratio = $service->resolveRenderedRatioByBreakpoint('hero', 'xs');

            $this->assertNotNull($ratio);
            $this->assertSame(16, $ratio['width']);
            $this->assertSame(9, $ratio['height']);
        });
    }

    public function testResolveRenderedRatioByBreakpointReturnsLockedRatioWhenSet(): void
    {
        $plugin = Plugin::getInstance();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => [
                        'width' => 640,
                        'height' => 360,
                        'enabled' => true,
                        'autoDimension' => null,
                        'ratioLocked' => true,
                        'ratioWidth' => 16,
                        'ratioHeight' => 9,
                    ],
                ],
                'config' => [],
            ],
        ], function () use ($plugin): void {
            $service = new OperationsService(
                $plugin->getTransformStore(),
                $plugin->getConfigService(),
                $plugin->getTelemetry(),
                null,
            );

            $ratio = $service->resolveRenderedRatioByBreakpoint('hero', 'xs');

            $this->assertNotNull($ratio);
            $this->assertSame(16, $ratio['width']);
            $this->assertSame(9, $ratio['height']);
        });
    }

    public function testResolveRenderedRatioByBreakpointReturnsNullWhenNoSavedValues(): void
    {
        $plugin = Plugin::getInstance();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => null, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($plugin): void {
            $service = new OperationsService(
                $plugin->getTransformStore(),
                $plugin->getConfigService(),
                $plugin->getTelemetry(),
                null,
            );

            $ratio = $service->resolveRenderedRatioByBreakpoint('hero', 'xs');

            $this->assertNull($ratio);
        });
    }

    public function testApplyRenderedValuesOperationMatchesByAssetKey(): void
    {
        $plugin = Plugin::getInstance();
        $previousTelemetry = $plugin->getTelemetry();

        $plugin->set('telemetry', new class() extends TelemetryService {
            public function getLatestRunSnapshot(): ?array
            {
                return [
                    'runStatus' => 'completed',
                    'ranAt' => '2026-05-01 10:00:00',
                    'rowsPayload' => [
                        [
                            'transformHandle' => 'hero',
                            'slotKey' => 'xs',
                            'slotIndex' => 1,
                            'breakpointWidth' => 640,
                            'assetId' => '100',
                            'renderedWidth' => 320,
                            'renderedHeight' => 180,
                            'rowStatus' => 'loaded',
                        ],
                        [
                            'transformHandle' => 'hero',
                            'slotKey' => 'xs',
                            'slotIndex' => 1,
                            'breakpointWidth' => 640,
                            'assetId' => '101',
                            'renderedWidth' => 620,
                            'renderedHeight' => 350,
                            'rowStatus' => 'loaded',
                        ],
                    ],
                    'rows' => [],
                ];
            }
        });

        try {
            $this->withRuntimeSets([
                'hero' => [
                    'name' => 'hero',
                    'includeEscapeWidth' => false,
                    'variants' => [
                        'xs' => ['width' => null, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                    ],
                    'config' => [],
                ],
            ], function () use ($plugin): void {
                $snapshotReader = new SnapshotReader(
                    $plugin->getTransformStore(),
                    $plugin->getTelemetry(),
                );
                $service = new OperationsService(
                    $plugin->getTransformStore(),
                    $plugin->getConfigService(),
                    $plugin->getTelemetry(),
                    $snapshotReader,
                );

                $result = $service->applyRenderedValuesOperation(
                    'hero',
                    '101',
                    false,
                    false,
                    $plugin->getTransformStore()->getCurrentVersion(),
                );

                $this->assertTrue(($result['persisted'] ?? false) === true);

                $sets = $plugin->getTransformStore()->getSets();
                $this->assertSame(620, $sets['hero']['variants']['xs']['width'] ?? null);
                $this->assertSame(350, $sets['hero']['variants']['xs']['height'] ?? null);
            });
        } finally {
            $plugin->set('telemetry', $previousTelemetry);
        }
    }

    public function testOperationsServiceReturnsErrorWhenSnapshotReaderIsNull(): void
    {
        $plugin = Plugin::getInstance();

        $service = new OperationsService(
            $plugin->getTransformStore(),
            $plugin->getConfigService(),
            $plugin->getTelemetry(),
            null,
        );

        $result = $service->applyRenderedValuesOperation('nonexistent');

        $this->assertFalse($result['persisted'] ?? true);
        $globalMessages = $result['validation']['global'] ?? [];
        $this->assertNotEmpty($globalMessages);
        $this->assertStringContainsString('No rendered evidence found', implode(' ', $globalMessages));

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => null, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($plugin, $service): void {
            $ratio = $service->resolveRenderedRatioByBreakpoint('hero', 'xs');
            $this->assertNull($ratio);
        });
    }

    public function testSnapshotReaderResolvesFromPreviewCacheFallback(): void
    {
        $plugin = Plugin::getInstance();
        $previousTelemetry = $plugin->getTelemetry();

        $plugin->set('telemetry', new class() extends TelemetryService {
            public function getLatestRunSnapshot(): ?array
            {
                return null;
            }
            public function getPreviewCacheRows(): array
            {
                return [
                    'hero|xs' => [
                        'transformHandle' => 'hero',
                        'slotKey' => 'xs',
                        'slotIndex' => 1,
                        'breakpointWidth' => 640,
                        'renderedWidth' => 580,
                        'renderedHeight' => 320,
                    ],
                ];
            }
        });

        try {
            $snapshotReader = new SnapshotReader(
                $plugin->getTransformStore(),
                $plugin->getTelemetry(),
            );

            $resolved = $snapshotReader->resolveRenderedWidthHeightByBreakpoint('hero', 640, null, 'xs');

            $this->assertNotNull($resolved);
            $this->assertSame(580, $resolved['renderedWidth']);
            $this->assertSame(320, $resolved['renderedHeight']);
        } finally {
            $plugin->set('telemetry', $previousTelemetry);
        }
    }

    public function testAllScopeAutoToggleSetsRequestedAutoDimensionOnEnabledBreakpoints(): void
    {
        $plugin = Plugin::getInstance();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'base' => ['width' => 1200, 'height' => 675, 'enabled' => false, 'autoDimension' => null],
                    'xs' => ['width' => 640, 'height' => 360, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($plugin): void {
            $service = new OperationsService(
                $plugin->getTransformStore(),
                $plugin->getConfigService(),
                $plugin->getTelemetry(),
                null,
            );

            $result = $service->applySetToggleAutoWidthOperation(
                'hero',
                'all',
                null,
                null,
                null,
                null,
                false,
                $plugin->getTransformStore()->getCurrentVersion(),
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);

            $sets = $plugin->getTransformStore()->getSets();
            $this->assertSame('width', $sets['hero']['variants']['xs']['autoDimension'] ?? null);
            $this->assertNull($sets['hero']['variants']['xs']['width'] ?? null);
            $this->assertNull($sets['hero']['variants']['base']['autoDimension'] ?? null);
        });
    }

    public function testAllScopeAutoToggleClearsRequestedAutoDimensionWithRenderedValues(): void
    {
        $plugin = Plugin::getInstance();
        $previousTelemetry = $plugin->getTelemetry();

        $plugin->set('telemetry', new class() extends TelemetryService {
            public function getLatestRunSnapshot(): ?array
            {
                return [
                    'runStatus' => 'completed',
                    'ranAt' => '2026-05-01 10:00:00',
                    'rowsPayload' => [
                        [
                            'transformHandle' => 'hero',
                            'slotKey' => 'xs',
                            'slotIndex' => 1,
                            'breakpointWidth' => 640,
                            'assetId' => '100',
                            'renderedWidth' => 610,
                            'renderedHeight' => 343,
                            'rowStatus' => 'loaded',
                        ],
                    ],
                    'rows' => [],
                ];
            }
        });

        try {
            $this->withRuntimeSets([
                'hero' => [
                    'name' => 'hero',
                    'includeEscapeWidth' => false,
                    'variants' => [
                        'xs' => ['width' => null, 'height' => 360, 'enabled' => true, 'autoDimension' => 'width'],
                    ],
                    'config' => [],
                ],
            ], function () use ($plugin): void {
                $snapshotReader = new SnapshotReader(
                    $plugin->getTransformStore(),
                    $plugin->getTelemetry(),
                );
                $service = new OperationsService(
                    $plugin->getTransformStore(),
                    $plugin->getConfigService(),
                    $plugin->getTelemetry(),
                    $snapshotReader,
                );

                $result = $service->applySetToggleAutoWidthOperation(
                    'hero',
                    'all',
                    null,
                    null,
                    null,
                    '100',
                    false,
                    $plugin->getTransformStore()->getCurrentVersion(),
                );

                $this->assertTrue(($result['persisted'] ?? false) === true);

                $sets = $plugin->getTransformStore()->getSets();
                $this->assertNull($sets['hero']['variants']['xs']['autoDimension'] ?? null);
                $this->assertSame(610, $sets['hero']['variants']['xs']['width'] ?? null);
            });
        } finally {
            $plugin->set('telemetry', $previousTelemetry);
        }
    }

    public function testApplyRenderedValuesOperationDisablesHiddenBreakpoints(): void
    {
        $plugin = Plugin::getInstance();
        $previousTelemetry = $plugin->getTelemetry();

        $plugin->set('telemetry', new class() extends TelemetryService {
            public function getLatestRunSnapshot(): ?array
            {
                return [
                    'runStatus' => 'completed',
                    'ranAt' => '2026-05-01 10:00:00',
                    'rowsPayload' => [
                        [
                            'transformHandle' => 'hero',
                            'slotKey' => 'xs',
                            'slotIndex' => 1,
                            'breakpointWidth' => 640,
                            'assetId' => '100',
                            'renderedWidth' => 600,
                            'renderedHeight' => 340,
                            'rowStatus' => 'loaded',
                        ],
                    ],
                    'rows' => [],
                ];
            }
        });

        try {
            $this->withRuntimeSets([
                'hero' => [
                    'name' => 'hero',
                    'includeEscapeWidth' => false,
                    'variants' => [
                        'xs' => ['width' => null, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                    ],
                    'config' => [],
                ],
            ], function () use ($plugin): void {
                $snapshotReader = new SnapshotReader(
                    $plugin->getTransformStore(),
                    $plugin->getTelemetry(),
                );
                $service = new OperationsService(
                    $plugin->getTransformStore(),
                    $plugin->getConfigService(),
                    $plugin->getTelemetry(),
                    $snapshotReader,
                );

                // 'xs' is slotIndex 1, i.e. slot id 2.
                $result = $service->applyRenderedValuesOperation(
                    'hero',
                    null,
                    false,
                    false,
                    $plugin->getTransformStore()->getCurrentVersion(),
                    [2],
                );

                $this->assertTrue(($result['persisted'] ?? false) === true);
                $this->assertFalse(($result['validation']['hasErrors'] ?? true) === true);

                $sets = $plugin->getTransformStore()->getSets();
                // Rendered values are still applied, and the hidden breakpoint is disabled.
                $this->assertSame(600, $sets['hero']['variants']['xs']['width'] ?? null);
                $this->assertSame(340, $sets['hero']['variants']['xs']['height'] ?? null);
                $this->assertFalse($sets['hero']['variants']['xs']['enabled'] ?? null);
            });
        } finally {
            $plugin->set('telemetry', $previousTelemetry);
        }
    }

    public function testApplyRenderedValuesOperationDisablesHiddenBreakpointEvenWhenNoDimensionsApply(): void
    {
        $plugin = Plugin::getInstance();
        $previousTelemetry = $plugin->getTelemetry();

        // Rendered evidence exists (width only) but the variant has an auto width,
        // so no dimension is applied. Disabling the hidden breakpoint must still
        // drive a persist instead of the auto-skipped no-op return.
        $plugin->set('telemetry', new class() extends TelemetryService {
            public function getLatestRunSnapshot(): ?array
            {
                return [
                    'runStatus' => 'completed',
                    'ranAt' => '2026-05-01 10:00:00',
                    'rowsPayload' => [
                        [
                            'transformHandle' => 'hero',
                            'slotKey' => 'xs',
                            'slotIndex' => 1,
                            'breakpointWidth' => 640,
                            'assetId' => '100',
                            'renderedWidth' => 600,
                            'renderedHeight' => 0,
                            'rowStatus' => 'loaded',
                        ],
                    ],
                    'rows' => [],
                ];
            }
        });

        try {
            $this->withRuntimeSets([
                'hero' => [
                    'name' => 'hero',
                    'includeEscapeWidth' => false,
                    'variants' => [
                        'xs' => ['width' => null, 'height' => null, 'enabled' => true, 'autoDimension' => 'width'],
                    ],
                    'config' => [],
                ],
            ], function () use ($plugin): void {
                $snapshotReader = new SnapshotReader(
                    $plugin->getTransformStore(),
                    $plugin->getTelemetry(),
                );
                $service = new OperationsService(
                    $plugin->getTransformStore(),
                    $plugin->getConfigService(),
                    $plugin->getTelemetry(),
                    $snapshotReader,
                );

                // 'xs' is slotIndex 1, i.e. slot id 2.
                $result = $service->applyRenderedValuesOperation(
                    'hero',
                    null,
                    false,
                    false,
                    $plugin->getTransformStore()->getCurrentVersion(),
                    [2],
                );

                $this->assertTrue(($result['persisted'] ?? false) === true);

                $sets = $plugin->getTransformStore()->getSets();
                // No dimension applied (auto width, no height), but the breakpoint is disabled.
                $this->assertFalse($sets['hero']['variants']['xs']['enabled'] ?? null);
                $this->assertNull($sets['hero']['variants']['xs']['width'] ?? null);
                $this->assertSame('width', $sets['hero']['variants']['xs']['autoDimension'] ?? null);
            });
        } finally {
            $plugin->set('telemetry', $previousTelemetry);
        }
    }

    private function withRuntimeSets(array $sets, callable $callback): mixed
    {
        $configService = Plugin::getInstance()->getConfigService();
        $normalizedSets = [];

        foreach ($sets as $setName => $setDefinition) {
            if (!is_string($setName) || !is_array($setDefinition)) {
                continue;
            }

            $includeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
            // Canonical variant labels (`base`-first, no `escape`) for this set.
            $setBreakpointNames = $configService->getBreakpointKeys($includeEscapeWidth);

            $variants = isset($setDefinition['variants']) && is_array($setDefinition['variants'])
                ? $setDefinition['variants']
                : [];

            foreach ($setBreakpointNames as $breakpointName) {
                if (!array_key_exists($breakpointName, $variants)) {
                    $variants[$breakpointName] = [
                        'width' => null,
                        'height' => null,
                        'enabled' => false,
                        'autoDimension' => null,
                    ];
                }
            }

            $setDefinition['variants'] = $variants;
            $normalizedSets[$setName] = $setDefinition;
        }

        $store = Plugin::getInstance()->getTransformStore();
        $previousSets = $store->getSets();
        $store->replaceSetsForRuntime($normalizedSets);

        try {
            return $callback();
        } finally {
            $store->replaceSetsForRuntime($previousSets);
        }
    }
}
