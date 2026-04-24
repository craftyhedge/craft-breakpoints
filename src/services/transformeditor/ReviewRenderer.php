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

    public function __construct(
        private readonly Plugin $plugin,
        private readonly SnapshotReader $snapshotReader,
        private readonly HealthAnalyzer $healthAnalyzer,
        private readonly ReviewWarningsBuilder $warningsBuilder,
    ) {
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
    ): array {
        $normalizedReviewMode = $this->normalizeReviewMode($reviewMode);
        $rowsByBreakpoint = $this->normalizeReviewRowsByBreakpoint($result['rowsByBreakpoint'] ?? []);
        $breakpoints = $this->normalizeReviewBreakpoints($result['breakpoints'] ?? []);
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
                $normalizedReviewMode,
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

    public function renderInitialStoredReview(
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
    ): array {
        $storedTransforms = $this->getReviewStoredTransforms();
        $previewCacheByTransformAndBreakpoint = $this->getPreviewCacheRowsByTransformAndBreakpoint();
        $syntheticRowsByBreakpoint = [];

        foreach ($storedTransforms as $setName => $transformDefinition) {
            if (!is_string($setName) || $setName === '' || !is_array($transformDefinition)) {
                continue;
            }

            $includeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
            $breakpoints = $this->getBreakpointsForTransform($includeEscapeWidth);
            $entries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
                ? array_values($transformDefinition['transforms'])
                : [];

            foreach ($breakpoints as $index => $breakpoint) {
                if (!is_int($breakpoint) || $breakpoint <= 0) {
                    continue;
                }

                $entry = isset($entries[$index]) && is_array($entries[$index])
                    ? $entries[$index]
                    : [];

                $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
                $width = $this->normalizeNullablePositiveInt($entry['width'] ?? null);
                $height = $this->normalizeNullablePositiveInt($entry['height'] ?? null);

                if ($autoDimension === 'width') {
                    $width = null;
                }

                if ($autoDimension === 'height') {
                    $height = null;
                }

                $placeholderSrc = $this->buildInitialReviewPlaceholderDataUri(
                    $width,
                    $height,
                    $autoDimension,
                );

                $snapshotRow = $previewCacheByTransformAndBreakpoint[$setName . '|' . $breakpoint] ?? null;
                $savedDisplayAssetUrl = is_array($snapshotRow)
                    ? trim((string)($snapshotRow['displayAssetUrl'] ?? ''))
                    : '';
                $snapshotRenderedWidth = is_array($snapshotRow) && isset($snapshotRow['renderedWidth'])
                    ? max(0, (int)$snapshotRow['renderedWidth'])
                    : 0;
                $snapshotRenderedHeight = is_array($snapshotRow) && isset($snapshotRow['renderedHeight'])
                    ? max(0, (int)$snapshotRow['renderedHeight'])
                    : 0;
                $previewSrc = $savedDisplayAssetUrl !== '' ? $savedDisplayAssetUrl : $placeholderSrc;
                $rowStatus = is_array($snapshotRow)
                    ? trim((string)($snapshotRow['rowStatus'] ?? 'unprocessed'))
                    : 'unprocessed';
                $enabled = $rowStatus !== 'disabled';
                $loaded = $rowStatus === 'loaded' || $rowStatus === 'disabled';
                $broken = $rowStatus === 'broken';
                $unresolved = $rowStatus === 'unresolved';

                $syntheticRowsByBreakpoint[$breakpoint][] = [
                    'transform' => $setName,
                    'assetId' => '',
                    'title' => $setName . ' ' . $breakpoint . 'px placeholder',
                    'enabled' => $enabled,
                    'isVisible' => true,
                    'loaded' => $loaded,
                    'broken' => $broken,
                    'unresolved' => $unresolved,
                    'sourceUsed' => $previewSrc,
                    'src' => $previewSrc,
                    'rendered' => [
                        'width' => $snapshotRenderedWidth,
                        'height' => $snapshotRenderedHeight,
                    ],
                    'intrinsic' => [
                        'width' => $snapshotRenderedWidth,
                        'height' => $snapshotRenderedHeight,
                    ],
                    'transformDimensions' => [
                        'width' => $width,
                        'height' => $height,
                        'autoDimension' => $autoDimension,
                    ],
                ];
            }
        }

        if ($this->plugin !== null) {
            $configuredNames = array_values(array_filter(
                array_keys($storedTransforms),
                static fn($name): bool => is_string($name) && $name !== '',
            ));
            $observedUnsaved = $this->plugin->getTelemetry()->getObservedUnsavedHandles($configuredNames);
            $observedBreakpoints = $this->getBreakpointsForTransform(false);
            foreach ($observedUnsaved as $observedEntry) {
                $handle = (string)$observedEntry['handle'];
                if ($handle === '') {
                    continue;
                }

                $placeholderSrc = $this->buildInitialReviewPlaceholderDataUri(null, null, null);
                foreach ($observedBreakpoints as $breakpoint) {
                    if (!is_int($breakpoint) || $breakpoint <= 0) {
                        continue;
                    }

                    $syntheticRowsByBreakpoint[$breakpoint][] = [
                        'transform' => $handle,
                        'assetId' => '',
                        'title' => $handle . ' ' . $breakpoint . 'px placeholder',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => false,
                        'broken' => false,
                        'unresolved' => false,
                        'sourceUsed' => $placeholderSrc,
                        'src' => $placeholderSrc,
                        'rendered' => ['width' => 0, 'height' => 0],
                        'intrinsic' => ['width' => 0, 'height' => 0],
                        'transformDimensions' => [
                            'width' => null,
                            'height' => null,
                            'autoDimension' => null,
                        ],
                    ];
                }
            }
        }

        return $this->renderResultReview(
            ['rowsByBreakpoint' => $syntheticRowsByBreakpoint],
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
            hideRenderedApply: true,
            hideAssetPagination: true,
            reviewMode: self::REVIEW_MODE_SAVED,
        );
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
            $cardWarningsMarkup = $this->renderReviewWarningsMarkup($cardWarnings, false, $reviewMode);
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

            $currentRows = $this->buildReviewCurrentRowsForTransform(
                $storedTransformConfig,
                $transformBreakpoints,
            );

            $assetCollection = $this->buildReviewAssetCollectionForTransform(
                $rowsByBreakpoint,
                $transformName,
                $transformBreakpoints,
            );
            $assetKeys = $assetCollection['assetKeys'];
            $selectedAssetKey = $this->normalizeReviewSelectedAssetKey(
                $selectedAssetKeyBySet[$transformName] ?? null,
                $assetKeys,
            );
            $normalizedSelectedAssetKeyBySet[$transformName] = $selectedAssetKey;
            $selectedAssetRowsByBreakpoint = $this->buildReviewSelectedAssetRowsByBreakpoint(
                $assetCollection['rowsByAssetByBreakpoint'],
                $selectedAssetKey,
                $transformBreakpoints,
            );

            $scope = $this->normalizeReviewScope(
                $editScopeBySet[$transformName] ?? null,
                $transformBreakpoints,
            );
            $tab = $this->normalizeReviewTab($editTabBySet[$transformName] ?? null);
            $passHeightWhenRenderedLteSaved = $this->isPassHeightWhenRenderedLteSavedEnabled($storedTransformConfig);
            $allowAnyHeight = $this->isAllowAnyHeightEnabled($storedTransformConfig);

            $selectedBreakpoint = $scope['mode'] === 'breakpoint' ? $scope['breakpoint'] : null;
            $signalKey = $this->getReviewTransformSignalKey($transformName);
            $signalPathBase = 'editor.cards.' . $signalKey;
            $scopeValues = $this->getReviewScopeDimensionInputValues($currentRows, $scope);

            $ratioTabDisabled = $scope['mode'] === 'breakpoint'
                && ($scopeValues['widthAuto'] === '1' || $scopeValues['heightAuto'] === '1');
            if ($ratioTabDisabled && $tab === 'ratio') {
                $tab = 'dimensions';
            }

            $normalizedScopeState[$transformName] = $scope;
            $normalizedTabState[$transformName] = $tab;

            $ratioSourceBreakpointDefault = $selectedBreakpoint !== null
                ? (string)$selectedBreakpoint
                : (string)$transformBreakpoints[0];

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
                            'passHeightWhenRenderedLteSaved' => $passHeightWhenRenderedLteSaved,
                            'allowAnyHeight' => $allowAnyHeight,
                        ],
                    ],
                ],
            ];

            $cardSignalsVolatile = [
                'editor' => [
                    'cards' => [
                        $signalKey => [
                            'widthInput' => $scopeValues['widthInput'],
                            'heightInput' => $scopeValues['heightInput'],
                            'widthAuto' => $scopeValues['widthAuto'],
                            'heightAuto' => $scopeValues['heightAuto'],
                            'ratioWidthInput' => $scopeValues['ratioWidthInput'],
                            'ratioHeightInput' => $scopeValues['ratioHeightInput'],
                            'ratioFloatInput' => $scopeValues['ratioFloatInput'],
                        ],
                    ],
                ],
            ];

            $cardSignalsStructuralJson = json_encode($cardSignalsStructural, JSON_UNESCAPED_SLASHES);
            if (!is_string($cardSignalsStructuralJson)) {
                $cardSignalsStructuralJson = '{"editor":{"cards":{}}}';
            }

            $cardSignalsVolatileJson = json_encode($cardSignalsVolatile, JSON_UNESCAPED_SLASHES);
            if (!is_string($cardSignalsVolatileJson)) {
                $cardSignalsVolatileJson = '{"editor":{"cards":{}}}';
            }

            $columnWidths = $this->calculateReviewBreakpointColumnWidths($transformBreakpoints);
            $previewLockHeightsByBreakpoint = $this->calculateReviewBreakpointPreviewLockHeights(
                $assetCollection['rowsByAssetByBreakpoint'],
                $transformBreakpoints,
                $columnWidths,
            );
            $breakpointColumns = '';
            $renderedRowsForTransform = [];
            foreach ($transformBreakpoints as $breakpoint) {
                $rows = $selectedAssetRowsByBreakpoint[$breakpoint] ?? [];
                $renderedRowsForTransform = array_merge(
                    $renderedRowsForTransform,
                    $this->buildReviewRenderedRowsPayload($rows, $breakpoint),
                );
                $breakpointColumns .= $this->renderReviewBreakpointColumn(
                    $transformName,
                    $breakpoint,
                    $rows,
                    $currentRows[$breakpoint] ?? $this->buildDefaultTransformEntry(),
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

            $slug = $this->slugifyReviewTransformName($transformName);
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
            foreach ($cardWarnings as $w) {
                if (is_array($w) && ($w['code'] ?? '') === 'missing-set-definitions') {
                    $hasMissingSetWarning = true;
                    break;
                }
            }

            $editedSinceProcess = ($editedTransforms[$transformName] ?? false) === true;
            $breakpointMismatchWarningMarkup = ($hasBreakpointMismatchWarning && !$hasMissingSetWarning)
                ? '<div class="bpts-warning-item bpts-warning-item-neutral">'
                    . '<div class="bpts-warning-copy"><h3 class="bpts-warning-heading">' . ($editedSinceProcess ? 'Saved Values Changed' : 'Breakpoint Mismatch') . '</h3></div>'
                    . '<div class="bpts-warning-detail">'
                        . ($editedSinceProcess
                            ? '<p>Saved values have been edited since the last process. Process to review these changes.</p>'
                            : '<p>Rendered values do not match the saved transform for one or more breakpoints.</p><p>If you made a change, it is best to process again and review the frontend is behaving correctly.</p>')
                    . '</div>'
                    . '</div>'
                : '';

            $assetMismatchWarningMarkup = ($hasAssetMismatchWarning && !$hasMissingSetWarning)
                ? '<div class="bpts-warning-item bpts-warning-item-neutral">'
                    . '<div class="bpts-warning-copy"><h3 class="bpts-warning-heading">Asset Mismatch</h3></div>'
                    . '<div class="bpts-warning-detail"><p>One or more assets have mismatched values that need reviewed.</p></div>'
                    . '</div>'
                : '';

            $cardWarningsWithMismatch = $cardWarningsMarkup
                . $breakpointMismatchWarningMarkup
                . $assetMismatchWarningMarkup;

            $lastProcessPanelHtml = $this->buildLastProcessPanelMarkup(
                $latestRunSnapshot,
                $latestRunSummaryForTransform,
                $transformName,
                $runEntryData,
                $observedDataByTransform[$transformName] ?? null,
            );

            $renderedRowsForTransformJson = json_encode($renderedRowsForTransform, JSON_UNESCAPED_SLASHES);
            if (!is_string($renderedRowsForTransformJson)) {
                $renderedRowsForTransformJson = '[]';
            }

            $cards[] = $this->renderReviewPartial('_partials/review/transform-card', [
                'transformNameEscaped' => $this->escapeReviewHtml($transformName),
                'signalKey' => $this->escapeReviewHtml($signalKey),
                'cardSignalsStructural' => $this->escapeReviewHtml($cardSignalsStructuralJson),
                'cardSignalsVolatile' => $this->escapeReviewHtml($cardSignalsVolatileJson),
                'cardWarningStateClass' => $cardWarningsWithMismatch !== ''
                    ? 'bpts-transform-card-warning'
                    : '',
                'cardWarningsHtml' => $cardWarningsWithMismatch !== ''
                    ? '<div class="bpts-transform-card-warnings">' . $cardWarningsWithMismatch . '</div>'
                    : '',
                'includeEscapeWidth' => $includeEscapeWidth ? '1' : '0',
                'renderedRowsForTransformJson' => $this->escapeReviewHtml($renderedRowsForTransformJson),
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
        $summary = $this->summarizeReviewRows($rows);
        $renderedRowsPayload = $this->buildReviewRenderedRowsPayload($rows, $breakpoint);
        $renderedRowsPayloadJson = json_encode($renderedRowsPayload, JSON_UNESCAPED_SLASHES);
        if (!is_string($renderedRowsPayloadJson)) {
            $renderedRowsPayloadJson = '[]';
        }

        $renderedWidth = (int)($summary['renderedWidth'] ?? 0);
        $renderedHeight = (int)($summary['renderedHeight'] ?? 0);
        $previewRow = $this->pickReviewPreviewRow($rows);
        $previewSrc = is_array($previewRow) ? (string)($previewRow['src'] ?? '') : '';
        $currentWidth = $this->normalizeNullablePositiveInt($currentRow['width'] ?? null);
        $currentHeight = $this->normalizeNullablePositiveInt($currentRow['height'] ?? null);
        $autoDimension = $this->normalizeAutoDimension($currentRow['autoDimension'] ?? null);
        $currentRatioWidth = $this->normalizeNullablePositiveInt($currentRow['ratioWidth'] ?? null);
        $currentRatioHeight = $this->normalizeNullablePositiveInt($currentRow['ratioHeight'] ?? null);
        $currentRatioSourceDimension = $this->normalizeRatioSourceDimension($currentRow['ratioSourceDimension'] ?? null) ?? 'width';
        $currentRatioLocked = ($currentRow['ratioLocked'] ?? false) === true
            && $currentRatioWidth !== null
            && $currentRatioHeight !== null;
        $currentRatioFloatValue = $currentRatioLocked
            ? $this->formatRatioFloatInput($currentRatioWidth, $currentRatioHeight)
            : '';
        $ratioIsDrivingDimensions = $currentRatioLocked && $autoDimension === null;
        $currentWidthDerivedClass = $ratioIsDrivingDimensions && $currentRatioSourceDimension === 'height'
            ? 'bpi_current-dimension-derived'
            : '';
        $currentHeightDerivedClass = $ratioIsDrivingDimensions && $currentRatioSourceDimension === 'width'
            ? 'bpi_current-dimension-derived'
            : '';

        $displayWidth = $renderedWidth;
        $displayHeight = $renderedHeight;
        if (is_array($previewRow)) {
            $previewRenderedWidth = $this->toNonNegativeInt($previewRow['rendered']['width'] ?? 0);
            $previewRenderedHeight = $this->toNonNegativeInt($previewRow['rendered']['height'] ?? 0);
            if ($previewRenderedWidth > 0 && $previewRenderedHeight > 0) {
                $displayWidth = $previewRenderedWidth;
                $displayHeight = $previewRenderedHeight;
            }

            if ($displayWidth < 1 || $displayHeight < 1) {
                $previewTransformDimensions = is_array($previewRow['transformDimensions'] ?? null)
                    ? $previewRow['transformDimensions']
                    : [];
                [$fallbackWidth, $fallbackHeight] = $this->resolveInitialPreviewBoxDimensions(
                    $this->normalizeNullablePositiveInt($previewTransformDimensions['width'] ?? null),
                    $this->normalizeNullablePositiveInt($previewTransformDimensions['height'] ?? null),
                    $this->normalizeAutoDimension($previewTransformDimensions['autoDimension'] ?? null),
                );

                if ($fallbackWidth > 0 && $fallbackHeight > 0) {
                    $displayWidth = $fallbackWidth;
                    $displayHeight = $fallbackHeight;
                }
            }
        }

        if ($displayWidth < 1 || $displayHeight < 1) {
            if ($previewSrc !== '' && $breakpoint > 0) {
                // Keep unknown preview dimensions bounded to breakpoint box to avoid oversizing.
                $displayWidth = $breakpoint;
                $displayHeight = $breakpoint;
            } else {
                [$displayWidth, $displayHeight] = $this->resolveInitialPreviewBoxDimensions(
                    $currentWidth,
                    $currentHeight,
                    $autoDimension,
                );
            }
        }

        $aspectRatio = $displayWidth > 0 && $displayHeight > 0
            ? $displayWidth . ' / ' . $displayHeight
            : '1 / 1';
        $relativeWidth = $breakpoint > 0
            ? max(0.0, min(100.0, ($displayWidth / $breakpoint) * 100))
            : 0.0;

        $widthClass = $this->getReviewRenderedDimensionClass($renderedWidth, $currentWidth, $autoDimension, 'width');
        $heightClass = $this->getReviewRenderedDimensionClass($renderedHeight, $currentHeight, $autoDimension, 'height');
        $renderedApplyNoop = $this->isReviewRenderedApplyNoop(
            $renderedRowsPayload,
            $currentWidth,
            $currentHeight,
            $autoDimension,
        );

        $currentEnabled = ($currentRow['enabled'] ?? true) === true;
        $previewMedia = $currentEnabled
            ? ($previewSrc !== ''
                ? sprintf(
                    '<img src="%s" alt="%s" class="bpi_breakpoint-result-image" draggable="false" style="--bpts-aspect-ratio:%s;">',
                    $this->escapeReviewHtml($previewSrc),
                    $this->escapeReviewHtml('Preview ' . $transformName . ' ' . $breakpoint . 'px'),
                    $this->escapeReviewHtml($aspectRatio),
                )
                : sprintf(
                    '<div class="bpi_breakpoint-result-image" style="--bpts-aspect-ratio:%s;"></div>',
                    $this->escapeReviewHtml($aspectRatio),
                ))
            : '';

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
        $hasBreakpointMismatch = false;
        if ($reviewMode === self::REVIEW_MODE_PROCESSED && $currentEnabled) {
            $columnEvaluation = $this->evaluateBreakpointMatch(
                $renderedWidth,
                $renderedHeight,
                $savedWidth,
                $savedHeight,
                $autoDimension,
                $passHeightWhenRenderedLteSaved,
                $allowAnyHeight,
            );
            $hasBreakpointMismatch = $columnEvaluation['isBreakpointMismatch'];
        }

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
            'currentEnabledValue' => $currentEnabled ? '1' : '0',
            'currentAutoDimension' => $autoDimension ?? '',
            'escapeBadge' => $escapeBadge,
            'hiddenBadge' => $hiddenBadge,
            'unloadedBadge' => $unloadedBadge,
            'breakpointEnableOnClass' => $currentEnabled ? 'on' : '',
            'breakpointEnableTitle' => $this->escapeReviewHtml(($currentEnabled ? 'Disable' : 'Enable') . ' ' . $breakpoint . 'px breakpoint'),
            'breakpointEnableAriaLabel' => $this->escapeReviewHtml(($currentEnabled ? 'Disable' : 'Enable') . ' ' . $breakpoint . 'px breakpoint'),
            'breakpointEnableAriaChecked' => $currentEnabled ? 'true' : 'false',
            'renderedRowsPayloadJson' => $this->escapeReviewHtml($renderedRowsPayloadJson),
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
            $breakpoint = $this->normalizeNullablePositiveInt($breakpointKey);
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
                    'rowKey' => $this->buildReviewRowKey($breakpoint, $transformName, $assetId, $sourceUsed, $src, $title),
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
                        'width' => $this->toNonNegativeInt($row['rendered']['width'] ?? 0),
                        'height' => $this->toNonNegativeInt($row['rendered']['height'] ?? 0),
                    ],
                    'intrinsic' => [
                        'width' => $this->toNonNegativeInt($row['intrinsic']['width'] ?? 0),
                        'height' => $this->toNonNegativeInt($row['intrinsic']['height'] ?? 0),
                    ],
                    'transformDimensions' => [
                        'width' => $this->normalizeNullablePositiveInt($row['transformDimensions']['width'] ?? null),
                        'height' => $this->normalizeNullablePositiveInt($row['transformDimensions']['height'] ?? null),
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
            $breakpoint = $this->normalizeNullablePositiveInt($rawBreakpoint);
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

        return $this->normalizeNullablePositiveInt($breakpoints['escape']);
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
            $normalized = $this->normalizeNullablePositiveInt($value);
            if ($normalized !== null) {
                $values[] = $normalized;
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_NUMERIC);

        return $values;
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
                'craft-breakpoints/cp/' . $template,
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

    private function buildReviewRowKey(
        int $breakpoint,
        string $transformName,
        string $assetId,
        string $sourceUsed,
        string $src,
        string $title,
    ): string {
        return ReviewAssetCollector::buildRowKey($breakpoint, $transformName, $assetId, $sourceUsed, $src, $title);
    }

    private function normalizeReviewSourceSignature(string $sourceUsed, string $src, string $title): string
    {
        return ReviewAssetCollector::normalizeSourceSignature($sourceUsed, $src, $title);
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array{assetKeys: array<int, string>, rowsByAssetByBreakpoint: array<string, array<int, array<int, array<string, mixed>>>>, assetLabelsByKey: array<string, string>}
     */
    private function buildReviewAssetCollectionForTransform(
        array $rowsByBreakpoint,
        string $transformName,
        array $transformBreakpoints,
    ): array {
        return ReviewAssetCollector::buildAssetCollectionForTransform(
            $rowsByBreakpoint,
            $transformName,
            $transformBreakpoints,
        );
    }

    private function normalizeReviewSelectedAssetKey(mixed $rawSelectedAssetKey, array $assetKeys): string
    {
        return ReviewAssetCollector::normalizeSelectedAssetKey($rawSelectedAssetKey, $assetKeys);
    }

    /**
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function buildReviewSelectedAssetRowsByBreakpoint(
        array $rowsByAssetByBreakpoint,
        string $selectedAssetKey,
        array $transformBreakpoints,
    ): array {
        return ReviewAssetCollector::buildSelectedAssetRowsByBreakpoint(
            $rowsByAssetByBreakpoint,
            $selectedAssetKey,
            $transformBreakpoints,
        );
    }

    private function buildReviewAssetLabel(array $row, int $fallbackIndex): string
    {
        return ReviewAssetCollector::buildAssetLabel($row, $fallbackIndex);
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

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{renderedWidth: int, renderedHeight: int, hiddenCount: int, unloadedCount: int}
     */
    private function summarizeReviewRows(array $rows): array
    {
        return ReviewLayoutCalculator::summarizeRows($rows);
    }

    private function buildReviewRenderedRowsPayload(array $rows, int $breakpoint): array
    {
        return ReviewLayoutCalculator::buildRenderedRowsPayload($rows, $breakpoint);
    }

    private function pickReviewPreviewRow(array $rows): ?array
    {
        return ReviewLayoutCalculator::pickPreviewRow($rows);
    }

    private function calculateReviewBreakpointColumnWidths(array $breakpoints): array
    {
        return ReviewLayoutCalculator::calculateBreakpointColumnWidths($breakpoints);
    }

    private function calculateReviewBreakpointPreviewLockHeights(
        array $rowsByAssetByBreakpoint,
        array $transformBreakpoints,
        array $breakpointColumnWidths,
    ): array {
        return ReviewLayoutCalculator::calculateBreakpointPreviewLockHeights(
            $rowsByAssetByBreakpoint,
            $transformBreakpoints,
            $breakpointColumnWidths,
        );
    }

    private function getReviewRenderedDimensionClass(
        int $renderedValue,
        ?int $transformValue,
        ?string $autoDimension,
        string $dimension,
    ): string {
        $status = $this->evaluateDimensionMatch(
            max(0, $renderedValue),
            $transformValue,
            $autoDimension === $dimension,
            true,
        );

        return match ($status) {
            'auto' => 'bpi_dimension-auto',
            'no-transform', 'missing' => 'bpi_dimension-no-transform',
            'match' => 'bpi_dimension-match',
            'mismatch' => 'bpi_dimension-mismatch',
            default => '',
        };
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

    /**
     * @param array<int, array<string, mixed>> $renderedRowsPayload
     */
    private function isReviewRenderedApplyNoop(
        array $renderedRowsPayload,
        ?int $currentWidth,
        ?int $currentHeight,
        ?string $autoDimension,
    ): bool {
        if ($renderedRowsPayload === []) {
            return false;
        }

        $candidateDimensionCount = 0;
        $hasComparedChange = false;

        foreach ($renderedRowsPayload as $renderedRow) {
            if (!is_array($renderedRow)) {
                continue;
            }

            $renderedWidth = $this->normalizeNullablePositiveInt($renderedRow['width'] ?? null);
            if ($renderedWidth !== null) {
                $candidateDimensionCount += 1;
                if ($autoDimension !== 'width' && $currentWidth !== $renderedWidth) {
                    $hasComparedChange = true;
                }
            }

            $renderedHeight = $this->normalizeNullablePositiveInt($renderedRow['height'] ?? null);
            if ($renderedHeight !== null) {
                $candidateDimensionCount += 1;
                if ($autoDimension !== 'height' && $currentHeight !== $renderedHeight) {
                    $hasComparedChange = true;
                }
            }
        }

        if ($candidateDimensionCount < 1) {
            return false;
        }

        return $hasComparedChange === false;
    }

    private function getReviewTransformSignalKey(string $transformName): string
    {
        $base = str_replace('-', '_', $this->slugifyReviewTransformName($transformName));
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
            $breakpoint = $this->normalizeNullablePositiveInt($rawScope['breakpoint'] ?? null);
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

        $breakpoint = $this->normalizeNullablePositiveInt($scope['breakpoint'] ?? null);
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
        $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
        $widthValue = $this->normalizeNullablePositiveInt($entry['width'] ?? null);
        $heightValue = $this->normalizeNullablePositiveInt($entry['height'] ?? null);
        $ratioWidthValue = $this->normalizeNullablePositiveInt($entry['ratioWidth'] ?? null);
        $ratioHeightValue = $this->normalizeNullablePositiveInt($entry['ratioHeight'] ?? null);
        $ratioSourceDimension = $this->normalizeRatioSourceDimension($entry['ratioSourceDimension'] ?? null) ?? 'width';
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
            'ratioFloatInput' => $this->formatRatioFloatInput(
                $this->normalizeNullablePositiveInt($resolvedRatioWidth),
                $this->normalizeNullablePositiveInt($resolvedRatioHeight),
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

    private function buildInitialReviewPlaceholderDataUri(
        ?int $width,
        ?int $height,
        ?string $autoDimension,
    ): string {
        return ReviewLayoutCalculator::buildInitialPlaceholderDataUri($width, $height, $autoDimension);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function resolveInitialPreviewBoxDimensions(?int $width, ?int $height, ?string $autoDimension): array
    {
        return ReviewLayoutCalculator::resolveInitialPreviewBoxDimensions($width, $height, $autoDimension);
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

            $rows[$breakpoint] = $this->normalizeTransformEntry($entry);
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

    private function normalizeNullablePositiveInt(mixed $value): ?int
    {
        return Support::normalizeNullablePositiveInt($value);
    }

    private function normalizeAutoDimension(mixed $value): ?string
    {
        return Support::normalizeAutoDimension($value);
    }

    private function normalizeRatioSourceDimension(mixed $value): ?string
    {
        return Support::normalizeRatioSourceDimension($value);
    }

    private function normalizeTransformEntry(mixed $entry): array
    {
        return Support::normalizeTransformEntry($entry);
    }

    private function normalizeTransformEntriesForBreakpoints(array $breakpoints, array $rawEntries): array
    {
        return Support::normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);
    }

    private function buildDefaultTransformEntry(): array
    {
        return Support::buildDefaultTransformEntry();
    }

    private function toNonNegativeInt(mixed $value): int
    {
        return Support::toNonNegativeInt($value);
    }

    private function truncateUrl(string $url, int $maxLength): string
    {
        return Support::truncateUrl($url, $maxLength);
    }

    private function formatRatioFloatInput(?int $ratioWidth, ?int $ratioHeight): string
    {
        return Support::formatRatioFloatInput($ratioWidth, $ratioHeight);
    }

    private function slugifyReviewTransformName(string $transformName): string
    {
        return Support::slugifyTransformName($transformName);
    }

    private function addGlobalError(array &$validation, string $message): void
    {
        Support::addGlobalError($validation, $message);
    }

    private function addFieldError(array &$validation, string $fieldPath, string $message): void
    {
        Support::addFieldError($validation, $fieldPath, $message);
    }
}
