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
}
