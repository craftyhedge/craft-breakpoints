<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use Craft;
use craft\web\View;
use craftyhedge\craftbreakpoints\helpers\IncludeStack;
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
    private readonly BreakpointCatalog $breakpointCatalog;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly SnapshotReader $snapshotReader,
        private readonly HealthAnalyzer $healthAnalyzer,
        private readonly ReviewWarningsBuilder $warningsBuilder,
    ) {
        $this->cardStateBuilder = new CardStateBuilder();
        $this->breakpointStateBuilder = new ReviewBreakpointStateBuilder($healthAnalyzer);
        $this->initialStoredReviewBuilder = new InitialStoredReviewBuilder($plugin, $snapshotReader);
        $this->breakpointCatalog = new BreakpointCatalog($plugin->getConfigService(), $plugin->getBreakpointPolicy());
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $editScopeBySet
     * @param array<string, mixed> $editTabBySet
     * @param array<string, mixed> $selectedAssetKeyBySet
     * @param array<string, mixed> $preferredOrderBySet
     * @param array<int, string> $newSetNames
     * @return array<string, mixed>
     */
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
        array $newSetNames = [],
    ): array {
        $normalizedReviewMode = $this->normalizeReviewMode($reviewMode);
        $rowsByBreakpoint = $this->normalizeReviewRowsByBreakpoint($result['rowsBySlot'] ?? ($result['rowsByBreakpoint'] ?? []));
        $breakpoints = $rowsByBreakpoint !== [] ? array_keys($rowsByBreakpoint) : $this->normalizeReviewBreakpoints($result['breakpoints'] ?? []);
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
            $this->normalizeHandleList($newSetNames),
        );
    }

    /**
     * @return string[]
     */
    private function normalizeHandleList(mixed $rawHandles): array
    {
        if (!is_array($rawHandles)) {
            return [];
        }

        $handles = [];
        foreach ($rawHandles as $rawHandle) {
            $handle = trim((string)$rawHandle);
            if ($handle !== '') {
                $handles[$handle] = $handle;
            }
        }

        return array_values($handles);
    }

    /**
     * @param array<string, mixed> $editScopeBySet
     * @param array<string, mixed> $editTabBySet
     * @param array<string, mixed> $selectedAssetKeyBySet
     * @param array<string, mixed> $preferredOrderBySet
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function renderInitialStoredReview(
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
        ?string $onlyTransformName = null,
        array $result = [],
    ): array {
        $resultRowsByBreakpoint = $this->normalizeReviewRowsByBreakpoint($result['rowsBySlot'] ?? ($result['rowsByBreakpoint'] ?? []));
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

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @param array<int, int> $breakpoints
     * @param array<string, mixed> $editScopeBySet
     * @param array<string, mixed> $editTabBySet
     * @param array<string, mixed> $selectedAssetKeyBySet
     * @param array<string, mixed> $preferredOrderBySet
     * @param array<int, string> $newSetNames
     * @return array<string, mixed>
     */
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
        array $newSetNames = [],
    ): array {
        if ($breakpoints === []) {
            $breakpoints = $this->getReviewConfiguredBreakpoints();
        }

        $warningsByTransform = $this->buildReviewWarningsByTransform($rowsByBreakpoint);
        $latestRunSnapshot = $this->getLatestRunSnapshotForReview();
        $mismatchCounts = $reviewMode === self::REVIEW_MODE_PROCESSED
            ? $this->countLatestRunMismatchesByTransform($latestRunSnapshot)
            : ['breakpointMismatchCount' => 0, 'assetMismatchCount' => 0, 'mismatchCount' => 0];
        $hiddenSetWarningCount = $reviewMode === self::REVIEW_MODE_PROCESSED
            ? $this->countLatestRunHiddenSetWarningsByTransform($latestRunSnapshot)
            : 0;
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
                $newSetNames,
            ),
            'warningCount' => $this->countReviewWarningsByTransform($warningsByTransform) + $hiddenSetWarningCount,
            'breakpointMismatchCount' => $mismatchCounts['breakpointMismatchCount'],
            'assetMismatchCount' => $mismatchCounts['assetMismatchCount'],
            'mismatchCount' => $mismatchCounts['mismatchCount'],
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
            $includeEscapeWidth = $this->plugin->getBreakpointPolicy()->resolveIncludeEscapeWidth([], $transformConfig);
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
     * Build all-scope values when Select All becomes the selected scope.
     *
     * @return array<string, string>
     */
    public function buildScopeValuesForAll(string $setName, ?bool $includeEscapeWidth = null): array
    {
        $storedTransforms = $this->getReviewStoredTransforms();
        $transformConfig = $storedTransforms[$setName] ?? null;
        if ($transformConfig === null) {
            return $this->emptyScopeValues();
        }

        if ($includeEscapeWidth === null) {
            $includeEscapeWidth = $this->plugin->getBreakpointPolicy()->resolveIncludeEscapeWidth([], $transformConfig);
        }
        $transformBreakpoints = $this->getBreakpointsForTransform($includeEscapeWidth);
        if ($transformBreakpoints === []) {
            return $this->emptyScopeValues();
        }

        $currentRows = $this->buildReviewCurrentRowsForTransform($transformConfig, $transformBreakpoints);
        $cardState = $this->cardStateBuilder->build(
            $currentRows,
            $transformBreakpoints,
            ['mode' => 'all'],
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

    /**
     * @return array{signalKey: string, rowsByBreakpoint: array<int|string, array<string, mixed>>, hasCurrentBreakpointMismatch?: bool, hasResolvedBreakpointMismatchAwaitingVerification?: bool, hasCardWarningDanger?: bool, savedRenderedFrom?: string, savedRenderedFromStack?: string, savedRenderedFromMore?: int, savedAt?: string, savedAtLabel?: string, savedAtTitle?: string}
     */
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
        $includeEscapeWidth = $this->plugin->getBreakpointPolicy()->resolveIncludeEscapeWidth([], $transformConfig);
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
        $allowHiddenDuringProcessing = $this->isAllowHiddenDuringProcessingEnabled($transformConfig);
        $latestRunSnapshot = $this->getLatestRunSnapshotForReview();
        $latestRunSummary = $reviewMode === self::REVIEW_MODE_PROCESSED
            ? ($this->buildLatestRunSummaryByTransform($latestRunSnapshot)[$normalized] ?? null)
            : null;
        $allowHiddenMismatchNeutral = $allowHiddenDuringProcessing
            || (is_array($latestRunSummary) && (($latestRunSummary['hasAllEnabledBreakpointsHidden'] ?? false) === true));
        $storedSavedWidthsByTransform = $this->buildStoredSavedWidthsByTransformAndBreakpoint();
        $storedSavedHeightsByTransform = $this->buildStoredSavedHeightsByTransformAndBreakpoint();
        $processSavedDimensionsByTransform = $this->buildProcessSavedDimensionsByTransformAndBreakpoint(
            $latestRunSnapshot,
        );

        $referenceWidthsById = [];
        foreach ($transformBreakpoints as $bp) {
            $referenceWidthsById[(string)$bp] = $this->getReviewSlotMediaWidthById($bp, $includeEscapeWidth) ?? $bp;
        }
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
                $allowHiddenMismatchNeutral,
                $hideRenderedApply,
                $reviewMode,
                $referenceWidthsById[(string)$breakpoint] ?? null,
                $processSavedDimensionsByTransform[$normalized][$breakpoint] ?? null,
            );
            $rowsByBreakpoint[$breakpointKey] = array_merge($coreRows[$breakpointKey] ?? [], $ui);
        }

        $warningsByTransform = $this->buildReviewWarningsByTransform($resultRowsByBreakpoint);
        $hasCurrentBreakpointMismatch = is_array($latestRunSummary)
            && (($latestRunSummary['hasBreakpointMismatch'] ?? false) === true);
        $hasCurrentAssetMismatch = is_array($latestRunSummary)
            && (($latestRunSummary['hasAssetMismatch'] ?? false) === true);
        $editedSinceProcess = $reviewMode === self::REVIEW_MODE_PROCESSED
            && (($this->buildEditedTransformsMap($latestRunSnapshot, true)[$normalized] ?? false) === true);

        $savedRenderedFromStack = IncludeStack::fromValue($transformConfig['renderedFrom'] ?? null);

        return [
            'signalKey' => $signalKey,
            'rowsByBreakpoint' => $rowsByBreakpoint,
            'hasCurrentBreakpointMismatch' => $hasCurrentBreakpointMismatch,
            'hasResolvedBreakpointMismatchAwaitingVerification' => $reviewMode === self::REVIEW_MODE_PROCESSED
                && $editedSinceProcess
                && !$hasCurrentBreakpointMismatch,
            'hasCardWarningDanger' => $hasCurrentBreakpointMismatch
                || $hasCurrentAssetMismatch
                || ReviewWarningsBuilder::hasNonAdvisoryWarning($warningsByTransform[$normalized] ?? []),
            'savedRenderedFrom' => IncludeStack::leaf($savedRenderedFromStack) ?? '',
            'savedRenderedFromStack' => IncludeStack::encode($savedRenderedFromStack),
            'savedRenderedFromMore' => max(0, IncludeStack::templateCount($savedRenderedFromStack) - 1),
            ...$this->buildSavedAtSignals($transformConfig['lastUpdatedAt'] ?? null),
        ];
    }

    /**
     * Build the UI-only signal fields for one breakpoint (mismatch, apply button state, classes, etc.).
     * Replicates the logic that used to live inside renderReviewBreakpointColumn.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $currentRow
     * @param array{w: int|null, h: int|null}|null $processSavedDimensions
     * @return array<string, string>
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
        bool $allowHiddenDuringProcessing,
        bool $hideRenderedApply,
        string $reviewMode,
        ?int $referenceWidth = null,
        ?array $processSavedDimensions = null,
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
            $allowHiddenDuringProcessing,
            $hideRenderedApply,
            $reviewMode,
            $referenceWidth,
            $processSavedDimensions,
        );

        $renderedApplyNoop = ($state['renderedApplyNoop'] ?? false) === true;
        $currentEnabled = ($state['currentEnabled'] ?? true) === true;
        $hiddenCount = (int)(($state['summary'] ?? [])['hiddenCount'] ?? 0);
        $displayPx = $this->getReviewSlotMediaWidthById($breakpoint, false) ?? $breakpoint;

        return [
            'breakpointColumnMismatchClass' => ($state['hasBreakpointMismatch'] ?? false) === true ? '1' : '0',
            'breakpointColumnDisabledClass' => $currentEnabled ? '0' : '1',
            'hiddenBadgeHiddenClass' => (!$currentEnabled || $hiddenCount > 0) ? '0' : '1',
            'hiddenBadgeTitle' => !$currentEnabled ? 'Disabled breakpoint' : "Hidden {$hiddenCount}",
            'breakpointEnableTitle' => $currentEnabled ? "Disable {$displayPx}px breakpoint" : "Enable {$displayPx}px breakpoint",
            'breakpointEnableAriaLabel' => $currentEnabled ? "Disable {$displayPx}px breakpoint" : "Enable {$displayPx}px breakpoint",
            'breakpointEnableAriaChecked' => $currentEnabled ? 'true' : 'false',
            // Only a genuinely disabled breakpoint blocks "Set to rendered"; a
            // hidden-but-enabled breakpoint stays clickable so it can be disabled.
            'breakpointDisabledAttr' => $currentEnabled ? '0' : '1',
            'breakpointRenderedApplyMatchClass' => $renderedApplyNoop ? '1' : '0',
            'breakpointRenderedApplyAriaLabel' => $renderedApplyNoop
                ? "Rendered values already match for {$displayPx}px"
                : "Apply rendered values for {$displayPx}px",
            'breakpointRenderedApplyTitle' => $renderedApplyNoop
                ? "Rendered values already match for {$displayPx}px"
                : "Apply rendered values for {$displayPx}px",
            'breakpointRenderedApplyIconName' => $renderedApplyNoop ? 'check' : 'arrow-down',
            'breakpointRenderedApplyHiddenClass' => $hideRenderedApply ? '1' : '0',
            'widthClass' => (string)($state['widthClass'] ?? ''),
            'heightClass' => (string)($state['heightClass'] ?? ''),
            'currentWidthDerivedClass' => (string)($state['currentWidthDerivedClass'] ?? '') !== '' ? '1' : '0',
            'currentHeightDerivedClass' => (string)($state['currentHeightDerivedClass'] ?? '') !== '' ? '1' : '0',
            'currentWidthEditedClass' => (string)($state['currentWidthEditedClass'] ?? '') !== '' ? '1' : '0',
            'currentHeightEditedClass' => (string)($state['currentHeightEditedClass'] ?? '') !== '' ? '1' : '0',
            // Hidden-but-enabled breakpoints suppress their preview just like disabled ones.
            'previewMediaHidden' => (!$currentEnabled || $hiddenCount > 0) ? '1' : '0',
        ];
    }

    /**
     * Build result array from latest persisted snapshot for a specific transform.
     * Used by the signal delta path to reuse the same normalized evidence shape as rendering.
     *
     * @return array{breakpoints: array<int, int>, rowsByBreakpoint: array<int, array<int, array<string, mixed>>>}
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
            $slotKey = trim((string)($row['slotKey'] ?? ''));
            $breakpointWidth = $this->getReviewSlotIdByKey($slotKey) ?? 0;
            $mediaWidth = isset($row['breakpointWidth']) && is_numeric($row['breakpointWidth'])
                ? (int)$row['breakpointWidth']
                : ($this->getReviewSlotMediaWidthById($breakpointWidth) ?? 0);

            if ($transformHandle !== $transformName || $breakpointWidth <= 0) {
                continue;
            }

            if (!isset($rowsByBreakpoint[$breakpointWidth])) {
                $rowsByBreakpoint[$breakpointWidth] = [];
                $breakpoints[] = $breakpointWidth;
            }

            $rowsByBreakpoint[$breakpointWidth][] = [
                'transform' => $transformHandle,
                'slotKey' => $slotKey,
                'slotIndex' => $breakpointWidth - 1,
                'mediaWidth' => $mediaWidth,
                'measureWidth' => isset($row['measureWidth']) && is_numeric($row['measureWidth']) ? (int)$row['measureWidth'] : null,
                'assetId' => trim((string)($row['assetId'] ?? '')),
                'title' => $transformHandle . ' ' . ($slotKey !== '' ? $slotKey : (string)$mediaWidth),
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

    /**
     * @param array<int, array<string, mixed>> $warnings
     */
    private function renderReviewWarningsMarkup(
        array $warnings,
        bool $showEmptyState = true,
        string $reviewMode = self::REVIEW_MODE_PROCESSED,
        string $setName = '',
    ): string {
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
            'canEditTransforms' => $this->plugin->getTelemetry()->canEditTransforms(),
            'setName' => $setName,
        ]);
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @param array<int, int> $breakpoints
     * @param array<string, array<int, array<string, mixed>>> $warningsByTransform
     * @param array<string, mixed> $editScopeBySet
     * @param array<string, mixed> $editTabBySet
     * @param array<string, mixed> $selectedAssetKeyBySet
     * @param array<string, mixed> $preferredOrderBySet
     * @param array<string, mixed> $normalizedScopeState
     * @param array<string, mixed> $normalizedTabState
     * @param array<string, mixed> $normalizedSelectedAssetKeyBySet
     * @param array<int, string> $newSetNames
     */
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
        array $newSetNames = [],
    ): string {
        $isProcessedReview = $reviewMode === self::REVIEW_MODE_PROCESSED;
        $transformNames = $this->collectReviewTransformNames($rowsByBreakpoint);

        $configuredBreakpoints = $breakpoints !== [] ? $breakpoints : $this->getReviewConfiguredBreakpoints();
        $storedTransforms = $this->getReviewStoredTransforms();
        $storedSavedHeightsByTransform = $this->buildStoredSavedHeightsByTransformAndBreakpoint();
        $storedSavedWidthsByTransform = $this->buildStoredSavedWidthsByTransformAndBreakpoint();
        $latestRunSnapshot = $this->getLatestRunSnapshotForReview();
        $latestRunSummariesByTransform = $this->buildLatestRunSummaryByTransform($latestRunSnapshot);
        $editedTransforms = $this->buildEditedTransformsMap($latestRunSnapshot, $isProcessedReview);
        $processSavedDimensionsByTransform = $this->buildProcessSavedDimensionsByTransformAndBreakpoint($latestRunSnapshot);
        $canEditTransforms = $this->plugin !== null && $this->plugin->getTelemetry()->canEditTransforms();

        $breakpointMismatchTransformNames = [];
        $assetMismatchTransformNames = [];
        $hiddenSetWarningTransformNames = [];
        if ($isProcessedReview) {
            foreach ($latestRunSummariesByTransform as $handle => $summary) {
                if (!is_string($handle)) {
                    continue;
                }
                if (($summary['hasAllEnabledBreakpointsHidden'] ?? false) === true) {
                    $hiddenSetWarningTransformNames[$handle] = true;
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
            $hiddenSetWarningTransformNames,
            array_fill_keys($newSetNames, true),
        );
        if ($transformNames === []) {
            return $this->renderReviewPartial('_partials/review/empty-state', []);
        }

        $runEntryData = $this->resolveRunEntryData($latestRunSnapshot);
        $snapshotTransformMetadata = isset($latestRunSnapshot['transformMetadata']) && is_array($latestRunSnapshot['transformMetadata'])
            ? $latestRunSnapshot['transformMetadata']
            : [];
        $newSetNameSet = array_fill_keys($newSetNames, true);
        $cards = [];

        foreach ($transformNames as $transformName) {
            if ($onlyTransformName !== null && $onlyTransformName !== '' && $transformName !== $onlyTransformName) {
                continue;
            }

            $renderedBreakpoints = [];
            foreach ($configuredBreakpoints as $breakpoint) {
                $rows = $rowsByBreakpoint[$breakpoint] ?? [];
                foreach ($rows as $row) {
                    if (($row['transform'] ?? '') === $transformName) {
                        $renderedBreakpoints[] = $breakpoint;
                        break;
                    }
                }
            }

            $storedTransformConfig = $this->getReviewTransformConfig($storedTransforms, $transformName);
            $cardWarnings = $warningsByTransform[$transformName] ?? [];
            $includeEscapeWidth = $this->plugin->getBreakpointPolicy()->resolveIncludeEscapeWidth([], $storedTransformConfig);
            if ($storedTransformConfig === null) {
                $includeEscapeWidth = ($snapshotTransformMetadata[$transformName]['includeEscapeWidth'] ?? null) === true;
            }

            $transformBreakpoints = $renderedBreakpoints !== []
                ? $renderedBreakpoints
                : $this->getReviewBreakpointsForTransformConfig($includeEscapeWidth, $configuredBreakpoints);

            if ($transformBreakpoints === []) {
                continue;
            }

            $hasSavedSet = $storedTransformConfig !== null;
            $hideBreakpointCardsForMissingSavedSet = !$isProcessedReview && !$hasSavedSet;

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
            $processedHiddenBreakpoints = $isProcessedReview
                ? $this->buildProcessedHiddenBreakpoints($selectedAssetRowsByBreakpoint, $transformBreakpoints)
                : [];

            $currentRows = $this->buildReviewCurrentRowsForTransform(
                $storedTransformConfig,
                $transformBreakpoints,
            );

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
            $allowHiddenDuringProcessing = $this->isAllowHiddenDuringProcessingEnabled($storedTransformConfig);
            $latestRunSummaryForTransform = $latestRunSummariesByTransform[$transformName] ?? null;
            $hasAllEnabledBreakpointsHiddenWarning = $isProcessedReview
                && is_array($latestRunSummaryForTransform)
                && (($latestRunSummaryForTransform['hasAllEnabledBreakpointsHidden'] ?? false) === true);
            $allowHiddenMismatchNeutral = $allowHiddenDuringProcessing || $hasAllEnabledBreakpointsHiddenWarning;
            $notes = $this->normalizeSetNotes($storedTransformConfig['notes'] ?? '');

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

            // Copy ratio auto-applies to the scoped breakpoint, so the scoped
            // breakpoint itself is disabled as a source; default to the first
            // other breakpoint (single-breakpoint sets keep the only option).
            $ratioSourceBreakpointDefaultId = null;
            foreach ($transformBreakpoints as $candidateBreakpoint) {
                if ($selectedBreakpoint === null || (int)$candidateBreakpoint !== (int)$selectedBreakpoint) {
                    $ratioSourceBreakpointDefaultId = (int)$candidateBreakpoint;
                    break;
                }
            }
            if ($ratioSourceBreakpointDefaultId === null && $selectedBreakpoint !== null) {
                $ratioSourceBreakpointDefaultId = (int)$selectedBreakpoint;
            }
            $ratioSourceBreakpointDefault = $ratioSourceBreakpointDefaultId !== null
                ? (string)$ratioSourceBreakpointDefaultId
                : '';
            $ratioSourceBreakpointKeyDefault = $ratioSourceBreakpointDefaultId !== null
                ? ($this->getReviewSlotKeyById($ratioSourceBreakpointDefaultId) ?? '')
                : '';
            $scopeBreakpointKey = $selectedBreakpoint !== null
                ? ($this->getReviewSlotKeyById((int)$selectedBreakpoint) ?? '')
                : '';
            $firstBreakpointKey = $firstBreakpoint !== null
                ? ($this->getReviewSlotKeyById((int)$firstBreakpoint) ?? '')
                : '';

            $ratioSourceBreakpointOptions = '';
            foreach ($transformBreakpoints as $transformBreakpoint) {
                $value = (string)$transformBreakpoint;
                $slotKey = $this->getReviewSlotKeyById((int)$transformBreakpoint) ?? '';
                $displayPx = $this->getReviewSlotMediaWidthById($transformBreakpoint, $includeEscapeWidth) ?? $transformBreakpoint;
                $selectedAttr = $value === $ratioSourceBreakpointDefault ? ' selected' : '';
                // The scoped breakpoint can't be its own copy source; in all-scope
                // every breakpoint is available. Server-rendered for first paint,
                // reactive binding for scope changes.
                $disabledAttr = ($selectedBreakpoint !== null && (int)$transformBreakpoint === (int)$selectedBreakpoint)
                    ? ' disabled'
                    : '';
                $disabledBinding = sprintf(
                    ' data-attr:disabled="$editor.cards.%1$s.scopeMode === \'breakpoint\' && Number($editor.cards.%1$s.scopeBreakpoint || 0) === %2$d ? true : null"',
                    $signalKey,
                    (int)$transformBreakpoint,
                );
                // Label by slot key (base, xs, …) to match the breakpoint column
                // headings; widths are only a fallback for unknown slots.
                $label = $slotKey !== '' ? $slotKey : $displayPx . 'px';
                $ratioSourceBreakpointOptions .= sprintf(
                    '<option value="%s" data-slot-key="%s"%s%s>%s</option>',
                    $this->escapeReviewHtml($value),
                    $this->escapeReviewHtml($slotKey),
                    $selectedAttr . $disabledAttr,
                    $disabledBinding,
                    $this->escapeReviewHtml((string)$label),
                );
            }

            $cardSignalsStructural = [
                'editor' => [
                    'cards' => [
                        $signalKey => [
                            'ratioLocked' => $scopeValues['ratioLocked'],
                            'ratioSourceDimension' => $scopeValues['ratioSourceDimension'],
                            'ratioSourceBreakpoint' => $ratioSourceBreakpointDefault,
                            'ratioSourceBreakpointKey' => $ratioSourceBreakpointKeyDefault,
                            'activeTab' => $tab,
                            'scopeMode' => $scope['mode'],
                            'scopeBreakpoint' => $scope['mode'] === 'breakpoint' ? (string)$scope['breakpoint'] : '',
                            'scopeBreakpointKey' => $scope['mode'] === 'breakpoint' ? $scopeBreakpointKey : '',
                            'selectedAssetKey' => $selectedAssetKey,
                            'rowsByBreakpoint' => $rowsByBreakpointSignal,
                            'processedHiddenBreakpoints' => $processedHiddenBreakpoints,
                            'firstBreakpoint' => $firstBreakpoint !== null ? (string)$firstBreakpoint : '',
                            'firstBreakpointKey' => $firstBreakpointKey,
                            'initSeedAppliedAny' => ($cardState['initSeedAppliedAny'] ?? false) === true,
                            'passHeightWhenRenderedLteSaved' => $passHeightWhenRenderedLteSaved,
                            'allowAnyHeight' => $allowAnyHeight,
                            'allowHiddenDuringProcessing' => $allowHiddenDuringProcessing,
                            'notesInput' => $notes,
                            'notesVisible' => false,
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

            $referenceWidthsById = [];
            foreach ($transformBreakpoints as $bp) {
                $referenceWidthsById[(string)$bp] = $this->getReviewSlotMediaWidthById($bp, $includeEscapeWidth) ?? $bp;
            }
            $columnWidths = ReviewLayoutCalculator::calculateBreakpointColumnWidths($transformBreakpoints, $referenceWidthsById);
            // Breakpoints whose preview is suppressed (disabled in the set, or flagged
            // hidden by processing) must not inflate the shared preview height.
            $lockHeightExcludedBreakpoints = [];
            foreach ($transformBreakpoints as $breakpoint) {
                $breakpointEnabled = ($currentRows[$breakpoint]['enabled'] ?? true) === true;
                $breakpointHidden = in_array($breakpoint, $processedHiddenBreakpoints, true);
                if (!$breakpointEnabled || $breakpointHidden) {
                    $lockHeightExcludedBreakpoints[] = $breakpoint;
                }
            }
            $previewLockHeightsByBreakpoint = ReviewLayoutCalculator::calculateBreakpointPreviewLockHeights(
                $assetCollection['rowsByAssetByBreakpoint'],
                $transformBreakpoints,
                $columnWidths,
                $referenceWidthsById,
                $lockHeightExcludedBreakpoints,
            );
            $hideRenderedApplyForCard = $hideRenderedApply || $setReviewState === 'missing';
            foreach ($transformBreakpoints as $breakpoint) {
                $breakpointKey = (string)$breakpoint;
                $breakpointUiState = $this->buildBreakpointUiState(
                    $transformName,
                    $breakpoint,
                    $selectedAssetRowsByBreakpoint[$breakpoint] ?? [],
                    $currentRows[$breakpoint] ?? Support::buildDefaultTransformEntry(),
                    $passHeightWhenRenderedLteSaved,
                    $storedSavedWidthsByTransform[$transformName][$breakpoint] ?? null,
                    $storedSavedHeightsByTransform[$transformName][$breakpoint] ?? null,
                    $allowAnyHeight,
                    $allowHiddenMismatchNeutral,
                    $hideRenderedApplyForCard,
                    $reviewMode,
                    $referenceWidthsById[(string)$breakpoint] ?? null,
                    $processSavedDimensionsByTransform[$transformName][$breakpoint] ?? null,
                );

                $rowsByBreakpointSignal[$breakpointKey] = array_merge(
                    $rowsByBreakpointSignal[$breakpointKey] ?? [],
                    $breakpointUiState,
                );
            }
            $cardSignalsStructural['editor']['cards'][$signalKey]['rowsByBreakpoint'] = $rowsByBreakpointSignal;

            $breakpointColumns = '';
            $breakpointKeysByWidth = $this->getBreakpointKeysByWidth($includeEscapeWidth);
            $lastBreakpoint = end($transformBreakpoints);
            reset($transformBreakpoints);
            $previousMediaWidth = null;
            if (!$hideBreakpointCardsForMissingSavedSet) {
                foreach ($transformBreakpoints as $breakpoint) {
                    $rows = $selectedAssetRowsByBreakpoint[$breakpoint] ?? [];
                    $mediaWidth = $this->getReviewSlotMediaWidthById($breakpoint, $includeEscapeWidth) ?? $breakpoint;
                    $breakpointColumns .= $this->renderReviewBreakpointColumn(
                        $transformName,
                        $breakpoint,
                        $breakpointKeysByWidth[(string)$breakpoint] ?? '',
                        $this->buildBreakpointRangeLabel($mediaWidth, $previousMediaWidth, $breakpoint === $lastBreakpoint),
                        $rows,
                        $currentRows[$breakpoint] ?? Support::buildDefaultTransformEntry(),
                        $columnWidths,
                        $previewLockHeightsByBreakpoint,
                        $signalKey,
                        $selectedBreakpoint,
                        $scope['mode'] === 'all',
                        $includeEscapeWidth && $breakpoint === $lastBreakpoint
                            ? ($this->getReviewSlotMeasureWidthById($breakpoint, true) ?? $mediaWidth)
                            : null,
                        $hideRenderedApplyForCard,
                        $reviewMode,
                        $passHeightWhenRenderedLteSaved,
                        $storedSavedWidthsByTransform[$transformName][$breakpoint] ?? null,
                        $storedSavedHeightsByTransform[$transformName][$breakpoint] ?? null,
                        $allowAnyHeight,
                        $allowHiddenMismatchNeutral,
                        $referenceWidthsById[(string)$breakpoint] ?? null,
                        $processSavedDimensionsByTransform[$transformName][$breakpoint] ?? null,
                    );
                    $previousMediaWidth = $mediaWidth;
                }
            }
            $assetMismatchByKey = ($isProcessedReview && !$hideAssetPagination)
                ? $this->buildReviewAssetMismatchByKey(
                    $assetKeys,
                    $assetCollection['rowsByAssetByBreakpoint'],
                    $transformBreakpoints,
                    $passHeightWhenRenderedLteSaved,
                    $storedSavedHeightsByTransform[$transformName] ?? [],
                    $allowAnyHeight,
                    $allowHiddenMismatchNeutral,
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
            $selectedIncludeStack = $isProcessedReview
                ? $this->resolveIncludeOriginStack($assetCollection['rowsByAssetByBreakpoint'][$selectedAssetKey] ?? [])
                : [];
            $savedRenderedFromStack = IncludeStack::fromValue($storedTransformConfig['renderedFrom'] ?? null);
            $savedAtSignals = $this->buildSavedAtSignals($storedTransformConfig['lastUpdatedAt'] ?? null);
            $includeOriginHtml = $isProcessedReview
                ? $this->buildIncludeOriginMarkup($selectedIncludeStack, $savedRenderedFromStack, $signalKey, $savedAtSignals)
                : $this->buildSavedRenderedFromMarkup($savedRenderedFromStack, $savedAtSignals, $signalKey);

            $slug = Support::slugifyTransformName($transformName);
            $editPanelId = 'bpts-edit-panel-' . $slug;
            $activeDimensions = $tab === 'dimensions';
            $activeRatio = $tab === 'ratio';
            $activeSettings = $tab === 'settings';
            $activeNotes = $tab === 'notes';
            $scopeLabel = $scope['mode'] === 'all'
                ? 'All'
                : ($scope['mode'] === 'breakpoint' && $scopeBreakpointKey !== '' ? $scopeBreakpointKey : 'Select scope');
            $hasAssetMismatchWarning = $isProcessedReview
                && is_array($latestRunSummaryForTransform)
                && (($latestRunSummaryForTransform['hasAssetMismatch'] ?? false) === true);
            $hasBreakpointMismatchWarning = $isProcessedReview
                && is_array($latestRunSummaryForTransform)
                && (($latestRunSummaryForTransform['hasBreakpointMismatch'] ?? false) === true);
            $hasResolvedBreakpointMismatchAwaitingVerification = $isProcessedReview
                && $editedSinceProcess
                && !$hasBreakpointMismatchWarning;

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

            $suppressMismatchBanners = $hasMissingSetWarning || $hasEmptyBreakpointsWarning || $hasAllEnabledBreakpointsHiddenWarning;

            $reactiveWarningsEnabled = $isProcessedReview && $canEditTransforms;

            // The reactive block owns the missing-set warning; drop it from the static
            // list so it is not rendered twice.
            $staticCardWarnings = $reactiveWarningsEnabled
                ? array_values(array_filter(
                    $cardWarnings,
                    static fn($w): bool => !is_array($w) || (string)($w['code'] ?? '') !== 'missing-set-definitions',
                ))
                : $cardWarnings;

            // When the asset mismatch banner shows, it carries the include origins
            // itself, so the separate origins notice is dropped.
            $showAssetMismatchWarning = $hasAssetMismatchWarning && !$suppressMismatchBanners;
            $assetMismatchOrigins = [];
            if ($showAssetMismatchWarning) {
                foreach ($staticCardWarnings as $index => $w) {
                    if (is_array($w) && (string)($w['code'] ?? '') === ReviewWarningsBuilder::MULTIPLE_INCLUDE_ORIGINS_CODE) {
                        $assetMismatchOrigins = is_array($w['origins'] ?? null) ? $w['origins'] : [];
                        unset($staticCardWarnings[$index]);
                    }
                }
                $staticCardWarnings = array_values($staticCardWarnings);
            }

            $staticWarningsMarkup = $this->renderReviewWarningsMarkup(
                $staticCardWarnings,
                false,
                $reviewMode,
                $transformName,
            );

            $missingSetMessage = ReviewWarningsBuilder::MISSING_SET_CAN_EDIT_MESSAGE;
            foreach ($cardWarnings as $w) {
                if (is_array($w)
                    && (string)($w['code'] ?? '') === 'missing-set-definitions'
                    && trim((string)($w['message'] ?? '')) !== '') {
                    $missingSetMessage = (string)$w['message'];
                    break;
                }
            }

            $firstInstanceHidden = $this->isFirstReviewInstanceHidden(
                $assetCollection['rowsByAssetByBreakpoint'],
                $assetKeys,
            );
            // A missing set whose first measured copy is hidden cannot be saved from
            // this run. Explain that instead of "Transform Set Missing".
            $showHiddenFirstInstanceWarning = $reactiveWarningsEnabled
                && $setReviewState === 'missing'
                && $firstInstanceHidden;
            $missingHeading = $showHiddenFirstInstanceWarning
                ? 'Hidden Set — Cannot Save'
                : 'Transform Set Missing';
            $missingDetailHtml = '<p>' . $this->escapeReviewHtml($missingSetMessage) . '</p>';
            if ($showHiddenFirstInstanceWarning) {
                $missingDetailHtml = '<p>Processing saves the first copy of this set on the page, and that copy is hidden.</p>'
                    . '<p>Force it visible, process again, then revert your change.</p>'
                    . '<p>Once reverted, turn on Allow Hidden During Processing so this set is not flagged again.</p>';
            }

            $reactiveWarningsMarkup = $reactiveWarningsEnabled
                ? $this->renderReviewPartial('_partials/review/missing-set-reactive', [
                    'signalKey' => $this->escapeReviewHtml($signalKey),
                    'setReviewState' => $setReviewState,
                    'missingHeading' => $missingHeading,
                    'missingDetailHtml' => $missingDetailHtml,
                    'processAgainMessage' => 'Process again to double check application.',
                    'applyButtonHtml' => '',
                ])
                : '';

            $breakpointMismatchWarningMarkup = ($hasBreakpointMismatchWarning && !$suppressMismatchBanners)
                ? '<div class="bpts-warning-item bpts-warning-item-neutral" data-class:bpts-force-hidden="$editor.cards.'
                    . $this->escapeReviewHtml($signalKey)
                    . '.hasCurrentBreakpointMismatch !== true">'
                    . '<div class="bpts-warning-copy"><h3 class="bpts-warning-heading">' . ($editedSinceProcess ? 'Saved Values Changed' : 'Breakpoint Mismatch') . '</h3></div>'
                    . '<div class="bpts-warning-detail">'
                        . ($editedSinceProcess
                            ? '<p>Saved values have been edited since the last process. Process to review these changes.</p>'
                            : '<p>Rendered values do not match the saved transform for one or more breakpoints.</p>'
                                . ($this->buildRenderedFromMismatchMarkup($savedRenderedFromStack, $selectedIncludeStack)
                                    ?: '<p>The saved values may be out of date and need updating, or the layout or CSS may need fixing.</p>'))
                    . '</div>'
                    . '</div>'
                : '';

            $assetMismatchWarningMarkup = $showAssetMismatchWarning
                ? $this->buildAssetMismatchWarningMarkup($assetMismatchOrigins)
                : '';

            $hiddenSetWarningMarkup = ($hasAllEnabledBreakpointsHiddenWarning && !$showHiddenFirstInstanceWarning)
                ? '<div class="bpts-warning-item bpts-warning-item-danger">'
                    . '<div class="bpts-warning-copy"><h3 class="bpts-warning-heading">Image Set Hidden</h3></div>'
                    . '<div class="bpts-warning-detail"><p>This image set is hidden in the latest processing run.</p><p>Force it visible, process again, then revert your change.</p><p>Once reverted, turn on Allow Hidden During Processing so this set is not flagged again.</p></div>'
                    . '</div>'
                : '';

            $newSetMarkup = !empty($newSetNameSet[$transformName])
                ? '<div class="bpts-warning-item bpts-warning-item-neutral">'
                    . '<div class="bpts-warning-copy"><h3 class="bpts-warning-heading">New Transform Set</h3></div>'
                    . '<div class="bpts-warning-detail"><p>This transform set was created from the latest processing result.</p></div>'
                    . '</div>'
                : '';

            $cardWarningsWithMismatch = $reactiveWarningsMarkup
                . $hiddenSetWarningMarkup
                . $newSetMarkup
                . $staticWarningsMarkup
                . $breakpointMismatchWarningMarkup
                . $assetMismatchWarningMarkup;

            // The card's red border tracks danger-level warnings. Static/mismatch warnings
            // are fixed per render; the missing-set danger toggles reactively via signal,
            // while the neutral "process again" notice and advisory warnings must NOT
            // make the card red.
            $staticWarningPresent = ReviewWarningsBuilder::hasNonAdvisoryWarning($staticCardWarnings)
                || $hiddenSetWarningMarkup !== ''
                || $breakpointMismatchWarningMarkup !== ''
                || $assetMismatchWarningMarkup !== '';
            $cardWarningDangerExpr = "(\$editor.cards.{$signalKey}.hasCardWarningDanger === true)"
                . ($reactiveWarningsEnabled
                    ? " || String(\$editor.cards.{$signalKey}.setReviewState || '') === 'missing'"
                    : '');
            $cardWarningSeedDanger = $staticWarningPresent
                || ($reactiveWarningsEnabled && $setReviewState === 'missing');
            $cardQuestionSeed = !$cardWarningSeedDanger && $hasResolvedBreakpointMismatchAwaitingVerification;
            $cardQuestionExpr = "(\$editor.cards.{$signalKey}.hasResolvedBreakpointMismatchAwaitingVerification === true)";
            $cardSignalsStructural['editor']['cards'][$signalKey]['hasCurrentBreakpointMismatch'] = $hasBreakpointMismatchWarning;
            $cardSignalsStructural['editor']['cards'][$signalKey]['hasResolvedBreakpointMismatchAwaitingVerification'] = $hasResolvedBreakpointMismatchAwaitingVerification;
            $cardSignalsStructural['editor']['cards'][$signalKey]['hasCardWarningDanger'] = $staticWarningPresent;
            $cardSignalsStructural['editor']['cards'][$signalKey]['savedRenderedFrom'] = IncludeStack::leaf($savedRenderedFromStack) ?? '';
            $cardSignalsStructural['editor']['cards'][$signalKey]['savedRenderedFromStack'] = IncludeStack::encode($savedRenderedFromStack);
            $cardSignalsStructural['editor']['cards'][$signalKey]['savedRenderedFromMore'] = max(0, IncludeStack::templateCount($savedRenderedFromStack) - 1);
            foreach ($savedAtSignals as $savedAtKey => $savedAtValue) {
                $cardSignalsStructural['editor']['cards'][$signalKey][$savedAtKey] = $savedAtValue;
            }
            $cardSignalsStructuralJson = json_encode($cardSignalsStructural, JSON_UNESCAPED_SLASHES);
            if (!is_string($cardSignalsStructuralJson)) {
                $cardSignalsStructuralJson = '{"editor":{"cards":{}}}';
            }

            $lastProcessPanelHtml = $this->buildLastProcessPanelMarkup(
                $latestRunSnapshot,
                $latestRunSummaryForTransform,
                $transformName,
                $runEntryData,
            );

            $cards[] = $this->renderReviewPartial('_partials/review/transform-card', [
                'cardId' => $this->escapeReviewHtml('bpts-card-' . $signalKey),
                'transformNameEscaped' => $this->escapeReviewHtml($transformName),
                'signalKey' => $this->escapeReviewHtml($signalKey),
                'cardSignalsStructural' => $this->escapeReviewHtml($cardSignalsStructuralJson),
                'cardWarningStateClass' => $cardWarningSeedDanger
                    ? 'bpts-transform-card-warning'
                    : '',
                'cardStatusSuccessHiddenClass' => ($cardWarningSeedDanger || $cardQuestionSeed) ? 'bpts-force-hidden' : '',
                'cardStatusWarningHiddenClass' => $cardWarningSeedDanger ? '' : 'bpts-force-hidden',
                'cardStatusQuestionHiddenClass' => $cardQuestionSeed ? '' : 'bpts-force-hidden',
                'cardWarningDangerExpr' => $cardWarningDangerExpr,
                'cardQuestionStateExpr' => $cardQuestionExpr,
                'cardWarningsHtml' => $cardWarningsWithMismatch !== ''
                    ? '<div class="bpts-transform-card-warnings">' . $cardWarningsWithMismatch . '</div>'
                    : '',
                'includeEscapeWidth' => $includeEscapeWidth ? '1' : '0',
                'selectedAssetKey' => $this->escapeReviewHtml($selectedAssetKey),
                'renderedApplyHiddenClass' => $hideRenderedApplyForCard ? 'bpts-force-hidden' : '',
                'cardBreakpointsHiddenClass' => $hideBreakpointCardsForMissingSavedSet ? 'bpts-force-hidden' : '',
                // Delete is only meaningful for a saved set; hide it while the set is
                // unsaved ('missing'). Kept in sync reactively via setReviewState.
                'deleteSetHiddenClass' => $setReviewState === 'missing' ? 'bpts-force-hidden' : '',
                'breakpointColumns' => $breakpointColumns,
                'assetPaginationHtml' => $assetPaginationHtml,
                'includeOriginHtml' => $includeOriginHtml,
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
                'notesTabActiveClass' => $activeNotes ? 'active' : '',
                'notesTabSelected' => $activeNotes ? 'true' : 'false',
                'notesTabTabindex' => $activeNotes ? '0' : '-1',
                'dimensionsPanelActiveClass' => $activeDimensions ? 'active' : '',
                'dimensionsPanelHiddenAttr' => $activeDimensions ? '' : 'hidden',
                'ratioPanelActiveClass' => $activeRatio ? 'active' : '',
                'ratioPanelHiddenAttr' => $activeRatio ? '' : 'hidden',
                'settingsPanelActiveClass' => $activeSettings ? 'active' : '',
                'settingsPanelHiddenAttr' => $activeSettings ? '' : 'hidden',
                'notesPanelActiveClass' => $activeNotes ? 'active' : '',
                'notesPanelHiddenAttr' => $activeNotes ? '' : 'hidden',
                'widthInputId' => $this->escapeReviewHtml($editPanelId . '-width'),
                'heightInputId' => $this->escapeReviewHtml($editPanelId . '-height'),
                'ratioWidthInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-width'),
                'ratioHeightInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-height'),
                'ratioFloatInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-float'),
                'ratioSourceName' => $this->escapeReviewHtml($editPanelId . '-ratio-source'),
                'passHeightToggleId' => $this->escapeReviewHtml($editPanelId . '-pass-height-toggle'),
                'allowAnyHeightToggleId' => $this->escapeReviewHtml($editPanelId . '-allow-any-height-toggle'),
                'allowHiddenDuringProcessingToggleId' => $this->escapeReviewHtml($editPanelId . '-allow-hidden-during-processing-toggle'),
                'notesInputId' => $this->escapeReviewHtml($editPanelId . '-notes'),
                'notesInput' => $this->escapeReviewHtml($notes),
                'allowHiddenDuringProcessingIndicatorHiddenClass' => $allowHiddenDuringProcessing ? '' : 'bpts-force-hidden',
                'passHeightIndicatorHiddenClass' => ($passHeightWhenRenderedLteSaved || $allowAnyHeight) ? '' : 'bpts-force-hidden',
                'passHeightIndicatorText' => $allowAnyHeight ? 'All heights allowed' : 'Shorter heights allowed',
                'ratioSourceBreakpointOptions' => $ratioSourceBreakpointOptions,
                'lastProcessPanelHtml' => $lastProcessPanelHtml,
            ]);
        }

        if ($cards === []) {
            return $this->renderReviewPartial('_partials/review/empty-state', []);
        }

        return implode('', $cards);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $currentRow
     * @param array<int|string, float> $breakpointColumnWidths
     * @param array<int|string, int> $previewLockHeightsByBreakpoint
     * @param array{w: int|null, h: int|null}|null $processSavedDimensions
     */
    private function renderReviewBreakpointColumn(
        string $transformName,
        int $breakpoint,
        string $breakpointKey,
        string $breakpointRangeLabel,
        array $rows,
        array $currentRow,
        array $breakpointColumnWidths,
        array $previewLockHeightsByBreakpoint,
        string $signalKey,
        ?int $selectedBreakpoint,
        bool $allSelected,
        ?int $escapeMeasureWidth,
        bool $hideRenderedApply,
        string $reviewMode,
        bool $passHeightWhenRenderedLteSaved = false,
        ?int $savedWidth = null,
        ?int $savedHeight = null,
        bool $allowAnyHeight = false,
        bool $allowHiddenDuringProcessing = false,
        ?int $referenceWidth = null,
        ?array $processSavedDimensions = null,
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
            $allowHiddenDuringProcessing,
            $hideRenderedApply,
            $reviewMode,
            $referenceWidth,
            $processSavedDimensions,
        );

        $summary = $state['summary'];
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
        $displayPx = $referenceWidth ?? ($this->getReviewSlotMediaWidthById($breakpoint, false) ?? $breakpoint);
        $hiddenCount = (int)($summary['hiddenCount'] ?? 0);
        // Hide the preview for breakpoints processing flagged as hidden (and not yet
        // saved/disabled), mirroring how disabled breakpoints suppress their preview.
        $previewMedia = '';
        if ($currentEnabled && $hiddenCount < 1) {
            $previewMedia = $previewSrc !== ''
                ? sprintf(
                    '<img src="%s" alt="%s" class="bpi_breakpoint-result-image" draggable="false" style="--bpts-aspect-ratio:%s;">',
                    $this->escapeReviewHtml($previewSrc),
                    $this->escapeReviewHtml('Preview ' . $transformName . ' ' . $displayPx . 'px'),
                    $this->escapeReviewHtml($aspectRatio),
                )
                : sprintf(
                    '<div class="bpi_breakpoint-result-image" style="--bpts-aspect-ratio:%s;"></div>',
                    $this->escapeReviewHtml($aspectRatio),
                );
        }

        $unloadedCount = (int)($summary['unloadedCount'] ?? 0);
        $hiddenBadgeTitle = !$currentEnabled ? 'Disabled breakpoint' : 'Hidden ' . $hiddenCount;
        $hiddenBadgeHiddenClass = (!$currentEnabled || $hiddenCount > 0) ? '' : 'bpts-force-hidden';
        $hiddenBadge = '<span class="bpi_hidden-notice bpts-icon-badge ' . $hiddenBadgeHiddenClass . '" title="' . $this->escapeReviewHtml($hiddenBadgeTitle) . '" aria-label="' . $this->escapeReviewHtml($hiddenBadgeTitle) . '" data-class:bpts-force-hidden="String(($editor.cards.' . $this->escapeReviewHtml($signalKey) . '.rowsByBreakpoint&&$editor.cards.' . $this->escapeReviewHtml($signalKey) . '.rowsByBreakpoint[\'' . $breakpoint . '\']) ? ($editor.cards.' . $this->escapeReviewHtml($signalKey) . '.rowsByBreakpoint[\'' . $breakpoint . '\'].hiddenBadgeHiddenClass || \'0\') : \'1\') === \'1\'" data-attr:title="($editor.cards.' . $this->escapeReviewHtml($signalKey) . '.rowsByBreakpoint&&$editor.cards.' . $this->escapeReviewHtml($signalKey) . '.rowsByBreakpoint[\'' . $breakpoint . '\']) ? ($editor.cards.' . $this->escapeReviewHtml($signalKey) . '.rowsByBreakpoint[\'' . $breakpoint . '\'].hiddenBadgeTitle || \'Disabled breakpoint\') : \'Disabled breakpoint\'" data-attr:aria-label="($editor.cards.' . $this->escapeReviewHtml($signalKey) . '.rowsByBreakpoint&&$editor.cards.' . $this->escapeReviewHtml($signalKey) . '.rowsByBreakpoint[\'' . $breakpoint . '\']) ? ($editor.cards.' . $this->escapeReviewHtml($signalKey) . '.rowsByBreakpoint[\'' . $breakpoint . '\'].hiddenBadgeTitle || \'Disabled breakpoint\') : \'Disabled breakpoint\'"><span class="icon" data-icon="eye-slash" aria-hidden="true"></span></span>';
        $unloadedBadge = $unloadedCount > 0
            ? '<span class="bpts-row-badge">Unloaded ' . $unloadedCount . '</span>'
            : '';
        $escapeBadge = $escapeMeasureWidth !== null
            ? '<span class="bpi_escaped-notice">ESC <span class="bpi_escaped-notice-width">' . $escapeMeasureWidth . '</span></span>'
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
            'breakpointRangeLabel' => $this->escapeReviewHtml($breakpointRangeLabel),
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
            'breakpointEnableTitle' => $this->escapeReviewHtml(($currentEnabled ? 'Disable' : 'Enable') . ' ' . $displayPx . 'px breakpoint'),
            'breakpointEnableAriaLabel' => $this->escapeReviewHtml(($currentEnabled ? 'Disable' : 'Enable') . ' ' . $displayPx . 'px breakpoint'),
            'breakpointEnableAriaChecked' => $currentEnabled ? 'true' : 'false',
            // Only a genuinely disabled breakpoint blocks "Set to rendered"; a
            // hidden-but-enabled breakpoint stays clickable so it can be disabled.
            'breakpointDisabledAttr' => !$currentEnabled ? 'disabled' : '',
            'breakpointRenderedApplyMatchClass' => $renderedApplyNoop ? 'bpts-rendered-apply-single-noop' : '',
            'breakpointRenderedApplyAriaLabel' => $this->escapeReviewHtml(
                ($renderedApplyNoop ? 'Rendered values already match for ' : 'Apply rendered values for ')
                . $displayPx
                . 'px'
            ),
            'breakpointRenderedApplyTitle' => $this->escapeReviewHtml(
                ($renderedApplyNoop ? 'Rendered values already match for ' : 'Apply rendered values for ')
                . $displayPx
                . 'px'
            ),
            'breakpointRenderedApplyIconName' => $renderedApplyNoop ? 'check' : 'arrow-down',
            'breakpointRenderedApplyHiddenClass' => $hideRenderedApply ? 'bpts-force-hidden' : '',
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

    /**
     * Page 1 is the first asset key. It is hidden when every enabled row for
     * that copy is not visible.
     *
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, string> $assetKeys
     */
    private function isFirstReviewInstanceHidden(array $rowsByAssetByBreakpoint, array $assetKeys): bool
    {
        $firstKey = $assetKeys[0] ?? '';
        if ($firstKey === '' || !isset($rowsByAssetByBreakpoint[$firstKey]) || !is_array($rowsByAssetByBreakpoint[$firstKey])) {
            return false;
        }

        $sawEnabled = false;
        foreach ($rowsByAssetByBreakpoint[$firstKey] as $rows) {
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row) || ($row['enabled'] ?? true) !== true) {
                    continue;
                }

                $sawEnabled = true;
                if (($row['isVisible'] ?? false) === true) {
                    return false;
                }
            }
        }

        return $sawEnabled;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array<int, int>
     */
    private function buildProcessedHiddenBreakpoints(array $rowsByBreakpoint, array $transformBreakpoints): array
    {
        $hiddenBreakpoints = [];
        foreach ($transformBreakpoints as $breakpoint) {
            foreach ($rowsByBreakpoint[$breakpoint] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (($row['enabled'] ?? true) === true && ($row['isVisible'] ?? false) !== true) {
                    $hiddenBreakpoints[] = $breakpoint;
                    break;
                }
            }
        }

        $hiddenBreakpoints = array_values(array_unique($hiddenBreakpoints));
        sort($hiddenBreakpoints, SORT_NUMERIC);

        return $hiddenBreakpoints;
    }

    private function buildBreakpointRangeLabel(int $breakpoint, ?int $previousBreakpoint, bool $isLast): string
    {
        if ($isLast) {
            return $breakpoint . 'px+';
        }

        $start = $previousBreakpoint ?? 0;
        $end = max($breakpoint - 1, 0);

        return $start . ' - ' . $end . 'px';
    }

    /**
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function normalizeReviewRowsByBreakpoint(mixed $rawRowsByBreakpoint): array
    {
        if (!is_array($rawRowsByBreakpoint)) {
            return [];
        }

        $normalized = [];
        foreach ($rawRowsByBreakpoint as $breakpointKey => $rows) {
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $breakpoint = $this->resolveReviewSlotIdFromRow($row, $breakpointKey);
                if ($breakpoint === null) {
                    continue;
                }

                $loaded = ($row['loaded'] ?? false) === true;
                $broken = ($row['broken'] ?? false) === true;
                $unresolved = ($row['unresolved'] ?? false) === true;
                $transformName = (string)($row['transform'] ?? 'unknown');
                $pictureId = trim((string)($row['pictureId'] ?? ''));
                $instance = trim((string)($row['instance'] ?? ''));
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

                $normalized[$breakpoint] ??= [];
                $normalized[$breakpoint][] = [
                    'slotKey' => is_string($row['slotKey'] ?? null) ? (string)$row['slotKey'] : $this->getReviewSlotKeyById($breakpoint),
                    'slotIndex' => $breakpoint - 1,
                    'mediaWidth' => Support::normalizeNullablePositiveInt($row['mediaWidth'] ?? null) ?? $this->getReviewSlotMediaWidthById($breakpoint),
                    'measureWidth' => Support::normalizeNullablePositiveInt($row['measureWidth'] ?? null),
                    'pictureId' => $pictureId,
                    'instance' => $instance,
                    'includeStack' => IncludeStack::normalize($row['includeStack'] ?? []),
                    'assetId' => $assetId,
                    'assetKey' => $this->buildReviewAssetKey($transformName, $assetId, $sourceUsed, $src, $title, $pictureId, $instance),
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
        }

        ksort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveReviewSlotIdFromRow(array $row, int|string $fallbackKey): ?int
    {
        $slotIndex = Support::normalizeNullablePositiveInt($row['slotIndex'] ?? null);
        if ($slotIndex !== null) {
            return $slotIndex + 1;
        }

        $slotKey = trim((string)($row['slotKey'] ?? (is_string($fallbackKey) ? $fallbackKey : '')));
        if ($slotKey !== '') {
            $id = $this->getReviewSlotIdByKey($slotKey);
            if ($id !== null) {
                return $id;
            }
        }

        return Support::normalizeNullablePositiveInt($fallbackKey);
    }

    /**
     * @return array<int, int>
     */
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

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @return array<int, string>
     */
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

    /**
     * @param array<int, string> $transformNames
     * @param array<string, array<int, array<string, mixed>>> $warningsByTransform
     * @param array<string, mixed> $preferredOrderBySet
     * @param array<string, bool> $breakpointMismatchTransformNames
     * @param array<string, bool> $assetMismatchTransformNames
     * @param array<string, bool> $hiddenSetWarningTransformNames
     * @param array<string, true> $newSetTransformNames
     * @return array<int, string>
     */
    private function orderReviewTransformNames(
        array $transformNames,
        array $warningsByTransform,
        array $preferredOrderBySet = [],
        array $breakpointMismatchTransformNames = [],
        array $assetMismatchTransformNames = [],
        array $hiddenSetWarningTransformNames = [],
        array $newSetTransformNames = [],
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
            $hiddenSetWarningTransformNames,
            $warningsByTransform,
            $newSetTransformNames,
        ): int {
            if (!empty($hiddenSetWarningTransformNames[$name])) {
                return -2;
            }
            if (!empty($newSetTransformNames[$name])) {
                return -1;
            }
            if (!empty($breakpointMismatchTransformNames[$name])) {
                return 0;
            }
            if (!empty($assetMismatchTransformNames[$name])) {
                return 1;
            }
            if (ReviewWarningsBuilder::hasNonAdvisoryWarning($warningsByTransform[$name] ?? [])) {
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

    /**
     * @param array<int, int> $breakpoints
     * @return array<int, int>
     */
    private function getReviewBreakpointsForTransformConfig(bool $includeEscapeWidth, array $breakpoints): array
    {
        return array_map(
            static fn(array $slot): int => ((int)$slot['index']) + 1,
            $this->plugin->getBreakpointSlots()->getSlots($includeEscapeWidth),
        );
    }

    /**
     * @return array<int, int>
     */
    private function getReviewConfiguredBreakpoints(): array
    {
        return array_map(
            static fn(array $slot): int => ((int)$slot['index']) + 1,
            $this->plugin->getBreakpointSlots()->getSlots(false),
        );
    }

    /**
     * @return array<int|string, string>
     */
    private function getBreakpointKeysByWidth(bool $includeEscapeWidth): array
    {
        // Labels come from the catalog (the canonical variant-key source:
        // `base` first, no `escape`), not the raw config map — otherwise column
        // labels disagree with the saved variant keys by one slot.
        $keysByWidth = [];
        foreach ($this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($includeEscapeWidth) as $definition) {
            $slotId = isset($definition['index']) && is_numeric($definition['index'])
                ? ((int)$definition['index']) + 1
                : $this->getReviewSlotIdByKey((string)$definition['key']);
            if ($slotId === null) {
                continue;
            }

            $keysByWidth[(string)$slotId] = (string)$definition['key'];
        }

        return $keysByWidth;
    }

    private function getReviewSlotIdByKey(string $slotKey): ?int
    {
        if ($slotKey === '') {
            return null;
        }

        foreach ($this->plugin->getBreakpointSlots()->getSlots(false) as $slot) {
            if ((string)$slot['key'] === $slotKey) {
                return ((int)$slot['index']) + 1;
            }
        }

        return null;
    }

    private function getReviewSlotKeyById(int $slotId): ?string
    {
        if ($slotId <= 0) {
            return null;
        }

        foreach ($this->plugin->getBreakpointSlots()->getSlots(false) as $slot) {
            if (((int)$slot['index']) + 1 === $slotId) {
                return (string)$slot['key'];
            }
        }

        return null;
    }

    private function getReviewSlotMediaWidthById(int $slotId, bool $includeEscapeWidth = false): ?int
    {
        if ($slotId <= 0) {
            return null;
        }

        foreach ($this->plugin->getBreakpointSlots()->getSlots($includeEscapeWidth) as $slot) {
            if (((int)$slot['index']) + 1 === $slotId) {
                return (int)$slot['mediaWidth'];
            }
        }

        return null;
    }

    private function getReviewSlotMeasureWidthById(int $slotId, bool $includeEscapeWidth = false): ?int
    {
        if ($slotId <= 0) {
            return null;
        }

        foreach ($this->plugin->getBreakpointSlots()->getSlots($includeEscapeWidth) as $slot) {
            if (((int)$slot['index']) + 1 === $slotId) {
                return (int)$slot['measureWidth'];
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getReviewStoredTransforms(): array
    {
        return $this->snapshotReader->getStoredTransforms();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLatestRunSnapshotForReview(): ?array
    {
        return $this->snapshotReader->getLatestRunSnapshot();
    }

    /**
     * @param array<string, mixed>|null $latestRunSnapshot
     * @return array<string, bool>
     */
    private function buildEditedTransformsMap(?array $latestRunSnapshot, bool $isProcessedReview): array
    {
        return $this->healthAnalyzer->buildEditedTransformsMap($latestRunSnapshot, $isProcessedReview);
    }

    /**
     * Saved dimensions captured at process time, re-keyed from snapshot slot keys to
     * breakpoint ids. This is the baseline for detecting per-dimension edits since the
     * last processing run (stale rendered values / edited current values).
     *
     * @param array<string, mixed>|null $latestRunSnapshot
     * @return array<string, array<int, array{w: int|null, h: int|null}>>
     */
    private function buildProcessSavedDimensionsByTransformAndBreakpoint(?array $latestRunSnapshot): array
    {
        $byTransform = is_array($latestRunSnapshot) ? ($latestRunSnapshot['savedDimensionsByTransform'] ?? null) : null;
        if (!is_array($byTransform)) {
            return [];
        }

        $result = [];
        foreach ($byTransform as $transformName => $entries) {
            if (!is_string($transformName) || $transformName === '' || !is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $slotKey = trim((string)($entry['slotKey'] ?? ''));
                $breakpoint = $slotKey !== '' ? ($this->getReviewSlotIdByKey($slotKey) ?? 0) : 0;
                if ($breakpoint <= 0) {
                    continue;
                }
                $result[$transformName][$breakpoint] = [
                    'w' => isset($entry['w']) && is_numeric($entry['w']) ? (int)$entry['w'] : null,
                    'h' => isset($entry['h']) && is_numeric($entry['h']) ? (int)$entry['h'] : null,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, array<string, mixed>>
     */
    public function buildLatestRunHealthByTransform(?array $snapshot = null): array
    {
        return $this->healthAnalyzer->buildLatestRunHealthByTransform($snapshot);
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function buildLatestRunSummaryByTransform(?array $snapshot): array
    {
        return $this->healthAnalyzer->buildLatestRunSummaryByTransform($snapshot);
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array{breakpointMismatchCount: int, assetMismatchCount: int, mismatchCount: int}
     */
    private function countLatestRunMismatchesByTransform(?array $snapshot): array
    {
        $breakpointMismatchCount = 0;
        $assetMismatchCount = 0;

        foreach ($this->buildLatestRunSummaryByTransform($snapshot) as $summary) {
            if (!is_array($summary)) {
                continue;
            }

            if (($summary['hasBreakpointMismatch'] ?? false) === true) {
                $breakpointMismatchCount++;
            }
            if (($summary['hasAssetMismatch'] ?? false) === true) {
                $assetMismatchCount++;
            }
        }

        return [
            'breakpointMismatchCount' => $breakpointMismatchCount,
            'assetMismatchCount' => $assetMismatchCount,
            'mismatchCount' => $breakpointMismatchCount + $assetMismatchCount,
        ];
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    private function countLatestRunHiddenSetWarningsByTransform(?array $snapshot): int
    {
        $count = 0;
        foreach ($this->buildLatestRunSummaryByTransform($snapshot) as $summary) {
            if (is_array($summary) && (($summary['hasAllEnabledBreakpointsHidden'] ?? false) === true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @param array<string, mixed>|null $transformSummary
     * @param string $transformHandle
     * @param array<string, mixed>|null $runEntryData
     */
    private function buildLastProcessPanelMarkup(
        ?array $snapshot,
        ?array $transformSummary,
        string $transformHandle,
        ?array $runEntryData,
    ): string {
        if (!is_array($snapshot)) {
            return $this->renderReviewPartial('_partials/review/last-process-panel', [
                'hasSnapshot' => false,
                'statusIconClass' => '',
                'statusLabel' => '',
                'statusIconName' => '',
                'ranAtLabel' => '',
                'runEntry' => null,
                'runSourceUrl' => '',
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

        $runSourceUrl = trim((string)($snapshot['sourceUrl'] ?? ''));

        return $this->renderReviewPartial('_partials/review/last-process-panel', [
            'hasSnapshot' => true,
            'statusIconClass' => $statusIconClass,
            'statusLabel' => $statusLabel,
            'statusIconName' => $statusIconName,
            'ranAtLabel' => $ranAtLabel,
            'runEntry' => is_array($runEntryData) ? $runEntryData : null,
            'runSourceUrl' => $runSourceUrl,
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
        return $this->snapshotReader->resolveRunEntryData($snapshot);
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

    /**
     * @param array<string, array<string, mixed>> $storedTransforms
     * @return array<string, mixed>|null
     */
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
        string $pictureId = '',
        string $instance = '',
    ): string {
        return ReviewAssetCollector::buildAssetKey($transformName, $assetId, $sourceUsed, $src, $title, $pictureId, $instance);
    }

    /**
     * @param array<int, string> $assetKeys
     * @param array<string, string> $assetLabelsByKey
     * @param array<string, bool> $assetMismatchByKey
     */
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
        bool $allowHiddenDuringProcessing = false,
    ): array {
        return $this->healthAnalyzer->buildAssetMismatchByKey(
            $assetKeys,
            $rowsByAssetByBreakpoint,
            $transformBreakpoints,
            $passHeightWhenRenderedLteSaved,
            $savedHeightsByBreakpoint,
            $allowAnyHeight,
            $allowHiddenDuringProcessing,
        );
    }

    /**
     * @param array<string, mixed>|null $transformDefinition
     */
    private function isPassHeightWhenRenderedLteSavedEnabled(?array $transformDefinition): bool
    {
        return $this->healthAnalyzer->isPassHeightWhenRenderedLteSavedEnabled($transformDefinition);
    }

    /**
     * @param array<string, mixed>|null $transformDefinition
     */
    private function isAllowAnyHeightEnabled(?array $transformDefinition): bool
    {
        return $this->healthAnalyzer->isAllowAnyHeightEnabled($transformDefinition);
    }

    /**
     * @param array<string, mixed>|null $transformDefinition
     */
    private function isAllowHiddenDuringProcessingEnabled(?array $transformDefinition): bool
    {
        return $this->healthAnalyzer->isAllowHiddenDuringProcessingEnabled($transformDefinition);
    }

    private function normalizeSetNotes(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    /**
     * @return array<string, array<int, int|null>>
     */
    private function buildStoredSavedHeightsByTransformAndBreakpoint(): array
    {
        return $this->healthAnalyzer->buildStoredSavedHeightsByTransformAndBreakpoint();
    }

    /**
     * @return array<string, array<int, int|null>>
     */
    private function buildStoredSavedWidthsByTransformAndBreakpoint(): array
    {
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
        return $this->healthAnalyzer->buildSavedDimensionsByTransformAndBreakpoint();
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

    private function normalizeReviewMode(mixed $rawReviewMode): string
    {
        $reviewMode = is_string($rawReviewMode) ? strtolower(trim($rawReviewMode)) : '';
        return in_array($reviewMode, [self::REVIEW_MODE_PROCESSED, self::REVIEW_MODE_SAVED], true)
            ? $reviewMode
            : self::REVIEW_MODE_PROCESSED;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildReviewWarningsByTransform(array $rowsByBreakpoint): array
    {
        return $this->warningsBuilder->buildWarningsByTransform(
            $rowsByBreakpoint,
            $this->getReviewStoredTransforms(),
        );
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $warningsByTransform
     */
    private function countReviewWarningsByTransform(array $warningsByTransform): int
    {
        return ReviewWarningsBuilder::countWarningsByTransform($warningsByTransform);
    }

    /**
     * @param array<string, mixed>|null $transformConfig
     * @param array<int, int> $transformBreakpoints
     * @return array<int, array<string, mixed>>
     */
    private function buildReviewCurrentRowsForTransform(?array $transformConfig, array $transformBreakpoints): array
    {
        $rows = [];
        $entries = isset($transformConfig['transforms']) && is_array($transformConfig['transforms'])
            ? array_values($transformConfig['transforms'])
            : [];
        $includeEscapeWidth = $this->plugin->getBreakpointPolicy()->resolveIncludeEscapeWidth([], $transformConfig);
        $entryIndexByBreakpoint = [];
        foreach ($this->getBreakpointsForTransform($includeEscapeWidth) as $index => $breakpoint) {
            if (is_int($breakpoint)) {
                $entryIndexByBreakpoint[(string)$breakpoint] = $index;
            }
        }

        foreach ($transformBreakpoints as $index => $breakpoint) {
            $entryIndex = $entryIndexByBreakpoint[(string)$breakpoint] ?? $index;
            $entry = isset($entries[$entryIndex]) && is_array($entries[$entryIndex])
                ? $entries[$entryIndex]
                : [];

            $rows[$breakpoint] = Support::normalizeTransformEntry($entry);
        }

        return $rows;
    }

    /**
     * Table under the copy pager: when the set was last saved, the template its
     * saved values came from, and the template that rendered the selected copy.
     * Both templates are green when they match and red when they differ. Each
     * template has a "+N templates" button that opens its full include stack.
     *
     * @param array<int, array<string, mixed>> $stack Include levels (root → leaf) of the selected copy.
     * @param array<int, array<string, mixed>> $savedStack Saved `renderedFrom` stack.
     * @param array{savedAt: string, savedAtLabel: string, savedAtTitle: string}|null $savedAtSignals
     */
    private function buildIncludeOriginMarkup(array $stack, array $savedStack = [], string $signalKey = '', ?array $savedAtSignals = null): string
    {
        $rowsHtml = $this->buildSavedAtRowMarkup($savedAtSignals, $signalKey);
        if ($stack !== []) {
            $rowsHtml .= $this->buildSavedRenderedFromNoteMarkup($savedStack, $stack, $signalKey)
                . $this->buildIncludeOriginRowMarkup($stack, 'rendered', 'Rendered from');
        }

        return $this->buildIncludeOriginTableMarkup($rowsHtml);
    }

    /**
     * When the set was last saved and the template its saved values were
     * applied from, for the saved view.
     *
     * @param array<int, array<string, mixed>> $savedStack Saved `renderedFrom` stack.
     * @param array{savedAt: string, savedAtLabel: string, savedAtTitle: string}|null $savedAtSignals
     */
    private function buildSavedRenderedFromMarkup(array $savedStack, ?array $savedAtSignals = null, string $signalKey = ''): string
    {
        $rowsHtml = $this->buildSavedAtRowMarkup($savedAtSignals, $signalKey);
        if ($savedStack !== []) {
            $rowsHtml .= $this->buildIncludeOriginRowMarkup($savedStack, 'saved', 'Saved from');
        }

        return $this->buildIncludeOriginTableMarkup($rowsHtml);
    }

    private function buildIncludeOriginTableMarkup(string $rowsHtml): string
    {
        if ($rowsHtml === '') {
            return '';
        }

        return '<div class="bpts-transform-include-origin">'
            . '<table class="data bpts-transform-include-origin-table"><tbody>' . $rowsHtml . '</tbody></table>'
            . '</div>';
    }

    /**
     * "Last saved" row, bound to the card's `savedAt*` signals so it updates after
     * an apply or edit. Omitted when the set has no `lastUpdatedAt`.
     *
     * @param array{savedAt: string, savedAtLabel: string, savedAtTitle: string}|null $savedAtSignals
     */
    private function buildSavedAtRowMarkup(?array $savedAtSignals, string $signalKey): string
    {
        if ($savedAtSignals === null || $savedAtSignals['savedAtLabel'] === '') {
            return '';
        }

        $bind = fn(string $attribute, string $field): string => $signalKey !== ''
            ? ' ' . $attribute . '="' . $this->escapeReviewHtml("String(\$editor.cards." . $signalKey . '.' . $field . " || '')") . '"'
            : '';

        return '<tr class="bpts-transform-saved-at-row">'
            . '<th scope="row" class="bpts-transform-include-origin-label">Last saved</th>'
            . '<td class="bpts-transform-include-origin-value" colspan="2">'
            . '<time class="bpts-transform-saved-at" datetime="' . $this->escapeReviewHtml($savedAtSignals['savedAt']) . '"'
            . ' title="' . $this->escapeReviewHtml($savedAtSignals['savedAtTitle']) . '"'
            . $bind('data-attr:datetime', 'savedAt')
            . $bind('data-attr:title', 'savedAtTitle')
            . $bind('data-text', 'savedAtLabel') . '>'
            . $this->escapeReviewHtml($savedAtSignals['savedAtLabel'])
            . '</time>'
            . '</td>'
            . '</tr>';
    }

    /**
     * @param array<int, array<string, mixed>> $stack Root → leaf include levels.
     */
    private function buildIncludeOriginRowMarkup(array $stack, string $source, string $label): string
    {
        $leaf = $this->escapeReviewHtml(IncludeStack::leaf($stack) ?? '');
        $templateCount = IncludeStack::templateCount($stack);
        $moreCount = $templateCount - 1;

        return '<tr class="bpts-transform-include-origin-row" data-origin-source="' . $source . '" data-include-stack="' . $this->escapeReviewHtml(IncludeStack::encode($stack)) . '">'
            . '<th scope="row" class="bpts-transform-include-origin-label">' . $label . '</th>'
            . '<td class="bpts-transform-include-origin-value">'
            . '<button type="button" class="bpts-transform-include-origin-copy" data-bpts-action="copyIncludeOrigin" data-template-name="' . $leaf . '"'
            . ' title="Copy template name" aria-label="Copy template name ' . $leaf . '"><code>' . $leaf . '</code></button>'
            . '</td>'
            . '<td class="bpts-transform-include-origin-more">'
            . ($moreCount > 0
                ? '<button type="button" class="bpts-transform-include-origin-stack" data-bpts-action="showIncludeStack"'
                    . ' title="Show include stack" aria-label="Show include stack (' . $templateCount . ' templates)">' . $this->buildIncludeStackButtonContent($templateCount) . '</button>'
                : '')
            . '</td>'
            . '</tr>';
    }

    /**
     * Stack icon and total template count for a "show include stack" button.
     * `$countAttrs` binds the count text when it is signal-driven.
     */
    private function buildIncludeStackButtonContent(int $templateCount, string $countAttrs = ''): string
    {
        return '<svg class="bpts-transform-include-origin-stack-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" focusable="false" aria-hidden="true">'
            . '<path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0 4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0-5.571 3-5.571-3"></path>'
            . '</svg>'
            . '<span class="bpts-transform-include-origin-stack-count"' . $countAttrs . '>' . $templateCount . ($templateCount === 1 ? ' template' : ' templates') . '</span>';
    }

    /**
     * "Saved from" row above the rendered row. Bound to the card's
     * `savedRenderedFrom*` signals so it updates after an apply. Marked
     * `is-match` or `is-different` against the selected copy (the CSS colours
     * both template rows from it), and hidden when none is saved.
     *
     * @param array<int, array<string, mixed>> $savedStack Saved `renderedFrom` stack.
     * @param array<int, array<string, mixed>> $renderedStack Include stack of the selected copy.
     */
    private function buildSavedRenderedFromNoteMarkup(array $savedStack, array $renderedStack, string $signalKey): string
    {
        $savedJson = IncludeStack::encode($savedStack);
        $renderedJson = IncludeStack::encode($renderedStack);
        $hidden = $savedStack === [];
        $matches = !$hidden && $savedJson === $renderedJson;

        if ($signalKey === '' && $hidden) {
            return '';
        }

        $name = $this->escapeReviewHtml(IncludeStack::leaf($savedStack) ?? '');
        $moreCount = max(0, IncludeStack::templateCount($savedStack) - 1);
        $leafExpr = "String(\$editor.cards." . $signalKey . ".savedRenderedFrom || '')";
        $stackExpr = "String(\$editor.cards." . $signalKey . ".savedRenderedFromStack || '')";
        $countExpr = "Number(\$editor.cards." . $signalKey . ".savedRenderedFromMore || 0)";
        $renderedJsonJs = (string)json_encode($renderedJson, JSON_UNESCAPED_SLASHES);
        $matchesExpr = $stackExpr . ' === ' . $renderedJsonJs;
        $bind = fn(string $attribute, string $expression): string => $signalKey !== ''
            ? ' ' . $attribute . '="' . $this->escapeReviewHtml($expression) . '"'
            : '';

        $rowClass = 'bpts-transform-include-origin-row bpts-transform-include-origin-saved'
            . ($hidden ? ' bpts-force-hidden' : '')
            . ($matches ? ' is-match' : '')
            . (!$hidden && !$matches ? ' is-different' : '');

        return '<tr class="' . $rowClass . '"'
            . ' data-origin-source="saved" data-include-stack="' . $this->escapeReviewHtml($savedJson) . '"'
            . $bind('data-class:bpts-force-hidden', $stackExpr . " === ''")
            . $bind('data-class:is-match', $matchesExpr)
            . $bind('data-class:is-different', $stackExpr . " !== '' && !(" . $matchesExpr . ')')
            . $bind('data-attr:data-include-stack', $stackExpr) . '>'
            . '<th scope="row" class="bpts-transform-include-origin-label">Saved from</th>'
            . '<td class="bpts-transform-include-origin-value">'
            . '<button type="button" class="bpts-transform-include-origin-copy" data-bpts-action="copyIncludeOrigin" data-template-name="' . $name . '"'
            . ' title="Copy template name" aria-label="Copy template name ' . $name . ' (saved from)"'
            . $bind('data-attr:data-template-name', $leafExpr)
            . $bind('data-attr:aria-label', "'Copy template name ' + " . $leafExpr . " + ' (saved from)'") . '>'
            . '<code' . $bind('data-text', $leafExpr) . '>' . $name . '</code>'
            . '</button>'
            . '</td>'
            . '<td class="bpts-transform-include-origin-more">'
            . '<button type="button" class="bpts-transform-include-origin-stack' . ($moreCount > 0 ? '' : ' bpts-force-hidden') . '" data-bpts-action="showIncludeStack"'
            . ' title="Show include stack" aria-label="Show include stack (' . ($moreCount + 1) . ' templates)"'
            . $bind('data-class:bpts-force-hidden', $countExpr . ' < 1')
            . $bind('data-attr:aria-label', "'Show include stack (' + (" . $countExpr . " + 1) + ' templates)'") . '>'
            . $this->buildIncludeStackButtonContent($moreCount + 1, $bind('data-text', "(" . $countExpr . " + 1) + ' templates'"))
            . '</button>'
            . '</td>'
            . '</tr>';
    }

    /**
     * Explains a Breakpoint Mismatch when the saved values came from a different
     * include stack than the copy rendered in this run, naming each by the
     * template where the stacks split.
     *
     * @param array<int, array<string, mixed>> $savedStack
     * @param array<int, array<string, mixed>> $renderedStack
     */
    private function buildRenderedFromMismatchMarkup(array $savedStack, array $renderedStack): string
    {
        if ($savedStack === [] || $renderedStack === [] || IncludeStack::encode($savedStack) === IncludeStack::encode($renderedStack)) {
            return '';
        }

        $origins = ReviewWarningsBuilder::resolveDivergentOrigins([$savedStack, $renderedStack]);
        [$savedName, $renderedName] = count($origins) === 2
            ? $origins
            : [IncludeStack::leaf($savedStack), IncludeStack::leaf($renderedStack)];

        return '<p>The saved values came from a different template:</p>'
            . '<ul class="bpts-warning-origins">'
            . '<li>Saved values: ' . $this->escapeReviewHtml((string)$savedName) . '</li>'
            . '<li>This run: ' . $this->escapeReviewHtml((string)$renderedName) . '</li>'
            . '</ul>'
            . '<p>This template may need a separate set, its layout or CSS may need fixing, or the saved values may be out of date and need updating.</p>';
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint Rows of the selected copy.
     * @return array<int, array<string, mixed>>
     */
    private function resolveIncludeOriginStack(array $rowsByBreakpoint): array
    {
        foreach ($rowsByBreakpoint as $rows) {
            foreach ($rows as $row) {
                $stack = IncludeStack::normalize($row['includeStack'] ?? []);
                if ($stack !== []) {
                    return $stack;
                }
            }
        }

        return [];
    }

    /**
     * @param array<int, mixed> $origins Include origins from the multiple-include-origins warning.
     */
    private function buildAssetMismatchWarningMarkup(array $origins): string
    {
        $originItems = '';
        foreach ($origins as $origin) {
            if (is_string($origin) && $origin !== '') {
                $originItems .= '<li>' . $this->escapeReviewHtml($origin) . '</li>';
            }
        }

        $detail = $originItems !== ''
            ? '<p>Images in this set rendered at different sizes.</p>'
                . '<p>They were rendered from these templates:</p>'
                . '<ul class="bpts-warning-origins">' . $originItems . '</ul>'
                . '<p>Give each template its own set, or fix the layout or CSS so they render at the same size.</p>'
            : '<p>Images in this set rendered at different sizes.</p>'
                . '<p>It may be used in layouts that need separate sets, or the layout or CSS may need fixing so they match.</p>';

        return '<div class="bpts-warning-item bpts-warning-item-neutral">'
            . '<div class="bpts-warning-copy"><h3 class="bpts-warning-heading">Asset Mismatch</h3></div>'
            . '<div class="bpts-warning-detail">' . $detail . '</div>'
            . '</div>';
    }

    /**
     * "3 days ago" for the card's "Last saved" row, with the exact time (in the
     * user's timezone) for its tooltip. Empty strings when the set has no
     * `lastUpdatedAt`.
     *
     * @return array{savedAt: string, savedAtLabel: string, savedAtTitle: string}
     */
    private function buildSavedAtSignals(mixed $lastUpdatedAt): array
    {
        $empty = ['savedAt' => '', 'savedAtLabel' => '', 'savedAtTitle' => ''];
        if (!is_string($lastUpdatedAt) || trim($lastUpdatedAt) === '') {
            return $empty;
        }

        try {
            $savedAt = new \DateTimeImmutable($lastUpdatedAt);
        } catch (\Throwable) {
            return $empty;
        }

        return [
            'savedAt' => $savedAt->format(DATE_ATOM),
            'savedAtLabel' => $this->formatTimeAgo($savedAt, time()),
            'savedAtTitle' => Craft::$app->getFormatter()->asDatetime($savedAt->getTimestamp(), 'medium'),
        ];
    }

    private function formatTimeAgo(\DateTimeImmutable $time, int $now): string
    {
        $seconds = max(0, $now - $time->getTimestamp());
        $units = [
            ['year', 365 * 86400],
            ['month', 30 * 86400],
            ['day', 86400],
            ['hour', 3600],
            ['minute', 60],
        ];
        foreach ($units as [$unit, $size]) {
            if ($seconds >= $size) {
                $count = intdiv($seconds, $size);

                return $count . ' ' . $unit . ($count === 1 ? '' : 's') . ' ago';
            }
        }

        return 'just now';
    }

    private function escapeReviewHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ---- Support-backed helper delegators (private) ----

    /**
     * @return array<int, int>
     */
    private function getBreakpointsForTransform(bool $includeEscapeWidth): array
    {
        return array_map(
            static fn(array $slot): int => ((int)$slot['index']) + 1,
            $this->plugin->getBreakpointSlots()->getSlots($includeEscapeWidth),
        );
    }

}
