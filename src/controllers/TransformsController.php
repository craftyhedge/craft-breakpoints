<?php

namespace craftyhedge\craftbreakpoints\controllers;

use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\TransformEditor;
use craftyhedge\craftbreakpoints\services\transformeditor\CardOperationRequest;
use craftyhedge\craftbreakpoints\services\transformeditor\Support;
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
            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'operation is required and must be a supported command.')),
            ]);
        }

        if ($operation->operation === 'scope.selectAll' || $operation->operation === 'scope.selectBreakpoint') {
            return $this->handleScopeSelect($operation, $editor);
        }

        if ($operation->operation === 'ratio.copyFromRenderedBreakpoint') {
            return $this->handleRatioCopyFromRendered($operation, $editor);
        }

        $operationResult = match ($operation->operation) {
            'renderedValues.apply'                       => $this->dispatchRenderedValuesApply($operation, $editor),
            'set.delete'                                 => $editor->deleteSetOperation($operation->setName, $operation->baseVersion),
            'dimensions.apply'                           => $this->dispatchDimensionsApply($operation, $editor),
            'dimensions.toggleAutoWidth'                 => $this->dispatchToggleAutoWidth($operation, $editor),
            'dimensions.toggleAutoHeight'                => $this->dispatchToggleAutoHeight($operation, $editor),
            'ratio.apply'                                => $this->dispatchRatioApply($operation, $editor),
            'breakpoint.toggleEnabled'                   => $this->dispatchBreakpointToggle($operation, $editor),
            'settings.setPassHeightWhenRenderedLteSaved' => $editor->applySetPassHeightWhenRenderedLteSavedOperation(
                $operation->setName,
                $operation->valueRaw,
                $operation->includeEscapeWidth,
                $operation->baseVersion,
            ),
            'settings.setAllowAnyHeight'                 => $editor->applySetAllowAnyHeightOperation(
                $operation->setName,
                $operation->valueRaw,
                $operation->includeEscapeWidth,
                $operation->baseVersion,
            ),
            default                                      => $editor->applySetDimensionOperation(
                $operation->setName,
                $operation->scopeMode,
                $operation->scopeBreakpoint,
                $operation->value,
                $operation->field,
                $operation->includeEscapeWidth,
                $operation->baseVersion,
                $operation->scopeBreakpointKey,
            ),
        };

        return $this->buildOperationResponse($operation, $operationResult, $editor);
    }

    private function handleScopeSelect(CardOperationRequest $operation, TransformEditor $editor): Response
    {
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
        $signalKey = $this->buildCardSignalKey($operation->setName);
        if ($signalKey === '') {
            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'Unable to resolve card state key.')),
            ]);
        }

        $requestedTab = $this->readRequestedCardSignalString($operation->setName, 'activeTab');
        $activeTab = in_array($requestedTab, ['dimensions', 'ratio', 'settings'], true) ? $requestedTab : 'dimensions';

        $cardSignals = [
            'scopeMode' => $scopeMode,
            'scopeBreakpoint' => $scopeBreakpoint !== null ? (string)$scopeBreakpoint : '',
            'scopeActive' => ($scopeMode === 'breakpoint' && $scopeBreakpoint !== null) ? '1' : '0',
            'activeTab' => $activeTab,
        ];

        if ($scopeMode === 'breakpoint' && $scopeBreakpoint !== null) {
            $scopeValues = $editor->buildScopeValuesForBreakpoint($operation->setName, $scopeBreakpoint, $operation->includeEscapeWidth);
            $cardSignals['widthInput'] = $scopeValues['widthInput'] ?? '';
            $cardSignals['heightInput'] = $scopeValues['heightInput'] ?? '';
            $cardSignals['widthAuto'] = $scopeValues['widthAuto'] ?? '0';
            $cardSignals['heightAuto'] = $scopeValues['heightAuto'] ?? '0';
            $cardSignals['ratioLocked'] = $scopeValues['ratioLocked'] ?? '0';
            $cardSignals['ratioWidthInput'] = $scopeValues['ratioWidthInput'] ?? '';
            $cardSignals['ratioHeightInput'] = $scopeValues['ratioHeightInput'] ?? '';
            $cardSignals['ratioFloatInput'] = $scopeValues['ratioFloatInput'] ?? '';
            $cardSignals['ratioSourceDimension'] = $scopeValues['ratioSourceDimension'] ?? 'width';
        }

        return $this->asDatastarEventStream([
            new PatchSignals([
                'editor' => [
                    'cards' => [
                        $signalKey => $cardSignals,
                    ],
                ],
            ]),
        ]);
    }

    private function handleRatioCopyFromRendered(CardOperationRequest $operation, TransformEditor $editor): Response
    {
        if ($operation->setName === '') {
            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'setName is required.')),
            ]);
        }

        $sourceBreakpoint = $this->readRequestedCardSignalNullablePositiveInt($operation->setName, 'ratioSourceBreakpoint')
            ?? $operation->ratioSourceBreakpoint;
        if ($sourceBreakpoint === null) {
            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'ratioSourceBreakpoint is required.')),
            ]);
        }

        $copiedRatio = $editor->applySetCopyRatioFromRenderedBreakpointOperation(
            $operation->setName,
            $sourceBreakpoint,
        );
        if ($copiedRatio === null) {
            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'No rendered ratio source found for selected breakpoint.')),
            ]);
        }

        $signalKey = $this->buildCardSignalKey($operation->setName);
        if ($signalKey === '') {
            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'Unable to resolve card state key.')),
            ]);
        }

        return $this->asDatastarEventStream([
            new PatchSignals([
                'editor' => [
                    'cards' => [
                        $signalKey => [
                            'ratioWidthInput' => (string)$copiedRatio['width'],
                            'ratioHeightInput' => (string)$copiedRatio['height'],
                            'ratioFloatInput' => Support::formatRatioFloatInput($copiedRatio['width'], $copiedRatio['height']),
                            'ratioLocked' => '0',
                        ],
                    ],
                ],
            ]),
            new PatchElements($this->renderEditorStatusFragment('success', 'Copied rendered ratio for selected breakpoint.')),
        ]);
    }

    private function dispatchRenderedValuesApply(CardOperationRequest $operation, TransformEditor $editor): array
    {
        return $editor->applyRenderedValuesOperation(
            $operation->setName,
            $operation->selectedAssetKey,
            $operation->includeEscapeWidth,
            $operation->clearAuto,
            $operation->baseVersion,
        );
    }

    private function dispatchDimensionsApply(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestedScope = $this->readRequestedCardScope($operation->setName);
        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;
        $scopeBreakpoint = $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint;

        return $editor->applySetDimensionsOperation(
            $operation->setName,
            $scopeMode,
            $scopeBreakpoint,
            $this->readRequestedCardSignalNullablePositiveInt($operation->setName, 'widthInput') ?? $operation->width,
            $this->readRequestedCardSignalNullablePositiveInt($operation->setName, 'heightInput') ?? $operation->height,
            $operation->includeEscapeWidth,
            $this->readRequestedCardSignalBool($operation->setName, 'widthAuto') ?? $operation->widthAuto,
            $this->readRequestedCardSignalBool($operation->setName, 'heightAuto') ?? $operation->heightAuto,
            $operation->forceAll,
            $operation->baseVersion,
            $operation->scopeBreakpointKey,
        );
    }

    private function dispatchToggleAutoWidth(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestedScope = $this->readRequestedCardScope($operation->setName);
        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;
        $scopeBreakpoint = $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint;

        return $editor->applySetToggleAutoWidthOperation(
            $operation->setName,
            $scopeMode,
            $scopeBreakpoint,
            $this->readRequestedCardSignalNullablePositiveInt($operation->setName, 'heightInput') ?? $operation->height,
            $operation->selectedAssetKey,
            $operation->includeEscapeWidth,
            $operation->baseVersion,
            $operation->scopeBreakpointKey,
        );
    }

    private function dispatchToggleAutoHeight(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestedScope = $this->readRequestedCardScope($operation->setName);
        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;
        $scopeBreakpoint = $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint;

        return $editor->applySetToggleAutoHeightOperation(
            $operation->setName,
            $scopeMode,
            $scopeBreakpoint,
            $this->readRequestedCardSignalNullablePositiveInt($operation->setName, 'widthInput') ?? $operation->width,
            $operation->selectedAssetKey,
            $operation->includeEscapeWidth,
            $operation->baseVersion,
            $operation->scopeBreakpointKey,
        );
    }

    private function dispatchRatioApply(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestedScope = $this->readRequestedCardScope($operation->setName);
        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;
        $scopeBreakpoint = $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint;

        $ratioWidth = $this->readRequestedCardSignalNullablePositiveInt($operation->setName, 'ratioWidthInput') ?? $operation->ratioWidth;
        $ratioHeight = $this->readRequestedCardSignalNullablePositiveInt($operation->setName, 'ratioHeightInput') ?? $operation->ratioHeight;

        if ($ratioWidth === null || $ratioHeight === null) {
            $ratioFloat = Support::parseNullablePositiveFloat(
                $this->readRequestedCardSignalString($operation->setName, 'ratioFloatInput'),
            ) ?? $operation->ratioFloat;
            $ratioPair = Support::approximateRatioPairFromFloat($ratioFloat);
            if ($ratioPair !== null) {
                $ratioWidth = $ratioPair['width'];
                $ratioHeight = $ratioPair['height'];
            }
        }

        return $editor->applySetRatioOperation(
            $operation->setName,
            $scopeMode,
            $scopeBreakpoint,
            $ratioWidth,
            $ratioHeight,
            $this->readRequestedCardSignalString($operation->setName, 'ratioSourceDimension') ?? $operation->ratioSourceDimension,
            $operation->includeEscapeWidth,
            $operation->baseVersion,
            $operation->scopeBreakpointKey,
        );
    }

    private function dispatchBreakpointToggle(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestBody = $this->request->getBodyParams();
        $enabledProvided = is_array($requestBody) && array_key_exists('enabled', $requestBody);

        return $editor->applySetBreakpointEnabledOperation(
            $operation->setName,
            $operation->scopeBreakpoint,
            $operation->enabled,
            $operation->includeEscapeWidth,
            $operation->baseVersion,
            $enabledProvided,
            $operation->scopeBreakpointKey,
        );
    }

    private function buildOperationResponse(CardOperationRequest $operation, array $operationResult, TransformEditor $editor): Response
    {
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

        if ($persisted || $conflict) {
            $savedSetNames = array_values(array_filter(
                array_map(
                    static fn(array $row): string => trim((string)($row['name'] ?? '')),
                    array_filter(
                        $editor->buildSidebarTransformRows(),
                        static fn(mixed $row): bool => is_array($row) && (($row['isObservedUnsaved'] ?? false) !== true),
                    ),
                ),
                static fn(string $name): bool => $name !== '',
            ));

            $events[] = new PatchSignals([
                'sidebar' => [
                    'savedSetNamesJson' => json_encode($savedSetNames, JSON_UNESCAPED_SLASHES) ?: '[]',
                ],
            ]);
        }

        if ($persisted && $operation->setName !== '') {
            $signalKey = $this->buildCardSignalKey($operation->setName);
            if ($signalKey !== '') {
                if ($operation->field === 'deleteSet') {
                    $cardId = $this->buildCardDomId($operation->setName);
                    if ($cardId !== '') {
                        $events[] = new PatchElements('', [
                            'selector' => '#' . $cardId,
                            'mode' => 'remove',
                        ]);
                    }
                    $events[] = new PatchElements($this->renderEditorStatusFragment($statusKind, $statusMessage));

                    return $this->asDatastarEventStream($events);
                }

                $selectedAssetKeyBySet = $this->buildCardSelectedAssetKey($operation->setName);
                $selectedAssetKey = $selectedAssetKeyBySet[$operation->setName] ?? null;
                $hideRenderedApply = Support::parseNullableBool($this->request->getBodyParam('hideRenderedApply')) === true;
                $requestedReviewMode = strtolower(trim((string)$this->request->getBodyParam('reviewMode', '')));
                $reviewMode = in_array($requestedReviewMode, ['processed', 'saved'], true)
                    ? $requestedReviewMode
                    : ($hideRenderedApply ? 'saved' : 'processed');
                $deltas = $editor->buildSignalDeltasForTransform($operation->setName, $selectedAssetKey, $hideRenderedApply, $reviewMode);
                if (!empty($deltas['rowsByBreakpoint'])) {
                    $events[] = new PatchSignals([
                        'editor' => [
                            'cards' => [
                                $signalKey => [
                                    'rowsByBreakpoint' => $deltas['rowsByBreakpoint'],
                                ],
                            ],
                        ],
                    ]);
                }

                // Applying rendered values saves the set (possibly creating a previously
                // missing one) but does not re-verify it against a render. Flip the card's
                // reactive warning state so the "Transform Set Missing" banner is replaced
                // with the "Process Again" notice without a full card re-render.
                if ($operation->operation === 'renderedValues.apply' && $reviewMode === 'processed') {
                    $events[] = new PatchSignals([
                        'editor' => [
                            'cards' => [
                                $signalKey => [
                                    'setReviewState' => 'awaitingReprocess',
                                ],
                            ],
                        ],
                    ]);
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
     * @return array{string, ?int}
     */
    private function resolveCardEditScopeForOperation(string $setName, string $scopeMode, ?int $scopeBreakpoint): array
    {
        if ($setName === '') {
            return [$scopeMode, $scopeBreakpoint];
        }

        $requestBody = $this->request->getBodyParams();
        if (is_array($requestBody) && array_key_exists('scopeMode', $requestBody)) {
            return [$scopeMode, $scopeBreakpoint];
        }

        $requestedScope = $this->readRequestedCardScope($setName);
        if ($requestedScope === null) {
            if ($scopeBreakpoint !== null) {
                return ['breakpoint', $scopeBreakpoint];
            }

            return [$scopeMode, $scopeBreakpoint];
        }

        return [$requestedScope['mode'], $requestedScope['breakpoint']];
    }

    /**
     * @return array{mode: string, breakpoint: ?int}|null
     */
    private function readRequestedCardScope(string $setName): ?array
    {
        $rawScopeMode = trim((string)$this->readRequestedCardSignalString($setName, 'scopeMode'));
        if ($rawScopeMode === 'all') {
            return [
                'mode' => 'all',
                'breakpoint' => null,
            ];
        }

        if ($rawScopeMode !== 'breakpoint') {
            return null;
        }

        $rawScopeBreakpoint = trim((string)$this->readRequestedCardSignalString($setName, 'scopeBreakpoint'));
        if ($rawScopeBreakpoint === '' || !preg_match('/^\d+$/', $rawScopeBreakpoint)) {
            return null;
        }

        $scopeBreakpoint = (int)$rawScopeBreakpoint;
        if ($scopeBreakpoint <= 0) {
            return null;
        }

        return [
            'mode' => 'breakpoint',
            'breakpoint' => $scopeBreakpoint,
        ];
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

    private function readRequestedCardSignalNullablePositiveInt(string $setName, string $key): ?int
    {
        $rawValue = $this->readRequestedCardSignalString($setName, $key);
        if ($rawValue === null) {
            return null;
        }

        return Support::parseNullablePositiveInt($rawValue);
    }

    private function readRequestedCardSignalBool(string $setName, string $key): ?bool
    {
        $rawValue = $this->readRequestedCardSignalString($setName, $key);
        if ($rawValue === null) {
            return null;
        }

        return Support::parseNullableBool($rawValue);
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
