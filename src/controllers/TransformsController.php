<?php

namespace craftyhedge\craftbreakpoints\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use craftyhedge\craftbreakpoints\Plugin;
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
        $currentVersion = Plugin::getInstance()->getTransformStore()->getCurrentVersion();
        $state = [
            'sessionId' => $this->resolveSessionId(),
            'baseVersion' => $currentVersion,
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

        $this->requireTransformEditPermission();

        $editor = Plugin::getInstance()->getTransformEditor();
        $baseVersion = $this->resolveBaseVersion(Plugin::getInstance()->getTransformStore()->getCurrentVersion());
        $resultSummary = $editor->buildResultSummary($this->extractResultSummaryFromRequest());
        $validation = $editor->defaultValidation();

        $draftJson = trim((string)$this->request->getBodyParam('draftJson', ''));
        if ($draftJson === '') {
            $validation['hasErrors'] = true;
            $validation['global'][] = 'Draft JSON is required.';
            $draft = $editor->buildDraftFromStore();

            $state = [
                'sessionId' => $this->resolveSessionId(),
                'baseVersion' => $baseVersion,
                'draft' => $draft,
                'draftJson' => $editor->encodeDraftJson($draft),
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

        $applyResult = $editor->applyDraft($decodedDraft, $baseVersion);
        $applied = ($applyResult['persisted'] ?? false) === true;
        $conflict = ($applyResult['conflict'] ?? false) === true;
        $currentVersion = (string)($applyResult['currentVersion'] ?? $baseVersion);
        $draft = is_array($applyResult['draft'] ?? null) ? $applyResult['draft'] : $editor->buildDraftFromStore();
        $applyValidation = is_array($applyResult['validation'] ?? null)
            ? $applyResult['validation']
            : $editor->defaultValidation();

        $state = [
            'sessionId' => $this->resolveSessionId(),
            'baseVersion' => $currentVersion,
            'draft' => $draft,
            'validation' => $applyValidation,
            'resultSummary' => $resultSummary,
            'serverStatus' => [
                'kind' => $applied ? 'success' : 'error',
                'message' => $applied
                    ? 'Draft applied and persisted to transform-sets.json.'
                    : ($conflict
                        ? 'Draft is out of date. Reloaded latest server version.'
                        : 'Draft has validation errors. Resolve errors and apply again.'),
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

        $this->requireTransformEditPermission();

        $editor = Plugin::getInstance()->getTransformEditor();

        $setName = trim((string)$this->request->getBodyParam('setName', ''));
        $scopeMode = trim((string)$this->request->getBodyParam('scopeMode', 'all'));
        $field = $this->normalizeOperationField(trim((string)$this->request->getBodyParam('field', 'width')));
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
        $baseVersion = $this->resolveBaseVersion(Plugin::getInstance()->getTransformStore()->getCurrentVersion());

        $includeEscapeWidth = $this->parseNullableBool($includeEscapeWidthRaw);
        $scopeBreakpoint = $this->parseNullablePositiveInt($scopeBreakpointRaw);
        $value = $this->parseNullablePositiveInt($valueRaw);
        $width = $this->parseNullablePositiveInt($widthRaw);
        $height = $this->parseNullablePositiveInt($heightRaw);
        $widthAuto = $this->parseNullableBool($widthAutoRaw);
        $heightAuto = $this->parseNullableBool($heightAutoRaw);
        $forceAll = $this->parseNullableBool($this->request->getBodyParam('forceAll')) === true;
        $clearAuto = $this->parseNullableBool($this->request->getBodyParam('clearAuto')) === true;
        $ratioWidth = $this->parseNullablePositiveInt($ratioWidthRaw);
        $ratioHeight = $this->parseNullablePositiveInt($ratioHeightRaw);
        $ratioSourceDimension = $this->parseNullableNonEmptyString($ratioSourceDimensionRaw);
        $enabled = $this->parseNullableBool($this->request->getBodyParam('enabled'));

        if ($field === 'renderedValues') {
            $renderedRowsRaw = $this->request->getBodyParam('renderedRows', []);
            $renderedRows = is_array($renderedRowsRaw) ? $renderedRowsRaw : [];

            $operationResult = $editor->applyRenderedValuesOperation(
                $setName,
                $renderedRows,
                $includeEscapeWidth,
                $clearAuto,
                $baseVersion,
            );
        } elseif ($field === 'deleteSet') {
            $operationResult = $editor->deleteSetOperation($setName, $baseVersion);
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
                $forceAll,
                $baseVersion,
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
                $baseVersion,
            );
        } elseif ($field === 'breakpointEnabled') {
            $operationResult = $editor->applySetBreakpointEnabledOperation(
                $setName,
                $scopeBreakpoint,
                $enabled,
                $includeEscapeWidth,
                $baseVersion,
            );
        } elseif ($field === 'passHeightWhenRenderedLteSaved') {
            $operationResult = $editor->applySetPassHeightWhenRenderedLteSavedOperation(
                $setName,
                $valueRaw,
                $includeEscapeWidth,
                $baseVersion,
            );
        } elseif ($field === 'allowAnyHeight') {
            $operationResult = $editor->applySetAllowAnyHeightOperation(
                $setName,
                $valueRaw,
                $includeEscapeWidth,
                $baseVersion,
            );
        } else {
            $operationResult = $editor->applySetDimensionOperation(
                $setName,
                $scopeMode,
                $scopeBreakpoint,
                $value,
                $field,
                $includeEscapeWidth,
                $baseVersion,
            );
        }

        $validation = is_array($operationResult['validation'] ?? null)
            ? $operationResult['validation']
            : $editor->defaultValidation();
        $persisted = ($operationResult['persisted'] ?? false) === true;
        $conflict = ($operationResult['conflict'] ?? false) === true;
        $currentVersion = (string)($operationResult['currentVersion'] ?? $baseVersion);
        $statusMessage = $this->buildOperationStatusMessage($field, $persisted, $conflict, $operationResult);
        $draft = $editor->buildDraftFromStore();

        $state = [
            'sessionId' => $this->resolveSessionId(),
            'baseVersion' => $currentVersion,
            'draft' => $draft,
            'draftJson' => $editor->encodeDraftJson($draft),
            'serverStatus' => [
                'kind' => $persisted ? 'success' : 'error',
                'message' => $statusMessage,
            ],
            'validation' => $validation,
            'resultSummary' => $editor->buildResultSummary($this->extractResultSummaryFromRequest()),
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
        $result = $this->readBodyArrayParam('result');
        $editScopeBySet = $this->readBodyArrayParam('editScopeBySet');
        $editTabBySet = $this->readBodyArrayParam('editTabBySet');
        $selectedAssetKeyBySet = $this->readBodyArrayParam('selectedAssetKeyBySet');
        $preferredOrderBySet = $this->readBodyArrayParam('preferredOrderBySet');

        $rendered = $editor->renderResultReview(
            $result,
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
        );

        return $this->asJson($rendered);
    }

    public function actionRenderInitialReview(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $editor = Plugin::getInstance()->getTransformEditor();
        $editScopeBySet = $this->readBodyArrayParam('editScopeBySet');
        $editTabBySet = $this->readBodyArrayParam('editTabBySet');
        $selectedAssetKeyBySet = $this->readBodyArrayParam('selectedAssetKeyBySet');
        $preferredOrderBySet = $this->readBodyArrayParam('preferredOrderBySet');

        $rendered = $editor->renderInitialStoredReview(
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
        );

        return $this->asJson($rendered);
    }

    public function actionPersistRunSnapshot(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $this->requireTransformEditPermission();

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

    private function resolveBaseVersion(string $fallbackVersion): string
    {
        $rawVersion = trim((string)$this->request->getBodyParam('baseVersion', ''));
        if ($rawVersion !== '') {
            return $rawVersion;
        }

        return $fallbackVersion;
    }

    private function readBodyArrayParam(string $name): array
    {
        $rawValue = $this->request->getBodyParam($name, []);

        return is_array($rawValue) ? $rawValue : [];
    }

    private function requireTransformEditPermission(): void
    {
        if (!Plugin::getInstance()->getTelemetry()->canEditTransforms()) {
            throw new ForbiddenHttpException('Transform editing is disabled in this environment.');
        }
    }

    private function normalizeOperationField(string $field): string
    {
        return match ($field) {
            'width', 'height', 'dimensions', 'ratio', 'breakpointEnabled', 'passHeightWhenRenderedLteSaved', 'allowAnyHeight', 'renderedValues', 'deleteSet' => $field,
            default => 'width',
        };
    }

    private function parseNullableBool(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw)) {
            if ($raw === 1) {
                return true;
            }

            if ($raw === 0) {
                return false;
            }

            return null;
        }

        if (is_float($raw)) {
            if ($raw === 1.0) {
                return true;
            }

            if ($raw === 0.0) {
                return false;
            }

            return null;
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
    }

    private function parseNullablePositiveInt(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }

        if (is_float($raw)) {
            if (!is_finite($raw) || floor($raw) !== $raw) {
                return null;
            }

            $value = (int)$raw;

            return $value > 0 ? $value : null;
        }

        if (!is_string($raw)) {
            return null;
        }

        $normalized = trim($raw);
        if ($normalized === '' || !ctype_digit($normalized)) {
            return null;
        }

        $value = (int)$normalized;

        return $value > 0 ? $value : null;
    }

    private function parseNullableNonEmptyString(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function buildOperationStatusMessage(string $field, bool $persisted, bool $conflict, array $operationResult = []): string
    {
        if (!$persisted && $conflict) {
            return 'Draft is out of date. Refresh and retry.';
        }

        if ($field === 'ratio') {
            $details = is_array($operationResult['operationDetails'] ?? null)
                ? $operationResult['operationDetails']
                : [];
            $appliedBreakpoints = isset($details['appliedBreakpoints']) && is_array($details['appliedBreakpoints'])
                ? $details['appliedBreakpoints']
                : [];
            $skippedBreakpoints = isset($details['skippedBreakpoints']) && is_array($details['skippedBreakpoints'])
                ? $details['skippedBreakpoints']
                : [];

            if ($persisted && ($appliedBreakpoints !== [] || $skippedBreakpoints !== [])) {
                $appliedCount = count($appliedBreakpoints);
                if ($appliedCount > 0) {
                    $message = sprintf('Ratio applied to %d breakpoint%s.', $appliedCount, $appliedCount === 1 ? '' : 's');
                } else {
                    $message = 'Ratio not applied to any breakpoints.';
                }

                if ($skippedBreakpoints !== []) {
                    $message .= ' Skipped: ' . $this->formatSkippedRatioBreakpoints($skippedBreakpoints) . '.';
                }

                return $message;
            }
        }

        if ($persisted) {
            return match ($field) {
                'renderedValues' => 'Rendered values applied.',
                'deleteSet' => 'Transform set deleted.',
                'dimensions' => 'Dimensions updated.',
                'ratio' => 'Ratio applied.',
                'breakpointEnabled' => 'Breakpoint state updated.',
                'passHeightWhenRenderedLteSaved' => 'Allow shorter heights setting updated.',
                'allowAnyHeight' => 'Allow any height setting updated.',
                default => ucfirst($field) . ' updated.',
            };
        }

        return match ($field) {
            'renderedValues' => 'Rendered values apply failed.',
            'deleteSet' => 'Transform set delete failed.',
            'dimensions' => 'Dimensions update failed.',
            'ratio' => 'Ratio apply failed.',
            'breakpointEnabled' => 'Breakpoint state update failed.',
            'passHeightWhenRenderedLteSaved' => 'Allow shorter heights setting update failed.',
            'allowAnyHeight' => 'Allow any height setting update failed.',
            default => ucfirst($field) . ' update failed.',
        };
    }

    private function formatSkippedRatioBreakpoints(array $skippedBreakpoints): string
    {
        $parts = [];

        foreach ($skippedBreakpoints as $item) {
            if (!is_array($item)) {
                continue;
            }

            $breakpoint = isset($item['breakpoint']) && is_numeric($item['breakpoint'])
                ? max(0, (int)$item['breakpoint'])
                : 0;
            $reason = is_string($item['reason'] ?? null)
                ? trim($item['reason'])
                : '';

            if ($breakpoint <= 0 || $reason === '') {
                continue;
            }

            $parts[] = sprintf('%dpx (%s)', $breakpoint, $reason);
        }

        if ($parts === []) {
            return 'none';
        }

        return implode(', ', $parts);
    }
}