<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpoints\controllers\TransformsController;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\TelemetryService;
use yii\web\Response;

final class TransformsControllerTest extends Unit
{
    protected function _before(): void
    {
        parent::_before();

        Plugin::getInstance()->set('telemetry', new class() extends TelemetryService {
            public function canEditTransforms(): bool
            {
                return true;
            }
        });
    }

    public function testEditorInitReturnsEventStreamPayloadAndNormalizesBaseVersion(): void
    {
        $controller = $this->controllerWithBody([
            'sessionId' => 'sess_test_init',
            'baseVersion' => 0,
            'resultSummaryAssetCount' => 2,
            'resultSummaryBreakpointCount' => 5,
            'resultSummaryWarningCount' => 1,
        ]);
        $response = $controller->actionEditorInit();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('text/event-stream', (string)$response->getHeaders()->get('Content-Type'));
        $this->assertStringContainsString('patch-signals', (string)$response->content);
        $this->assertStringContainsString('sess_test_init', (string)$response->content);
        $this->assertMatchesRegularExpression('/"baseVersion":"[^"]+"/', (string)$response->content);
        $this->assertStringContainsString('Draft initialized from current transform set configuration.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyReturnsErrorWhenDraftJsonIsMissingAndKeepsBaseVersion(): void
    {
        $controller = $this->controllerWithBody([
            'sessionId' => 'sess_test_missing',
            'baseVersion' => 3,
        ]);
        $response = $controller->actionApply();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('"baseVersion":"3"', (string)$response->content);
        $this->assertStringContainsString('Draft JSON is required.', (string)$response->content);
        $this->assertStringContainsString('Draft could not be applied.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyReturnsErrorWhenDraftJsonIsNotAnObject(): void
    {
        $response = $this->controllerWithBody([
            'sessionId' => 'sess_test_invalid',
            'baseVersion' => 3,
            'draftJson' => '"not-an-object"',
        ])->actionApply();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('Draft JSON must decode to an object.', (string)$response->content);
        $this->assertStringContainsString('Draft JSON is invalid.', (string)$response->content);
    }

    public function testApplyPersistsValidDraft(): void
    {
        $editor = Plugin::getInstance()->getTransformEditor();
        $validDraftJson = $editor->encodeDraftJson($editor->buildDraftFromStore());
        $baseVersion = Plugin::getInstance()->getTransformStore()->getCurrentVersion();

        $controller = $this->controllerWithBody([
            'sessionId' => 'sess_test_success',
            'baseVersion' => $baseVersion,
            'draftJson' => $validDraftJson,
        ]);
        $response = $controller->actionApply();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringNotContainsString('"baseVersion":"' . $baseVersion . '"', (string)$response->content);
        $this->assertStringContainsString('"kind":"success"', (string)$response->content);
        $this->assertStringContainsString('Draft applied and persisted to transform-sets.json.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRejectsUnknownOperation(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 5,
            'operation' => 'unexpected-operation',
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringNotContainsString('patch-signals', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('operation is required and must be a supported command.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRoutesBreakpointEnabledField(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'breakpoint.toggleEnabled',
            'setName' => '',
            'scopeBreakpoint' => 640,
            'enabled' => false,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('setName is required.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRejectsScopeSelectBreakpointWithoutBreakpoint(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'scope.selectBreakpoint',
            'setName' => 'hero',
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('scopeBreakpoint is required when selecting a breakpoint.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationScopeSelectAllReturnsCardPatchWithoutStatus(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'scope.selectAll',
            'setName' => 'hero',
            'editor' => [
                'cards' => [],
            ],
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringNotContainsString('bpts-editor-status', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRejectsNonBooleanNumericEnabledValue(): void
    {
        $breakpoints = Plugin::getInstance()->getConfigService()->getBreakpoints();
        unset($breakpoints['escape']);
        $firstBreakpointValue = (int)($breakpoints[(string)array_key_first($breakpoints)] ?? 0);

        $this->assertGreaterThan(0, $firstBreakpointValue);

        $controller = $this->controllerWithBody([
            'baseVersion' => 7,
            'operation' => 'breakpoint.toggleEnabled',
            'setName' => 'hero',
            'scopeBreakpoint' => $firstBreakpointValue,
            'enabled' => 2,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('enabled must be a boolean value.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationTogglesBreakpointEnabledWhenEnabledIsOmitted(): void
    {
        $breakpoints = Plugin::getInstance()->getConfigService()->getBreakpoints();
        unset($breakpoints['escape']);
        $firstBreakpointValue = (int)($breakpoints[(string)array_key_first($breakpoints)] ?? 0);

        $this->assertGreaterThan(0, $firstBreakpointValue);

        $controller = $this->controllerWithBody([
            'baseVersion' => Plugin::getInstance()->getTransformStore()->getCurrentVersion(),
            'operation' => 'breakpoint.toggleEnabled',
            'setName' => 'hero',
            'scopeBreakpoint' => $firstBreakpointValue,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringNotContainsString('enabled must be a boolean value.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationToggleEnabledPreservesRequestedBreakpointScope(): void
    {
        $breakpoints = Plugin::getInstance()->getConfigService()->getBreakpoints();
        unset($breakpoints['escape']);
        $firstBreakpointValue = (int)($breakpoints[(string)array_key_first($breakpoints)] ?? 0);

        $this->assertGreaterThan(0, $firstBreakpointValue);

        $signalKey = $this->buildSignalKey('hero');
        $controller = $this->controllerWithBody([
            'baseVersion' => Plugin::getInstance()->getTransformStore()->getCurrentVersion(),
            'operation' => 'breakpoint.toggleEnabled',
            'setName' => 'hero',
            'scopeBreakpoint' => $firstBreakpointValue,
            'editor' => [
                'cards' => [
                    $signalKey => [
                        'scopeMode' => 'breakpoint',
                        'scopeBreakpoint' => (string)$firstBreakpointValue,
                    ],
                ],
            ],
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringNotContainsString('scopeMode&quot;:&quot;all&quot;', (string)$response->content);
        $this->assertStringContainsString('scopeMode&quot;:&quot;breakpoint&quot;', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationToggleEnabledWithoutEditorSignalsUsesBreakpointScope(): void
    {
        $breakpoints = Plugin::getInstance()->getConfigService()->getBreakpoints();
        unset($breakpoints['escape']);
        $firstBreakpointValue = (int)($breakpoints[(string)array_key_first($breakpoints)] ?? 0);

        $this->assertGreaterThan(0, $firstBreakpointValue);

        $controller = $this->controllerWithBody([
            'baseVersion' => Plugin::getInstance()->getTransformStore()->getCurrentVersion(),
            'operation' => 'breakpoint.toggleEnabled',
            'setName' => 'hero',
            'scopeBreakpoint' => $firstBreakpointValue,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('scopeMode&quot;:&quot;breakpoint&quot;', (string)$response->content);
        $this->assertStringNotContainsString('scopeMode&quot;:&quot;all&quot;', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRoutesDimensionsToggleAutoWidthOperation(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'dimensions.toggleAutoWidth',
            'setName' => '',
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('setName is required.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRoutesDimensionsToggleAutoHeightOperation(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'dimensions.toggleAutoHeight',
            'setName' => '',
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('setName is required.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRatioCopyRequiresRatioSourceBreakpoint(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'ratio.copyFromRenderedBreakpoint',
            'setName' => 'hero',
            'renderedRows' => '[{"breakpoint":640,"width":320,"height":180}]',
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('ratioSourceBreakpoint is required.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRatioCopyPatchesSignalsFromRenderedRow(): void
    {
        $signalKey = $this->buildSignalKey('hero');
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'ratio.copyFromRenderedBreakpoint',
            'setName' => 'hero',
            'renderedRows' => '[{"breakpoint":640,"width":320,"height":180}]',
            'editor' => [
                'cards' => [
                    $signalKey => [
                        'ratioSourceBreakpoint' => '640',
                    ],
                ],
            ],
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('patch-signals', (string)$response->content);
        $this->assertStringContainsString('ratioWidthInput', (string)$response->content);
        $this->assertStringContainsString('320', (string)$response->content);
        $this->assertStringContainsString('180', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRejectsMalformedRenderedRowsForRenderedValuesApply(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'renderedValues.apply',
            'setName' => 'hero',
            'renderedRows' => '{bad-json}',
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('renderedRows payload is malformed.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRejectsMalformedRenderedRowsForRatioCopy(): void
    {
        $signalKey = $this->buildSignalKey('hero');
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'ratio.copyFromRenderedBreakpoint',
            'setName' => 'hero',
            'renderedRows' => '{bad-json}',
            'editor' => [
                'cards' => [
                    $signalKey => [
                        'ratioSourceBreakpoint' => '640',
                    ],
                ],
            ],
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('renderedRows payload is malformed.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRejectsMalformedRenderedRowsForAutoToggle(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'operation' => 'dimensions.toggleAutoWidth',
            'setName' => 'hero',
            'renderedRows' => '{bad-json}',
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('renderedRows payload is malformed.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRejectsFractionalScopeBreakpoint(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 8,
            'operation' => 'breakpoint.toggleEnabled',
            'setName' => 'hero',
            'scopeBreakpoint' => '640.5',
            'enabled' => true,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('scopeBreakpoint is required when updating breakpoint state.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRoutesPassHeightWhenRenderedLteSavedField(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 9,
            'operation' => 'settings.setPassHeightWhenRenderedLteSaved',
            'setName' => '',
            'value' => true,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('setName is required.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRoutesAllowAnyHeightField(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 9,
            'operation' => 'settings.setAllowAnyHeight',
            'setName' => '',
            'value' => true,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('datastar-patch-elements', (string)$response->content);
        $this->assertStringContainsString('data-kind="error"', (string)$response->content);
        $this->assertStringContainsString('setName is required.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testRenderResultReviewCoercesNonArrayPayloadsToArrays(): void
    {
        $controller = $this->controllerWithBody([
            'result' => 'not-an-array',
            'editScopeBySet' => 'invalid-scope',
            'editTabBySet' => 'invalid-tab',
            'selectedAssetKeyBySet' => 'invalid-selected-asset',
            'preferredOrderBySet' => 'invalid-order',
        ]);
        $response = $controller->actionRenderResultReview();

        $this->assertSame(Response::FORMAT_JSON, $response->format);
        $this->assertIsArray($response->data);
        $this->assertArrayHasKey('warningsHtml', $response->data);
        $this->assertArrayHasKey('visualResultsHtml', $response->data);
        $this->assertArrayHasKey('warningCount', $response->data);
        $this->assertSame('', $response->data['warningsHtml'] ?? null);
        $this->assertStringContainsString('No transform sets found in results.', (string)($response->data['visualResultsHtml'] ?? ''));
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testRenderResultReviewNormalizesSelectedAssetKeyBySetFromRequestPayload(): void
    {
        $controller = $this->controllerWithBody([
            'result' => [
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
                            'rendered' => ['width' => 620, 'height' => 350],
                            'transformDimensions' => ['width' => 620, 'height' => 350, 'autoDimension' => null],
                        ],
                    ],
                ],
            ],
            'selectedAssetKeyBySet' => ['hero' => 'does-not-exist'],
        ]);
        $response = $controller->actionRenderResultReview();

        $this->assertSame(Response::FORMAT_JSON, $response->format);
        $this->assertIsArray($response->data);
        $this->assertIsArray($response->data['selectedAssetKeyBySet'] ?? null);
        $this->assertSame('asset:hero:100', $response->data['selectedAssetKeyBySet']['hero'] ?? null);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testRenderInitialReviewCoercesNonArrayPayloadsToArrays(): void
    {
        $controller = $this->controllerWithBody([
            'editScopeBySet' => 'invalid-scope',
            'editTabBySet' => 'invalid-tab',
            'preferredOrderBySet' => 'invalid-order',
        ]);
        $response = $controller->actionRenderInitialReview();

        $this->assertSame(Response::FORMAT_JSON, $response->format);
        $this->assertIsArray($response->data);
        $this->assertArrayHasKey('warningsHtml', $response->data);
        $this->assertArrayHasKey('visualResultsHtml', $response->data);
        $this->assertArrayHasKey('warningCount', $response->data);
        $this->assertArrayHasKey('editScopeBySet', $response->data);
        $this->assertArrayHasKey('editTabBySet', $response->data);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testRenderInitialReviewMarkupUsesSignalBindingsWithoutStaticInputValues(): void
    {
        $controller = $this->controllerWithBody([]);
        $response = $controller->actionRenderInitialReview();

        $this->assertSame(Response::FORMAT_JSON, $response->format);
        $visualResults = (string)($response->data['visualResultsHtml'] ?? '');

        $this->assertStringContainsString('data-bind="editor.cards.', $visualResults);
        $this->assertDoesNotMatchRegularExpression('/bpts-transform-width-input[^>]*\svalue="/i', $visualResults);
        $this->assertDoesNotMatchRegularExpression('/bpts-transform-height-input[^>]*\svalue="/i', $visualResults);
        $this->assertDoesNotMatchRegularExpression('/bpts-transform-ratio-width-input[^>]*\svalue="/i', $visualResults);
        $this->assertDoesNotMatchRegularExpression('/bpts-transform-ratio-height-input[^>]*\svalue="/i', $visualResults);
        $this->assertDoesNotMatchRegularExpression('/bpts-transform-ratio-float-input[^>]*\svalue="/i', $visualResults);
        $this->assertStringContainsString('@post($_applyCardOperationUrl', $visualResults);
        $this->assertStringContainsString('dimensions.toggleAutoWidth', $visualResults);
        $this->assertStringContainsString('dimensions.toggleAutoHeight', $visualResults);
        $this->assertStringContainsString('renderedValues.apply', $visualResults);
        $this->assertStringContainsString('ratio.copyFromRenderedBreakpoint', $visualResults);
        $this->assertStringNotContainsString('localStateByBreakpoint', $visualResults);
        $this->assertStringNotContainsString('JSON.parse(', $visualResults);
    }

    private function controllerWithBody(array $bodyParams): TransformsController
    {
        Craft::$app->getRequest()->setBodyParams($bodyParams);

        return new class('transforms', Craft::$app) extends TransformsController {
            public bool $cpRequestChecked = false;
            public bool $postRequestChecked = false;

            public function requireCpRequest(): void
            {
                $this->cpRequestChecked = true;
            }

            public function requirePostRequest(): void
            {
                $this->postRequestChecked = true;
            }
        };
    }

    private function buildSignalKey(string $setName): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($setName)));
        $slug = trim((string)$slug, '-');
        if ($slug === '') {
            $slug = 'transform';
        }

        return 't_' . str_replace('-', '_', $slug) . '_' . substr(sha1($setName), 0, 8);
    }
}
