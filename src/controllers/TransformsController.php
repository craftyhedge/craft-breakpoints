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
use Throwable;
use yii\web\Response;
use yii\web\ForbiddenHttpException;

class TransformsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->getTelemetry()->canEditTransforms()) {
            throw new ForbiddenHttpException('Transform editing is disabled in this environment.');
        }

        return true;
    }

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

        $operation = CardOperationRequest::fromRequest(
            $this->request,
            Plugin::getInstance()->getTransformStore()->getCurrentVersion(),
        );

        $this->requireTransformEditPermission($operation);

        $editor = Plugin::getInstance()->getTransformEditor();

        if (!$operation->hasValidOperation) {
            Plugin::warning('Transform card operation rejected: ' . $this->formatOperationLogContext($operation, [
                'statusMessage' => 'operation is required and must be a supported command.',
            ]));

            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'operation is required and must be a supported command.')),
            ]);
        }

        if ($operation->operation === 'scope.selectAll' || $operation->operation === 'scope.selectBreakpoint') {
            return $this->handleScopeSelect($operation, $editor);
        }

        try {
            $operationResult = match ($operation->operation) {
                'renderedValues.apply'                       => $this->dispatchRenderedValuesApply($operation, $editor),
                'set.delete'                                 => $editor->deleteSetOperation($operation->setName, $operation->baseVersion),
                'dimensions.apply'                           => $this->dispatchDimensionsApply($operation, $editor),
                'dimensions.toggleAutoWidth'                 => $this->dispatchToggleAutoWidth($operation, $editor),
                'dimensions.toggleAutoHeight'                => $this->dispatchToggleAutoHeight($operation, $editor),
                'ratio.apply'                                => $this->dispatchRatioApply($operation, $editor),
                'ratio.remove'                               => $this->dispatchRatioRemove($operation, $editor),
                'ratio.copyFromRenderedBreakpoint'           => $this->dispatchRatioCopyFromRendered($operation, $editor),
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
                'set.notes.update'                           => $editor->applySetNotesOperation(
                    $operation->setName,
                    $operation->notes,
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
        } catch (Throwable $exception) {
            Plugin::error('Transform card operation threw: ' . $this->formatOperationLogContext($operation, [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]));

            throw $exception;
        }

        return $this->buildOperationResponse($operation, $operationResult, $editor);
    }

    private function handleScopeSelect(CardOperationRequest $operation, TransformEditor $editor): Response
    {
        if ($operation->setName === '') {
            $this->logCardOperationResponseFailure($operation, 'setName is required.');

            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'setName is required.')),
            ]);
        }

        if ($operation->operation === 'scope.selectBreakpoint' && $operation->scopeBreakpoint === null) {
            $this->logCardOperationResponseFailure($operation, 'scopeBreakpoint is required when selecting a breakpoint.');

            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'scopeBreakpoint is required when selecting a breakpoint.')),
            ]);
        }

        $scopeMode = $operation->operation === 'scope.selectAll' ? 'all' : 'breakpoint';
        $scopeBreakpoint = $scopeMode === 'breakpoint' ? $operation->scopeBreakpoint : null;
        $scopeBreakpointKey = $scopeMode === 'breakpoint' ? ($operation->scopeBreakpointKey ?? '') : '';
        $signalKey = $this->buildCardSignalKey($operation->setName);
        if ($signalKey === '') {
            $this->logCardOperationResponseFailure($operation, 'Unable to resolve card state key.');

            return $this->asDatastarEventStream([
                new PatchElements($this->renderEditorStatusFragment('error', 'Unable to resolve card state key.')),
            ]);
        }

        $scopeValues = $scopeMode === 'breakpoint' && $scopeBreakpoint !== null
            ? $editor->buildScopeValuesForBreakpoint($operation->setName, $scopeBreakpoint, $operation->includeEscapeWidth)
            : $editor->buildScopeValuesForAll($operation->setName, $operation->includeEscapeWidth);

        $activeTab = $this->normalizeCardActiveTab(
            $this->readRequestedCardActiveTab($operation->setName),
            $scopeMode,
            $scopeValues,
        );

        $cardSignals = [
            'scopeMode' => $scopeMode,
            'scopeBreakpoint' => $scopeBreakpoint !== null ? (string)$scopeBreakpoint : '',
            'scopeBreakpointKey' => $scopeBreakpointKey,
            'activeTab' => $activeTab,
            'widthInput' => $scopeValues['widthInput'] ?? '',
            'heightInput' => $scopeValues['heightInput'] ?? '',
            'widthAuto' => $scopeValues['widthAuto'] ?? '0',
            'heightAuto' => $scopeValues['heightAuto'] ?? '0',
            'ratioLocked' => $scopeValues['ratioLocked'] ?? '0',
            'ratioWidthInput' => $scopeValues['ratioWidthInput'] ?? '',
            'ratioHeightInput' => $scopeValues['ratioHeightInput'] ?? '',
            'ratioFloatInput' => $scopeValues['ratioFloatInput'] ?? '',
            'ratioSourceDimension' => $scopeValues['ratioSourceDimension'] ?? 'width',
        ];

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

    /**
     * Resolves the source breakpoint's saved ratio and applies it to the current
     * scope in one step — "Copy ratio" auto-applies rather than only pre-filling
     * the inputs. The copied pair is echoed back into the ratio inputs by
     * buildOperationResponse so the panel shows what was applied.
     *
     * @return array<string, mixed>
     */
    private function dispatchRatioCopyFromRendered(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $validation = Support::defaultValidation();

        if ($operation->setName === '') {
            Support::addGlobalError($validation, 'setName is required.');

            return ['persisted' => false, 'validation' => $validation];
        }

        $sourceBreakpointKey = $this->readRequestedCardSignalString($operation->setName, 'ratioSourceBreakpointKey')
            ?? $operation->ratioSourceBreakpointKey;
        $sourceBreakpointKey = is_string($sourceBreakpointKey) ? trim($sourceBreakpointKey) : '';
        if ($sourceBreakpointKey === '') {
            Support::addGlobalError($validation, 'ratioSourceBreakpointKey is required.');

            return ['persisted' => false, 'validation' => $validation];
        }

        $copiedRatio = $editor->applySetCopyRatioFromRenderedBreakpointOperation(
            $operation->setName,
            $sourceBreakpointKey,
        );
        if ($copiedRatio === null) {
            Support::addGlobalError($validation, 'No saved ratio source found for the selected breakpoint.');

            return ['persisted' => false, 'validation' => $validation];
        }

        $requestedScope = $this->readRequestedCardScope($operation->setName);

        $result = $editor->applySetRatioOperation(
            $operation->setName,
            $requestedScope['mode'] ?? $operation->scopeMode,
            $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint,
            (int)$copiedRatio['width'],
            (int)$copiedRatio['height'],
            $this->readRequestedCardSignalString($operation->setName, 'ratioSourceDimension') ?? $operation->ratioSourceDimension ?? 'width',
            $operation->includeEscapeWidth,
            $operation->baseVersion,
            $requestedScope['key'] ?? $operation->scopeBreakpointKey,
        );

        $result['copiedRatio'] = [
            'width' => (int)$copiedRatio['width'],
            'height' => (int)$copiedRatio['height'],
        ];
        $operationDetails = is_array($result['operationDetails'] ?? null) ? $result['operationDetails'] : [];
        $operationDetails['copiedRatioLabel'] = $copiedRatio['width'] . ':' . $copiedRatio['height'];
        $operationDetails['copiedRatioSourceKey'] = $sourceBreakpointKey;
        $result['operationDetails'] = $operationDetails;

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchRenderedValuesApply(CardOperationRequest $operation, TransformEditor $editor): array
    {
        return $editor->applyRenderedValuesOperation(
            $operation->setName,
            $operation->selectedAssetKey,
            $operation->includeEscapeWidth,
            $operation->clearAuto,
            $operation->baseVersion,
            $operation->hiddenBreakpoints,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchDimensionsApply(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestedScope = $this->readRequestedCardScope($operation->setName);
        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;
        $scopeBreakpoint = $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint;
        $scopeBreakpointKey = $requestedScope['key'] ?? $operation->scopeBreakpointKey;

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
            $scopeBreakpointKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchToggleAutoWidth(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestedScope = $this->readRequestedCardScope($operation->setName);
        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;
        $scopeBreakpoint = $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint;
        $scopeBreakpointKey = $requestedScope['key'] ?? $operation->scopeBreakpointKey;

        return $editor->applySetToggleAutoWidthOperation(
            $operation->setName,
            $scopeMode,
            $scopeBreakpoint,
            $this->readRequestedCardSignalNullablePositiveInt($operation->setName, 'heightInput') ?? $operation->height,
            $operation->selectedAssetKey,
            $operation->includeEscapeWidth,
            $operation->baseVersion,
            $scopeBreakpointKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchToggleAutoHeight(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestedScope = $this->readRequestedCardScope($operation->setName);
        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;
        $scopeBreakpoint = $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint;
        $scopeBreakpointKey = $requestedScope['key'] ?? $operation->scopeBreakpointKey;

        return $editor->applySetToggleAutoHeightOperation(
            $operation->setName,
            $scopeMode,
            $scopeBreakpoint,
            $this->readRequestedCardSignalNullablePositiveInt($operation->setName, 'widthInput') ?? $operation->width,
            $operation->selectedAssetKey,
            $operation->includeEscapeWidth,
            $operation->baseVersion,
            $scopeBreakpointKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchRatioApply(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestedScope = $this->readRequestedCardScope($operation->setName);
        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;
        $scopeBreakpoint = $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint;
        $scopeBreakpointKey = $requestedScope['key'] ?? $operation->scopeBreakpointKey;

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
            $scopeBreakpointKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchRatioRemove(CardOperationRequest $operation, TransformEditor $editor): array
    {
        $requestedScope = $this->readRequestedCardScope($operation->setName);

        return $editor->applySetRatioRemoveOperation(
            $operation->setName,
            $requestedScope['mode'] ?? $operation->scopeMode,
            $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint,
            $operation->includeEscapeWidth,
            $operation->baseVersion,
            $requestedScope['key'] ?? $operation->scopeBreakpointKey,
        );
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $operationResult
     */
    private function buildOperationResponse(CardOperationRequest $operation, array $operationResult, TransformEditor $editor): Response
    {
        $persisted = ($operationResult['persisted'] ?? false) === true;
        $conflict = ($operationResult['conflict'] ?? false) === true;
        $statusMessage = $this->buildOperationStatusMessage($operation->field, $persisted, $conflict, $operationResult);
        $statusKind = $conflict ? 'conflict' : ($persisted ? 'success' : 'error');
        $resolvedBaseVersion = trim((string)($operationResult['currentVersion'] ?? ''));

        if (!$persisted) {
            Plugin::warning('Transform card operation did not persist: ' . $this->formatOperationLogContext($operation, [
                'statusKind' => $statusKind,
                'statusMessage' => $statusMessage,
                'currentVersion' => $resolvedBaseVersion,
                'validation' => $this->summarizeValidationForLog($operationResult['validation'] ?? null),
                'operationDetails' => $this->summarizeOperationDetailsForLog($operationResult['operationDetails'] ?? null),
            ]));
        }

        $events = [];
        if (($persisted || $conflict) && $resolvedBaseVersion !== '') {
            $events[] = new PatchSignals([
                'editor' => [
                    'baseVersion' => $resolvedBaseVersion,
                ],
            ]);
        }

        if ($persisted || $conflict) {
            $savedSetNames = $this->buildSavedSetNames($editor);

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
                $cardSignalPatch = [];
                foreach ([
                    'hasCurrentBreakpointMismatch',
                    'hasResolvedBreakpointMismatchAwaitingVerification',
                    'hasCardWarningDanger',
                ] as $deltaKey) {
                    if (array_key_exists($deltaKey, $deltas)) {
                        $cardSignalPatch[$deltaKey] = (bool)($deltas[$deltaKey] ?? false);
                    }
                }
                if (!empty($deltas['rowsByBreakpoint'])) {
                    $cardSignalPatch['rowsByBreakpoint'] = $deltas['rowsByBreakpoint'];
                    if ($this->operationMayChangeAllScopeAutoSignals($operation)) {
                        $autoSignals = $this->buildAllScopeAutoSignalsFromRows($deltas['rowsByBreakpoint']);
                        $cardSignalPatch = array_merge($cardSignalPatch, $autoSignals);
                        $cardSignalPatch['activeTab'] = $this->normalizeCardActiveTab(
                            $this->readRequestedCardActiveTab($operation->setName),
                            'all',
                            [
                                'widthAuto' => $autoSignals['widthAuto'] ?? '0',
                                'heightAuto' => $autoSignals['heightAuto'] ?? '0',
                            ],
                        );
                    }
                    // Refresh the edit-panel scope values after operations that change
                    // saved values the inputs are bound to (rendered apply rewrites
                    // dimensions; ratio removal clears the ratio inputs/lock; ratio
                    // copy auto-applies the copied pair).
                    $refreshesScopeValues = in_array($operation->operation, [
                        'renderedValues.apply',
                        'ratio.remove',
                        'ratio.copyFromRenderedBreakpoint',
                    ], true);
                    if ($refreshesScopeValues) {
                        $requestedScope = $this->readRequestedCardScope($operation->setName);
                        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;
                        $scopeBreakpoint = $requestedScope['breakpoint'] ?? $operation->scopeBreakpoint;
                        $scopeValues = $scopeMode === 'breakpoint' && $scopeBreakpoint !== null
                            ? $editor->buildScopeValuesForBreakpoint($operation->setName, $scopeBreakpoint, $operation->includeEscapeWidth)
                            : $editor->buildScopeValuesForAll($operation->setName, $operation->includeEscapeWidth);

                        $cardSignalPatch = array_merge($cardSignalPatch, [
                            'activeTab' => $this->normalizeCardActiveTab(
                                $this->readRequestedCardActiveTab($operation->setName),
                                $scopeMode,
                                $scopeValues,
                            ),
                            'widthInput' => $scopeValues['widthInput'] ?? '',
                            'heightInput' => $scopeValues['heightInput'] ?? '',
                            'widthAuto' => $scopeValues['widthAuto'] ?? '0',
                            'heightAuto' => $scopeValues['heightAuto'] ?? '0',
                            'ratioLocked' => $scopeValues['ratioLocked'] ?? '0',
                            'ratioWidthInput' => $scopeValues['ratioWidthInput'] ?? '',
                            'ratioHeightInput' => $scopeValues['ratioHeightInput'] ?? '',
                            'ratioFloatInput' => $scopeValues['ratioFloatInput'] ?? '',
                            'ratioSourceDimension' => $scopeValues['ratioSourceDimension'] ?? 'width',
                        ]);

                        // After a partial all-scope apply the aggregated ratio values can
                        // be blank (skipped breakpoints kept their old ratio), so echo the
                        // copied pair into the inputs regardless of the aggregate.
                        $copiedRatio = $operationResult['copiedRatio'] ?? null;
                        if ($operation->operation === 'ratio.copyFromRenderedBreakpoint' && is_array($copiedRatio)) {
                            $copiedWidth = (int)($copiedRatio['width'] ?? 0);
                            $copiedHeight = (int)($copiedRatio['height'] ?? 0);
                            if ($copiedWidth > 0 && $copiedHeight > 0) {
                                $cardSignalPatch['ratioWidthInput'] = (string)$copiedWidth;
                                $cardSignalPatch['ratioHeightInput'] = (string)$copiedHeight;
                                $cardSignalPatch['ratioFloatInput'] = Support::formatRatioFloatInput($copiedWidth, $copiedHeight);
                            }
                        }
                    }
                }

                if ($operation->operation === 'set.notes.update') {
                    $cardSignalPatch['notesInput'] = (string)($operationResult['notes'] ?? $operation->notes);
                }

                if ($cardSignalPatch !== []) {
                    $events[] = new PatchSignals([
                        'editor' => [
                            'cards' => [
                                $signalKey => $cardSignalPatch,
                            ],
                        ],
                    ]);
                }

                if ($reviewMode === 'processed') {
                    $events[] = new PatchSignals([
                        'editor' => [
                            'cards' => [
                                $signalKey => [
                                    'setReviewState' => $editor->isTransformEditedSinceLatestProcess($operation->setName)
                                        ? 'awaitingReprocess'
                                        : 'ok',
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
        $newSetNames = $this->readBodyArrayParam('newSetNames');

        $rendered = $editor->renderResultReview(
            $result,
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
            false,
            false,
            TransformEditor::REVIEW_MODE_PROCESSED,
            null,
            $newSetNames,
        );

        return $this->asJson($rendered);
    }

    public function actionRenderTransformCard(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();

        $setName = $this->readBodyStringParam('setName');
        if ($setName === '') {
            return $this->asDatastarEventStream([]);
        }

        $cardId = $this->buildCardDomId($setName);
        if ($cardId === '') {
            return $this->asDatastarEventStream([]);
        }

        $editor = Plugin::getInstance()->getTransformEditor();
        $result = $this->readBodyArrayParam('result');
        $editScopeBySet = $this->readBodyArrayParam('editScopeBySet');
        $editTabBySet = $this->readBodyArrayParam('editTabBySet');
        $selectedAssetKeyBySet = $this->readBodyArrayParam('selectedAssetKeyBySet');
        $preferredOrderBySet = $this->readBodyArrayParam('preferredOrderBySet');

        $rendered = $result !== []
            ? $editor->renderResultReview(
                $result,
                $editScopeBySet,
                $editTabBySet,
                $selectedAssetKeyBySet,
                $preferredOrderBySet,
                false,
                false,
                TransformEditor::REVIEW_MODE_PROCESSED,
                $setName,
            )
            : $editor->renderInitialStoredReview(
                $editScopeBySet,
                $editTabBySet,
                $selectedAssetKeyBySet,
                $preferredOrderBySet,
                [],
                $setName,
            );

        $cardHtml = trim((string)($rendered['visualResultsHtml'] ?? ''));
        if ($cardHtml === '') {
            return $this->asDatastarEventStream([]);
        }

        return $this->asDatastarEventStream([
            new PatchElements($cardHtml, [
                'selector' => '#' . $cardId,
                'mode' => 'outer',
            ]),
        ]);
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
            'transformMetadata' => $this->request->getBodyParam('transformMetadata', []),
            'rowsByBreakpoint' => $this->request->getBodyParam('rowsByBreakpoint', []),
            'rowsBySlot' => $this->request->getBodyParam('rowsBySlot', []),
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

    public function actionAutoApplyNewSets(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $this->requireTransformEditPermission();

        $editor = Plugin::getInstance()->getTransformEditor();
        $baseVersion = $this->resolveBaseVersion(Plugin::getInstance()->getTransformStore()->getCurrentVersion());
        $requestedSets = $this->normalizeAutoApplyRequestedSets($this->readBodyArrayParam('sets'));

        $result = $editor->autoApplyRenderedValuesForNewSets($requestedSets, $baseVersion);
        $savedSetNames = $this->buildSavedSetNames($editor);

        return $this->asJson(array_merge($result, [
            'ok' => ($result['persisted'] ?? false) === true,
            'savedSetNames' => $savedSetNames,
        ]));
    }

    /**
     * @param array<string, mixed> $signals
     */
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
        if (!$response instanceof Response) {
            throw new \RuntimeException('Datastar event streams require a web response.');
        }

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
     * @return array{mode: string, breakpoint: ?int, key: ?string}|null
     */
    private function readRequestedCardScope(string $setName): ?array
    {
        $rawScopeMode = trim((string)$this->readRequestedCardSignalString($setName, 'scopeMode'));
        if ($rawScopeMode === 'all') {
            return [
                'mode' => 'all',
                'breakpoint' => null,
                'key' => null,
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
            'key' => Support::parseNullableNonEmptyString($this->readRequestedCardSignalString($setName, 'scopeBreakpointKey')),
        ];
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
     * @param array<string, string> $scopeValues
     */
    private function normalizeCardActiveTab(?string $requestedTab, string $scopeMode, array $scopeValues): string
    {
        $tab = is_string($requestedTab) ? strtolower(trim($requestedTab)) : '';
        $tab = in_array($tab, ['dimensions', 'ratio', 'settings', 'notes'], true) ? $tab : 'dimensions';

        if ($scopeMode !== 'all' && $tab === 'settings') {
            return 'dimensions';
        }

        $ratioBlockedByAuto = ($scopeValues['widthAuto'] ?? '0') === '1'
            || ($scopeValues['heightAuto'] ?? '0') === '1';
        if ($tab === 'ratio' && $ratioBlockedByAuto) {
            return 'dimensions';
        }

        return $tab;
    }

    private function operationMayChangeAllScopeAutoSignals(CardOperationRequest $operation): bool
    {
        if ($operation->operation !== 'dimensions.toggleAutoWidth' && $operation->operation !== 'dimensions.toggleAutoHeight') {
            return false;
        }

        $requestedScope = $this->readRequestedCardScope($operation->setName);
        $scopeMode = $requestedScope['mode'] ?? $operation->scopeMode;

        return $scopeMode === 'all';
    }

    /**
     * @param array<int|string, array<string, mixed>> $rowsByBreakpoint
     * @return array{widthAuto: string, heightAuto: string}
     */
    private function buildAllScopeAutoSignalsFromRows(array $rowsByBreakpoint): array
    {
        return [
            'widthAuto' => $this->allEnabledRowsUseAutoDimension($rowsByBreakpoint, 'width') ? '1' : '0',
            'heightAuto' => $this->allEnabledRowsUseAutoDimension($rowsByBreakpoint, 'height') ? '1' : '0',
        ];
    }

    /**
     * @param array<int|string, array<string, mixed>> $rowsByBreakpoint
     */
    private function allEnabledRowsUseAutoDimension(array $rowsByBreakpoint, string $dimension): bool
    {
        $enabledCount = 0;

        foreach ($rowsByBreakpoint as $row) {
            if (($row['enabled'] ?? true) !== true) {
                continue;
            }

            $enabledCount += 1;
            if (($row['autoDimension'] ?? '') !== $dimension) {
                return false;
            }
        }

        return $enabledCount > 0;
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

    /**
     * The active edit-panel tab for the card. Datastar @post sends only the explicit
     * payload (signals are not included when a payload is given), so the signal read
     * is a no-op for card operations — the flat `activeTab` payload param is the one
     * that actually arrives. The signal read stays as a fallback for payload-less posts.
     */
    private function readRequestedCardActiveTab(string $setName): ?string
    {
        $payloadTab = Support::parseNullableNonEmptyString($this->request->getBodyParam('activeTab'));
        if ($payloadTab !== null) {
            return $payloadTab;
        }

        return $this->readRequestedCardSignalString($setName, 'activeTab');
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

    /**
     * @return array{assetCount: mixed, breakpointCount: mixed, warningCount: mixed}
     */
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

    /**
     * @return array<mixed>
     */
    private function readBodyArrayParam(string $name): array
    {
        $rawValue = $this->request->getBodyParam($name, []);

        return is_array($rawValue) ? $rawValue : [];
    }

    private function readBodyStringParam(string $name): string
    {
        return trim((string)$this->request->getBodyParam($name, ''));
    }

    /**
     * @param array<int, mixed> $rawSets
     * @return array<int, array{name: string, selectedAssetKey: string}>
     */
    private function normalizeAutoApplyRequestedSets(array $rawSets): array
    {
        $normalized = [];

        foreach ($rawSets as $rawSet) {
            if (!is_array($rawSet)) {
                continue;
            }

            $name = trim((string)($rawSet['name'] ?? ''));
            if ($name === '') {
                $normalized[] = [
                    'name' => '',
                    'selectedAssetKey' => '',
                ];
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'selectedAssetKey' => trim((string)($rawSet['selectedAssetKey'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function buildSavedSetNames(TransformEditor $editor): array
    {
        return array_values(array_filter(
            array_map(
                static fn(array $row): string => trim($row['name']),
                $editor->buildSidebarTransformRows(),
            ),
            static fn(string $name): bool => $name !== '',
        ));
    }

    private function requireTransformEditPermission(?CardOperationRequest $operation = null): void
    {
        if (!Plugin::getInstance()->getTelemetry()->canEditTransforms()) {
            $message = 'Transform editing is disabled in this environment.';
            Plugin::warning($operation !== null
                ? 'Transform card operation forbidden: ' . $this->formatOperationLogContext($operation, [
                    'statusMessage' => $message,
                ])
                : $message);

            throw new ForbiddenHttpException('Transform editing is disabled in this environment.');
        }
    }

    /**
     * @param array<string, mixed> $operationResult
     */
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

        if ($field === 'ratio' || $field === 'dimensions') {
            $details = is_array($operationResult['operationDetails'] ?? null)
                ? $operationResult['operationDetails']
                : [];
            $appliedBreakpoints = isset($details['appliedBreakpoints']) && is_array($details['appliedBreakpoints'])
                ? $details['appliedBreakpoints']
                : [];
            $skippedBreakpoints = isset($details['skippedBreakpoints']) && is_array($details['skippedBreakpoints'])
                ? $details['skippedBreakpoints']
                : [];

            $ratioRemoved = ($details['ratioRemoved'] ?? false) === true;

            if ($persisted && $ratioRemoved) {
                $appliedCount = count($appliedBreakpoints);

                return $appliedCount > 0
                    ? sprintf('Ratio removed from %d breakpoint%s.', $appliedCount, $appliedCount === 1 ? '' : 's')
                    : 'No saved ratio to remove.';
            }

            $copiedRatioLabel = is_string($details['copiedRatioLabel'] ?? null) ? $details['copiedRatioLabel'] : '';
            $copiedRatioSourceKey = is_string($details['copiedRatioSourceKey'] ?? null) ? $details['copiedRatioSourceKey'] : '';

            if ($persisted && ($appliedBreakpoints !== [] || $skippedBreakpoints !== [])) {
                $appliedCount = count($appliedBreakpoints);
                if ($field === 'ratio' && $copiedRatioLabel !== '') {
                    $copiedFrom = $copiedRatioSourceKey !== '' ? ' from ' . $copiedRatioSourceKey : '';
                    if ($appliedCount > 0) {
                        $message = sprintf('Copied ratio %s%s, applied to %d breakpoint%s.', $copiedRatioLabel, $copiedFrom, $appliedCount, $appliedCount === 1 ? '' : 's');
                    } else {
                        $message = sprintf('Copied ratio %s%s, not applied to any breakpoints.', $copiedRatioLabel, $copiedFrom);
                    }
                } elseif ($field === 'ratio') {
                    if ($appliedCount > 0) {
                        $message = sprintf('Ratio applied to %d breakpoint%s.', $appliedCount, $appliedCount === 1 ? '' : 's');
                    } else {
                        $message = 'Ratio not applied to any breakpoints.';
                    }
                } else {
                    if ($appliedCount > 0) {
                        $message = sprintf('Dimensions updated for %d breakpoint%s.', $appliedCount, $appliedCount === 1 ? '' : 's');
                    } else {
                        $message = 'Dimensions not updated for any breakpoints.';
                    }
                }

                if ($skippedBreakpoints !== []) {
                    $message .= ' Skipped: ' . $this->formatSkippedBreakpoints($skippedBreakpoints) . '.';
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
                'notes' => 'Notes updated.',
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
            'notes' => 'Notes update failed.',
            default => ucfirst($field) . ' update failed.',
        };
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function logCardOperationResponseFailure(CardOperationRequest $operation, string $message, array $extra = []): void
    {
        Plugin::warning('Transform card operation failed: ' . $this->formatOperationLogContext($operation, array_merge([
            'statusMessage' => $message,
        ], $extra)));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function formatOperationLogContext(CardOperationRequest $operation, array $extra = []): string
    {
        $context = array_filter([
            'operation' => $operation->operation,
            'field' => $operation->field,
            'setName' => $operation->setName,
            'scopeMode' => $operation->scopeMode,
            'scopeBreakpoint' => $operation->scopeBreakpoint,
            'scopeBreakpointKey' => $operation->scopeBreakpointKey,
            'baseVersion' => $operation->baseVersion,
            'includeEscapeWidth' => $operation->includeEscapeWidth,
            'value' => $operation->value,
            'width' => $operation->width,
            'height' => $operation->height,
            'widthAuto' => $operation->widthAuto,
            'heightAuto' => $operation->heightAuto,
            'forceAll' => $operation->forceAll,
            'clearAuto' => $operation->clearAuto,
            'ratioWidth' => $operation->ratioWidth,
            'ratioHeight' => $operation->ratioHeight,
            'ratioFloat' => $operation->ratioFloat,
            'ratioSourceDimension' => $operation->ratioSourceDimension,
            'ratioSourceBreakpoint' => $operation->ratioSourceBreakpoint,
            'ratioSourceBreakpointKey' => $operation->ratioSourceBreakpointKey,
            'enabled' => $operation->enabled,
            'selectedAssetKey' => $operation->selectedAssetKey,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');

        $encoded = Json::encode(array_merge($context, $extra));

        return is_string($encoded) ? $encoded : '[unencodable context]';
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeValidationForLog(mixed $validation): array
    {
        if (!is_array($validation)) {
            return [];
        }

        return array_filter([
            'hasErrors' => ($validation['hasErrors'] ?? false) === true,
            'global' => $this->stringListForLog($validation['global'] ?? null),
        ], static fn(mixed $value): bool => $value !== [] && $value !== false);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeOperationDetailsForLog(mixed $details): array
    {
        if (!is_array($details)) {
            return [];
        }

        return array_filter([
            'appliedBreakpoints' => $this->stringListForLog($details['appliedBreakpoints'] ?? null),
            'skippedBreakpoints' => $this->skippedBreakpointsForLog($details['skippedBreakpoints'] ?? null),
        ], static fn(mixed $value): bool => $value !== []);
    }

    /**
     * @return array<int, string>
     */
    private function stringListForLog(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => is_scalar($item) ? trim((string)$item) : '',
            $value,
        ), static fn(string $item): bool => $item !== ''));
    }

    /**
     * @return array<int, array{breakpoint: int, reason: string}>
     */
    private function skippedBreakpointsForLog(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $skippedBreakpoints = [];

        foreach ($value as $item) {
            if (!is_array($item) || !is_numeric($item['breakpoint'] ?? null)) {
                continue;
            }

            $breakpoint = max(0, (int)$item['breakpoint']);
            $reason = is_string($item['reason'] ?? null) ? trim($item['reason']) : '';

            if ($breakpoint <= 0 || $reason === '') {
                continue;
            }

            $skippedBreakpoints[] = [
                'breakpoint' => $breakpoint,
                'reason' => $reason,
            ];
        }

        return $skippedBreakpoints;
    }

    /**
     * @param array<int, mixed> $skippedBreakpoints
     */
    private function formatSkippedBreakpoints(array $skippedBreakpoints): string
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
