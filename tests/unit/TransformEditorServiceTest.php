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

        $result = $editor->renderResultReview([
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
        ]);

        $this->assertStringContainsString('2 assets', (string)($result['visualResultsHtml'] ?? ''));
        $this->assertStringNotContainsString('bpi-transform-stats-warning', (string)($result['visualResultsHtml'] ?? ''));
    }

    private function setEditorPlugin(TransformEditor $editor, ?Plugin $plugin): void
    {
        $property = new \ReflectionProperty($editor, '_plugin');
        $property->setValue($editor, $plugin);
    }
}
