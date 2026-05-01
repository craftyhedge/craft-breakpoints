<?php

namespace craftyhedge\craftbreakpoints\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\transformeditor\CardOperationRequest;
use starfederation\datastar\events\PatchElements;
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

        $operation = CardOperationRequest::fromRequest(
            $this->request,
            Plugin::getInstance()->getTransformStore()->getCurrentVersion(),
        );

        if (!$operation->hasValidOperation) {
            $events = [
                new PatchElements($this->renderEditorStatusFragment('error', 'operation is required and must be a supported command.')),
            ];

            return $this->asDatastarEventStream($events);
        }

        if ($operation->operation === 'scope.selectAll' || $operation->operation === 'scope.selectBreakpoint') {
            if ($operation->setName === '') {
                return $this->asDatastarEventStream([
                    new PatchElements($this->renderEditorStatusFragment('error', 'setName is required.')),
                ]);
            }

            if ($operation->operation === 'scope.selectBreakpoint' && $operation->scopeBreakpoint === null) {
                return $this->asDatastarEventStream([
                    new PatchElements($this->renderEditorStatusFragment('error', 'scopeBreakpoint is required when selecting a breakpoint.')),
                ]);
            }

            $scopeMode = $operation->operation === 'scope.selectAll' ? 'all' : 'breakpoint';
            $scopeBreakpoint = $scopeMode === 'breakpoint' ? $operation->scopeBreakpoint : null;
            $editScopeBySet = $this->buildCardEditScope($operation->setName, $scopeMode, $scopeBreakpoint);
            $editTabBySet = $this->buildCardRequestedEditTab($operation->setName);
            $selectedAssetKeyBySet = $this->buildCardSelectedAssetKey($operation->setName);
            $cardMarkup = $editor->renderCardFragment(
                $operation->setName,
                $editScopeBySet,
                $editTabBySet,
                $selectedAssetKeyBySet,
            );

            if ($cardMarkup === '') {
                return $this->asDatastarEventStream([
                    new PatchElements($this->renderEditorStatusFragment('error', 'Unable to render selected scope state.')),
                ]);
            }

            return $this->asDatastarEventStream([
                new PatchElements($cardMarkup),
            ]);
        }

        if ($operation->operation === 'renderedValues.apply') {
            $operationResult = $editor->applyRenderedValuesOperation(
                $operation->setName,
                $operation->renderedRows,
                $operation->includeEscapeWidth,
                $operation->clearAuto,
                $operation->baseVersion,
            );
        } elseif ($operation->operation === 'set.delete') {
            $operationResult = $editor->deleteSetOperation($operation->setName, $operation->baseVersion);
        } elseif ($operation->operation === 'dimensions.apply') {
            $operationResult = $editor->applySetDimensionsOperation(
                $operation->setName,
                $operation->scopeMode,
                $operation->scopeBreakpoint,
                $operation->width,
                $operation->height,
                $operation->includeEscapeWidth,
                $operation->widthAuto,
                $operation->heightAuto,
                $operation->forceAll,
                $operation->baseVersion,
            );
        } elseif ($operation->operation === 'ratio.apply') {
            $operationResult = $editor->applySetRatioOperation(
                $operation->setName,
                $operation->scopeMode,
                $operation->scopeBreakpoint,
                $operation->ratioWidth,
                $operation->ratioHeight,
                $operation->ratioSourceDimension,
                $operation->includeEscapeWidth,
                $operation->baseVersion,
            );
        } elseif ($operation->operation === 'breakpoint.toggleEnabled') {
            $operationResult = $editor->applySetBreakpointEnabledOperation(
                $operation->setName,
                $operation->scopeBreakpoint,
                $operation->enabled,
                $operation->includeEscapeWidth,
                $operation->baseVersion,
            );
        } elseif ($operation->operation === 'settings.setPassHeightWhenRenderedLteSaved') {
            $operationResult = $editor->applySetPassHeightWhenRenderedLteSavedOperation(
                $operation->setName,
                $operation->valueRaw,
                $operation->includeEscapeWidth,
                $operation->baseVersion,
            );
        } elseif ($operation->operation === 'settings.setAllowAnyHeight') {
            $operationResult = $editor->applySetAllowAnyHeightOperation(
                $operation->setName,
                $operation->valueRaw,
                $operation->includeEscapeWidth,
                $operation->baseVersion,
            );
        } else {
            $operationResult = $editor->applySetDimensionOperation(
                $operation->setName,
                $operation->scopeMode,
                $operation->scopeBreakpoint,
                $operation->value,
                $operation->field,
                $operation->includeEscapeWidth,
                $operation->baseVersion,
            );
        }

        $validation = is_array($operationResult['validation'] ?? null)
            ? $operationResult['validation']
            : $editor->defaultValidation();
        $persisted = ($operationResult['persisted'] ?? false) === true;
        $conflict = ($operationResult['conflict'] ?? false) === true;
        $statusMessage = $this->buildOperationStatusMessage($operation->field, $persisted, $conflict, $operationResult);
        $statusKind = $conflict ? 'conflict' : ($persisted ? 'success' : 'error');
        $resolvedBaseVersion = trim((string)($operationResult['currentVersion'] ?? ''));

        $events = [];
        if (($persisted || $conflict) && $resolvedBaseVersion !== '') {
            $events[] = new PatchSignals([
                'editor' => [
                    'baseVersion' => $resolvedBaseVersion,
                ],
            ]);
        }

        $shouldPatchCard = $persisted || $conflict;
        if ($shouldPatchCard) {
            $editScopeBySet = $this->buildCardEditScope($operation->setName, $operation->scopeMode, $operation->scopeBreakpoint);
            $editTabBySet = $this->buildCardEditTab($operation->setName, $operation->field);
            $selectedAssetKeyBySet = $this->buildCardSelectedAssetKey($operation->setName);
            $cardMarkup = $editor->renderCardFragment(
                $operation->setName,
                $editScopeBySet,
                $editTabBySet,
                $selectedAssetKeyBySet,
            );
            $cardId = $this->buildCardDomId($operation->setName);
            if ($cardMarkup !== '') {
                $events[] = new PatchElements($cardMarkup);
            } elseif ($cardId !== '') {
                $events[] = new PatchElements('', [
                    'selector' => '#' . $cardId,
                    'mode' => 'remove',
                ]);
            }
        }

        if ($persisted && $operation->setName !== '') {
            $signalKey = $this->buildCardSignalKey($operation->setName);
            if ($signalKey !== '') {
                if ($operation->field === 'deleteSet') {
                    $events[] = new PatchElements($this->renderEditorStatusFragment($statusKind, $statusMessage));

                    return $this->asDatastarEventStream($events);
                }
            }
        }

        $events[] = new PatchElements($this->renderEditorStatusFragment($statusKind, $statusMessage));

        return $this->asDatastarEventStream($events);
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
        $result = $this->readBodyArrayParam('result');
        $editScopeBySet = $this->readBodyArrayParam('editScopeBySet');
        $editTabBySet = $this->readBodyArrayParam('editTabBySet');
        $selectedAssetKeyBySet = $this->readBodyArrayParam('selectedAssetKeyBySet');
        $preferredOrderBySet = $this->readBodyArrayParam('preferredOrderBySet');

        $rendered = $editor->renderInitialStoredReview(
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
            $result,
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
        return $this->asDatastarEventStream([$event]);
    }

    /**
     * @param array<int, object> $events
     */
    private function asDatastarEventStream(array $events): Response
    {
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
        $output = '';
        foreach ($events as $event) {
            if (is_object($event) && method_exists($event, 'getOutput')) {
                $output .= (string)$event->getOutput();
            }
        }
        $response->content = $output;

        return $response;
    }

    private function renderEditorStatusFragment(string $kind, string $message): string
    {
        $escapedKind = htmlspecialchars($kind, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return sprintf(
            '<div id="bpts-editor-status" data-kind="%s" data-message="%s" class="visually-hidden">%s</div>',
            $escapedKind,
            $escapedMessage,
            $escapedMessage,
        );
    }

    private function buildCardDomId(string $setName): string
    {
        $signalKey = $this->buildCardSignalKey($setName);
        if ($signalKey === '') {
            return '';
        }

        return 'bpts-card-' . $signalKey;
    }

    private function buildCardSignalKey(string $setName): string
    {
        $normalized = trim($setName);
        if ($normalized === '') {
            return '';
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($normalized));
        $slug = trim((string)$slug, '-');
        if ($slug === '') {
            $slug = 'transform';
        }

        return 't_' . str_replace('-', '_', $slug) . '_' . substr(sha1($normalized), 0, 8);
    }

    /**
     * @return array<string, array{mode: string, breakpoint: ?int}>
     */
    private function buildCardEditScope(string $setName, string $scopeMode, ?int $scopeBreakpoint): array
    {
        if ($setName === '') {
            return [];
        }

        if ($scopeMode === 'breakpoint' && $scopeBreakpoint !== null) {
            return [
                $setName => [
                    'mode' => 'breakpoint',
                    'breakpoint' => $scopeBreakpoint,
                ],
            ];
        }

        if ($scopeMode === 'all') {
            return [
                $setName => [
                    'mode' => 'all',
                    'breakpoint' => null,
                ],
            ];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function buildCardEditTab(string $setName, string $field): array
    {
        if ($setName === '') {
            return [];
        }

        $tab = match ($field) {
            'ratio' => 'ratio',
            'passHeightWhenRenderedLteSaved', 'allowAnyHeight' => 'settings',
            default => 'dimensions',
        };

        return [$setName => $tab];
    }

    /**
     * @return array<string, string>
     */
    private function buildCardSelectedAssetKey(string $setName): array
    {
        if ($setName === '') {
            return [];
        }

        $selectedAssetKey = $this->readRequestedCardSelectedAssetKey($setName);
        if ($selectedAssetKey === null) {
            return [];
        }

        return [$setName => $selectedAssetKey];
    }

    /**
     * @return array<string, string>
     */
    private function buildCardRequestedEditTab(string $setName): array
    {
        if ($setName === '') {
            return [];
        }

        $requestedTab = trim((string)$this->readRequestedCardSignalString($setName, 'activeTab'));
        if ($requestedTab === '') {
            return [];
        }

        $normalizedTab = strtolower($requestedTab);
        if (!in_array($normalizedTab, ['dimensions', 'ratio', 'settings'], true)) {
            return [];
        }

        return [$setName => $normalizedTab];
    }

    private function readRequestedCardSelectedAssetKey(string $setName): ?string
    {
        $rawSelectedAssetKey = $this->readRequestedCardSignalString($setName, 'selectedAssetKey');
        if ($rawSelectedAssetKey === null) {
            return null;
        }

        $selectedAssetKey = trim($rawSelectedAssetKey);
        return $selectedAssetKey !== '' ? $selectedAssetKey : null;
    }

    private function readRequestedCardSignalString(string $setName, string $key): ?string
    {
        if ($setName === '' || $key === '') {
            return null;
        }

        $signalKey = $this->buildCardSignalKey($setName);
        if ($signalKey === '') {
            return null;
        }

        $editorSignals = $this->request->getBodyParam('editor');
        if (!is_array($editorSignals)) {
            return null;
        }

        $cards = $editorSignals['cards'] ?? null;
        if (!is_array($cards)) {
            return null;
        }

        $card = $cards[$signalKey] ?? null;
        if (!is_array($card)) {
            return null;
        }

        $rawValue = $card[$key] ?? null;
        return is_string($rawValue) ? $rawValue : null;
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

    private function buildOperationStatusMessage(string $field, bool $persisted, bool $conflict, array $operationResult = []): string
    {
        if (!$persisted && $conflict) {
            return 'Version is out of date. Refresh and retry.';
        }

        if (!$persisted) {
            $validation = is_array($operationResult['validation'] ?? null)
                ? $operationResult['validation']
                : [];
            $globalMessages = isset($validation['global']) && is_array($validation['global'])
                ? $validation['global']
                : [];

            foreach ($globalMessages as $globalMessage) {
                if (!is_string($globalMessage)) {
                    continue;
                }

                $trimmed = trim($globalMessage);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
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