<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\services\ConfigService;

/**
 * Health analysis + dimension comparison against the latest run snapshot
 * and the current transform store.
 *
 * Reads snapshot and stored-transform data via SnapshotReader, and
 * breakpoint configuration via ConfigService. Methods that both the
 * health pipeline and the review renderer need (e.g.
 * `shouldIgnoreHeightMismatch`, `resolveReviewDimensionComparison`) are
 * exposed publicly so callers can share one canonical implementation.
 */
final class HealthAnalyzer
{
    private const MISMATCH_TOLERANCE_PX = 1;

    public function __construct(
        private readonly SnapshotReader $snapshotReader,
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, array<string, mixed>>
     */
    public function buildLatestRunHealthByTransform(?array $snapshot = null): array
    {
        $resolvedSnapshot = is_array($snapshot) ? $snapshot : $this->snapshotReader->getLatestRunSnapshot();
        if (!is_array($resolvedSnapshot)) {
            return [];
        }

        $storedAutoDimensionsByTransform = $this->buildStoredAutoDimensionsByTransformAndSlot();
        $storedSavedWidthsByTransform = $this->buildStoredSavedWidthsByTransformAndSlot();
        $storedSavedHeightsByTransform = $this->buildStoredSavedHeightsByTransformAndSlot();
        $storedEnabledByTransform = $this->buildStoredEnabledByTransformAndSlot();
        $storedTransforms = $this->snapshotReader->getStoredTransforms();

        $rowsPayload = isset($resolvedSnapshot['rowsPayload']) && is_array($resolvedSnapshot['rowsPayload'])
            ? $resolvedSnapshot['rowsPayload']
            : [];
        if ($rowsPayload === []) {
            return [];
        }

        $payloadByTransform = [];
        foreach ($rowsPayload as $payloadRow) {
            if (!is_array($payloadRow)) {
                continue;
            }

            $transformHandle = trim((string)($payloadRow['transformHandle'] ?? ''));
            $breakpointWidth = isset($payloadRow['breakpointWidth']) && is_numeric($payloadRow['breakpointWidth'])
                ? (int)$payloadRow['breakpointWidth']
                : 0;
            $slotKey = trim((string)($payloadRow['slotKey'] ?? ''));
            $slotIndex = isset($payloadRow['slotIndex']) && is_numeric($payloadRow['slotIndex'])
                ? (int)$payloadRow['slotIndex']
                : -1;
            if ($transformHandle === '' || $slotKey === '' || $slotIndex < 0 || $breakpointWidth <= 0) {
                continue;
            }

            $autoDimension = Support::normalizeAutoDimension($payloadRow['autoDimension'] ?? null)
                ?? ($storedAutoDimensionsByTransform[$transformHandle][$slotKey] ?? null);
            $enabled = ($storedEnabledByTransform[$transformHandle][$slotKey] ?? true) === true;

            $payloadByTransform[$transformHandle][$slotKey][] = [
                'slotKey' => $slotKey,
                'slotIndex' => $slotIndex,
                'breakpointWidth' => $breakpointWidth,
                'assetId' => trim((string)($payloadRow['assetId'] ?? '')),
                'rowStatus' => $this->normalizeLatestRunRowStatus((string)($payloadRow['rowStatus'] ?? '')),
                'renderedWidth' => max(0, (int)($payloadRow['renderedWidth'] ?? 0)),
                'renderedHeight' => max(0, (int)($payloadRow['renderedHeight'] ?? 0)),
                'autoDimension' => $autoDimension,
                'enabled' => $enabled,
            ];
        }

        if ($payloadByTransform === []) {
            return [];
        }

        $healthByTransform = [];

        foreach ($payloadByTransform as $transformHandle => $breakpointEntriesByWidth) {
            $transformDefinition = isset($storedTransforms[$transformHandle]) && is_array($storedTransforms[$transformHandle])
                ? $storedTransforms[$transformHandle]
                : null;
            $passHeightWhenRenderedLteSaved = $this->isPassHeightWhenRenderedLteSavedEnabled($transformDefinition);
            $allowAnyHeight = $this->isAllowAnyHeightEnabled($transformDefinition);
            $breakpointRows = $this->buildLatestRunBreakpointHealthRows(
                $breakpointEntriesByWidth,
                $passHeightWhenRenderedLteSaved,
                $storedSavedWidthsByTransform[$transformHandle] ?? [],
                $storedSavedHeightsByTransform[$transformHandle] ?? [],
                $allowAnyHeight,
            );

            $assetMismatchBreakpoints = [];
            $breakpointMismatchBreakpoints = [];
            foreach ($breakpointRows as $breakpointRow) {
                if (!is_array($breakpointRow)) {
                    continue;
                }

                $breakpointId = isset($breakpointRow['breakpointWidth']) && is_numeric($breakpointRow['breakpointWidth'])
                    ? (int)$breakpointRow['breakpointWidth']
                    : 0;
                if ($breakpointId <= 0) {
                    continue;
                }

                if (($breakpointRow['hasAssetMismatch'] ?? false) === true) {
                    $assetMismatchBreakpoints[] = $breakpointId;
                }
                if (($breakpointRow['hasBreakpointMismatch'] ?? false) === true) {
                    $breakpointMismatchBreakpoints[] = $breakpointId;
                }
            }

            sort($assetMismatchBreakpoints, SORT_NUMERIC);
            sort($breakpointMismatchBreakpoints, SORT_NUMERIC);
            $healthByTransform[$transformHandle] = [
                'hasAssetMismatch' => $assetMismatchBreakpoints !== [],
                'assetMismatchBreakpointCount' => count($assetMismatchBreakpoints),
                'assetMismatchBreakpoints' => $assetMismatchBreakpoints,
                'hasBreakpointMismatch' => $breakpointMismatchBreakpoints !== [],
                'breakpointMismatchBreakpointCount' => count($breakpointMismatchBreakpoints),
                'breakpointMismatchBreakpoints' => $breakpointMismatchBreakpoints,
                'breakpointRows' => $breakpointRows,
            ];
        }

        return $healthByTransform;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, array<string, mixed>>
     */
    public function buildLatestRunSummaryByTransform(?array $snapshot): array
    {
        if (!is_array($snapshot)) {
            return [];
        }

        $rows = isset($snapshot['rows']) && is_array($snapshot['rows'])
            ? $snapshot['rows']
            : [];
        if ($rows === []) {
            return [];
        }

        $summaries = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transformHandle = trim((string)($row['transformHandle'] ?? ''));
            $slotIndex = isset($row['slotIndex']) && is_numeric($row['slotIndex'])
                ? (int)$row['slotIndex']
                : -1;
            $breakpointId = $slotIndex + 1;
            if ($transformHandle === '' || $breakpointId <= 0) {
                continue;
            }

            if (!isset($summaries[$transformHandle])) {
                $summaries[$transformHandle] = $this->defaultSummary();
            }

            $rowStatus = $this->normalizeLatestRunRowStatus((string)($row['rowStatus'] ?? ''));
            $displayAssetUrl = trim((string)($row['displayAssetUrl'] ?? ''));

            $summaries[$transformHandle]['rowsTotal'] += 1;
            if ($displayAssetUrl !== '') {
                $summaries[$transformHandle]['previewCount'] += 1;
            }

            if (!isset($summaries[$transformHandle]['statusCounts'][$rowStatus])) {
                $summaries[$transformHandle]['statusCounts'][$rowStatus] = 0;
            }

            $summaries[$transformHandle]['statusCounts'][$rowStatus] += 1;
            $summaries[$transformHandle]['statusByBreakpoint'][(string)$breakpointId] = $rowStatus;
        }

        $healthByTransform = $this->buildLatestRunHealthByTransform($snapshot);
        foreach ($healthByTransform as $transformHandle => $health) {
            if (!isset($summaries[$transformHandle])) {
                $summaries[$transformHandle] = $this->defaultSummary();
            }

            $summaries[$transformHandle]['hasAssetMismatch'] = ($health['hasAssetMismatch'] ?? false) === true;
            $summaries[$transformHandle]['assetMismatchBreakpointCount'] = isset($health['assetMismatchBreakpointCount'])
                ? max(0, (int)$health['assetMismatchBreakpointCount'])
                : 0;
            $summaries[$transformHandle]['assetMismatchBreakpoints'] = isset($health['assetMismatchBreakpoints']) && is_array($health['assetMismatchBreakpoints'])
                ? array_values($health['assetMismatchBreakpoints'])
                : [];
            $summaries[$transformHandle]['hasBreakpointMismatch'] = ($health['hasBreakpointMismatch'] ?? false) === true;
            $summaries[$transformHandle]['breakpointMismatchBreakpointCount'] = isset($health['breakpointMismatchBreakpointCount'])
                ? max(0, (int)$health['breakpointMismatchBreakpointCount'])
                : 0;
            $summaries[$transformHandle]['breakpointMismatchBreakpoints'] = isset($health['breakpointMismatchBreakpoints']) && is_array($health['breakpointMismatchBreakpoints'])
                ? array_values($health['breakpointMismatchBreakpoints'])
                : [];
        }

        foreach ($summaries as $transformHandle => $summary) {
            $statusByBreakpoint = $summary['statusByBreakpoint'];
            if (is_array($statusByBreakpoint)) {
                ksort($statusByBreakpoint, SORT_NUMERIC);
                $summaries[$transformHandle]['statusByBreakpoint'] = $statusByBreakpoint;
            }
        }

        return $summaries;
    }

    /**
     * @param array<string, mixed>|null $latestRunSnapshot
     * @return array<string, bool>
     */
    public function buildEditedTransformsMap(?array $latestRunSnapshot, bool $isProcessedReview): array
    {
        if (!$isProcessedReview || !is_array($latestRunSnapshot)) {
            return [];
        }

        $snapshotDimensions = $latestRunSnapshot['savedDimensionsByTransform'] ?? null;
        if (!is_array($snapshotDimensions)) {
            $snapshotDimensions = [];
        }

        $currentDimensions = $this->buildSavedDimensionsByTransformAndSlot();

        $edited = [];

        // Sets that were saved at process time but whose dimensions have since changed.
        foreach ($snapshotDimensions as $transformName => $snapshotByBreakpoint) {
            if (!is_string($transformName) || $transformName === '' || !is_array($snapshotByBreakpoint)) {
                continue;
            }

            $currentByBreakpoint = $currentDimensions[$transformName] ?? [];
            if ($this->savedDimensionsDiffer($snapshotByBreakpoint, $currentByBreakpoint)) {
                $edited[$transformName] = true;
            }
        }

        // Sets that did not exist in the process snapshot but have since been saved
        // (e.g. created via "Set to rendered"). These have never been verified against
        // a render, so they count as edited-since-process until re-processed.
        foreach ($currentDimensions as $transformName => $currentByBreakpoint) {
            if (!is_string($transformName) || $transformName === '' || isset($edited[$transformName])) {
                continue;
            }

            if (array_key_exists($transformName, $snapshotDimensions)) {
                continue;
            }

            if ($this->savedDimensionsDiffer($currentByBreakpoint, [])) {
                $edited[$transformName] = true;
            }
        }

        return $edited;
    }

    /**
     * @return array<string, array<int, array{w: int|null, h: int|null}>>
     */
    public function buildSavedDimensionsByTransformAndBreakpoint(): array
    {
        $widths = $this->buildStoredSavedWidthsByTransformAndBreakpoint();
        $heights = $this->buildStoredSavedHeightsByTransformAndBreakpoint();

        $merged = [];
        $transformNames = array_unique(array_merge(array_keys($widths), array_keys($heights)));
        foreach ($transformNames as $transformName) {
            $widthsForTransform = $widths[$transformName] ?? [];
            $heightsForTransform = $heights[$transformName] ?? [];
            $breakpoints = array_unique(array_merge(
                array_keys($widthsForTransform),
                array_keys($heightsForTransform),
            ));
            sort($breakpoints, SORT_NUMERIC);

            foreach ($breakpoints as $breakpoint) {
                $merged[$transformName][(int)$breakpoint] = [
                    'w' => $widthsForTransform[$breakpoint] ?? null,
                    'h' => $heightsForTransform[$breakpoint] ?? null,
                ];
            }
        }

        return $merged;
    }

    /**
     * @return array<string, array<string, array{slotKey: string, slotIndex: int, breakpointWidth: int, measureWidth: int, w: int|null, h: int|null}>>
     */
    public function buildSavedDimensionsByTransformAndSlot(): array
    {
        $widths = $this->buildStoredSavedWidthsByTransformAndSlot();
        $heights = $this->buildStoredSavedHeightsByTransformAndSlot();
        $slotMetadata = $this->buildStoredSlotMetadataByTransform();

        $merged = [];
        $transformNames = array_unique(array_merge(array_keys($widths), array_keys($heights), array_keys($slotMetadata)));
        foreach ($transformNames as $transformName) {
            $slotKeys = array_unique(array_merge(
                array_keys($widths[$transformName] ?? []),
                array_keys($heights[$transformName] ?? []),
                array_keys($slotMetadata[$transformName] ?? []),
            ));
            usort($slotKeys, function (string $a, string $b) use ($slotMetadata, $transformName): int {
                $aIndex = $slotMetadata[$transformName][$a]['slotIndex'] ?? PHP_INT_MAX;
                $bIndex = $slotMetadata[$transformName][$b]['slotIndex'] ?? PHP_INT_MAX;
                return $aIndex <=> $bIndex ?: strcmp($a, $b);
            });

            foreach ($slotKeys as $slotKey) {
                $metadata = $slotMetadata[$transformName][$slotKey] ?? null;
                if (!is_array($metadata)) {
                    continue;
                }

                $merged[$transformName][$slotKey] = [
                    'slotKey' => $slotKey,
                    'slotIndex' => (int)$metadata['slotIndex'],
                    'breakpointWidth' => (int)$metadata['breakpointWidth'],
                    'measureWidth' => (int)$metadata['measureWidth'],
                    'w' => $widths[$transformName][$slotKey] ?? null,
                    'h' => $heights[$transformName][$slotKey] ?? null,
                ];
            }
        }

        return $merged;
    }

    /**
     * @param array<int|string, array{w: int|null, h: int|null}> $a
     * @param array<int|string, array{w: int|null, h: int|null}> $b
     */
    public function savedDimensionsDiffer(array $a, array $b): bool
    {
        $breakpoints = array_unique(array_merge(array_keys($a), array_keys($b)));
        foreach ($breakpoints as $breakpointKey) {
            $aEntry = $a[$breakpointKey] ?? null;
            $bEntry = $b[$breakpointKey] ?? (is_numeric($breakpointKey) ? ($b[(int)$breakpointKey] ?? null) : null);
            $aW = is_array($aEntry) ? ($aEntry['w'] ?? null) : null;
            $aH = is_array($aEntry) ? ($aEntry['h'] ?? null) : null;
            $bW = is_array($bEntry) ? ($bEntry['w'] ?? null) : null;
            $bH = is_array($bEntry) ? ($bEntry['h'] ?? null) : null;
            if ($aW !== $bW || $aH !== $bH) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{widthStatus: string, heightStatus: string, isBreakpointMismatch: bool}
     */
    public function evaluateBreakpointMatch(
        int $renderedWidth,
        int $renderedHeight,
        ?int $savedWidth,
        ?int $savedHeight,
        ?string $autoDimension,
        bool $passHeightWhenRenderedLteSaved,
        bool $allowAnyHeight = false,
    ): array {
        $widthStatus = $this->evaluateDimensionMatch(
            $renderedWidth,
            $savedWidth,
            $autoDimension === 'width',
        );

        $heightStatus = $this->evaluateDimensionMatch(
            $renderedHeight,
            $savedHeight,
            $autoDimension === 'height',
        );

        if ($heightStatus === 'mismatch' && $this->shouldIgnoreHeightMismatch(
            $passHeightWhenRenderedLteSaved,
            $renderedHeight,
            $savedHeight,
            $allowAnyHeight,
        )) {
            $heightStatus = 'match';
        }

        $isMismatch = $widthStatus === 'mismatch'
            || $heightStatus === 'mismatch'
            || $widthStatus === 'missing'
            || $heightStatus === 'missing';

        return [
            'widthStatus' => $widthStatus,
            'heightStatus' => $heightStatus,
            'isBreakpointMismatch' => $isMismatch,
        ];
    }

    public function evaluateDimensionMatch(
        int $renderedValue,
        ?int $savedValue,
        bool $isAuto,
    ): string {
        if ($isAuto) {
            return 'auto';
        }

        if ($savedValue === null || $savedValue <= 0) {
            return 'no-transform';
        }

        if ($renderedValue <= 0) {
            return 'missing';
        }

        return abs($renderedValue - $savedValue) <= self::MISMATCH_TOLERANCE_PX
            ? 'match'
            : 'mismatch';
    }

    public function shouldIgnoreHeightMismatch(
        bool $passHeightWhenRenderedLteSaved,
        int $renderedHeight,
        ?int $savedHeight,
        bool $allowAnyHeight = false,
    ): bool {
        if ($allowAnyHeight && $renderedHeight > 0) {
            return true;
        }

        return $passHeightWhenRenderedLteSaved
            && $renderedHeight > 0
            && $savedHeight !== null
            && $savedHeight > 0
            && $renderedHeight <= $savedHeight;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function hasAssetMismatchForBreakpoint(
        array $rows,
        ?array $referenceRendered,
        bool $passHeightWhenRenderedLteSaved,
        ?int $savedHeight,
        bool $allowAnyHeight = false,
    ): bool {
        $hasLoadedRow = false;

        foreach ($rows as $row) {
            $enabled = ($row['enabled'] ?? false) === true;
            if ($enabled && ($row['loaded'] ?? false) === true) {
                $hasLoadedRow = true;
            }

            if ($enabled && (($row['broken'] ?? false) === true || ($row['unresolved'] ?? false) === true)) {
                return true;
            }
        }

        if (!$hasLoadedRow || $referenceRendered === null) {
            return false;
        }

        $comparison = $this->resolveReviewDimensionComparison($rows);
        $compareWidth = $comparison['compareWidth'];
        $compareHeight = $comparison['compareHeight'];
        if (!$compareWidth && !$compareHeight) {
            return false;
        }

        $summary = $this->summarizeReviewRows($rows);
        $renderedWidth = max(0, (int)($summary['renderedWidth'] ?? 0));
        $renderedHeight = max(0, (int)($summary['renderedHeight'] ?? 0));

        if (($compareWidth && $renderedWidth < 1) || ($compareHeight && $renderedHeight < 1)) {
            return false;
        }

        $widthMismatch = $compareWidth
            && ($referenceRendered['width'] ?? 0) > 0
            && abs($renderedWidth - (int)$referenceRendered['width']) > self::MISMATCH_TOLERANCE_PX;
        $heightMismatch = $compareHeight
            && ($referenceRendered['height'] ?? 0) > 0
            && abs($renderedHeight - (int)$referenceRendered['height']) > self::MISMATCH_TOLERANCE_PX;

        if ($heightMismatch && $this->shouldIgnoreHeightMismatch(
            $passHeightWhenRenderedLteSaved,
            $renderedHeight,
            $savedHeight,
            $allowAnyHeight,
        )) {
            $heightMismatch = false;
        }

        return $widthMismatch || $heightMismatch;
    }

    /**
     * @param array<int, string> $assetKeys
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @param array<int, int|null> $savedHeightsByBreakpoint
     * @return array<string, bool>
     */
    public function buildAssetMismatchByKey(
        array $assetKeys,
        array $rowsByAssetByBreakpoint,
        array $transformBreakpoints,
        bool $passHeightWhenRenderedLteSaved,
        array $savedHeightsByBreakpoint,
        bool $allowAnyHeight = false,
    ): array {
        $referenceByBreakpoint = [];
        foreach ($transformBreakpoints as $breakpoint) {
            foreach ($assetKeys as $firstAssetKey) {
                $rows = $rowsByAssetByBreakpoint[$firstAssetKey][$breakpoint] ?? [];
                if (!is_array($rows) || $rows === []) {
                    break;
                }
                $comparison = $this->resolveReviewDimensionComparison($rows);
                $summary = $this->summarizeReviewRows($rows);
                $refW = max(0, (int)($summary['renderedWidth'] ?? 0));
                $refH = max(0, (int)($summary['renderedHeight'] ?? 0));

                $hasComparableWidth = !$comparison['compareWidth'] || $refW > 0;
                $hasComparableHeight = !$comparison['compareHeight'] || $refH > 0;
                if ($hasComparableWidth && $hasComparableHeight) {
                    $referenceByBreakpoint[$breakpoint] = ['width' => $refW, 'height' => $refH];
                }
                break;
            }
        }

        $assetMismatchByKey = [];

        foreach ($assetKeys as $assetKey) {
            $hasMismatch = false;

            foreach ($transformBreakpoints as $breakpoint) {
                $rows = $rowsByAssetByBreakpoint[$assetKey][$breakpoint] ?? [];
                if (!is_array($rows) || $rows === []) {
                    continue;
                }
                if ($this->hasAssetMismatchForBreakpoint(
                    $rows,
                    $referenceByBreakpoint[$breakpoint] ?? null,
                    $passHeightWhenRenderedLteSaved,
                    $savedHeightsByBreakpoint[$breakpoint] ?? null,
                    $allowAnyHeight,
                )) {
                    $hasMismatch = true;
                    break;
                }
            }

            $assetMismatchByKey[$assetKey] = $hasMismatch;
        }

        return $assetMismatchByKey;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{compareWidth: bool, compareHeight: bool}
     */
    public function resolveReviewDimensionComparison(array $rows): array
    {
        $compareWidth = true;
        $compareHeight = true;

        foreach ($rows as $row) {
            if (!is_array($row) || ($row['enabled'] ?? false) !== true) {
                continue;
            }

            $autoDimension = Support::normalizeAutoDimension($row['transformDimensions']['autoDimension'] ?? null);
            if ($autoDimension === 'width') {
                $compareWidth = false;
            }

            if ($autoDimension === 'height') {
                $compareHeight = false;
            }
        }

        return [
            'compareWidth' => $compareWidth,
            'compareHeight' => $compareHeight,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{renderedWidth: int, renderedHeight: int, hiddenCount: int, unloadedCount: int}
     */
    public function summarizeReviewRows(array $rows): array
    {
        return ReviewLayoutCalculator::summarizeRows($rows);
    }

    /**
     * @return array<string, array<int, string|null>>
     */
    public function buildStoredAutoDimensionsByTransformAndBreakpoint(): array
    {
        return $this->buildStoredTransformMap(
            fn(array $entry) => Support::normalizeAutoDimension($entry['autoDimension'] ?? null),
        );
    }

    /**
     * @return array<string, array<int, int|null>>
     */
    public function buildStoredSavedHeightsByTransformAndBreakpoint(): array
    {
        return $this->buildStoredTransformMap(function (array $entry): ?int {
            $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
            if ($autoDimension === 'height') {
                return null;
            }
            return Support::normalizeNullablePositiveInt($entry['height'] ?? null);
        });
    }

    /**
     * @return array<string, array<int, int|null>>
     */
    public function buildStoredSavedWidthsByTransformAndBreakpoint(): array
    {
        return $this->buildStoredTransformMap(function (array $entry): ?int {
            $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
            if ($autoDimension === 'width') {
                return null;
            }
            return Support::normalizeNullablePositiveInt($entry['width'] ?? null);
        });
    }

    /**
     * @return array<string, array<string, string|null>>
     */
    public function buildStoredAutoDimensionsByTransformAndSlot(): array
    {
        return $this->buildStoredTransformMapBySlot(
            fn(array $entry) => Support::normalizeAutoDimension($entry['autoDimension'] ?? null),
        );
    }

    /**
     * @return array<string, array<string, int|null>>
     */
    public function buildStoredSavedHeightsByTransformAndSlot(): array
    {
        return $this->buildStoredTransformMapBySlot(function (array $entry): ?int {
            $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
            if ($autoDimension === 'height') {
                return null;
            }
            return Support::normalizeNullablePositiveInt($entry['height'] ?? null);
        });
    }

    /**
     * @return array<string, array<string, int|null>>
     */
    public function buildStoredSavedWidthsByTransformAndSlot(): array
    {
        return $this->buildStoredTransformMapBySlot(function (array $entry): ?int {
            $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
            if ($autoDimension === 'width') {
                return null;
            }
            return Support::normalizeNullablePositiveInt($entry['width'] ?? null);
        });
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function buildStoredEnabledByTransformAndSlot(): array
    {
        return $this->buildStoredTransformMapBySlot(
            static fn(array $entry): bool => ($entry['enabled'] ?? true) === true,
        );
    }

    /**
     * @param callable(array<string, mixed>): mixed $extractValue
     * @return array<string, array<int, mixed>>
     */
    private function buildStoredTransformMap(callable $extractValue): array
    {
        $storedTransforms = $this->snapshotReader->getStoredTransforms();
        if ($storedTransforms === []) {
            return [];
        }

        $result = [];

        foreach ($storedTransforms as $transformName => $transformDefinition) {
            if (!is_string($transformName) || $transformName === '' || !is_array($transformDefinition)) {
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

                $result[$transformName][$breakpoint] = $extractValue($entry);
            }
        }

        return $result;
    }

    /**
     * @param callable(array<string, mixed>): mixed $extractValue
     * @return array<string, array<string, mixed>>
     */
    private function buildStoredTransformMapBySlot(callable $extractValue): array
    {
        $storedTransforms = $this->snapshotReader->getStoredTransforms();
        if ($storedTransforms === []) {
            return [];
        }

        $result = [];

        foreach ($storedTransforms as $transformName => $transformDefinition) {
            if (!is_string($transformName) || $transformName === '' || !is_array($transformDefinition)) {
                continue;
            }

            $includeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
            $slots = $this->configService->getBreakpointSlotDefinitions($includeEscapeWidth);
            $entries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
                ? array_values($transformDefinition['transforms'])
                : [];

            foreach ($slots as $slot) {
                $slotKey = trim((string)($slot['key'] ?? ''));
                $slotIndex = isset($slot['index']) && is_numeric($slot['index']) ? (int)$slot['index'] : -1;
                if ($slotKey === '' || $slotIndex < 0) {
                    continue;
                }

                $entry = isset($entries[$slotIndex]) && is_array($entries[$slotIndex])
                    ? $entries[$slotIndex]
                    : [];

                $result[$transformName][$slotKey] = $extractValue($entry);
            }
        }

        return $result;
    }

    /**
     * @return array<string, array<string, array{slotIndex: int, breakpointWidth: int, measureWidth: int}>>
     */
    private function buildStoredSlotMetadataByTransform(): array
    {
        $storedTransforms = $this->snapshotReader->getStoredTransforms();
        if ($storedTransforms === []) {
            return [];
        }

        $result = [];
        foreach ($storedTransforms as $transformName => $transformDefinition) {
            if (!is_string($transformName) || $transformName === '' || !is_array($transformDefinition)) {
                continue;
            }

            $includeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
            foreach ($this->configService->getBreakpointSlotDefinitions($includeEscapeWidth) as $slot) {
                $slotKey = trim((string)($slot['key'] ?? ''));
                $slotIndex = isset($slot['index']) && is_numeric($slot['index']) ? (int)$slot['index'] : -1;
                $mediaWidth = isset($slot['mediaWidth']) && is_numeric($slot['mediaWidth']) ? (int)$slot['mediaWidth'] : 0;
                $measureWidth = isset($slot['measureWidth']) && is_numeric($slot['measureWidth']) ? (int)$slot['measureWidth'] : $mediaWidth;
                if ($slotKey === '' || $slotIndex < 0 || $mediaWidth <= 0 || $measureWidth <= 0) {
                    continue;
                }

                $result[$transformName][$slotKey] = [
                    'slotIndex' => $slotIndex,
                    'breakpointWidth' => $mediaWidth,
                    'measureWidth' => $measureWidth,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|null $transformDefinition
     */
    public function isPassHeightWhenRenderedLteSavedEnabled(?array $transformDefinition): bool
    {
        if (!is_array($transformDefinition)) {
            return false;
        }

        $config = $transformDefinition['config'] ?? null;
        if (!is_array($config)) {
            return false;
        }

        return ($config['passHeightWhenRenderedLteSaved'] ?? null) === true;
    }

    /**
     * @param array<string, mixed>|null $transformDefinition
     */
    public function isAllowAnyHeightEnabled(?array $transformDefinition): bool
    {
        if (!is_array($transformDefinition)) {
            return false;
        }

        $config = $transformDefinition['config'] ?? null;
        if (!is_array($config)) {
            return false;
        }

        return ($config['allowAnyHeight'] ?? null) === true;
    }

    public function normalizeLatestRunRowStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === 'success') {
            return 'loaded';
        }

        if ($normalized === 'failed' || $normalized === 'cancelled') {
            return 'unprocessed';
        }

        return match ($normalized) {
            'loaded', 'broken', 'unresolved', 'disabled', 'unprocessed' => $normalized,
            default => 'unprocessed',
        };
    }

    /**
     * @return array<int, int>
     */
    public function getBreakpointsForTransform(bool $includeEscapeWidth): array
    {
        return $this->configService->getBreakpointWidths($includeEscapeWidth);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $breakpointEntriesByWidth
     * @param array<string, int|null> $savedWidthsByBreakpoint
     * @param array<string, int|null> $savedHeightsByBreakpoint
     * @return array<int, array<string, mixed>>
     */
    private function buildLatestRunBreakpointHealthRows(
        array $breakpointEntriesByWidth,
        bool $passHeightWhenRenderedLteSaved,
        array $savedWidthsByBreakpoint,
        array $savedHeightsByBreakpoint,
        bool $allowAnyHeight = false,
    ): array {
        uasort($breakpointEntriesByWidth, static function (array $a, array $b): int {
            $aFirst = $a[0] ?? [];
            $bFirst = $b[0] ?? [];
            $aIndex = is_array($aFirst) && isset($aFirst['slotIndex']) && is_numeric($aFirst['slotIndex'])
                ? (int)$aFirst['slotIndex']
                : PHP_INT_MAX;
            $bIndex = is_array($bFirst) && isset($bFirst['slotIndex']) && is_numeric($bFirst['slotIndex'])
                ? (int)$bFirst['slotIndex']
                : PHP_INT_MAX;

            return $aIndex <=> $bIndex;
        });
        $rows = [];

        foreach ($breakpointEntriesByWidth as $slotKey => $breakpointEntries) {
            if (!is_array($breakpointEntries) || $breakpointEntries === []) {
                continue;
            }

            $firstEntry = $breakpointEntries[0] ?? [];
            $slotIndex = is_array($firstEntry) && isset($firstEntry['slotIndex']) && is_numeric($firstEntry['slotIndex'])
                ? (int)$firstEntry['slotIndex']
                : -1;
            $breakpointId = $slotIndex + 1;
            if (!is_string($slotKey) || $slotKey === '' || $breakpointId <= 0) {
                continue;
            }

            $savedWidth = $savedWidthsByBreakpoint[$slotKey] ?? null;
            $savedHeight = $savedHeightsByBreakpoint[$slotKey] ?? null;
            $autoDimension = null;
            $enabledEntries = [];
            foreach ($breakpointEntries as $candidateEntry) {
                if (!is_array($candidateEntry)) {
                    continue;
                }
                if (($candidateEntry['enabled'] ?? true) !== true) {
                    continue;
                }
                $enabledEntries[] = $candidateEntry;
                $entryAuto = Support::normalizeAutoDimension($candidateEntry['autoDimension'] ?? null);
                if ($entryAuto !== null) {
                    $autoDimension = $entryAuto;
                }
            }
            $breakpointEnabled = $enabledEntries !== [];

            $comparisonEntries = $breakpointEnabled ? $enabledEntries : [];
            $referenceEntry = $comparisonEntries[0] ?? $breakpointEntries[0];
            foreach ($comparisonEntries as $candidateEntry) {
                if (($candidateEntry['renderedWidth'] ?? 0) > 0 && ($candidateEntry['renderedHeight'] ?? 0) > 0) {
                    $referenceEntry = $candidateEntry;
                    break;
                }
            }

            $expectedAssetWidth = max(0, (int)($referenceEntry['renderedWidth'] ?? 0));
            $expectedAssetHeight = max(0, (int)($referenceEntry['renderedHeight'] ?? 0));
            $comparison = $this->resolveLatestRunDimensionComparison($comparisonEntries);
            $compareWidth = $comparison['compareWidth'];
            $compareHeight = $comparison['compareHeight'];
            $assetMismatchDetails = [];

            $representativeEvaluation = $breakpointEnabled
                ? $this->evaluateBreakpointMatch(
                    $expectedAssetWidth,
                    $expectedAssetHeight,
                    $savedWidth,
                    $savedHeight,
                    $autoDimension,
                    $passHeightWhenRenderedLteSaved,
                    $allowAnyHeight,
                )
                : [
                    'widthStatus' => 'disabled',
                    'heightStatus' => 'disabled',
                    'isBreakpointMismatch' => false,
                ];

            foreach ($breakpointEntries as $entryIndex => $entry) {
                if (($entry['enabled'] ?? true) !== true) {
                    continue;
                }

                $assetLabel = trim((string)($entry['assetId'] ?? ''));
                if ($assetLabel === '') {
                    $assetLabel = 'Asset ' . (string)($entryIndex + 1);
                }

                $status = $this->normalizeLatestRunRowStatus((string)($entry['rowStatus'] ?? ''));
                $renderedWidth = max(0, (int)($entry['renderedWidth'] ?? 0));
                $renderedHeight = max(0, (int)($entry['renderedHeight'] ?? 0));

                if ($status !== 'loaded') {
                    $assetMismatchDetails[] = $assetLabel . ': status ' . $status;
                }

                if ($renderedWidth < 1 || $renderedHeight < 1) {
                    $missingComparedWidth = $compareWidth && $renderedWidth < 1;
                    $missingComparedHeight = $compareHeight && $renderedHeight < 1;
                    if ($missingComparedWidth || $missingComparedHeight) {
                        $assetMismatchDetails[] = $assetLabel . ': size unavailable';
                    }
                    continue;
                }

                $widthMismatch = $compareWidth
                    && $expectedAssetWidth > 0
                    && abs($renderedWidth - $expectedAssetWidth) > self::MISMATCH_TOLERANCE_PX;
                $heightMismatch = $compareHeight
                    && $expectedAssetHeight > 0
                    && abs($renderedHeight - $expectedAssetHeight) > self::MISMATCH_TOLERANCE_PX;

                if ($heightMismatch && $this->shouldIgnoreHeightMismatch(
                    $passHeightWhenRenderedLteSaved,
                    $renderedHeight,
                    $savedHeight,
                    $allowAnyHeight,
                )) {
                    $heightMismatch = false;
                }

                if ($widthMismatch || $heightMismatch) {
                    if ($widthMismatch && $heightMismatch) {
                        $assetMismatchDetails[] = $assetLabel . ': '
                            . $renderedWidth . 'x' . $renderedHeight
                            . ' expected asset ' . $expectedAssetWidth . 'x' . $expectedAssetHeight;
                    } elseif ($widthMismatch) {
                        $assetMismatchDetails[] = $assetLabel . ': '
                            . 'width ' . $renderedWidth
                            . ' expected asset width ' . $expectedAssetWidth;
                    } else {
                        $assetMismatchDetails[] = $assetLabel . ': '
                            . 'height ' . $renderedHeight
                            . ' expected asset height ' . $expectedAssetHeight;
                    }
                }
            }

            $hasAssetMismatch = $assetMismatchDetails !== [];
            $visibleDetails = array_slice($assetMismatchDetails, 0, 6);
            if (count($assetMismatchDetails) > 6) {
                $visibleDetails[] = '+' . (string)(count($assetMismatchDetails) - 6) . ' more';
            }

            $hasBreakpointMismatch = $representativeEvaluation['isBreakpointMismatch'];
            $breakpointMismatchInfo = $this->formatBreakpointMismatchInfo(
                $representativeEvaluation['widthStatus'],
                $representativeEvaluation['heightStatus'],
                $expectedAssetWidth,
                $expectedAssetHeight,
                $savedWidth,
                $savedHeight,
            );

            $rows[] = [
                'slotKey' => $slotKey,
                'slotIndex' => $slotIndex,
                'breakpointWidth' => $breakpointId,
                'hasAssetMismatch' => $hasAssetMismatch,
                'assetMismatchLabel' => $hasAssetMismatch ? 'Mismatch' : 'Matching',
                'assetMismatchInfo' => $hasAssetMismatch ? implode('; ', $visibleDetails) : '-',
                'hasBreakpointMismatch' => $hasBreakpointMismatch,
                'breakpointMismatchLabel' => $this->formatBreakpointMismatchLabel(
                    $representativeEvaluation['widthStatus'],
                    $representativeEvaluation['heightStatus'],
                ),
                'breakpointMismatchInfo' => $breakpointMismatchInfo,
                'widthStatus' => $representativeEvaluation['widthStatus'],
                'heightStatus' => $representativeEvaluation['heightStatus'],
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array{compareWidth: bool, compareHeight: bool}
     */
    private function resolveLatestRunDimensionComparison(array $entries): array
    {
        $compareWidth = true;
        $compareHeight = true;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
            if ($autoDimension === 'width') {
                $compareWidth = false;
            }

            if ($autoDimension === 'height') {
                $compareHeight = false;
            }
        }

        return [
            'compareWidth' => $compareWidth,
            'compareHeight' => $compareHeight,
        ];
    }

    private function formatBreakpointMismatchLabel(string $widthStatus, string $heightStatus): string
    {
        $sides = [$widthStatus, $heightStatus];

        if (in_array('mismatch', $sides, true) || in_array('missing', $sides, true)) {
            return 'Mismatch';
        }

        if (in_array('match', $sides, true)) {
            return 'Matching';
        }

        if ($widthStatus === 'auto' && $heightStatus === 'auto') {
            return 'Auto';
        }

        if ($widthStatus === 'no-transform' && $heightStatus === 'no-transform') {
            return 'No transform';
        }

        return 'Matching';
    }

    private function formatBreakpointMismatchInfo(
        string $widthStatus,
        string $heightStatus,
        int $renderedWidth,
        int $renderedHeight,
        ?int $savedWidth,
        ?int $savedHeight,
    ): string {
        $parts = [];

        if ($widthStatus === 'mismatch' && $savedWidth !== null) {
            $parts[] = 'width ' . $renderedWidth . ' expected ' . $savedWidth;
        } elseif ($widthStatus === 'missing') {
            $parts[] = 'width unavailable';
        }

        if ($heightStatus === 'mismatch' && $savedHeight !== null) {
            $parts[] = 'height ' . $renderedHeight . ' expected ' . $savedHeight;
        } elseif ($heightStatus === 'missing') {
            $parts[] = 'height unavailable';
        }

        return $parts === [] ? '-' : implode('; ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultSummary(): array
    {
        return [
            'rowsTotal' => 0,
            'previewCount' => 0,
            'statusCounts' => [
                'loaded' => 0,
                'broken' => 0,
                'unresolved' => 0,
                'disabled' => 0,
                'unprocessed' => 0,
            ],
            'statusByBreakpoint' => [],
            'hasAssetMismatch' => false,
            'assetMismatchBreakpointCount' => 0,
            'assetMismatchBreakpoints' => [],
            'hasBreakpointMismatch' => false,
            'breakpointMismatchBreakpointCount' => 0,
            'breakpointMismatchBreakpoints' => [],
        ];
    }
}
