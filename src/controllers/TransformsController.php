<?php

namespace craftyhedge\craftbreakpointimages\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use craftyhedge\craftbreakpointimages\Plugin;
use starfederation\datastar\events\PatchSignals;
use starfederation\datastar\ServerSentEventGenerator;
use yii\web\Response;
use yii\web\ForbiddenHttpException;

class TransformsController extends Controller
{
    public function actionEditorInit(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $editor = Plugin::getInstance()->getTransformEditor();
        $state = [
            'sessionId' => $this->resolveSessionId(),
            'baseVersion' => max(1, (int)$this->request->getBodyParam('baseVersion', 1)),
            'draft' => $editor->buildDraftFromStore(),
            'validation' => $editor->defaultValidation(),
            'resultSummary' => $editor->buildResultSummary($this->extractResultSummaryFromRequest()),
            'serverStatus' => [
                'kind' => 'ready',
                'message' => 'Draft initialized from current transform set configuration.',
            ],
        ];
        $state['draftJson'] = $editor->encodeDraftJson($state['draft']);

        return $this->asDatastarSignalsPatch([
            'editor' => $state,
        ]);
    }

    public function actionApply(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        if (!Plugin::getInstance()->getTelemetry()->canEditTransforms()) {
            throw new ForbiddenHttpException('Transform editing is disabled in this environment.');
        }

        $editor = Plugin::getInstance()->getTransformEditor();
        $baseVersion = max(1, (int)$this->request->getBodyParam('baseVersion', 1));
        $resultSummary = $editor->buildResultSummary($this->extractResultSummaryFromRequest());
        $validation = $editor->defaultValidation();

        $draftJson = trim((string)$this->request->getBodyParam('draftJson', ''));
        if ($draftJson === '') {
            $validation['hasErrors'] = true;
            $validation['global'][] = 'Draft JSON is required.';

            $state = [
                'sessionId' => $this->resolveSessionId(),
                'baseVersion' => $baseVersion,
                'draft' => $editor->buildDraftFromStore(),
                'draftJson' => $editor->encodeDraftJson($editor->buildDraftFromStore()),
                'validation' => $validation,
                'resultSummary' => $resultSummary,
                'serverStatus' => [
                    'kind' => 'error',
                    'message' => 'Draft could not be applied.',
                ],
            ];

            return $this->asDatastarSignalsPatch([
                'editor' => $state,
            ]);
        }

        $decodedDraft = Json::decodeIfJson($draftJson);
        if (!is_array($decodedDraft)) {
            $validation['hasErrors'] = true;
            $validation['global'][] = 'Draft JSON must decode to an object.';

            $state = [
                'sessionId' => $this->resolveSessionId(),
                'baseVersion' => $baseVersion,
                'draft' => $editor->buildDraftFromStore(),
                'draftJson' => $draftJson,
                'validation' => $validation,
                'resultSummary' => $resultSummary,
                'serverStatus' => [
                    'kind' => 'error',
                    'message' => 'Draft JSON is invalid.',
                ],
            ];

            return $this->asDatastarSignalsPatch([
                'editor' => $state,
            ]);
        }

        $applyResult = $editor->applyDraft($decodedDraft);
        $applied = ($applyResult['persisted'] ?? false) === true;
        $draft = is_array($applyResult['draft'] ?? null) ? $applyResult['draft'] : $editor->buildDraftFromStore();
        $applyValidation = is_array($applyResult['validation'] ?? null)
            ? $applyResult['validation']
            : $editor->defaultValidation();

        $state = [
            'sessionId' => $this->resolveSessionId(),
            'baseVersion' => $applied ? ($baseVersion + 1) : $baseVersion,
            'draft' => $draft,
            'validation' => $applyValidation,
            'resultSummary' => $resultSummary,
            'serverStatus' => [
                'kind' => $applied ? 'success' : 'error',
                'message' => $applied
                    ? 'Draft applied and persisted to transform-sets.json.'
                    : 'Draft has validation errors. Resolve errors and apply again.',
            ],
        ];
        $state['draftJson'] = $editor->encodeDraftJson($state['draft']);

        return $this->asDatastarSignalsPatch([
            'editor' => $state,
        ]);
    }

    public function actionApplyCardOperation(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        if (!Plugin::getInstance()->getTelemetry()->canEditTransforms()) {
            throw new ForbiddenHttpException('Transform editing is disabled in this environment.');
        }

        $editor = Plugin::getInstance()->getTransformEditor();

        $setName = trim((string)$this->request->getBodyParam('setName', ''));
        $scopeMode = trim((string)$this->request->getBodyParam('scopeMode', 'all'));
        $field = trim((string)$this->request->getBodyParam('field', 'width'));
        $includeEscapeWidthRaw = $this->request->getBodyParam('includeEscapeWidth');
        $scopeBreakpointRaw = $this->request->getBodyParam('scopeBreakpoint');
        $valueRaw = $this->request->getBodyParam('value');
        $widthRaw = $this->request->getBodyParam('width');
        $heightRaw = $this->request->getBodyParam('height');
        $widthAutoRaw = $this->request->getBodyParam('widthAuto');
        $heightAutoRaw = $this->request->getBodyParam('heightAuto');
        $ratioWidthRaw = $this->request->getBodyParam('ratioWidth');
        $ratioHeightRaw = $this->request->getBodyParam('ratioHeight');
        $ratioSourceDimensionRaw = $this->request->getBodyParam('ratioSourceDimension');
        $baseVersion = max(1, (int)$this->request->getBodyParam('baseVersion', 1));

        if ($field !== 'width' && $field !== 'height' && $field !== 'dimensions' && $field !== 'ratio' && $field !== 'renderedValues') {
            $field = 'width';
        }

        $includeEscapeWidth = null;
        if (is_bool($includeEscapeWidthRaw)) {
            $includeEscapeWidth = $includeEscapeWidthRaw;
        } elseif (is_numeric($includeEscapeWidthRaw)) {
            $includeEscapeWidth = ((int)$includeEscapeWidthRaw) === 1;
        } elseif (is_string($includeEscapeWidthRaw)) {
            $normalizedIncludeEscapeWidth = strtolower(trim($includeEscapeWidthRaw));
            if ($normalizedIncludeEscapeWidth === 'true' || $normalizedIncludeEscapeWidth === '1') {
                $includeEscapeWidth = true;
            } elseif ($normalizedIncludeEscapeWidth === 'false' || $normalizedIncludeEscapeWidth === '0') {
                $includeEscapeWidth = false;
            }
        }

        $scopeBreakpoint = null;
        if (is_numeric($scopeBreakpointRaw)) {
            $parsedBreakpoint = (int)$scopeBreakpointRaw;
            if ($parsedBreakpoint > 0) {
                $scopeBreakpoint = $parsedBreakpoint;
            }
        }

        $value = null;
        if (is_numeric($valueRaw)) {
            $parsedValue = (int)$valueRaw;
            if ($parsedValue > 0) {
                $value = $parsedValue;
            }
        }

        $width = null;
        if (is_numeric($widthRaw)) {
            $parsedWidth = (int)$widthRaw;
            if ($parsedWidth > 0) {
                $width = $parsedWidth;
            }
        }

        $height = null;
        if (is_numeric($heightRaw)) {
            $parsedHeight = (int)$heightRaw;
            if ($parsedHeight > 0) {
                $height = $parsedHeight;
            }
        }

        $parseNullableBool = static function (mixed $raw): ?bool {
            if (is_bool($raw)) {
                return $raw;
            }

            if (is_numeric($raw)) {
                return ((int)$raw) === 1;
            }

            if (is_string($raw)) {
                $normalized = strtolower(trim($raw));
                if ($normalized === 'true' || $normalized === '1') {
                    return true;
                }

                if ($normalized === 'false' || $normalized === '0') {
                    return false;
                }
            }

            return null;
        };

        $widthAuto = $parseNullableBool($widthAutoRaw);
        $heightAuto = $parseNullableBool($heightAutoRaw);

        $ratioWidth = null;
        if (is_numeric($ratioWidthRaw)) {
            $parsedRatioWidth = (int)$ratioWidthRaw;
            if ($parsedRatioWidth > 0) {
                $ratioWidth = $parsedRatioWidth;
            }
        }

        $ratioHeight = null;
        if (is_numeric($ratioHeightRaw)) {
            $parsedRatioHeight = (int)$ratioHeightRaw;
            if ($parsedRatioHeight > 0) {
                $ratioHeight = $parsedRatioHeight;
            }
        }

        $ratioSourceDimension = null;
        if (is_string($ratioSourceDimensionRaw)) {
            $trimmedRatioSourceDimension = trim($ratioSourceDimensionRaw);
            if ($trimmedRatioSourceDimension !== '') {
                $ratioSourceDimension = $trimmedRatioSourceDimension;
            }
        }

        if ($field === 'renderedValues') {
            $renderedRowsRaw = $this->request->getBodyParam('renderedRows', []);
            $renderedRows = is_array($renderedRowsRaw) ? $renderedRowsRaw : [];

            $operationResult = $editor->applyRenderedValuesOperation(
                $setName,
                $renderedRows,
                $includeEscapeWidth,
            );
        } elseif ($field === 'dimensions') {
            $operationResult = $editor->applySetDimensionsOperation(
                $setName,
                $scopeMode,
                $scopeBreakpoint,
                $width,
                $height,
                $includeEscapeWidth,
                $widthAuto,
                $heightAuto,
            );
        } elseif ($field === 'ratio') {
            $operationResult = $editor->applySetRatioOperation(
                $setName,
                $scopeMode,
                $scopeBreakpoint,
                $ratioWidth,
                $ratioHeight,
                $ratioSourceDimension,
                $includeEscapeWidth,
            );
        } else {
            $operationResult = $editor->applySetDimensionOperation(
                $setName,
                $scopeMode,
                $scopeBreakpoint,
                $value,
                $field,
                $includeEscapeWidth,
            );
        }

        $validation = is_array($operationResult['validation'] ?? null)
            ? $operationResult['validation']
            : $editor->defaultValidation();
        $persisted = ($operationResult['persisted'] ?? false) === true;
        $statusMessage = $persisted
            ? match ($field) {
                'renderedValues' => 'Rendered values applied.',
                'dimensions' => 'Dimensions updated.',
                'ratio' => 'Ratio applied.',
                default => ucfirst($field) . ' updated.',
            }
            : match ($field) {
                'renderedValues' => 'Rendered values apply failed.',
                'dimensions' => 'Dimensions update failed.',
                'ratio' => 'Ratio apply failed.',
                default => ucfirst($field) . ' update failed.',
            };

        $state = [
            'baseVersion' => $persisted ? ($baseVersion + 1) : $baseVersion,
            'serverStatus' => [
                'kind' => $persisted ? 'success' : 'error',
                'message' => $statusMessage,
            ],
            'validation' => $validation,
        ];

        return $this->asDatastarSignalsPatch([
            'editor' => $state,
        ]);
    }

    public function actionRenderResultReview(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $editor = Plugin::getInstance()->getTransformEditor();
        $rawResult = $this->request->getBodyParam('result', []);
        $rawEditScopeBySet = $this->request->getBodyParam('editScopeBySet', []);
        $rawEditTabBySet = $this->request->getBodyParam('editTabBySet', []);
        $rawPreferredOrderBySet = $this->request->getBodyParam('preferredOrderBySet', []);

        $result = is_array($rawResult) ? $rawResult : [];
        $editScopeBySet = is_array($rawEditScopeBySet) ? $rawEditScopeBySet : [];
        $editTabBySet = is_array($rawEditTabBySet) ? $rawEditTabBySet : [];
        $preferredOrderBySet = is_array($rawPreferredOrderBySet) ? $rawPreferredOrderBySet : [];

        $rendered = $editor->renderResultReview(
            $result,
            $editScopeBySet,
            $editTabBySet,
            $preferredOrderBySet,
        );

        return $this->asJson($rendered);
    }

    public function actionRenderInitialReview(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $editor = Plugin::getInstance()->getTransformEditor();
        $rawEditScopeBySet = $this->request->getBodyParam('editScopeBySet', []);
        $rawEditTabBySet = $this->request->getBodyParam('editTabBySet', []);
        $rawPreferredOrderBySet = $this->request->getBodyParam('preferredOrderBySet', []);

        $editScopeBySet = is_array($rawEditScopeBySet) ? $rawEditScopeBySet : [];
        $editTabBySet = is_array($rawEditTabBySet) ? $rawEditTabBySet : [];
        $preferredOrderBySet = is_array($rawPreferredOrderBySet) ? $rawPreferredOrderBySet : [];

        $rendered = $editor->renderInitialStoredReview(
            $editScopeBySet,
            $editTabBySet,
            $preferredOrderBySet,
        );

        return $this->asJson($rendered);
    }

    public function actionPersistRunSnapshot(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        if (!Plugin::getInstance()->getTelemetry()->canEditTransforms()) {
            throw new ForbiddenHttpException('Transform editing is disabled in this environment.');
        }

        $payload = [
            'runId' => $this->request->getBodyParam('runId'),
            'timestamp' => $this->request->getBodyParam('timestamp'),
            'runStatus' => $this->request->getBodyParam('runStatus'),
            'durationMs' => $this->request->getBodyParam('durationMs'),
            'entryId' => $this->request->getBodyParam('entryId'),
            'sourceUrl' => $this->request->getBodyParam('sourceUrl'),
            'failureReasonCounts' => $this->request->getBodyParam('failureReasonCounts', []),
            'rowsByBreakpoint' => $this->request->getBodyParam('rowsByBreakpoint', []),
        ];

        $persisted = Plugin::getInstance()->getTelemetry()->persistRunSnapshot($payload);
        if (!$persisted) {
            return $this->asJson([
                'ok' => false,
            ]);
        }

        return $this->asJson([
            'ok' => true,
        ]);
    }

    private function asDatastarSignalsPatch(array $signals): Response
    {
        $event = new PatchSignals($signals);
        $headers = ServerSentEventGenerator::headers();

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        foreach ($headers as $name => $value) {
            if ($name === 'Content-Type') {
                $response->getHeaders()->set('Content-Type', 'text/event-stream; charset=UTF-8');
                continue;
            }

            $response->getHeaders()->set($name, $value);
        }
        $response->content = $event->getOutput();

        return $response;
    }

    private function resolveSessionId(): string
    {
        $requestSessionId = trim((string)$this->request->getBodyParam('sessionId', ''));
        if ($requestSessionId !== '') {
            return $requestSessionId;
        }

        return 'sess_' . substr(sha1((string)microtime(true)), 0, 12);
    }

    private function extractResultSummaryFromRequest(): array
    {
        return [
            'assetCount' => $this->request->getBodyParam('resultSummaryAssetCount', 0),
            'breakpointCount' => $this->request->getBodyParam('resultSummaryBreakpointCount', 0),
            'warningCount' => $this->request->getBodyParam('resultSummaryWarningCount', 0),
        ];
    }
}