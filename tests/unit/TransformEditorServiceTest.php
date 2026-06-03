<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\TransformEditor;
use craftyhedge\craftbreakpoints\services\TelemetryService;

final class TransformEditorServiceTest extends Unit
{
    public function testDefaultValidationReturnsExpectedShape(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $validation = $editor->defaultValidation();

        $this->assertSame([
            'hasErrors' => false,
            'global' => [],
            'fields' => [],
        ], $validation);
    }

    public function testBuildResultSummaryReturnsZerosWhenPluginMissing(): void
    {
        $editor = new TransformEditor();
        $this->setEditorPlugin($editor, null);

        $summary = $editor->buildResultSummary([
            'assetCount' => 5,
            'breakpointCount' => 7,
            'warningCount' => 2,
        ]);

        $this->assertSame([
            'assetCount' => 0,
            'breakpointCount' => 0,
            'warningCount' => 0,
        ], $summary);
    }

    public function testApplyDraftRejectsEmptyTransforms(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $editor->applyDraft([
            'transforms' => [],
        ]);

        $this->assertFalse($result['persisted'] ?? true);
        $this->assertTrue(($result['validation']['hasErrors'] ?? false) === true);
        $this->assertContains('Draft must include at least one transform.', $result['validation']['global'] ?? []);
    }

    public function testApplySetDimensionOperationRejectsInvalidDimensionName(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $editor->applySetDimensionOperation(
            'default',
            'all',
            null,
            100,
            'depth'
        );

        $this->assertFalse($result['persisted'] ?? true);
        $this->assertContains('dimension must be width or height.', $result['validation']['global'] ?? []);
    }

    public function testApplySetDimensionOperationRequiresScopeBreakpointInBreakpointMode(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $editor->applySetDimensionOperation(
            'default',
            'breakpoint',
            null,
            100,
            'width'
        );

        $this->assertFalse($result['persisted'] ?? true);
        $this->assertContains(
            'breakpoint key is required.',
            $result['validation']['global'] ?? []
        );
    }

    public function testApplySetBreakpointEnabledOperationRequiresBooleanEnabledValue(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $editor->applySetBreakpointEnabledOperation(
            'hero',
            640,
            null,
            true,
        );

        $this->assertFalse($result['persisted'] ?? true);
        $this->assertContains('enabled must be a boolean value.', $result['validation']['global'] ?? []);
    }

    public function testApplySetBreakpointEnabledOperationUpdatesSelectedBreakpoint(): void
    {
        $plugin = Plugin::getInstance();
        $editor = $plugin->getTransformEditor();
        $configService = $plugin->getConfigService();
        $firstBreakpointName = (string)($configService->getBreakpointKeys(false)[0] ?? '');

        $this->assertNotSame('', $firstBreakpointName);

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    $firstBreakpointName => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($editor, $firstBreakpointName): void {
            $result = $editor->applySetBreakpointEnabledOperation(
                'hero',
                null,
                false,
                null,
                null,
                true,
                $firstBreakpointName,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? true) === false);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $variant = $sets['hero']['variants'][$firstBreakpointName] ?? null;

            $this->assertIsArray($variant);
            $this->assertFalse(($variant['enabled'] ?? true) === true);
        });
    }

    public function testBuildResultSummaryNormalizesNegativeValuesAndUsesDefaultBreakpoints(): void
    {
        $plugin = Plugin::getInstance();
        $editor = $plugin->getTransformEditor();
        $expectedBreakpointCount = count($plugin->getConfigService()->getBreakpoints());

        $summary = $editor->buildResultSummary([
            'assetCount' => -12,
            'warningCount' => '3',
        ]);

        $this->assertSame(0, $summary['assetCount'] ?? null);
        $this->assertSame($expectedBreakpointCount, $summary['breakpointCount'] ?? null);
        $this->assertSame(3, $summary['warningCount'] ?? null);
    }

    public function testApplySetWidthOperationDelegatesToSetDimensionWidth(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $viaWidthOperation = $editor->applySetWidthOperation(
            '',
            'all',
            null,
            100,
        );

        $viaSetDimension = $editor->applySetDimensionOperation(
            '',
            'all',
            null,
            100,
            'width'
        );

        $this->assertSame($viaSetDimension, $viaWidthOperation);
    }

    public function testApplySetDimensionOperationAppliesAllScopeByConfiguredKeys(): void
    {
        $plugin = Plugin::getInstance();
        $editor = $plugin->getTransformEditor();
        $breakpointNames = array_map('strval', $plugin->getConfigService()->getBreakpointKeys(false));
        $this->assertGreaterThanOrEqual(2, count($breakpointNames));

        $variants = [];
        foreach ($breakpointNames as $breakpointName) {
            $variants[$breakpointName] = [
                'width' => null,
                'height' => null,
                'enabled' => true,
                'autoDimension' => null,
            ];
        }

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => $variants,
                'config' => [],
            ],
        ], function () use ($editor, $breakpointNames): void {
            $result = $editor->applySetDimensionOperation(
                'hero',
                'all',
                null,
                321,
                'width',
                false,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            foreach ($breakpointNames as $breakpointName) {
                $this->assertSame(321, $sets['hero']['variants'][$breakpointName]['width'] ?? null);
            }
        });
    }

    public function testBreakpointMutationsAcceptKeyOnlyTargets(): void
    {
        $plugin = Plugin::getInstance();
        $editor = $plugin->getTransformEditor();
        $firstBreakpointName = (string)($plugin->getConfigService()->getBreakpointKeys(false)[0] ?? '');
        $this->assertNotSame('', $firstBreakpointName);

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    $firstBreakpointName => ['width' => 640, 'height' => 360, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($editor, $firstBreakpointName): void {
            $toggleResult = $editor->applySetToggleAutoWidthOperation(
                'hero',
                'breakpoint',
                null,
                360,
                null,
                false,
                null,
                $firstBreakpointName,
            );
            $enabledResult = $editor->applySetBreakpointEnabledOperation(
                'hero',
                null,
                false,
                false,
                null,
                true,
                $firstBreakpointName,
            );

            $this->assertTrue(($toggleResult['persisted'] ?? false) === true);
            $this->assertTrue(($enabledResult['persisted'] ?? false) === true);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $variant = $sets['hero']['variants'][$firstBreakpointName] ?? [];
            $this->assertSame('width', $variant['autoDimension'] ?? null);
            $this->assertFalse(($variant['enabled'] ?? true) === true);
        });
    }

    public function testApplySetDimensionsOperationPersistsWhenOtherEnabledBreakpointsAreEmpty(): void
    {
        $plugin = Plugin::getInstance();
        $editor = $plugin->getTransformEditor();
        $configService = $plugin->getConfigService();
        $breakpointNames = $configService->getBreakpointKeys(false);
        $firstBreakpointName = (string)($breakpointNames[0] ?? '');
        $secondBreakpointName = (string)($breakpointNames[1] ?? '');
        $variants = [];

        foreach ($breakpointNames as $breakpointName) {
            $variants[$breakpointName] = [
                'width' => null,
                'height' => null,
                'enabled' => true,
                'autoDimension' => null,
            ];
        }

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => $variants,
                'config' => [],
            ],
        ], function () use ($editor, $firstBreakpointName, $secondBreakpointName): void {
            $result = $editor->applySetDimensionsOperation(
                'hero',
                'breakpoint',
                null,
                321,
                654,
                false,
                false,
                false,
                false,
                null,
                $firstBreakpointName,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);
            $this->assertFalse(($result['validation']['hasErrors'] ?? true) === true);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $variants = $sets['hero']['variants'] ?? [];
            $this->assertSame(321, $variants[$firstBreakpointName]['width'] ?? null);
            $this->assertSame(654, $variants[$firstBreakpointName]['height'] ?? null);
            $this->assertNull($variants[$secondBreakpointName]['width'] ?? null);
            $this->assertNull($variants[$secondBreakpointName]['height'] ?? null);
        });
    }

    public function testDeleteSetOperationRemovesOnlyRequestedSet(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
            'alpha' => [
                'name' => 'alpha',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 480, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($editor): void {
            $result = $editor->deleteSetOperation('hero');

            $this->assertTrue(($result['persisted'] ?? false) === true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? true) === false);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertArrayNotHasKey('hero', $sets);
            $this->assertArrayHasKey('alpha', $sets);
        });
    }

    public function testApplyDraftPersistsWhenProvidedValidDraftFromStore(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();
        $draft = $editor->buildDraftFromStore();

        $result = $editor->applyDraft($draft);

        $this->assertTrue(($result['persisted'] ?? false) === true);
        $this->assertTrue(($result['validation']['hasErrors'] ?? true) === false);
        $this->assertIsArray($result['draft'] ?? null);
        $this->assertArrayHasKey('transforms', $result['draft']);
    }

    public function testRenderResultReviewDoesNotRenderMismatchWarningWhenRenderedDimensionsDiffer(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withReviewFixtureSets(fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [
                    [
                        'assetId' => '100',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 600, 'height' => 340],
                        'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                    ],
                    [
                        'assetId' => '101',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 580, 'height' => 340],
                        'transformDimensions' => ['width' => 580, 'height' => 340, 'autoDimension' => null],
                    ],
                ],
            ],
        ]));

        $html = (string)($result['visualResultsHtml'] ?? '');
        $this->assertStringNotContainsString('bpts-transform-stats-warning', $html);

        $xpath = $this->createReviewMarkupXPath($html);
        $assetPages = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpts-transform-asset-page ')]");
        $this->assertNotFalse($assetPages);
        $this->assertSame(2, $assetPages->length);
    }

    public function testRenderResultReviewIgnoresAutoWidthForProcessedMismatchClasses(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => null, 'height' => 340, 'enabled' => true, 'autoDimension' => 'width'],
                ],
                'config' => [],
            ],
            'alpha' => [
                'name' => 'alpha',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [
                    [
                        'assetId' => '100',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 600, 'height' => 340],
                        'transformDimensions' => ['width' => null, 'height' => 340, 'autoDimension' => 'width'],
                    ],
                    [
                        'assetId' => '101',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 560, 'height' => 340],
                        'transformDimensions' => ['width' => null, 'height' => 340, 'autoDimension' => 'width'],
                    ],
                ],
            ],
        ]));

        $html = (string)($result['visualResultsHtml'] ?? '');
        $this->assertStringContainsString('breakpointColumnMismatchClass&quot;:&quot;0&quot;', $html);
        $this->assertStringNotContainsString('bpts-transform-asset-page-mismatch', $html);

        $xpath = $this->createReviewMarkupXPath($html);
        $applyButtons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpts-rendered-apply-single ')]");
        $this->assertNotFalse($applyButtons);
        $this->assertGreaterThan(0, $applyButtons->length);
    }

    public function testRenderResultReviewMarksRenderedApplySingleNoopWhenDimensionsAlreadyMatch(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => 600, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
            'alpha' => [
                'name' => 'alpha',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [
                    [
                        'assetId' => '100',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 600, 'height' => 340],
                        'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                    ],
                ],
            ],
        ]));

        $xpath = $this->createReviewMarkupXPath((string)($result['visualResultsHtml'] ?? ''));
        $applyButtons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpts-rendered-apply-single ') and @data-bpts-action='renderedValues']");
        $this->assertNotFalse($applyButtons);
        $this->assertSame(1, $applyButtons->length);
    }

    public function testBuildLatestRunHealthByTransformIgnoresAutoWidthMismatch(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $health = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => null, 'height' => 340, 'enabled' => true, 'autoDimension' => 'width'],
                ],
                'config' => [],
            ],
        ], fn() => $editor->buildLatestRunHealthByTransform([
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
                [
                    'transformHandle' => 'hero',
                    'slotKey' => 'xs',
                    'slotIndex' => 1,
                    'breakpointWidth' => 640,
                    'assetId' => '101',
                    'renderedWidth' => 560,
                    'renderedHeight' => 340,
                    'rowStatus' => 'loaded',
                ],
            ],
        ]));

        $this->assertArrayHasKey('hero', $health);
        $this->assertFalse(($health['hero']['hasAssetMismatch'] ?? true) === true);
        $this->assertFalse(($health['hero']['hasBreakpointMismatch'] ?? true) === true);
        $this->assertSame(0, (int)($health['hero']['assetMismatchBreakpointCount'] ?? -1));
        $rows = $health['hero']['breakpointRows'] ?? [];
        $this->assertIsArray($rows);
        $this->assertSame('Matching', (string)($rows[0]['assetMismatchLabel'] ?? ''));
    }

    public function testRenderResultReviewRendersMissingDefinitionWarningWithinTransformCard(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withReviewFixtureSets(fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [
                    [
                        'assetId' => '100',
                        'transform' => 'missing-manifest-set',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 600, 'height' => 340],
                        'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                    ],
                ],
            ],
        ]));

        $this->assertSame('', $result['warningsHtml'] ?? null);
        $this->assertSame(1, $result['warningCount'] ?? null);
        $this->assertReviewWarningMarkup(
            (string)($result['visualResultsHtml'] ?? ''),
            'Transform Set Missing'
        );
    }

    public function testRenderResultReviewPlacesWarningCardsBeforeNonWarningCards(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withReviewFixtureSets(fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [
                    [
                        'assetId' => '100',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 600, 'height' => 340],
                        'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                    ],
                    [
                        'assetId' => '101',
                        'transform' => 'missing-manifest-set',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 580, 'height' => 320],
                        'transformDimensions' => ['width' => 580, 'height' => 320, 'autoDimension' => null],
                    ],
                ],
            ],
        ]));

        $this->assertReviewTransformOrder(
            (string)($result['visualResultsHtml'] ?? ''),
            ['missing-manifest-set', 'hero']
        );
    }

    public function testRenderResultReviewPreservesPreferredOrderWhenWarningsAreResolved(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withReviewFixtureSets(fn() => $editor->renderResultReview(
            [
                'breakpoints' => [640],
                'rowsByBreakpoint' => [
                    640 => [
                        [
                            'assetId' => '100',
                            'transform' => 'hero',
                            'enabled' => true,
                            'isVisible' => true,
                            'loaded' => true,
                            'rendered' => ['width' => 600, 'height' => 340],
                            'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                        ],
                        [
                            'assetId' => '101',
                            'transform' => 'alpha',
                            'enabled' => true,
                            'isVisible' => true,
                            'loaded' => true,
                            'rendered' => ['width' => 580, 'height' => 320],
                            'transformDimensions' => ['width' => 580, 'height' => 320, 'autoDimension' => null],
                        ],
                    ],
                ],
            ],
            [],
            [],
            ['hero', 'alpha']
        ));

        $this->assertReviewTransformOrder(
            (string)($result['visualResultsHtml'] ?? ''),
            ['alpha', 'hero']
        );
    }

    public function testRenderResultReviewKeepsWarningsAtTopEvenWithPreferredOrder(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withReviewFixtureSets(fn() => $editor->renderResultReview(
            [
                'breakpoints' => [640],
                'rowsByBreakpoint' => [
                    640 => [
                        [
                            'assetId' => '100',
                            'transform' => 'hero',
                            'enabled' => true,
                            'isVisible' => true,
                            'loaded' => true,
                            'rendered' => ['width' => 600, 'height' => 340],
                            'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                        ],
                        [
                            'assetId' => '101',
                            'transform' => 'missing-manifest-set',
                            'enabled' => true,
                            'isVisible' => true,
                            'loaded' => true,
                            'rendered' => ['width' => 580, 'height' => 320],
                            'transformDimensions' => ['width' => 580, 'height' => 320, 'autoDimension' => null],
                        ],
                    ],
                ],
            ],
            [],
            [],
            ['hero', 'missing-manifest-set']
        ));

        $this->assertReviewTransformOrder(
            (string)($result['visualResultsHtml'] ?? ''),
            ['missing-manifest-set', 'hero']
        );
    }

    public function testRenderResultReviewNormalizesInvalidSelectedAssetKeyToFirstAvailableAsset(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withReviewFixtureSets(fn() => $editor->renderResultReview(
            [
                'breakpoints' => [640],
                'rowsByBreakpoint' => [
                    640 => [
                        [
                            'assetId' => '100',
                            'transform' => 'hero',
                            'enabled' => true,
                            'isVisible' => true,
                            'loaded' => true,
                            'rendered' => ['width' => 600, 'height' => 340],
                            'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                        ],
                        [
                            'assetId' => '101',
                            'transform' => 'hero',
                            'enabled' => true,
                            'isVisible' => true,
                            'loaded' => true,
                            'rendered' => ['width' => 610, 'height' => 350],
                            'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                        ],
                    ],
                ],
            ],
            [],
            [],
            ['hero' => 'does-not-exist']
        ));

        $normalized = is_array($result['selectedAssetKeyBySet'] ?? null)
            ? $result['selectedAssetKeyBySet']
            : [];

        $this->assertSame('asset:hero:100', $normalized['hero'] ?? null);
    }

    public function testRenderResultReviewKeepsSelectedAssetKeyWhenItExists(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withReviewFixtureSets(fn() => $editor->renderResultReview(
            [
                'breakpoints' => [640],
                'rowsByBreakpoint' => [
                    640 => [
                        [
                            'assetId' => '100',
                            'transform' => 'hero',
                            'enabled' => true,
                            'isVisible' => true,
                            'loaded' => true,
                            'rendered' => ['width' => 600, 'height' => 340],
                            'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                        ],
                        [
                            'assetId' => '101',
                            'transform' => 'hero',
                            'enabled' => true,
                            'isVisible' => true,
                            'loaded' => true,
                            'rendered' => ['width' => 610, 'height' => 350],
                            'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                        ],
                    ],
                ],
            ],
            [],
            [],
            ['hero' => 'asset:hero:101']
        ));

        $normalized = is_array($result['selectedAssetKeyBySet'] ?? null)
            ? $result['selectedAssetKeyBySet']
            : [];

        $this->assertSame('asset:hero:101', $normalized['hero'] ?? null);
    }

    public function testRenderInitialStoredReviewRendersCardsAndHidesRenderedApplyAll(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderInitialStoredReview());

        $this->assertSame('', $result['warningsHtml'] ?? null);
        $this->assertSame(0, $result['warningCount'] ?? null);

        $xpath = $this->createReviewMarkupXPath((string)($result['visualResultsHtml'] ?? ''));
        $cards = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-transform-card ') and @data-set='hero']");
        $this->assertNotFalse($cards);
        $this->assertSame(1, $cards->length);

        $hiddenApplyButtons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpts-rendered-apply-all ') and contains(concat(' ', normalize-space(@class), ' '), ' bpts-force-hidden ')]");
        $this->assertNotFalse($hiddenApplyButtons);
        $this->assertSame(1, $hiddenApplyButtons->length);

        $this->assertStringContainsString('breakpointRenderedApplyHiddenClass&quot;:&quot;1&quot;', (string)($result['visualResultsHtml'] ?? ''));
    }

    public function testRenderInitialStoredReviewUsesEmptyStateWhenNoStoredTransformsExist(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([], fn() => $editor->renderInitialStoredReview());

        $this->assertStringContainsString('No transform sets found in results.', (string)($result['visualResultsHtml'] ?? ''));
    }

    public function testRenderInitialStoredReviewOmitsEscapeBreakpointForTransformsWithoutEscapeWidth(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => null, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderInitialStoredReview());

        $xpath = $this->createReviewMarkupXPath((string)($result['visualResultsHtml'] ?? ''));
        $escapeColumns = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-transform-card ') and @data-set='hero']//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-breakpoint-column ') and @data-breakpoint='1920']");
        $this->assertNotFalse($escapeColumns);
        $this->assertSame(0, $escapeColumns->length);
    }

    public function testRenderInitialStoredReviewUsesDeterministicPlaceholderDimensionsForMissingOrAutoDimensions(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => null, 'height' => null, 'enabled' => true, 'autoDimension' => 'width'],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderInitialStoredReview());

        $this->assertStringContainsString('data:image/svg+xml,', (string)($result['visualResultsHtml'] ?? ''));
        $this->assertStringContainsString('width%3D%221200%22', (string)($result['visualResultsHtml'] ?? ''));
        $this->assertStringContainsString('height%3D%22800%22', (string)($result['visualResultsHtml'] ?? ''));
    }

    public function testRenderInitialStoredReviewUsesSavedSnapshotAssetUrlForCardPreview(): void
    {
        $plugin = Plugin::getInstance();
        $previousTelemetry = $plugin->get('telemetry');

        $plugin->set('telemetry', new class() extends TelemetryService {
            public function getLatestRunSnapshot(): ?array
            {
                return [
                    'runStatus' => 'completed',
                    'ranAt' => '2026-04-16 10:20:30',
                    'durationMs' => 4321,
                    'entryId' => 123,
                    'rows' => [
                        [
                            'transformHandle' => 'hero',
                            'slotKey' => 'xs',
                            'slotIndex' => 1,
                            'breakpointWidth' => 640,
                            'displayAssetUrl' => 'https://example.test/saved-preview.jpg',
                            'rowStatus' => 'unprocessed',
                        ],
                    ],
                ];
            }
        });

        try {
            $editor = $plugin->getTransformEditor();
            $result = $this->withRuntimeSets([
                'hero' => [
                    'name' => 'hero',
                    'includeEscapeWidth' => false,
                    'variants' => [
                        'sm' => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                    ],
                    'config' => [],
                ],
            ], fn() => $editor->renderInitialStoredReview());

            $this->assertStringContainsString('data-set="hero"', (string)($result['visualResultsHtml'] ?? ''));
            $this->assertStringContainsString('bpts-transform-last-process-pane', (string)($result['visualResultsHtml'] ?? ''));
            $this->assertStringContainsString('bpts-transform-last-process-status-icon-success', (string)($result['visualResultsHtml'] ?? ''));
            $this->assertStringContainsString('data-icon="check"', (string)($result['visualResultsHtml'] ?? ''));
            $this->assertStringContainsString('data-bpts-process-again="true"', (string)($result['visualResultsHtml'] ?? ''));
            $this->assertStringContainsString('data-entry-id="123"', (string)($result['visualResultsHtml'] ?? ''));
            $this->assertStringContainsString('2026-04-16 10:20:30', (string)($result['visualResultsHtml'] ?? ''));
            $this->assertStringNotContainsString('4321 ms', (string)($result['visualResultsHtml'] ?? ''));
            $this->assertStringNotContainsString('Saved breakpoints', (string)($result['visualResultsHtml'] ?? ''));
            $this->assertStringNotContainsString('<dt>Status</dt>', (string)($result['visualResultsHtml'] ?? ''));
        } finally {
            $plugin->set('telemetry', $previousTelemetry);
        }
    }

    public function testRenderResultReviewKeepsRenderedApplyAllVisibleByDefault(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withReviewFixtureSets(fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [
                    [
                        'assetId' => '100',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 600, 'height' => 340],
                        'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                    ],
                ],
            ],
        ]));

        $xpath = $this->createReviewMarkupXPath((string)($result['visualResultsHtml'] ?? ''));
        $visibleApplyButtons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpts-rendered-apply-all ') and not(contains(concat(' ', normalize-space(@class), ' '), ' bpts-force-hidden '))]");
        $this->assertNotFalse($visibleApplyButtons);
        $this->assertSame(1, $visibleApplyButtons->length);
    }

    public function testRenderResultReviewDoesNotRenderDummyPreviewHolderForDisabledBreakpoint(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 600, 'height' => 340, 'enabled' => false, 'autoDimension' => null],
                ],
                'config' => [],
            ],
            'alpha' => [
                'name' => 'alpha',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [
                    [
                        'assetId' => '100',
                        'transform' => 'hero',
                        'enabled' => false,
                        'isVisible' => false,
                        'loaded' => false,
                        'src' => 'https://example.test/disabled-preview.jpg',
                        'rendered' => ['width' => 0, 'height' => 0],
                        'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                    ],
                ],
            ],
        ]));

        $xpath = $this->createReviewMarkupXPath((string)($result['visualResultsHtml'] ?? ''));
        $breakpointColumns = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-breakpoint-column ') and @data-breakpoint='640']");
        $this->assertNotFalse($breakpointColumns);
        $this->assertSame(1, $breakpointColumns->length);

        $dummyHolders = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-breakpoint-column ') and @data-breakpoint='640']//*[contains(concat(' ', normalize-space(@class), ' '), ' bpi_breakpoint-result-image ')]");
        $this->assertNotFalse($dummyHolders);
        $this->assertSame(0, $dummyHolders->length);
    }

    public function testApplySetPassHeightWhenRenderedLteSavedOperationPersistsConfigWithoutMutatingVariants(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($editor): void {
            $beforeSm = Plugin::getInstance()->getTransformStore()->getSets()['hero']['variants']['sm'] ?? [];

            $result = $editor->applySetPassHeightWhenRenderedLteSavedOperation(
                'hero',
                true,
                false,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? true) === false);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertTrue((($sets['hero']['config']['passHeightWhenRenderedLteSaved'] ?? false) === true));
            $this->assertSame(
                $this->extractVariantCoreFields($beforeSm),
                $this->extractVariantCoreFields($sets['hero']['variants']['sm'] ?? []),
            );
        });
    }

    public function testApplySetPassHeightWhenRenderedLteSavedOperationTreatsNonBooleanValuesAsDisabled(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($editor): void {
            $result = $editor->applySetPassHeightWhenRenderedLteSavedOperation(
                'hero',
                'true',
                false,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? true) === false);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertFalse((($sets['hero']['config']['passHeightWhenRenderedLteSaved'] ?? true) === true));
        });
    }

    public function testApplySetAllowAnyHeightOperationPersistsConfigWithoutMutatingVariants(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($editor): void {
            $beforeSm = Plugin::getInstance()->getTransformStore()->getSets()['hero']['variants']['sm'] ?? [];

            $result = $editor->applySetAllowAnyHeightOperation(
                'hero',
                true,
                false,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? true) === false);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertTrue((($sets['hero']['config']['allowAnyHeight'] ?? false) === true));
            $this->assertSame(
                $this->extractVariantCoreFields($beforeSm),
                $this->extractVariantCoreFields($sets['hero']['variants']['sm'] ?? []),
            );
        });
    }

    public function testApplySetAllowAnyHeightOperationTreatsNonBooleanValuesAsDisabled(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($editor): void {
            $result = $editor->applySetAllowAnyHeightOperation(
                'hero',
                'true',
                false,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? true) === false);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertFalse((($sets['hero']['config']['allowAnyHeight'] ?? true) === true));
        });
    }

    public function testEnablingAllowAnyHeightClearsPassHeightWhenRenderedLteSaved(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['passHeightWhenRenderedLteSaved' => true],
            ],
        ], function () use ($editor): void {
            $result = $editor->applySetAllowAnyHeightOperation(
                'hero',
                true,
                false,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertTrue((($sets['hero']['config']['allowAnyHeight'] ?? false) === true));
            $this->assertFalse((($sets['hero']['config']['passHeightWhenRenderedLteSaved'] ?? true) === true));
        });
    }

    public function testEnablingPassHeightWhenRenderedLteSavedClearsAllowAnyHeight(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['allowAnyHeight' => true],
            ],
        ], function () use ($editor): void {
            $result = $editor->applySetPassHeightWhenRenderedLteSavedOperation(
                'hero',
                true,
                false,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertTrue((($sets['hero']['config']['passHeightWhenRenderedLteSaved'] ?? false) === true));
            $this->assertFalse((($sets['hero']['config']['allowAnyHeight'] ?? true) === true));
        });
    }

    public function testDisablingAllowAnyHeightDoesNotTouchPassHeightWhenRenderedLteSaved(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['passHeightWhenRenderedLteSaved' => true, 'allowAnyHeight' => false],
            ],
        ], function () use ($editor): void {
            $result = $editor->applySetAllowAnyHeightOperation(
                'hero',
                false,
                false,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertTrue((($sets['hero']['config']['passHeightWhenRenderedLteSaved'] ?? false) === true));
            $this->assertFalse((($sets['hero']['config']['allowAnyHeight'] ?? true) === true));
        });
    }

    public function testApplySetNotesOperationPersistsNotesAndPreservesSetDefinition(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['allowAnyHeight' => true],
            ],
        ], function () use ($editor): void {
            $result = $editor->applySetNotesOperation(
                'hero',
                "  Campaign hero\r\nCheck crop after launch.  ",
                false,
            );

            $this->assertTrue(($result['persisted'] ?? false) === true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? true) === false);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertSame("Campaign hero\nCheck crop after launch.", $sets['hero']['notes'] ?? null);
            $this->assertTrue((($sets['hero']['config']['allowAnyHeight'] ?? false) === true));
            $this->assertSame(640, $sets['hero']['variants']['sm']['width'] ?? null);
        });
    }

    public function testApplySetNotesOperationRejectsLongNotes(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'notes' => 'Original',
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function () use ($editor): void {
            $result = $editor->applySetNotesOperation(
                'hero',
                str_repeat('x', 4001),
                false,
            );

            $this->assertFalse(($result['persisted'] ?? true) === true);
            $this->assertTrue(($result['validation']['hasErrors'] ?? false) === true);

            $sets = Plugin::getInstance()->getTransformStore()->getSets();
            $this->assertSame('Original', $sets['hero']['notes'] ?? null);
        });
    }

    public function testBuildLatestRunHealthByTransformSuppressesHeightMismatchWhenRenderedHeightLteSaved(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $health = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => 600, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['passHeightWhenRenderedLteSaved' => true],
            ],
        ], fn() => $editor->buildLatestRunHealthByTransform([
            'rowsPayload' => [
                [
                    'transformHandle' => 'hero',
                    'slotKey' => 'xs',
                    'slotIndex' => 1,
                    'breakpointWidth' => 640,
                    'assetId' => '100',
                    'renderedWidth' => 600,
                    'renderedHeight' => 336,
                    'rowStatus' => 'loaded',
                ],
                [
                    'transformHandle' => 'hero',
                    'slotKey' => 'xs',
                    'slotIndex' => 1,
                    'breakpointWidth' => 640,
                    'assetId' => '101',
                    'renderedWidth' => 600,
                    'renderedHeight' => 330,
                    'rowStatus' => 'loaded',
                ],
            ],
        ]));

        $this->assertArrayHasKey('hero', $health);
        $this->assertFalse(($health['hero']['hasAssetMismatch'] ?? true) === true);
        $this->assertFalse(($health['hero']['hasBreakpointMismatch'] ?? true) === true);
        $rows = $health['hero']['breakpointRows'] ?? [];
        $this->assertIsArray($rows);
        $this->assertSame('Matching', (string)($rows[0]['assetMismatchLabel'] ?? ''));
    }

    public function testBuildLatestRunHealthByTransformKeepsHeightMismatchWhenRenderedHeightExceedsSaved(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $health = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => 600, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['passHeightWhenRenderedLteSaved' => true],
            ],
        ], fn() => $editor->buildLatestRunHealthByTransform([
            'rowsPayload' => [
                [
                    'transformHandle' => 'hero',
                    'slotKey' => 'xs',
                    'slotIndex' => 1,
                    'breakpointWidth' => 640,
                    'assetId' => '100',
                    'renderedWidth' => 600,
                    'renderedHeight' => 330,
                    'rowStatus' => 'loaded',
                ],
                [
                    'transformHandle' => 'hero',
                    'slotKey' => 'xs',
                    'slotIndex' => 1,
                    'breakpointWidth' => 640,
                    'assetId' => '101',
                    'renderedWidth' => 600,
                    'renderedHeight' => 350,
                    'rowStatus' => 'loaded',
                ],
            ],
        ]));

        $this->assertArrayHasKey('hero', $health);
        $this->assertTrue(($health['hero']['hasAssetMismatch'] ?? false) === true);
        $rows = $health['hero']['breakpointRows'] ?? [];
        $this->assertIsArray($rows);
        $this->assertSame('Mismatch', (string)($rows[0]['assetMismatchLabel'] ?? ''));
    }

    public function testBuildLatestRunHealthByTransformKeepsStatusMismatchBehaviorWhenHeightWaiverApplies(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $health = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => 600, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['passHeightWhenRenderedLteSaved' => true],
            ],
        ], fn() => $editor->buildLatestRunHealthByTransform([
            'rowsPayload' => [
                [
                    'transformHandle' => 'hero',
                    'slotKey' => 'xs',
                    'slotIndex' => 1,
                    'breakpointWidth' => 640,
                    'assetId' => '100',
                    'renderedWidth' => 600,
                    'renderedHeight' => 336,
                    'rowStatus' => 'broken',
                ],
                [
                    'transformHandle' => 'hero',
                    'slotKey' => 'xs',
                    'slotIndex' => 1,
                    'breakpointWidth' => 640,
                    'assetId' => '101',
                    'renderedWidth' => 600,
                    'renderedHeight' => 330,
                    'rowStatus' => 'loaded',
                ],
            ],
        ]));

        $this->assertArrayHasKey('hero', $health);
        $this->assertTrue(($health['hero']['hasAssetMismatch'] ?? false) === true);
        $rows = $health['hero']['breakpointRows'] ?? [];
        $this->assertIsArray($rows);
        $this->assertSame('Mismatch', (string)($rows[0]['assetMismatchLabel'] ?? ''));
        $this->assertStringContainsString('status broken', (string)($rows[0]['assetMismatchInfo'] ?? ''));
    }

    public function testRenderResultReviewAppliesHeightWaiverToBreakpointAndAssetMismatchMarkers(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'xs' => ['width' => 600, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['passHeightWhenRenderedLteSaved' => true],
            ],
        ], fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [
                    [
                        'assetId' => '100',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 600, 'height' => 336],
                        'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                    ],
                    [
                        'assetId' => '101',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 600, 'height' => 330],
                        'transformDimensions' => ['width' => 600, 'height' => 340, 'autoDimension' => null],
                    ],
                ],
            ],
        ]));

        $html = (string)($result['visualResultsHtml'] ?? '');
        $this->assertStringContainsString('breakpointColumnMismatchClass&quot;:&quot;0&quot;', $html);
        $this->assertStringNotContainsString('bpts-transform-asset-page-mismatch', $html);
    }

    public function testRenderResultReviewRendersPassHeightIndicatorWhenEnabledAndHidesWhenDisabled(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $enabledResult = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['passHeightWhenRenderedLteSaved' => true],
            ],
        ], fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [[
                    'assetId' => '100',
                    'transform' => 'hero',
                    'enabled' => true,
                    'isVisible' => true,
                    'loaded' => true,
                    'rendered' => ['width' => 640, 'height' => 340],
                    'transformDimensions' => ['width' => 640, 'height' => 340, 'autoDimension' => null],
                ]],
            ],
        ]));

        $enabledHtml = (string)($enabledResult['visualResultsHtml'] ?? '');
        $this->assertStringContainsString('bpts-transform-pass-height-indicator', $enabledHtml);
        $this->assertStringNotContainsString('bpts-transform-pass-height-indicator bpts-force-hidden', $enabledHtml);

        $disabledResult = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => ['passHeightWhenRenderedLteSaved' => false],
            ],
        ], fn() => $editor->renderResultReview([
            'breakpoints' => [640],
            'rowsByBreakpoint' => [
                640 => [[
                    'assetId' => '100',
                    'transform' => 'hero',
                    'enabled' => true,
                    'isVisible' => true,
                    'loaded' => true,
                    'rendered' => ['width' => 640, 'height' => 340],
                    'transformDimensions' => ['width' => 640, 'height' => 340, 'autoDimension' => null],
                ]],
            ],
        ]));

        $disabledHtml = (string)($disabledResult['visualResultsHtml'] ?? '');
        $this->assertStringContainsString('bpts-transform-pass-height-indicator bpts-force-hidden', $disabledHtml);
    }

    public function testRenderResultReviewAcceptsSettingsTabFromEditTabState(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withReviewFixtureSets(fn() => $editor->renderResultReview(
            [
                'breakpoints' => [640],
                'rowsByBreakpoint' => [
                    640 => [[
                        'assetId' => '100',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 640, 'height' => 340],
                        'transformDimensions' => ['width' => 640, 'height' => 340, 'autoDimension' => null],
                    ]],
                ],
            ],
            [],
            ['hero' => 'settings'],
        ));

        $this->assertStringContainsString('data-set="hero"', (string)($result['visualResultsHtml'] ?? ''));
        $this->assertStringContainsString('&quot;activeTab&quot;:&quot;settings&quot;', (string)($result['visualResultsHtml'] ?? ''));
        $this->assertStringContainsString('data-attr:data-active-tab=', (string)($result['visualResultsHtml'] ?? ''));
        $this->assertStringContainsString('bpts-edit-panel-hero-tab-settings" type="button" role="tab" class="bpts-transform-tab active"', (string)($result['visualResultsHtml'] ?? ''));
    }

    public function testRenderResultReviewAcceptsNotesTabAndEscapesNotes(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'notes' => 'Needs <crop> review',
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 340, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderResultReview(
            [
                'breakpoints' => [640],
                'rowsByBreakpoint' => [
                    640 => [[
                        'assetId' => '100',
                        'transform' => 'hero',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => true,
                        'rendered' => ['width' => 640, 'height' => 340],
                        'transformDimensions' => ['width' => 640, 'height' => 340, 'autoDimension' => null],
                    ]],
                ],
            ],
            ['hero' => ['mode' => 'breakpoint', 'breakpoint' => 640]],
            ['hero' => 'notes'],
        ));

        $html = (string)($result['visualResultsHtml'] ?? '');
        $this->assertStringContainsString('&quot;activeTab&quot;:&quot;notes&quot;', $html);
        $this->assertStringContainsString('bpts-edit-panel-hero-tab-notes" type="button" role="tab" class="bpts-transform-tab active"', $html);
        $this->assertStringContainsString('operation: \'set.notes.update\'', $html);
        $this->assertStringContainsString('Needs &lt;crop&gt; review', $html);
        $this->assertStringContainsString('bpts-transform-note-toggle', $html);
        $this->assertStringContainsString('bpts-transform-note-toggle-svg', $html);
        $this->assertStringContainsString("activeTab = 'notes'", $html);
        $this->assertStringContainsString('notesVisible', $html);
    }

    public function testRenderInitialStoredReviewRendersRatioOverlayAndMarksDerivedCurrentDimension(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => [
                        'width' => 640,
                        'height' => 360,
                        'enabled' => true,
                        'autoDimension' => null,
                        'ratioWidth' => 16,
                        'ratioHeight' => 9,
                        'ratioSourceDimension' => 'width',
                        'ratioLocked' => true,
                    ],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderInitialStoredReview());

        $html = (string)($result['visualResultsHtml'] ?? '');
        $this->assertStringContainsString('bpi_current-ratio-overlay', $html);

        $this->assertStringContainsString('currentHeightDerivedClass&quot;:&quot;1&quot;', $html);
    }

    public function testRenderInitialStoredReviewDoesNotMarkDerivedDimensionWhenAutoIsActive(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => [
                        'width' => null,
                        'height' => 360,
                        'enabled' => true,
                        'autoDimension' => 'width',
                        'ratioWidth' => 16,
                        'ratioHeight' => 9,
                        'ratioSourceDimension' => 'width',
                        'ratioLocked' => true,
                    ],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderInitialStoredReview());

        $xpath = $this->createReviewMarkupXPath((string)($result['visualResultsHtml'] ?? ''));

        $derivedDims = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-breakpoint-column ') and @data-breakpoint='640']//*[contains(concat(' ', normalize-space(@class), ' '), ' bpi_current-dimension-derived ')]");
        $this->assertNotFalse($derivedDims);
        $this->assertSame(0, $derivedDims->length);
    }

    public function testRenderInitialStoredReviewIncludesDimensionsActionOnAutoToggles(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $result = $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => 360, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], fn() => $editor->renderInitialStoredReview());

        $xpath = $this->createReviewMarkupXPath((string)($result['visualResultsHtml'] ?? ''));

        $autoToggles = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpts-transform-auto-toggle ') and @data-bpts-action='dimensions']");
        $this->assertNotFalse($autoToggles);
        $this->assertSame(2, $autoToggles->length);

        $widthToggle = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpts-transform-auto-toggle ') and @data-bpts-action='dimensions' and @aria-label='Toggle auto width']");
        $this->assertNotFalse($widthToggle);
        $this->assertSame(1, $widthToggle->length);

        $heightToggle = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpts-transform-auto-toggle ') and @data-bpts-action='dimensions' and @aria-label='Toggle auto height']");
        $this->assertNotFalse($heightToggle);
        $this->assertSame(1, $heightToggle->length);
    }

    public function testRenderInitialStoredReviewSeedsInitWidthAndHeightForUnsavedSetFromTelemetry(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $previousTelemetry = Plugin::getInstance()->getTelemetry();
        Plugin::getInstance()->set('telemetry', new class() extends TelemetryService {
            public function canEditTransforms(): bool
            {
                return true;
            }
            public function getMostRecentByHandle(): array
            {
                return [
                    'hero' => [
                        'handle' => 'hero',
                        'entryId' => null,
                        'sourceUrl' => null,
                        'lastSeenAt' => '',
                        'initWidth' => 320,
                        'initHeight' => 180,
                        'initRatio' => null,
                        'initWidthAuto' => false,
                        'initHeightAuto' => false,
                    ],
                ];
            }
            public function getObservedUnsavedHandles(array $configuredHandles): array
            {
                return array_values($this->getMostRecentByHandle());
            }
        });

        try {
            $result = $this->withRuntimeSets([], fn() => $editor->renderInitialStoredReview());
        } finally {
            Plugin::getInstance()->set('telemetry', $previousTelemetry);
        }

        $html = (string)($result['visualResultsHtml'] ?? '');
        $this->assertStringContainsString('&quot;widthInput&quot;:&quot;320&quot;', $html);
        $this->assertStringContainsString('&quot;heightInput&quot;:&quot;180&quot;', $html);
    }

    public function testRenderInitialStoredReviewSeedsInitAutoForUnsavedSetFromTelemetry(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();

        $previousTelemetry = Plugin::getInstance()->getTelemetry();
        Plugin::getInstance()->set('telemetry', new class() extends TelemetryService {
            public function canEditTransforms(): bool
            {
                return true;
            }
            public function getMostRecentByHandle(): array
            {
                return [
                    'hero' => [
                        'handle' => 'hero',
                        'entryId' => null,
                        'sourceUrl' => null,
                        'lastSeenAt' => '',
                        'initWidth' => null,
                        'initHeight' => 180,
                        'initRatio' => null,
                        'initWidthAuto' => true,
                        'initHeightAuto' => false,
                    ],
                ];
            }
            public function getObservedUnsavedHandles(array $configuredHandles): array
            {
                return array_values($this->getMostRecentByHandle());
            }
        });

        try {
            $result = $this->withRuntimeSets([], fn() => $editor->renderInitialStoredReview());
        } finally {
            Plugin::getInstance()->set('telemetry', $previousTelemetry);
        }

        $html = (string)($result['visualResultsHtml'] ?? '');
        $this->assertStringContainsString('&quot;widthAuto&quot;:&quot;1&quot;', $html);
        $this->assertStringContainsString('&quot;heightAuto&quot;:&quot;0&quot;', $html);
    }

    private function setEditorPlugin(TransformEditor $editor, ?Plugin $plugin): void
    {
        $property = new \ReflectionProperty($editor, '_plugin');
        $property->setValue($editor, $plugin);
    }

    private function withReviewFixtureSets(callable $callback): mixed
    {
        return $this->withRuntimeSets([
            'hero' => [
                'name' => 'hero',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
            'alpha' => [
                'name' => 'alpha',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], $callback);
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

    private function assertReviewWarningMarkup(string $html, string $expectedHeading): void
    {
        $xpath = $this->createReviewMarkupXPath($html);

        // The missing-set warning is rendered as a reactive pair: a visible danger item
        // and a hidden neutral "Process Again" notice that the setReviewState signal swaps
        // to after "Set to rendered". Both items live in the DOM at all times.
        $warningItems = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-transform-card-warnings ')]//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-warning-item ')]");
        $this->assertNotFalse($warningItems);
        $this->assertSame(2, $warningItems->length);

        $missingItem = $this->assertReactiveWarningItem($xpath, 'missing-set', false);
        $missingHeading = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-warning-heading ')]", $missingItem);
        $this->assertNotFalse($missingHeading);
        $this->assertSame($expectedHeading, trim((string)($missingHeading->item(0)?->textContent ?? '')));

        $processAgainItem = $this->assertReactiveWarningItem($xpath, 'process-again', true);
        $this->assertStringContainsString(
            'Process again to double check application.',
            (string)($processAgainItem->textContent ?? ''),
        );

        $applyButtons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpts-warning-apply-rendered ')]");
        $this->assertNotFalse($applyButtons);
        $this->assertSame(1, $applyButtons->length);
        $this->assertSame('Set to rendered', trim((string)($applyButtons->item(0)?->textContent ?? '')));
    }

    /**
     * Asserts a single reactive warning item exists with the expected initial hidden state.
     */
    private function assertReactiveWarningItem(\DOMXPath $xpath, string $marker, bool $expectHidden): \DOMElement
    {
        $items = $xpath->query("//*[@data-bpts-warning='" . $marker . "']");
        $this->assertNotFalse($items);
        $this->assertSame(1, $items->length, "Expected exactly one '{$marker}' warning item.");

        $item = $items->item(0);
        $this->assertInstanceOf(\DOMElement::class, $item);

        $class = ' ' . preg_replace('/\s+/', ' ', trim((string)$item->getAttribute('class'))) . ' ';
        if ($expectHidden) {
            $this->assertStringContainsString(' bpts-force-hidden ', $class, "'{$marker}' should start hidden.");
        } else {
            $this->assertStringNotContainsString(' bpts-force-hidden ', $class, "'{$marker}' should start visible.");
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private function extractVariantCoreFields(array $variant): array
    {
        return [
            'width' => $variant['width'] ?? null,
            'height' => $variant['height'] ?? null,
            'enabled' => $variant['enabled'] ?? null,
            'autoDimension' => $variant['autoDimension'] ?? null,
        ];
    }

    private function assertReviewTransformOrder(string $html, array $expectedOrder): void
    {
        $xpath = $this->createReviewMarkupXPath($html);

        $cards = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpts-transform-card ')]");
        $this->assertNotFalse($cards);

        $actualOrder = [];
        foreach ($cards as $card) {
            if (!$card instanceof \DOMElement) {
                continue;
            }

            $actualOrder[] = (string)$card->getAttribute('data-set');
        }

        $this->assertSame($expectedOrder, $actualOrder);
    }

    private function createReviewMarkupXPath(string $html): \DOMXPath
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
