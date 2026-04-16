<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpointimages\Plugin;
use craftyhedge\craftbreakpointimages\services\TransformEditor;

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
            'scopeBreakpoint is required when scopeMode is breakpoint.',
            $result['validation']['global'] ?? []
        );
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

        $this->assertStringContainsString('2 assets', (string)($result['visualResultsHtml'] ?? ''));
        $this->assertStringNotContainsString('bpi-transform-stats-warning', (string)($result['visualResultsHtml'] ?? ''));
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
            ['hero', 'alpha']
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
        $cards = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpi-transform-card ') and @data-set='hero']");
        $this->assertNotFalse($cards);
        $this->assertSame(1, $cards->length);

        $hiddenApplyButtons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpi-rendered-apply-all ') and contains(concat(' ', normalize-space(@class), ' '), ' bpi-force-hidden ')]");
        $this->assertNotFalse($hiddenApplyButtons);
        $this->assertSame(1, $hiddenApplyButtons->length);

        $hiddenColumnButtons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpi-rendered-apply-single ') and contains(concat(' ', normalize-space(@class), ' '), ' bpi-force-hidden ')]");
        $this->assertNotFalse($hiddenColumnButtons);
        $this->assertGreaterThan(0, $hiddenColumnButtons->length);
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
        $escapeColumns = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpi-transform-card ') and @data-set='hero']//*[contains(concat(' ', normalize-space(@class), ' '), ' bpi-breakpoint-column ') and @data-breakpoint='1920']");
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
        $visibleApplyButtons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpi-rendered-apply-all ') and not(contains(concat(' ', normalize-space(@class), ' '), ' bpi-force-hidden '))]");
        $this->assertNotFalse($visibleApplyButtons);
        $this->assertSame(1, $visibleApplyButtons->length);
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
        $store = Plugin::getInstance()->getTransformStore();
        $previousSets = $store->getSets();
        $store->replaceSetsForRuntime($sets);

        try {
            return $callback();
        } finally {
            $store->replaceSetsForRuntime($previousSets);
        }
    }

    private function assertReviewWarningMarkup(string $html, string $expectedHeading): void
    {
        $xpath = $this->createReviewMarkupXPath($html);

        $warningItems = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpi-transform-card-warnings ')]//*[contains(concat(' ', normalize-space(@class), ' '), ' bpi-warning-item ')]");
        $this->assertNotFalse($warningItems);
        $this->assertSame(1, $warningItems->length);

        $warningHeading = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpi-warning-heading ')]");
        $this->assertNotFalse($warningHeading);
        $this->assertSame($expectedHeading, trim((string)($warningHeading->item(0)?->textContent ?? '')));

        $applyButtons = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' bpi-warning-apply-rendered ')]");
        $this->assertNotFalse($applyButtons);
        $this->assertSame(1, $applyButtons->length);
        $this->assertSame('Set to rendered', trim((string)($applyButtons->item(0)?->textContent ?? '')));
    }

    private function assertReviewTransformOrder(string $html, array $expectedOrder): void
    {
        $xpath = $this->createReviewMarkupXPath($html);

        $cards = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' bpi-transform-card ')]");
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
