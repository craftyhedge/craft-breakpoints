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

    public function testApplyCardOperationUsesWidthFallbackForUnknownFieldAndReportsValidationErrors(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 5,
            'field' => 'unexpected-field',
            'setName' => '',
            'scopeMode' => 'all',
            'value' => 120,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('"baseVersion":"5"', (string)$response->content);
        $this->assertStringContainsString('Width update failed.', (string)$response->content);
        $this->assertStringContainsString('setName is required.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRoutesBreakpointEnabledField(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 6,
            'field' => 'breakpointEnabled',
            'setName' => '',
            'scopeBreakpoint' => 640,
            'enabled' => false,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('Breakpoint state update failed.', (string)$response->content);
        $this->assertStringContainsString('setName is required.', (string)$response->content);
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
            'field' => 'breakpointEnabled',
            'setName' => 'hero',
            'scopeBreakpoint' => $firstBreakpointValue,
            'enabled' => 2,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('enabled must be a boolean value.', (string)$response->content);
        $this->assertStringContainsString('Breakpoint state update failed.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRejectsFractionalScopeBreakpoint(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 8,
            'field' => 'breakpointEnabled',
            'setName' => 'hero',
            'scopeBreakpoint' => '640.5',
            'enabled' => true,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('scopeBreakpoint is required when updating breakpoint state.', (string)$response->content);
        $this->assertStringContainsString('Breakpoint state update failed.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRoutesPassHeightWhenRenderedLteSavedField(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 9,
            'field' => 'passHeightWhenRenderedLteSaved',
            'setName' => '',
            'value' => true,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('Allow shorter heights setting update failed.', (string)$response->content);
        $this->assertStringContainsString('setName is required.', (string)$response->content);
        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    public function testApplyCardOperationRoutesAllowAnyHeightField(): void
    {
        $controller = $this->controllerWithBody([
            'baseVersion' => 9,
            'field' => 'allowAnyHeight',
            'setName' => '',
            'value' => true,
        ]);
        $response = $controller->actionApplyCardOperation();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('Allow any height setting update failed.', (string)$response->content);
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
