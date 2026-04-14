<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpointimages\controllers\TransformsController;
use craftyhedge\craftbreakpointimages\Plugin;
use yii\web\Response;

final class TransformsControllerTest extends Unit
{
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
        $this->assertStringContainsString('"baseVersion":1', (string)$response->content);
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
        $this->assertStringContainsString('"baseVersion":3', (string)$response->content);
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

        $controller = $this->controllerWithBody([
            'sessionId' => 'sess_test_success',
            'baseVersion' => 1,
            'draftJson' => $validDraftJson,
        ]);
        $response = $controller->actionApply();

        $this->assertSame(Response::FORMAT_RAW, $response->format);
        $this->assertStringContainsString('"baseVersion":2', (string)$response->content);
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
        $this->assertStringContainsString('"baseVersion":5', (string)$response->content);
        $this->assertStringContainsString('Width update failed.', (string)$response->content);
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
