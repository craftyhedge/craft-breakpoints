<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use Craft;
use craft\web\View;
use craftyhedge\craftbreakpoints\Plugin;

/**
 * Builds the result-review and initial-stored-review markup for the transforms
 * editor, including per-card breakpoint columns, asset pagination, warnings,
 * and last-process panel. Extracted from TransformEditor to keep the facade
 * focused on orchestration.
 */
final class ReviewRenderer
{
    private const REVIEW_MODE_PROCESSED = 'processed';
    private const REVIEW_MODE_SAVED = 'saved';
    private readonly CardStateBuilder $cardStateBuilder;
    private readonly ReviewBreakpointStateBuilder $breakpointStateBuilder;
    private readonly InitialStoredReviewBuilder $initialStoredReviewBuilder;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly SnapshotReader $snapshotReader,
        private readonly HealthAnalyzer $healthAnalyzer,
        private readonly ReviewWarningsBuilder $warningsBuilder,
    ) {
        $this->cardStateBuilder = new CardStateBuilder();
        $this->breakpointStateBuilder = new ReviewBreakpointStateBuilder($healthAnalyzer);
        $this->initialStoredReviewBuilder = new InitialStoredReviewBuilder($plugin, $snapshotReader);
    }

    public function renderResultReview(
        array $result,
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
        bool $hideRenderedApply = false,
        bool $hideAssetPagination = false,
        string $reviewMode = self::REVIEW_MODE_PROCESSED,
        ?string $onlyTransformName = null,
    ): array {
        $normalizedReviewMode = $this->normalizeReviewMode($reviewMode);
        $this->initialStoredReviewBuilder->resetTelemetryInitCache();
        $rowsByBreakpoint = $this->normalizeReviewRowsByBreakpoint($result['rowsByBreakpoint'] ?? []);
        $breakpoints = $this->normalizeReviewBreakpoints($result['breakpoints'] ?? []);
        if ($breakpoints === []) {
            $breakpoints = $this->getReviewConfiguredBreakpoints();
        }

        return $this->renderReview(
            $rowsByBreakpoint,
            $breakpoints,
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
            $hideRenderedApply,
            $hideAssetPagination,
            $normalizedReviewMode,
            $onlyTransformName,
        );
    }

    public function renderInitialStoredReview(
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
        ?string $onlyTransformName = null,
        array $result = [],
    ): array {
        $this->initialStoredReviewBuilder->resetTelemetryInitCache();
        $resultRowsByBreakpoint = $this->normalizeReviewRowsByBreakpoint($result['rowsByBreakpoint'] ?? []);
        $resultBreakpoints = $this->normalizeReviewBreakpoints($result['breakpoints'] ?? []);
        $mergedRowsByBreakpoint = $this->initialStoredReviewBuilder->buildRowsByBreakpoint($resultRowsByBreakpoint);

        return $this->renderReview(
            $this->normalizeReviewRowsByBreakpoint($mergedRowsByBreakpoint),
            $resultBreakpoints,
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
            true,
            true,
            self::REVIEW_MODE_SAVED,
            $onlyTransformName,
        );
    }

    private function renderReview(
        array $rowsByBreakpoint,
        array $breakpoints,
        array $editScopeBySet,
        array $editTabBySet,
        array $selectedAssetKeyBySet,
        array $preferredOrderBySet,
        bool $hideRenderedApply,
        bool $hideAssetPagination,
        string $reviewMode,
        ?string $onlyTransformName,
    ): array {
        if ($breakpoints === []) {
            $breakpoints = $this->getReviewConfiguredBreakpoints();
        }

        $warningsByTransform = $this->buildReviewWarningsByTransform($rowsByBreakpoint);
        $normalizedScopeState = [];
        $normalizedTabState = [];
        $normalizedSelectedAssetKeyBySet = [];

        return [
            'warningsHtml' => '',
            'visualResultsHtml' => $this->buildReviewCardsMarkup(
                $rowsByBreakpoint,
                $breakpoints,
                $warningsByTransform,
                $editScopeBySet,
                $editTabBySet,
                $selectedAssetKeyBySet,
                $preferredOrderBySet,
                $normalizedScopeState,
                $normalizedTabState,
                $hideRenderedApply,
                $normalizedSelectedAssetKeyBySet,
                $hideAssetPagination,
                $reviewMode,
                $onlyTransformName,
            ),
            'warningCount' => $this->countReviewWarningsByTransform($warningsByTransform),
            'editScopeBySet' => $normalizedScopeState,
            'editTabBySet' => $normalizedTabState,
            'selectedAssetKeyBySet' => $normalizedSelectedAssetKeyBySet,
            'savedSetNames' => array_values(array_filter(
                array_keys($this->getReviewStoredTransforms()),
                static fn($name): bool => is_string($name) && $name !== '',
            )),
        ];
    }

    /**
     * Build scope values for a specific breakpoint when it becomes the selected scope.
     * Used by the scope.selectBreakpoint operation to update reactive signals.
     *
     * @return array<string, string>
     */
    public function buildScopeValuesForBreakpoint(string $setName, int $breakpoint, ?bool $includeEscapeWidth = null): array
    {
        $storedTransforms = $this->getReviewStoredTransforms();
        $transformConfig = $storedTransforms[$setName] ?? null;
        if ($transformConfig === null) {
            return $this->emptyScopeValues();
        }

        if ($includeEscapeWidth === null) {
            $includeEscapeWidth = ($transformConfig['includeEscapeWidth'] ?? false) === true;
        }
        $transformBreakpoints = $this->getBreakpointsForTransform($includeEscapeWidth);
        if ($transformBreakpoints === []) {
            return $this->emptyScopeValues();
        }

        if (!in_array($breakpoint, $transformBreakpoints, true)) {
            return $this->emptyScopeValues();
        }

        $currentRows = $this->buildReviewCurrentRowsForTransform($transformConfig, $transformBreakpoints);
        $cardState = $this->cardStateBuilder->build(
            $currentRows,
            $transformBreakpoints,
            ['mode' => 'breakpoint', 'breakpoint' => $breakpoint],
            null,
        );

        return $cardState['scopeValues'];
    }

    /**
     * @return array<string, string>
     */
    private function emptyScopeValues(): array
    {
        return [
            'widthInput' => '',
            'heightInput' => '',
            'widthAuto' => '0',
            'heightAuto' => '0',
            'ratioLocked' => '0',
            'ratioWidthInput' => '',
            'ratioHeightInput' => '',
            'ratioFloatInput' => '',
            'ratioSourceDimension' => 'width',
        ];
    }

    public function buildSignalDeltasForTransform(
        string $setName,
        ?string $selectedAssetKey = null,
        bool $hideRenderedApply = false,
        string $reviewMode = self::REVIEW_MODE_PROCESSED,
    ): array
    {
        $reviewMode = $this->normalizeReviewMode($reviewMode);
        $normalized = trim($setName);
        if ($normalized === '') {
            return ['signalKey' => '', 'rowsByBreakpoint' => []];
        }

        $signalKey = $this->getReviewTransformSignalKey($normalized);
        if ($signalKey === '') {
            return ['signalKey' => '', 'rowsByBreakpoint' => []];
        }

        $storedTransforms = $this->getReviewStoredTransforms();
        $transformConfig = $this->getReviewTransformConfig($storedTransforms, $normalized);
        if ($transformConfig === null) {
            return ['signalKey' => $signalKey, 'rowsByBreakpoint' => []];
        }

        $configuredBreakpoints = $this->getReviewConfiguredBreakpoints();
        $includeEscapeWidth = ($transformConfig['includeEscapeWidth'] ?? false) === true;
        $transformBreakpoints = $this->getReviewBreakpointsForTransformConfig($includeEscapeWidth, $configuredBreakpoints);
        if ($transformBreakpoints === []) {
            return ['signalKey' => $signalKey, 'rowsByBreakpoint' => []];
        }

        $result = $this->buildResultFromLatestSnapshot($normalized);
        $resultRowsByBreakpoint = $this->normalizeReviewRowsByBreakpoint($result['rowsByBreakpoint'] ?? []);
        $assetCollection = ReviewAssetCollector::buildAssetCollectionForTransform(
            $resultRowsByBreakpoint,
            $normalized,
            $transformBreakpoints,
        );
        $selectedAssetKey = ReviewAssetCollector::normalizeSelectedAssetKey($selectedAssetKey, $assetCollection['assetKeys']);
        $selectedAssetRowsByBreakpoint = ReviewAssetCollector::buildSelectedAssetRowsByBreakpoint(
            $assetCollection['rowsByAssetByBreakpoint'],
            $selectedAssetKey,
            $transformBreakpoints,
        );

        $currentRows = $this->buildReviewCurrentRowsForTransform($transformConfig, $transformBreakpoints);
        $cardState = $this->cardStateBuilder->build($currentRows, $transformBreakpoints, ['mode' => 'all'], null);
        $coreRows = $cardState['rowsByBreakpoint'];
        $passHeightWhenRenderedLteSaved = $this->isPassHeightWhenRenderedLteSavedEnabled($transformConfig);
        $allowAnyHeight = $this->isAllowAnyHeightEnabled($transformConfig);
        $storedSavedWidthsByTransform = $this->buildStoredSavedWidthsByTransformAndBreakpoint();
        $storedSavedHeightsByTransform = $this->buildStoredSavedHeightsByTransformAndBreakpoint();

        $rowsByBreakpoint = [];
        foreach ($transformBreakpoints as $breakpoint) {
            $breakpointKey = (string)$breakpoint;
            $currentRow = $currentRows[$breakpoint] ?? Support::buildDefaultTransformEntry();
            $ui = $this->buildBreakpointUiState(
                $normalized,
                $breakpoint,
                $selectedAssetRowsByBreakpoint[$breakpoint] ?? [],
                $currentRow,
                $passHeightWhenRenderedLteSaved,
                $storedSavedWidthsByTransform[$normalized][$breakpoint] ?? null,
                $storedSavedHeightsByTransform[$normalized][$breakpoint] ?? null,
                $allowAnyHeight,
                $hideRenderedApply,
                $reviewMode,
            );
            $rowsByBreakpoint[$breakpointKey] = array_merge($coreRows[$breakpointKey] ?? [], $ui);
        }

        return ['signalKey' => $signalKey, 'rowsByBreakpoint' => $rowsByBreakpoint];
    }

    /**
     * Build the UI-only signal fields for one breakpoint (mismatch, apply button state, classes, etc.).
     * Replicates the logic that used to live inside renderReviewBreakpointColumn.
     */
    private function buildBreakpointUiState(
        string $transformName,
        int $breakpoint,
        array $rows,
        array $currentRow,
        bool $passHeightWhenRenderedLteSaved,
        ?int $savedWidth,
        ?int $savedHeight,
        bool $allowAnyHeight,
        bool $hideRenderedApply,
        string $reviewMode
    ): array {
        $state = $this->breakpointStateBuilder->build(
            $transformName,
            $breakpoint,
            $rows,
            $currentRow,
            $passHeightWhenRenderedLteSaved,
            $savedWidth,
            $savedHeight,
            $allowAnyHeight,
            $hideRenderedApply,
            $reviewMode,
        );

        $renderedRowsPayload = $state['renderedRowsPayload'];
        $renderedApplyNoop = ($state['renderedApplyNoop'] ?? false) === true;
        $currentEnabled = ($state['currentEnabled'] ?? true) === true;

        return [
            'breakpointColumnMismatchClass' => ($state['hasBreakpointMismatch'] ?? false) === true ? '1' : '0',
            'breakpointColumnDisabledClass' => $currentEnabled ? '0' : '1',
            'breakpointEnableTitle' => $currentEnabled ? "Disable {$breakpoint}px breakpoint" : "Enable {$breakpoint}px breakpoint",
            'breakpointEnableAriaLabel' => $currentEnabled ? "Disable {$breakpoint}px breakpoint" : "Enable {$breakpoint}px breakpoint",
            'breakpointEnableAriaChecked' => $currentEnabled ? 'true' : 'false',
            'breakpointDisabledAttr' => $renderedRowsPayload === [] ? '1' : '0',
            'breakpointRenderedApplyMatchClass' => $renderedApplyNoop ? '1' : '0',
            'breakpointRenderedApplyAriaLabel' => $renderedApplyNoop
                ? "Rendered values already match for {$breakpoint}px"
                : "Apply rendered values for {$breakpoint}px",
            'breakpointRenderedApplyTitle' => $renderedApplyNoop
                ? "Rendered values already match for {$breakpoint}px"
                : "Apply rendered values for {$breakpoint}px",
            'breakpointRenderedApplyIconName' => $renderedApplyNoop ? 'check' : 'arrow-down',
            'breakpointRenderedApplyHiddenClass' => $hideRenderedApply ? '1' : '0',
            'breakpointRenderedRowHiddenClass' => $hideRenderedApply ? '1' : '0',
            'widthClass' => (string)($state['widthClass'] ?? ''),
            'heightClass' => (string)($state['heightClass'] ?? ''),
            'currentWidthDerivedClass' => (string)($state['currentWidthDerivedClass'] ?? '') !== '' ? '1' : '0',
            'currentHeightDerivedClass' => (string)($state['currentHeightDerivedClass'] ?? '') !== '' ? '1' : '0',
            'previewMediaHidden' => $currentEnabled ? '0' : '1',
        ];
    }

    /**
     * Build result array from latest persisted snapshot for a specific transform.
     * Used by the signal delta path to reuse the same normalized evidence shape as rendering.
     *
     * @return array{breakpoints: int[], rowsByBreakpoint: array<int, array<string, mixed>>}
     */
    private function buildResultFromLatestSnapshot(string $transformName): array
    {
        $snapshot = $this->snapshotReader->getLatestRunSnapshot();
        if ($snapshot === null) {
            return ['breakpoints' => [], 'rowsByBreakpoint' => []];
        }

        $perAssetRows = $snapshot['rowsPayload'] ?? [];
        if ($perAssetRows === []) {
            return ['breakpoints' => [], 'rowsByBreakpoint' => []];
        }

        $rowsByBreakpoint = [];
        $breakpoints = [];

        foreach ($perAssetRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transformHandle = trim((string)($row['transformHandle'] ?? ''));
            $breakpointWidth = isset($row['breakpointWidth']) && is_numeric($row['breakpointWidth'])
                ? (int)$row['breakpointWidth']
                : 0;

            if ($transformHandle !== $transformName || $breakpointWidth <= 0) {
                continue;
            }

            if (!isset($rowsByBreakpoint[$breakpointWidth])) {
                $rowsByBreakpoint[$breakpointWidth] = [];
                $breakpoints[] = $breakpointWidth;
            }

            $rowsByBreakpoint[$breakpointWidth][] = [
                'transform' => $transformHandle,
                'assetId' => trim((string)($row['assetId'] ?? '')),
                'title' => $transformHandle . ' ' . $breakpointWidth . 'px',
                'enabled' => true,
                'isVisible' => true,
                'loaded' => ($row['rowStatus'] ?? '') === 'loaded',
                'broken' => ($row['rowStatus'] ?? '') === 'broken',
                'unresolved' => ($row['rowStatus'] ?? '') === 'unresolved',
                'sourceUsed' => $row['displayAssetUrl'] ?? null,
                'src' => $row['displayAssetUrl'] ?? null,
                'rendered' => [
                    'width' => max(0, (int)($row['renderedWidth'] ?? 0)),
                    'height' => max(0, (int)($row['renderedHeight'] ?? 0)),
                ],
                'intrinsic' => [
                    'width' => max(0, (int)($row['renderedWidth'] ?? 0)),
                    'height' => max(0, (int)($row['renderedHeight'] ?? 0)),
                ],
                'autoDimension' => $row['autoDimension'] ?? null,
            ];
        }

        sort($breakpoints, SORT_NUMERIC);

        return ['breakpoints' => $breakpoints, 'rowsByBreakpoint' => $rowsByBreakpoint];
    }

    private function renderReviewWarningsMarkup(array $warnings, bool $showEmptyState = true, string $reviewMode = self::REVIEW_MODE_PROCESSED): string
    {
        $normalized = [];
        foreach ($warnings as $warning) {
            if (is_array($warning)) {
                $normalized[] = $warning;
            }
        }

        return $this->renderReviewPartial('_partials/review/warnings', [
            'warnings' => $normalized,
            'showEmptyState' => $showEmptyState,
            'reviewMode' => $reviewMode,
            'canEditTransforms' => $this->plugin !== null
                && $this->plugin->getTelemetry()->canEditTransforms(),
        ]);
    }

    private function buildReviewWarningActionsMarkup(array $warning, string $reviewMode = self::REVIEW_MODE_PROCESSED): string
    {
        return $this->renderReviewPartial('_partials/review/warning-actions', [
            'code' => (string)($warning['code'] ?? ''),
            'reviewMode' => $reviewMode,
            'canEditTransforms' => $this->plugin !== null
                && $this->plugin->getTelemetry()->canEditTransforms(),
            'entryId' => (int)($warning['entryId'] ?? 0),
            'entryAvailable' => ($warning['entryAvailable'] ?? false) === true,
            'entryMissing' => ($warning['entryMissing'] ?? false) === true,
        ]);
    }

    private function buildReviewCardsMarkup(
        array $rowsByBreakpoint,
        array $breakpoints,
        array $warningsByTransform,
        array $editScopeBySet,
        array $editTabBySet,
        array $selectedAssetKeyBySet,
        array $preferredOrderBySet,
        array &$normalizedScopeState,
        array &$normalizedTabState,
        bool $hideRenderedApply,
        array &$normalizedSelectedAssetKeyBySet,
        bool $hideAssetPagination,
        string $reviewMode,
        ?string $onlyTransformName,
    ): string {
        $isProcessedReview = $reviewMode === self::REVIEW_MODE_PROCESSED;
        $transformNames = $this->collectReviewTransformNames($rowsByBreakpoint);

        $configuredBreakpoints = $breakpoints !== [] ? $breakpoints : $this->getReviewConfiguredBreakpoints();
        $escapeBreakpoint = $this->getReviewEscapeBreakpoint();
        $storedTransforms = $this->getReviewStoredTransforms();
        $storedSavedHeightsByTransform = $this->buildStoredSavedHeightsByTransformAndBreakpoint();
        $storedSavedWidthsByTransform = $this->buildStoredSavedWidthsByTransformAndBreakpoint();
        $latestRunSnapshot = $this->getLatestRunSnapshotForReview();
        $latestRunSummariesByTransform = $this->buildLatestRunSummaryByTransform($latestRunSnapshot);
        $editedTransforms = $this->buildEditedTransformsMap($latestRunSnapshot, $isProcessedReview);
        $canEditTransforms = $this->plugin !== null && $this->plugin->getTelemetry()->canEditTransforms();

        $breakpointMismatchTransformNames = [];
        $assetMismatchTransformNames = [];
        if ($isProcessedReview) {
            foreach ($latestRunSummariesByTransform as $handle => $summary) {
                if (!is_string($handle)) {
                    continue;
                }
                if (($summary['hasBreakpointMismatch'] ?? false) === true) {
                    $breakpointMismatchTransformNames[$handle] = true;
                }
                if (($summary['hasAssetMismatch'] ?? false) === true) {
                    $assetMismatchTransformNames[$handle] = true;
                }
            }
        }

        $transformNames = $this->orderReviewTransformNames(
            $transformNames,
            $warningsByTransform,
            $preferredOrderBySet,
            $breakpointMismatchTransformNames,
            $assetMismatchTransformNames,
        );
        if ($transformNames === []) {
            return $this->renderReviewPartial('_partials/review/empty-state', []);
        }

        $runEntryData = $this->resolveRunEntryData($latestRunSnapshot);
        $observedDataByTransform = $this->resolveObservedDataByTransform();
        $cards = [];

        foreach ($transformNames as $transformName) {
            if ($onlyTransformName !== null && $onlyTransformName !== '' && $transformName !== $onlyTransformName) {
                continue;
            }

            $observedBreakpoints = [];
            foreach ($configuredBreakpoints as $breakpoint) {
                $rows = $rowsByBreakpoint[$breakpoint] ?? [];
                foreach ($rows as $row) {
                    if (($row['transform'] ?? '') === $transformName) {
                        $observedBreakpoints[] = $breakpoint;
                        break;
                    }
                }
            }

            $storedTransformConfig = $this->getReviewTransformConfig($storedTransforms, $transformName);
            $cardWarnings = $warningsByTransform[$transformName] ?? [];
            $includeEscapeWidth = ($storedTransformConfig['includeEscapeWidth'] ?? false) === true;
            if ($storedTransformConfig === null) {
                $includeEscapeWidth = $escapeBreakpoint !== null && in_array($escapeBreakpoint, $observedBreakpoints, true);
            }

            $transformBreakpoints = $observedBreakpoints !== []
                ? $observedBreakpoints
                : $this->getReviewBreakpointsForTransformConfig($includeEscapeWidth, $configuredBreakpoints);

            if ($transformBreakpoints === []) {
                continue;
            }

            $hasSavedSet = $storedTransformConfig !== null;

            // Reactive missing-set / process-again state (processed mode only):
            //   'missing'           -> no saved definition (danger banner + "Set to rendered")
            //   'awaitingReprocess' -> saved since the last process run, not yet re-verified
            //   'ok'                -> saved and verified; no reactive banner
            $editedSinceProcess = ($editedTransforms[$transformName] ?? false) === true;
            $setReviewState = 'ok';
            if ($isProcessedReview) {
                if (!$hasSavedSet) {
                    $setReviewState = 'missing';
                } elseif ($editedSinceProcess) {
                    $setReviewState = 'awaitingReprocess';
                }
            }

            $assetCollection = ReviewAssetCollector::buildAssetCollectionForTransform(
                $rowsByBreakpoint,
                $transformName,
                $transformBreakpoints,
            );
            $assetKeys = $assetCollection['assetKeys'];
            $selectedAssetKey = ReviewAssetCollector::normalizeSelectedAssetKey(
                $selectedAssetKeyBySet[$transformName] ?? null,
                $assetKeys,
            );
            $normalizedSelectedAssetKeyBySet[$transformName] = $selectedAssetKey;
            $selectedAssetRowsByBreakpoint = ReviewAssetCollector::buildSelectedAssetRowsByBreakpoint(
                $assetCollection['rowsByAssetByBreakpoint'],
                $selectedAssetKey,
                $transformBreakpoints,
            );

            $currentRows = $this->buildReviewCurrentRowsForTransform(
                $storedTransformConfig,
                $transformBreakpoints,
            );
            $initSeedState = $this->initialStoredReviewBuilder->buildInitSeedStateByBreakpoint(
                $transformName,
                $transformBreakpoints,
                !$hasSavedSet,
            );
            if (($initSeedState['seedRows'] ?? []) !== []) {
                $currentRows = $this->initialStoredReviewBuilder->applyInitSeedRowsToCurrentRows(
                    $currentRows,
                    $initSeedState['seedRows'],
                );
            }

            $cardState = $this->cardStateBuilder->build(
                $currentRows,
                $transformBreakpoints,
                $editScopeBySet[$transformName] ?? null,
                $editTabBySet[$transformName] ?? null,
            );
            $scope = $cardState['scope'];
            $tab = $cardState['tab'];
            $scopeValues = $cardState['scopeValues'];
            $rowsByBreakpointSignal = $cardState['rowsByBreakpoint'];
            $firstBreakpoint = $cardState['firstBreakpoint'];
            $passHeightWhenRenderedLteSaved = $this->isPassHeightWhenRenderedLteSavedEnabled($storedTransformConfig);
            $allowAnyHeight = $this->isAllowAnyHeightEnabled($storedTransformConfig);

            $selectedBreakpoint = $scope['mode'] === 'breakpoint' ? $scope['breakpoint'] : null;
            $signalKey = $this->getReviewTransformSignalKey($transformName);
            $signalPathBase = 'editor.cards.' . $signalKey;

            $ratioTabDisabled = $scope['mode'] === 'breakpoint'
                && ($scopeValues['widthAuto'] === '1' || $scopeValues['heightAuto'] === '1');
            if ($ratioTabDisabled && $tab === 'ratio') {
                $tab = 'dimensions';
            }

            $normalizedScopeState[$transformName] = $scope;
            $normalizedTabState[$transformName] = $tab;

            $ratioSourceBreakpointDefault = $selectedBreakpoint !== null
                ? (string)$selectedBreakpoint
                : ($firstBreakpoint !== null ? (string)$firstBreakpoint : '');

            $ratioSourceBreakpointOptions = '';
            foreach ($transformBreakpoints as $transformBreakpoint) {
                $value = (string)$transformBreakpoint;
                $selectedAttr = $value === $ratioSourceBreakpointDefault ? ' selected' : '';
                $ratioSourceBreakpointOptions .= sprintf(
                    '<option value="%s"%s>%spx</option>',
                    $this->escapeReviewHtml($value),
                    $selectedAttr,
                    $this->escapeReviewHtml($value),
                );
            }

            $cardSignalsStructural = [
                'editor' => [
                    'cards' => [
                        $signalKey => [
                            'ratioLocked' => $scopeValues['ratioLocked'],
                            'ratioSourceDimension' => $scopeValues['ratioSourceDimension'],
                            'ratioSourceBreakpoint' => $ratioSourceBreakpointDefault,
                            'activeTab' => $tab,
                            'scopeMode' => $scope['mode'],
                            'scopeBreakpoint' => $scope['mode'] === 'breakpoint' ? (string)$scope['breakpoint'] : '',
                            'scopeActive' => $this->isReviewScopeActive($scope) ? '1' : '0',
                            'selectedAssetKey' => $selectedAssetKey,
                            'rowsByBreakpoint' => $rowsByBreakpointSignal,
                            'firstBreakpoint' => $firstBreakpoint !== null ? (string)$firstBreakpoint : '',
                            'initSeedAppliedAny' => ($cardState['initSeedAppliedAny'] ?? false) === true,
                            'passHeightWhenRenderedLteSaved' => $passHeightWhenRenderedLteSaved,
                            'allowAnyHeight' => $allowAnyHeight,
                            'includeEscapeWidth' => $includeEscapeWidth ? '1' : '0',
                            'hideRenderedApply' => $hideRenderedApply ? '1' : '0',
                            'reviewMode' => $reviewMode,
                            'setReviewState' => $setReviewState,
                        ],
                    ],
                ],
            ];

            $cardSignalsStructural['editor']['cards'][$signalKey]['widthInput'] = $scopeValues['widthInput'];
            $cardSignalsStructural['editor']['cards'][$signalKey]['heightInput'] = $scopeValues['heightInput'];
            $cardSignalsStructural['editor']['cards'][$signalKey]['widthAuto'] = $scopeValues['widthAuto'];
            $cardSignalsStructural['editor']['cards'][$signalKey]['heightAuto'] = $scopeValues['heightAuto'];
            $cardSignalsStructural['editor']['cards'][$signalKey]['ratioWidthInput'] = $scopeValues['ratioWidthInput'];
            $cardSignalsStructural['editor']['cards'][$signalKey]['ratioHeightInput'] = $scopeValues['ratioHeightInput'];
            $cardSignalsStructural['editor']['cards'][$signalKey]['ratioFloatInput'] = $scopeValues['ratioFloatInput'];

            $columnWidths = ReviewLayoutCalculator::calculateBreakpointColumnWidths($transformBreakpoints);
            $previewLockHeightsByBreakpoint = ReviewLayoutCalculator::calculateBreakpointPreviewLockHeights(
                $assetCollection['rowsByAssetByBreakpoint'],
                $transformBreakpoints,
                $columnWidths,
            );
            foreach ($transformBreakpoints as $breakpoint) {
                $breakpointKey = (string)$breakpoint;
                $rowsByBreakpointSignal[$breakpointKey] = array_merge(
                    $rowsByBreakpointSignal[$breakpointKey] ?? [],
                    $this->buildBreakpointUiState(
                        $transformName,
                        $breakpoint,
                        $selectedAssetRowsByBreakpoint[$breakpoint] ?? [],
                        $currentRows[$breakpoint] ?? Support::buildDefaultTransformEntry(),
                        $passHeightWhenRenderedLteSaved,
                        $storedSavedWidthsByTransform[$transformName][$breakpoint] ?? null,
                        $storedSavedHeightsByTransform[$transformName][$breakpoint] ?? null,
                        $allowAnyHeight,
                        $hideRenderedApply,
                        $reviewMode,
                    )
                );
            }

            $cardSignalsStructural['editor']['cards'][$signalKey]['rowsByBreakpoint'] = $rowsByBreakpointSignal;
            $cardSignalsStructuralJson = json_encode($cardSignalsStructural, JSON_UNESCAPED_SLASHES);
            if (!is_string($cardSignalsStructuralJson)) {
                $cardSignalsStructuralJson = '{"editor":{"cards":{}}}';
            }

            $breakpointColumns = '';
            $breakpointKeysByWidth = $this->getBreakpointKeysByWidth($includeEscapeWidth);
            foreach ($transformBreakpoints as $breakpoint) {
                $rows = $selectedAssetRowsByBreakpoint[$breakpoint] ?? [];
                $breakpointColumns .= $this->renderReviewBreakpointColumn(
                    $transformName,
                    $breakpoint,
                    $breakpointKeysByWidth[(string)$breakpoint] ?? '',
                    $rows,
                    $currentRows[$breakpoint] ?? Support::buildDefaultTransformEntry(),
                    $columnWidths,
                    $previewLockHeightsByBreakpoint,
                    $signalKey,
                    $selectedBreakpoint,
                    $scope['mode'] === 'all',
                    $escapeBreakpoint,
                    $hideRenderedApply,
                    $reviewMode,
                    $passHeightWhenRenderedLteSaved,
                    $storedSavedWidthsByTransform[$transformName][$breakpoint] ?? null,
                    $storedSavedHeightsByTransform[$transformName][$breakpoint] ?? null,
                    $allowAnyHeight,
                );
            }
            $assetMismatchByKey = ($isProcessedReview && !$hideAssetPagination)
                ? $this->buildReviewAssetMismatchByKey(
                    $assetKeys,
                    $assetCollection['rowsByAssetByBreakpoint'],
                    $transformBreakpoints,
                    $passHeightWhenRenderedLteSaved,
                    $storedSavedHeightsByTransform[$transformName] ?? [],
                    $allowAnyHeight,
                )
                : [];
            $assetPaginationHtml = $this->buildReviewAssetPaginationMarkup(
                $assetKeys,
                $assetCollection['assetLabelsByKey'],
                $assetMismatchByKey,
                $selectedAssetKey,
                $signalKey,
                $hideAssetPagination,
            );

            $slug = Support::slugifyTransformName($transformName);
            $editPanelId = 'bpts-edit-panel-' . $slug;
            $activeDimensions = $tab === 'dimensions';
            $activeRatio = $tab === 'ratio';
            $activeSettings = $tab === 'settings';
            $scopeLabel = $scope['mode'] === 'all'
                ? 'All'
                : ($scope['mode'] === 'breakpoint' ? ($scope['breakpoint'] . 'px') : 'Select scope');
            $latestRunSummaryForTransform = $latestRunSummariesByTransform[$transformName] ?? null;
            $hasAssetMismatchWarning = $isProcessedReview
                && is_array($latestRunSummaryForTransform)
                && (($latestRunSummaryForTransform['hasAssetMismatch'] ?? false) === true);
            $hasBreakpointMismatchWarning = $isProcessedReview
                && is_array($latestRunSummaryForTransform)
                && (($latestRunSummaryForTransform['hasBreakpointMismatch'] ?? false) === true);

            $hasMissingSetWarning = false;
            $hasEmptyBreakpointsWarning = false;
            foreach ($cardWarnings as $w) {
                if (!is_array($w)) {
                    continue;
                }

                $warningCode = (string)($w['code'] ?? '');
                if ($warningCode === 'missing-set-definitions') {
                    $hasMissingSetWarning = true;
                }

                if ($warningCode === 'empty-enabled-breakpoints') {
                    $hasEmptyBreakpointsWarning = true;
                }
            }

            $suppressMismatchBanners = $hasMissingSetWarning || $hasEmptyBreakpointsWarning;

            $reactiveWarningsEnabled = $isProcessedReview && $canEditTransforms;

            // The reactive block owns the missing-set warning; drop it from the static
            // list so it is not rendered twice.
            $staticCardWarnings = $reactiveWarningsEnabled
                ? array_values(array_filter(
                    $cardWarnings,
                    static fn($w): bool => !is_array($w) || (string)($w['code'] ?? '') !== 'missing-set-definitions',
                ))
                : $cardWarnings;
            $staticWarningsMarkup = $this->renderReviewWarningsMarkup($staticCardWarnings, false, $reviewMode);

            $missingSetMessage = 'No transforms are saved for this set. Process the observed entry to capture rendered dimensions, or edit the transforms.';
            foreach ($cardWarnings as $w) {
                if (is_array($w)
                    && (string)($w['code'] ?? '') === 'missing-set-definitions'
                    && trim((string)($w['message'] ?? '')) !== '') {
                    $missingSetMessage = (string)$w['message'];
                    break;
                }
            }

            $reactiveWarningsMarkup = $reactiveWarningsEnabled
                ? $this->renderReviewPartial('_partials/review/missing-set-reactive', [
                    'signalKey' => $this->escapeReviewHtml($signalKey),
                    'setReviewState' => $setReviewState,
                    'missingMessage' => $missingSetMessage,
                    'processAgainMessage' => 'Process again to double check application.',
                    'applyButtonHtml' => $this->buildReviewWarningActionsMarkup(
                        ['code' => 'missing-set-definitions'],
                        self::REVIEW_MODE_PROCESSED,
                    ),
                ])
                : '';

            $breakpointMismatchWarningMarkup = ($hasBreakpointMismatchWarning && !$suppressMismatchBanners)
                ? '<div class="bpts-warning-item bpts-warning-item-neutral">'
                    . '<div class="bpts-warning-copy"><h3 class="bpts-warning-heading">' . ($editedSinceProcess ? 'Saved Values Changed' : 'Breakpoint Mismatch') . '</h3></div>'
                    . '<div class="bpts-warning-detail">'
                        . ($editedSinceProcess
                            ? '<p>Saved values have been edited since the last process. Process to review these changes.</p>'
                            : '<p>Rendered values do not match the saved transform for one or more breakpoints.</p><p>If you made a change, it is best to process again and review the frontend is behaving correctly.</p>')
                    . '</div>'
                    . '</div>'
                : '';

            $assetMismatchWarningMarkup = ($hasAssetMismatchWarning && !$suppressMismatchBanners)
                ? '<div class="bpts-warning-item bpts-warning-item-neutral">'
                    . '<div class="bpts-warning-copy"><h3 class="bpts-warning-heading">Asset Mismatch</h3></div>'
                    . '<div class="bpts-warning-detail"><p>One or more assets have mismatched values that need reviewed.</p></div>'
                    . '</div>'
                : '';

            $cardWarningsWithMismatch = $reactiveWarningsMarkup
                . $staticWarningsMarkup
                . $breakpointMismatchWarningMarkup
                . $assetMismatchWarningMarkup;

            // The card's red border tracks danger-level warnings. Static/mismatch warnings
            // are fixed per render; the missing-set danger toggles reactively via signal,
            // while the neutral "process again" notice must NOT make the card red.
            $staticWarningPresent = $staticWarningsMarkup !== ''
                || $breakpointMismatchWarningMarkup !== ''
                || $assetMismatchWarningMarkup !== '';
            $cardWarningDangerExpr = $staticWarningPresent
                ? 'true'
                : ($reactiveWarningsEnabled
                    ? "String(\$editor.cards.{$signalKey}.setReviewState || '') === 'missing'"
                    : 'false');
            $cardWarningSeedDanger = $staticWarningPresent
                || ($reactiveWarningsEnabled && $setReviewState === 'missing');

            $lastProcessPanelHtml = $this->buildLastProcessPanelMarkup(
                $latestRunSnapshot,
                $latestRunSummaryForTransform,
                $transformName,
                $runEntryData,
                $observedDataByTransform[$transformName] ?? null,
            );

            $cards[] = $this->renderReviewPartial('_partials/review/transform-card', [
                'cardId' => $this->escapeReviewHtml('bpts-card-' . $signalKey),
                'transformNameEscaped' => $this->escapeReviewHtml($transformName),
                'signalKey' => $this->escapeReviewHtml($signalKey),
                'cardSignalsStructural' => $this->escapeReviewHtml($cardSignalsStructuralJson),
                'cardWarningStateClass' => $cardWarningSeedDanger
                    ? 'bpts-transform-card-warning'
                    : '',
                'cardWarningDangerExpr' => $cardWarningDangerExpr,
                'cardWarningsHtml' => $cardWarningsWithMismatch !== ''
                    ? '<div class="bpts-transform-card-warnings">' . $cardWarningsWithMismatch . '</div>'
                    : '',
                'includeEscapeWidth' => $includeEscapeWidth ? '1' : '0',
                'selectedAssetKey' => $this->escapeReviewHtml($selectedAssetKey),
                'renderedApplyHiddenClass' => $hideRenderedApply ? 'bpts-force-hidden' : '',
                'breakpointColumns' => $breakpointColumns,
                'assetPaginationHtml' => $assetPaginationHtml,
                'editPanelId' => $this->escapeReviewHtml($editPanelId),
                'signalPathBase' => $this->escapeReviewHtml($signalPathBase),
                'editScopeDefaultLabel' => $this->escapeReviewHtml($scopeLabel),
                'editScopeAllCheckedAttr' => $scope['mode'] === 'all' ? 'checked' : '',
                'dimensionsTabActiveClass' => $activeDimensions ? 'active' : '',
                'dimensionsTabSelected' => $activeDimensions ? 'true' : 'false',
                'dimensionsTabTabindex' => $activeDimensions ? '0' : '-1',
                'ratioTabActiveClass' => $activeRatio ? 'active' : '',
                'ratioTabSelected' => $activeRatio ? 'true' : 'false',
                'ratioTabTabindex' => $activeRatio ? '0' : '-1',
                'settingsTabActiveClass' => $activeSettings ? 'active' : '',
                'settingsTabSelected' => $activeSettings ? 'true' : 'false',
                'settingsTabTabindex' => $activeSettings ? '0' : '-1',
                'dimensionsPanelActiveClass' => $activeDimensions ? 'active' : '',
                'dimensionsPanelHiddenAttr' => $activeDimensions ? '' : 'hidden',
                'ratioPanelActiveClass' => $activeRatio ? 'active' : '',
                'ratioPanelHiddenAttr' => $activeRatio ? '' : 'hidden',
                'settingsPanelActiveClass' => $activeSettings ? 'active' : '',
                'settingsPanelHiddenAttr' => $activeSettings ? '' : 'hidden',
                'widthInputId' => $this->escapeReviewHtml($editPanelId . '-width'),
                'heightInputId' => $this->escapeReviewHtml($editPanelId . '-height'),
                'ratioWidthInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-width'),
                'ratioHeightInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-height'),
                'ratioFloatInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-float'),
                'ratioSourceName' => $this->escapeReviewHtml($editPanelId . '-ratio-source'),
                'passHeightToggleId' => $this->escapeReviewHtml($editPanelId . '-pass-height-toggle'),
                'allowAnyHeightToggleId' => $this->escapeReviewHtml($editPanelId . '-allow-any-height-toggle'),
                'passHeightIndicatorHiddenClass' => ($passHeightWhenRenderedLteSaved || $allowAnyHeight) ? '' : 'bpts-force-hidden',
                'ratioSourceBreakpointOptions' => $ratioSourceBreakpointOptions,
                'lastProcessPanelHtml' => $lastProcessPanelHtml,
            ]);
        }

        if ($cards === []) {
            return $this->renderReviewPartial('_partials/review/empty-state', []);
        }

        return implode('', $cards);
    }

    private function renderReviewBreakpointColumn(
        string $transformName,
        int $breakpoint,
        string $breakpointKey,
        array $rows,
        array $currentRow,
        array $breakpointColumnWidths,
        array $previewLockHeightsByBreakpoint,
        string $signalKey,
        ?int $selectedBreakpoint,
        bool $allSelected,
        ?int $escapeBreakpoint,
        bool $hideRenderedApply,
        string $reviewMode,
        bool $passHeightWhenRenderedLteSaved = false,
        ?int $savedWidth = null,
        ?int $savedHeight = null,
        bool $allowAnyHeight = false,
    ): string {
        $state = $this->breakpointStateBuilder->build(
            $transformName,
            $breakpoint,
            $rows,
            $currentRow,
            $passHeightWhenRenderedLteSaved,
            $savedWidth,
            $savedHeight,
            $allowAnyHeight,
            $hideRenderedApply,
            $reviewMode,
        );

        $summary = $state['summary'];
        $renderedRowsPayload = $state['renderedRowsPayload'];
        $renderedWidth = (int)($state['renderedWidth'] ?? 0);
        $renderedHeight = (int)($state['renderedHeight'] ?? 0);
        $previewSrc = (string)($state['previewSrc'] ?? '');
        $currentWidth = $state['currentWidth'] ?? null;
        $currentHeight = $state['currentHeight'] ?? null;
        $currentRatioWidth = $state['currentRatioWidth'] ?? null;
        $currentRatioHeight = $state['currentRatioHeight'] ?? null;
        $currentRatioFloatValue = (string)($state['currentRatioFloatValue'] ?? '');
        $currentRatioSourceDimension = (string)($state['currentRatioSourceDimension'] ?? 'width');
        $currentRatioLocked = ($state['currentRatioLocked'] ?? false) === true;
        $autoDimension = $state['autoDimension'] ?? null;
        $aspectRatio = (string)($state['aspectRatio'] ?? '1 / 1');
        $relativeWidth = (float)($state['relativeWidth'] ?? 0.0);
        $widthClass = (string)($state['widthClass'] ?? '');
        $heightClass = (string)($state['heightClass'] ?? '');
        $renderedApplyNoop = ($state['renderedApplyNoop'] ?? false) === true;
        $currentEnabled = ($state['currentEnabled'] ?? true) === true;
        $currentWidthDerivedClass = (string)($state['currentWidthDerivedClass'] ?? '');
        $currentHeightDerivedClass = (string)($state['currentHeightDerivedClass'] ?? '');
        $previewMedia = '';
        if ($currentEnabled) {
            $previewMedia = $previewSrc !== ''
                ? sprintf(
                    '<img src="%s" alt="%s" class="bpi_breakpoint-result-image" draggable="false" style="--bpts-aspect-ratio:%s;">',
                    $this->escapeReviewHtml($previewSrc),
                    $this->escapeReviewHtml('Preview ' . $transformName . ' ' . $breakpoint . 'px'),
                    $this->escapeReviewHtml($aspectRatio),
                )
                : sprintf(
                    '<div class="bpi_breakpoint-result-image" style="--bpts-aspect-ratio:%s;"></div>',
                    $this->escapeReviewHtml($aspectRatio),
                );
        }

        $hiddenCount = (int)($summary['hiddenCount'] ?? 0);
        $unloadedCount = (int)($summary['unloadedCount'] ?? 0);
        $hiddenBadge = $hiddenCount > 0
            ? '<span class="bpi_hidden-notice">Hidden ' . $hiddenCount . '</span>'
            : '';
        $unloadedBadge = $unloadedCount > 0
            ? '<span class="bpts-row-badge">Unloaded ' . $unloadedCount . '</span>'
            : '';
        $escapeBadge = $escapeBreakpoint !== null && $escapeBreakpoint === $breakpoint
            ? '<span class="bpi_escaped-notice">ESC</span>'
            : '';
        $hasBreakpointMismatch = ($state['hasBreakpointMismatch'] ?? false) === true;

        $isSelected = $allSelected || ($selectedBreakpoint !== null && $selectedBreakpoint === $breakpoint);
        $breakpointColumnWidth = (float)($breakpointColumnWidths[(string)$breakpoint] ?? 1.0);
        if ($breakpointColumnWidth < 1.0) {
            $breakpointColumnWidth = 1.0;
        }
        $previewLockHeight = max(48, (int)($previewLockHeightsByBreakpoint[(string)$breakpoint] ?? 48));

        return $this->renderReviewPartial('_partials/review/breakpoint-column', [
            'breakpointColumnSelectedClass' => $isSelected ? 'bpts-breakpoint-column-selected' : '',
            'breakpointColumnMismatchClass' => $hasBreakpointMismatch ? 'bpts-breakpoint-column-mismatch' : '',
            'breakpointColumnDisabledClass' => !$currentEnabled ? 'bpts-breakpoint-column-disabled' : '',
            'breakpoint' => (string)$breakpoint,
            'breakpointKey' => $this->escapeReviewHtml($breakpointKey),
            'breakpointColumnWidth' => (string)$breakpointColumnWidth,
            'previewLockHeight' => (string)$previewLockHeight,
            'signalKey' => $this->escapeReviewHtml($signalKey),
            'currentWidthValue' => $currentWidth !== null ? (string)$currentWidth : '',
            'currentHeightValue' => $currentHeight !== null ? (string)$currentHeight : '',
            'currentRatioWidthValue' => $currentRatioWidth !== null ? (string)$currentRatioWidth : '',
            'currentRatioHeightValue' => $currentRatioHeight !== null ? (string)$currentRatioHeight : '',
            'currentRatioFloatValue' => $currentRatioFloatValue,
            'currentRatioSourceDimension' => $currentRatioSourceDimension,
            'currentRatioLockedValue' => $currentRatioLocked ? '1' : '0',
            'currentAutoDimension' => $autoDimension ?? '',
            'escapeBadge' => $escapeBadge,
            'hiddenBadge' => $hiddenBadge,
            'unloadedBadge' => $unloadedBadge,
            'breakpointEnableOnClass' => $currentEnabled ? 'on' : '',
            'breakpointEnableTitle' => $this->escapeReviewHtml(($currentEnabled ? 'Disable' : 'Enable') . ' ' . $breakpoint . 'px breakpoint'),
            'breakpointEnableAriaLabel' => $this->escapeReviewHtml(($currentEnabled ? 'Disable' : 'Enable') . ' ' . $breakpoint . 'px breakpoint'),
            'breakpointEnableAriaChecked' => $currentEnabled ? 'true' : 'false',
            'breakpointDisabledAttr' => $renderedRowsPayload === [] ? 'disabled' : '',
            'breakpointRenderedApplyMatchClass' => $renderedApplyNoop ? 'bpts-rendered-apply-single-noop' : '',
            'breakpointRenderedApplyAriaLabel' => $this->escapeReviewHtml(
                ($renderedApplyNoop ? 'Rendered values already match for ' : 'Apply rendered values for ')
                . $breakpoint
                . 'px'
            ),
            'breakpointRenderedApplyTitle' => $this->escapeReviewHtml(
                ($renderedApplyNoop ? 'Rendered values already match for ' : 'Apply rendered values for ')
                . $breakpoint
                . 'px'
            ),
            'breakpointRenderedApplyIconName' => $renderedApplyNoop ? 'check' : 'arrow-down',
            'breakpointRenderedApplyHiddenClass' => $hideRenderedApply ? 'bpts-force-hidden' : '',
            'breakpointRenderedRowHiddenClass' => $hideRenderedApply ? 'bpts-force-hidden' : '',
            'relativeWidth' => (string)$relativeWidth,
            'previewMedia' => $previewMedia,
            'widthClass' => $widthClass,
            'heightClass' => $heightClass,
            'renderedWidth' => $renderedWidth > 0 ? (string)$renderedWidth : '-',
            'renderedHeight' => $renderedHeight > 0 ? (string)$renderedHeight : '-',
            'currentWidth' => $this->escapeReviewHtml($this->getReviewCurrentDimensionDisplay($currentWidth, $autoDimension, 'width')),
            'currentHeight' => $this->escapeReviewHtml($this->getReviewCurrentDimensionDisplay($currentHeight, $autoDimension, 'height')),
            'currentWidthDerivedClass' => $currentWidthDerivedClass,
            'currentHeightDerivedClass' => $currentHeightDerivedClass,
        ]);
    }

    private function normalizeReviewRowsByBreakpoint(mixed $rawRowsByBreakpoint): array
    {
        if (!is_array($rawRowsByBreakpoint)) {
            return [];
        }

        $normalized = [];
        foreach ($rawRowsByBreakpoint as $breakpointKey => $rows) {
            $breakpoint = Support::normalizeNullablePositiveInt($breakpointKey);
            if ($breakpoint === null || !is_array($rows)) {
                continue;
            }

            $normalizedRows = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $loaded = ($row['loaded'] ?? false) === true;
                $broken = ($row['broken'] ?? false) === true;
                $unresolved = ($row['unresolved'] ?? false) === true;
                $transformName = (string)($row['transform'] ?? 'unknown');
                $assetId = trim((string)($row['assetId'] ?? ''));
                $sourceUsed = (string)($row['sourceUsed'] ?? '');
                $src = (string)($row['src'] ?? ($row['sourceUsed'] ?? ''));
                $title = (string)($row['title'] ?? '');

                if ($loaded) {
                    $broken = false;
                    $unresolved = false;
                } elseif ($broken) {
                    $loaded = false;
                    $unresolved = false;
                } elseif ($unresolved) {
                    $loaded = false;
                    $broken = false;
                }

                $normalizedRows[] = [
                    'assetId' => $assetId,
                    'assetKey' => $this->buildReviewAssetKey($transformName, $assetId, $sourceUsed, $src, $title),
                    'transform' => $transformName,
                    'title' => $title,
                    'enabled' => ($row['enabled'] ?? true) === true,
                    'isVisible' => ($row['isVisible'] ?? false) === true,
                    'loaded' => $loaded,
                    'broken' => $broken,
                    'unresolved' => $unresolved,
                    'sourceUsed' => $sourceUsed,
                    'src' => $src,
                    'rendered' => [
                        'width' => Support::toNonNegativeInt($row['rendered']['width'] ?? 0),
                        'height' => Support::toNonNegativeInt($row['rendered']['height'] ?? 0),
                    ],
                    'intrinsic' => [
                        'width' => Support::toNonNegativeInt($row['intrinsic']['width'] ?? 0),
                        'height' => Support::toNonNegativeInt($row['intrinsic']['height'] ?? 0),
                    ],
                    'transformDimensions' => [
                        'width' => Support::normalizeNullablePositiveInt($row['transformDimensions']['width'] ?? null),
                        'height' => Support::normalizeNullablePositiveInt($row['transformDimensions']['height'] ?? null),
                        'autoDimension' => (string)($row['transformDimensions']['autoDimension'] ?? ''),
                    ],
                ];
            }

            $normalized[$breakpoint] = $normalizedRows;
        }

        ksort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    private function normalizeReviewBreakpoints(mixed $rawBreakpoints): array
    {
        if (!is_array($rawBreakpoints)) {
            return [];
        }

        $normalized = [];
        foreach ($rawBreakpoints as $rawBreakpoint) {
            $breakpoint = Support::normalizeNullablePositiveInt($rawBreakpoint);
            if ($breakpoint !== null) {
                $normalized[] = $breakpoint;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function collectReviewTransformNames(array $rowsByBreakpoint): array
    {
        $names = [];
        foreach ($rowsByBreakpoint as $rows) {
            foreach ($rows as $row) {
                $name = (string)($row['transform'] ?? '');
                if ($name !== '' && $name !== 'unknown') {
                    $names[$name] = true;
                }
            }
        }

        $transformNames = array_keys($names);
        sort($transformNames, SORT_STRING);

        return $transformNames;
    }

    private function orderReviewTransformNames(
        array $transformNames,
        array $warningsByTransform,
        array $preferredOrderBySet = [],
        array $breakpointMismatchTransformNames = [],
        array $assetMismatchTransformNames = [],
    ): array
    {
        $preferredPositions = [];
        foreach ($preferredOrderBySet as $index => $transformName) {
            if (!is_string($transformName) || trim($transformName) === '') {
                continue;
            }

            $normalizedName = trim($transformName);
            if (array_key_exists($normalizedName, $preferredPositions)) {
                continue;
            }

            $preferredPositions[$normalizedName] = $index;
        }

        $priorityFor = static function (string $name) use (
            $breakpointMismatchTransformNames,
            $assetMismatchTransformNames,
            $warningsByTransform,
        ): int {
            if (!empty($breakpointMismatchTransformNames[$name])) {
                return 0;
            }
            if (!empty($assetMismatchTransformNames[$name])) {
                return 1;
            }
            if (!empty($warningsByTransform[$name])) {
                return 2;
            }

            return 3;
        };

        usort($transformNames, static function (string $left, string $right) use ($priorityFor, $preferredPositions): int {
            $leftPriority = $priorityFor($left);
            $rightPriority = $priorityFor($right);

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            $leftPosition = $preferredPositions[$left] ?? null;
            $rightPosition = $preferredPositions[$right] ?? null;

            if ($leftPosition !== null && $rightPosition !== null) {
                return $leftPosition <=> $rightPosition;
            }

            if ($leftPosition !== null) {
                return -1;
            }

            if ($rightPosition !== null) {
                return 1;
            }

            return strcmp($left, $right);
        });

        return $transformNames;
    }

    private function getReviewBreakpointsForTransformConfig(bool $includeEscapeWidth, array $breakpoints): array
    {
        $escapeBreakpoint = $this->getReviewEscapeBreakpoint();
        if ($includeEscapeWidth || $escapeBreakpoint === null) {
            return $breakpoints;
        }

        return array_values(array_filter(
            $breakpoints,
            static fn(int $breakpoint): bool => $breakpoint !== $escapeBreakpoint,
        ));
    }

    private function getReviewEscapeBreakpoint(): ?int
    {
        if ($this->plugin === null) {
            return null;
        }

        $breakpoints = $this->plugin->getConfigService()->getBreakpoints();
        if (!is_array($breakpoints) || !array_key_exists('escape', $breakpoints)) {
            return null;
        }

        return Support::normalizeNullablePositiveInt($breakpoints['escape']);
    }

    private function getReviewConfiguredBreakpoints(): array
    {
        if ($this->plugin === null) {
            return [];
        }

        $breakpoints = $this->plugin->getConfigService()->getBreakpoints();
        if (!is_array($breakpoints)) {
            return [];
        }

        $values = [];
        foreach ($breakpoints as $value) {
            $normalized = Support::normalizeNullablePositiveInt($value);
            if ($normalized !== null) {
                $values[] = $normalized;
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    /**
     * @return array<string, string>
     */
    private function getBreakpointKeysByWidth(bool $includeEscapeWidth): array
    {
        if ($this->plugin === null) {
            return [];
        }

        $breakpoints = $this->plugin->getConfigService()->getBreakpoints();
        if (!$includeEscapeWidth) {
            unset($breakpoints['escape']);
        }

        $keysByWidth = [];
        foreach ($breakpoints as $key => $width) {
            $normalizedWidth = Support::normalizeNullablePositiveInt($width);
            if ($normalizedWidth === null) {
                continue;
            }

            $keysByWidth[(string)$normalizedWidth] = (string)$key;
        }

        return $keysByWidth;
    }

    private function getReviewStoredTransforms(): array
    {
        if ($this->snapshotReader === null) {
            return [];
        }

        return $this->snapshotReader->getStoredTransforms();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getLatestRunSnapshotRowsByTransformAndBreakpoint(): array
    {
        if ($this->snapshotReader === null) {
            return [];
        }

        return $this->snapshotReader->getLatestRunRowsByTransformAndBreakpoint();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getPreviewCacheRowsByTransformAndBreakpoint(): array
    {
        if ($this->snapshotReader === null) {
            return [];
        }

        return $this->snapshotReader->getPreviewCacheRowsByTransformAndBreakpoint();
    }

    private function getLatestRunSnapshotForReview(): ?array
    {
        if ($this->snapshotReader === null) {
            return null;
        }

        return $this->snapshotReader->getLatestRunSnapshot();
    }

    /**
     * @param array<string, mixed>|null $latestRunSnapshot
     * @return array<string, bool>
     */
    private function buildEditedTransformsMap(?array $latestRunSnapshot, bool $isProcessedReview): array
    {
        if ($this->healthAnalyzer === null) {
            return [];
        }

        return $this->healthAnalyzer->buildEditedTransformsMap($latestRunSnapshot, $isProcessedReview);
    }

    /**
     * @param array<int|string, array{w: int|null, h: int|null}> $a
     * @param array<int|string, array{w: int|null, h: int|null}> $b
     */
    private function savedDimensionsDiffer(array $a, array $b): bool
    {
        if ($this->healthAnalyzer === null) {
            return false;
        }

        return $this->healthAnalyzer->savedDimensionsDiffer($a, $b);
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, array<string, mixed>>
     */
    public function buildLatestRunHealthByTransform(?array $snapshot = null): array
    {
        if ($this->healthAnalyzer === null) {
            return [];
        }

        return $this->healthAnalyzer->buildLatestRunHealthByTransform($snapshot);
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function buildLatestRunSummaryByTransform(?array $snapshot): array
    {
        if ($this->healthAnalyzer === null) {
            return [];
        }

        return $this->healthAnalyzer->buildLatestRunSummaryByTransform($snapshot);
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @param array<string, mixed>|null $transformSummary
     * @param string $transformHandle
     * @param array<string, mixed>|null $runEntryData
     * @param array<string, mixed>|null $observedData
     */
    private function buildLastProcessPanelMarkup(
        ?array $snapshot,
        ?array $transformSummary,
        string $transformHandle,
        ?array $runEntryData,
        ?array $observedData,
    ): string {
        if (!is_array($snapshot)) {
            return $this->renderReviewPartial('_partials/review/last-process-panel', [
                'hasSnapshot' => false,
                'statusIconClass' => '',
                'statusLabel' => '',
                'statusIconName' => '',
                'ranAtLabel' => '',
                'runEntry' => null,
                'observedEntry' => null,
                'observedSourceUrl' => '',
            ]);
        }

        $ranAtLabel = $this->formatLatestRunTimestamp($snapshot['ranAt'] ?? null);
        $hasHealthData = is_array($transformSummary);
        $hasBreakpointMismatchCount = $hasHealthData
            && is_numeric($transformSummary['breakpointMismatchBreakpointCount'] ?? null)
            && (int)$transformSummary['breakpointMismatchBreakpointCount'] > 0;
        $hasAssetMismatchCount = $hasHealthData
            && is_numeric($transformSummary['assetMismatchBreakpointCount'] ?? null)
            && (int)$transformSummary['assetMismatchBreakpointCount'] > 0;
        $hasMismatch = $hasBreakpointMismatchCount || $hasAssetMismatchCount;

        if (!$hasHealthData) {
            $statusIconClass = 'bpts-transform-last-process-status-icon-unknown';
            $statusLabel = 'No Health Data';
            $statusIconName = 'alert';
        } elseif ($hasMismatch) {
            $statusIconClass = 'bpts-transform-last-process-status-icon-failed';
            $statusLabel = 'Needs Review';
            $statusIconName = 'alert';
        } else {
            $statusIconClass = 'bpts-transform-last-process-status-icon-success';
            $statusLabel = 'Transform Sets Valid';
            $statusIconName = 'check';
        }

        $observedEntry = is_array($observedData) && is_array($observedData['entry'] ?? null)
            ? $observedData['entry']
            : null;
        $observedSourceUrl = is_array($observedData)
            ? trim((string)($observedData['sourceUrl'] ?? ''))
            : '';

        return $this->renderReviewPartial('_partials/review/last-process-panel', [
            'hasSnapshot' => true,
            'statusIconClass' => $statusIconClass,
            'statusLabel' => $statusLabel,
            'statusIconName' => $statusIconName,
            'ranAtLabel' => $ranAtLabel,
            'runEntry' => is_array($runEntryData) ? $runEntryData : null,
            'observedEntry' => $observedEntry,
            'observedSourceUrl' => $observedSourceUrl,
        ]);
    }

    /**
     * @param array<string, mixed> $entryData
     */
    private function buildEntryIconLinkMarkup(array $entryData, string $iconName, string $tooltip = ''): string
    {
        $id = (int)($entryData['id'] ?? 0);
        if ($id <= 0) {
            return '';
        }

        if ($tooltip === '') {
            $title = trim((string)($entryData['title'] ?? ''));
            $tooltip = $title !== '' ? $title : 'Entry #' . $id;
        }

        $href = trim((string)($entryData['cpEditUrl'] ?? '#'));
        if ($href === '') {
            $href = '#';
        }
        $siteId = max(0, (int)($entryData['siteId'] ?? 0));

        return $this->renderReviewPartial('_partials/review/entry-link', [
            'id' => $id,
            'href' => $href,
            'siteId' => $siteId,
            'tooltip' => $tooltip,
            'iconName' => $iconName,
        ]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderReviewPartial(string $template, array $variables): string
    {
        $view = Craft::$app->getView();
        $originalMode = $view->getTemplateMode();
        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_CP);
            return $view->renderTemplate(
                'breakpoints/cp/' . $template,
                $variables,
                View::TEMPLATE_MODE_CP,
            );
        } finally {
            $view->setTemplateMode($originalMode);
        }
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, mixed>|null
     */
    private function resolveRunEntryData(?array $snapshot): ?array
    {
        if ($this->snapshotReader === null) {
            return null;
        }

        return $this->snapshotReader->resolveRunEntryData($snapshot);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveObservedDataByTransform(): array
    {
        if ($this->snapshotReader === null) {
            return [];
        }

        return $this->snapshotReader->resolveObservedDataByTransform();
    }

    private function normalizeLatestRunStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match ($normalized) {
            'completed', 'success' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => 'unknown',
        };
    }

    private function formatLatestRunTimestamp(mixed $rawValue): string
    {
        $raw = trim((string)$rawValue);
        if ($raw === '') {
            return '-';
        }

        try {
            $date = new \DateTimeImmutable($raw);
            return $date->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $raw;
        }
    }

    private function getReviewTransformConfig(array $storedTransforms, string $transformName): ?array
    {
        $config = $storedTransforms[$transformName] ?? null;
        return is_array($config) ? $config : null;
    }

    private function buildReviewAssetKey(
        string $transformName,
        string $assetId,
        string $sourceUsed,
        string $src,
        string $title,
    ): string {
        return ReviewAssetCollector::buildAssetKey($transformName, $assetId, $sourceUsed, $src, $title);
    }

    private function buildReviewAssetPaginationMarkup(
        array $assetKeys,
        array $assetLabelsByKey,
        array $assetMismatchByKey,
        string $selectedAssetKey,
        string $signalKey,
        bool $hideAssetPagination,
    ): string {
        return $this->renderReviewPartial('_partials/review/asset-pagination', [
            'assetKeys' => array_values($assetKeys),
            'assetLabelsByKey' => $assetLabelsByKey,
            'assetMismatchByKey' => $assetMismatchByKey,
            'selectedAssetKey' => $selectedAssetKey,
            'signalKey' => $signalKey,
            'hideAssetPagination' => $hideAssetPagination,
        ]);
    }

    /**
     * @param array<int, string> $assetKeys
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, int> $transformBreakpoints
    /**
     * @param array<int, string> $assetKeys
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @param array<int, int|null> $savedHeightsByBreakpoint
     * @return array<string, bool>
     */
    private function buildReviewAssetMismatchByKey(
        array $assetKeys,
        array $rowsByAssetByBreakpoint,
        array $transformBreakpoints,
        bool $passHeightWhenRenderedLteSaved,
        array $savedHeightsByBreakpoint,
        bool $allowAnyHeight = false,
    ): array {
        if ($this->healthAnalyzer === null) {
            return [];
        }

        return $this->healthAnalyzer->buildAssetMismatchByKey(
            $assetKeys,
            $rowsByAssetByBreakpoint,
            $transformBreakpoints,
            $passHeightWhenRenderedLteSaved,
            $savedHeightsByBreakpoint,
            $allowAnyHeight,
        );
    }

    private function hasAssetMismatchForBreakpoint(
        array $rows,
        ?array $referenceRendered,
        bool $passHeightWhenRenderedLteSaved,
        ?int $savedHeight,
        bool $allowAnyHeight = false,
    ): bool {
        if ($this->healthAnalyzer === null) {
            return false;
        }

        return $this->healthAnalyzer->hasAssetMismatchForBreakpoint(
            $rows,
            $referenceRendered,
            $passHeightWhenRenderedLteSaved,
            $savedHeight,
            $allowAnyHeight,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{compareWidth: bool, compareHeight: bool}
     */
    private function resolveReviewDimensionComparison(array $rows): array
    {
        if ($this->healthAnalyzer === null) {
            return ['compareWidth' => true, 'compareHeight' => true];
        }

        return $this->healthAnalyzer->resolveReviewDimensionComparison($rows);
    }

    /**
     * @return array<string, array<int, string|null>>
     */
    private function buildStoredAutoDimensionsByTransformAndBreakpoint(): array
    {
        if ($this->healthAnalyzer === null) {
            return [];
        }

        return $this->healthAnalyzer->buildStoredAutoDimensionsByTransformAndBreakpoint();
    }

    private function isPassHeightWhenRenderedLteSavedEnabled(?array $transformDefinition): bool
    {
        if ($this->healthAnalyzer === null) {
            return false;
        }

        return $this->healthAnalyzer->isPassHeightWhenRenderedLteSavedEnabled($transformDefinition);
    }

    private function isAllowAnyHeightEnabled(?array $transformDefinition): bool
    {
        if ($this->healthAnalyzer === null) {
            return false;
        }

        return $this->healthAnalyzer->isAllowAnyHeightEnabled($transformDefinition);
    }

    /**
     * @return array<string, array<int, int|null>>
     */
    private function buildStoredSavedHeightsByTransformAndBreakpoint(): array
    {
        if ($this->healthAnalyzer === null) {
            return [];
        }

        return $this->healthAnalyzer->buildStoredSavedHeightsByTransformAndBreakpoint();
    }

    private function shouldIgnoreHeightMismatch(
        bool $passHeightWhenRenderedLteSaved,
        int $renderedHeight,
        ?int $savedHeight,
        bool $allowAnyHeight = false,
    ): bool {
        if ($this->healthAnalyzer === null) {
            return false;
        }

        return $this->healthAnalyzer->shouldIgnoreHeightMismatch(
            $passHeightWhenRenderedLteSaved,
            $renderedHeight,
            $savedHeight,
            $allowAnyHeight,
        );
    }

    /**
     * @return array<string, array<int, int|null>>
     */
    private function buildStoredSavedWidthsByTransformAndBreakpoint(): array
    {
        if ($this->healthAnalyzer === null) {
            return [];
        }

        return $this->healthAnalyzer->buildStoredSavedWidthsByTransformAndBreakpoint();
    }

    /**
     * Returns the saved width/height for every (transform, breakpoint) currently in the store.
     *
     * Shape: `[transformName => [breakpoint => ['w' => int|null, 'h' => int|null]]]`.
     * `null` values mean "auto" or "not set" on that side.
     *
     * Used by snapshot persistence to capture the saved dimensions at process time,
     * and by the review renderer to detect per-transform edits since processing.
     *
     * @return array<string, array<int, array{w: int|null, h: int|null}>>
     */
    public function buildSavedDimensionsByTransformAndBreakpoint(): array
    {
        if ($this->healthAnalyzer === null) {
            return [];
        }

        return $this->healthAnalyzer->buildSavedDimensionsByTransformAndBreakpoint();
    }

    /**
     * @return array{widthStatus: string, heightStatus: string, isBreakpointMismatch: bool}
     */
    private function evaluateBreakpointMatch(
        int $renderedWidth,
        int $renderedHeight,
        ?int $savedWidth,
        ?int $savedHeight,
        ?string $autoDimension,
        bool $passHeightWhenRenderedLteSaved,
        bool $allowAnyHeight = false,
    ): array {
        if ($this->healthAnalyzer === null) {
            return ['widthStatus' => 'no-transform', 'heightStatus' => 'no-transform', 'isBreakpointMismatch' => false];
        }

        return $this->healthAnalyzer->evaluateBreakpointMatch(
            $renderedWidth,
            $renderedHeight,
            $savedWidth,
            $savedHeight,
            $autoDimension,
            $passHeightWhenRenderedLteSaved,
            $allowAnyHeight,
        );
    }

    private function evaluateDimensionMatch(
        int $renderedValue,
        ?int $savedValue,
        bool $isAuto,
    ): string {
        if ($this->healthAnalyzer === null) {
            return 'no-transform';
        }

        return $this->healthAnalyzer->evaluateDimensionMatch($renderedValue, $savedValue, $isAuto);
    }

    private function getReviewCurrentDimensionDisplay(?int $value, ?string $autoDimension, string $dimension): string
    {
        if ($autoDimension === $dimension) {
            return 'auto';
        }

        if ($value === null) {
            return '-';
        }

        return (string)$value;
    }
    private function getReviewTransformSignalKey(string $transformName): string
    {
        $base = str_replace('-', '_', Support::slugifyTransformName($transformName));
        return 't_' . $base . '_' . substr(sha1($transformName), 0, 8);
    }

    private function normalizeReviewTab(mixed $rawTab): string
    {
        $tab = is_string($rawTab) ? $rawTab : '';
        return in_array($tab, ['dimensions', 'ratio', 'settings'], true) ? $tab : 'dimensions';
    }

    private function normalizeReviewMode(mixed $rawReviewMode): string
    {
        $reviewMode = is_string($rawReviewMode) ? strtolower(trim($rawReviewMode)) : '';
        return in_array($reviewMode, [self::REVIEW_MODE_PROCESSED, self::REVIEW_MODE_SAVED], true)
            ? $reviewMode
            : self::REVIEW_MODE_PROCESSED;
    }

    private function normalizeReviewScope(mixed $rawScope, array $transformBreakpoints): array
    {
        if (!is_array($rawScope)) {
            return ['mode' => 'unset', 'breakpoint' => null];
        }

        $mode = strtolower(trim((string)($rawScope['mode'] ?? 'unset')));
        if ($mode === 'all') {
            return ['mode' => 'all', 'breakpoint' => null];
        }

        if ($mode === 'breakpoint') {
            $breakpoint = Support::normalizeNullablePositiveInt($rawScope['breakpoint'] ?? null);
            if ($breakpoint !== null && in_array($breakpoint, $transformBreakpoints, true)) {
                return ['mode' => 'breakpoint', 'breakpoint' => $breakpoint];
            }
        }

        return ['mode' => 'unset', 'breakpoint' => null];
    }

    private function isReviewScopeActive(array $scope): bool
    {
        return ($scope['mode'] ?? 'unset') === 'all' || ($scope['mode'] ?? 'unset') === 'breakpoint';
    }

    private function getReviewScopeDimensionInputValues(array $currentRowsByBreakpoint, array $scope): array
    {
        if (($scope['mode'] ?? 'unset') !== 'breakpoint') {
            return [
                'widthInput' => '',
                'heightInput' => '',
                'widthAuto' => '0',
                'heightAuto' => '0',
                'ratioLocked' => '0',
                'ratioWidthInput' => '',
                'ratioHeightInput' => '',
                'ratioFloatInput' => '',
                'ratioSourceDimension' => 'width',
            ];
        }

        $breakpoint = Support::normalizeNullablePositiveInt($scope['breakpoint'] ?? null);
        if ($breakpoint === null || !isset($currentRowsByBreakpoint[$breakpoint])) {
            return [
                'widthInput' => '',
                'heightInput' => '',
                'widthAuto' => '0',
                'heightAuto' => '0',
                'ratioLocked' => '0',
                'ratioWidthInput' => '',
                'ratioHeightInput' => '',
                'ratioFloatInput' => '',
                'ratioSourceDimension' => 'width',
            ];
        }

        $entry = $currentRowsByBreakpoint[$breakpoint];
        $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
        $widthValue = Support::normalizeNullablePositiveInt($entry['width'] ?? null);
        $heightValue = Support::normalizeNullablePositiveInt($entry['height'] ?? null);
        $ratioWidthValue = Support::normalizeNullablePositiveInt($entry['ratioWidth'] ?? null);
        $ratioHeightValue = Support::normalizeNullablePositiveInt($entry['ratioHeight'] ?? null);
        $ratioSourceDimension = Support::normalizeRatioSourceDimension($entry['ratioSourceDimension'] ?? null) ?? 'width';
        $ratioLocked = ($entry['ratioLocked'] ?? false) === true
            && $ratioWidthValue !== null
            && $ratioHeightValue !== null;
        $widthAuto = $autoDimension === 'width';
        $heightAuto = $autoDimension === 'height';

        $fallbackRatioWidth = $widthAuto || $widthValue === null ? '' : (string)$widthValue;
        $fallbackRatioHeight = $heightAuto || $heightValue === null ? '' : (string)$heightValue;
        $resolvedRatioWidth = $ratioLocked ? (string)$ratioWidthValue : $fallbackRatioWidth;
        $resolvedRatioHeight = $ratioLocked ? (string)$ratioHeightValue : $fallbackRatioHeight;

        return [
            'widthInput' => $widthAuto || $widthValue === null ? '' : (string)$widthValue,
            'heightInput' => $heightAuto || $heightValue === null ? '' : (string)$heightValue,
            'widthAuto' => $widthAuto ? '1' : '0',
            'heightAuto' => $heightAuto ? '1' : '0',
            'ratioLocked' => $ratioLocked ? '1' : '0',
            'ratioWidthInput' => $resolvedRatioWidth,
            'ratioHeightInput' => $resolvedRatioHeight,
            'ratioFloatInput' => Support::formatRatioFloatInput(
                Support::normalizeNullablePositiveInt($resolvedRatioWidth),
                Support::normalizeNullablePositiveInt($resolvedRatioHeight),
            ),
            'ratioSourceDimension' => $ratioLocked ? $ratioSourceDimension : 'width',
        ];
    }

    private function buildReviewWarningsByTransform(array $rowsByBreakpoint): array
    {
        if ($this->warningsBuilder === null) {
            return [];
        }

        return $this->warningsBuilder->buildWarningsByTransform(
            $rowsByBreakpoint,
            $this->getReviewStoredTransforms(),
        );
    }

    private function buildMissingSetDefinitionWarning(int $entryId = 0, bool $entryAvailable = false, bool $entryMissing = false): array
    {
        if ($this->warningsBuilder === null) {
            return [
                'code' => 'missing-set-definitions',
                'message' => 'No transforms are saved for this set.',
                'entryId' => $entryId,
                'entryAvailable' => $entryAvailable,
                'entryMissing' => $entryMissing,
            ];
        }

        return $this->warningsBuilder->buildMissingSetDefinitionWarning(
            $entryId,
            $entryAvailable,
            $entryMissing,
        );
    }

    private function countReviewWarningsByTransform(array $warningsByTransform): int
    {
        return ReviewWarningsBuilder::countWarningsByTransform($warningsByTransform);
    }

    private function buildReviewCurrentRowsForTransform(?array $transformConfig, array $transformBreakpoints): array
    {
        $rows = [];
        $entries = isset($transformConfig['transforms']) && is_array($transformConfig['transforms'])
            ? array_values($transformConfig['transforms'])
            : [];

        foreach ($transformBreakpoints as $index => $breakpoint) {
            $entry = isset($entries[$index]) && is_array($entries[$index])
                ? $entries[$index]
                : [];

            $rows[$breakpoint] = Support::normalizeTransformEntry($entry);
        }

        return $rows;
    }

    private function escapeReviewHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---- Support-backed helper delegators (private) ----

    private function getBreakpointsForTransform(bool $includeEscapeWidth): array
    {
        $breakpoints = $this->plugin->getConfigService()->getBreakpoints();

        if (!$includeEscapeWidth) {
            unset($breakpoints['escape']);
        }

        return array_values(array_map(static fn(mixed $value): int => (int)$value, $breakpoints));
    }

}
