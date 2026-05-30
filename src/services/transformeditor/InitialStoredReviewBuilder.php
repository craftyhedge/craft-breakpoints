<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\Plugin;

/**
 * Builds saved-review source rows and telemetry init seeds before rendering.
 */
final class InitialStoredReviewBuilder
{
    /**
     * Per-render cache of telemetry init options keyed by transform handle.
     *
     * @var array<string, array{handle: string, entryId: ?int, sourceUrl: ?string, lastSeenAt: string, initWidth: ?int, initHeight: ?int, initRatio: ?string, initWidthAuto: ?bool, initHeightAuto: ?bool, includeEscapeWidth: ?bool}>|null
     */
    private ?array $telemetryInitByHandleCache = null;

    public function __construct(
        private readonly Plugin $plugin,
        private readonly SnapshotReader $snapshotReader,
    ) {
    }

    public function resetTelemetryInitCache(): void
    {
        $this->telemetryInitByHandleCache = null;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $resultRowsByBreakpoint
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function buildRowsByBreakpoint(array $resultRowsByBreakpoint): array
    {
        $storedTransforms = $this->snapshotReader->getStoredTransforms();
        $previewCacheByTransformAndBreakpoint = $this->snapshotReader->getPreviewCacheRowsByTransformAndBreakpoint();
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

                $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
                $width = Support::normalizeNullablePositiveInt($entry['width'] ?? null);
                $height = Support::normalizeNullablePositiveInt($entry['height'] ?? null);

                if ($autoDimension === 'width') {
                    $width = null;
                }

                if ($autoDimension === 'height') {
                    $height = null;
                }

                $placeholderSrc = ReviewLayoutCalculator::buildInitialPlaceholderDataUri(
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

            $placeholderSrc = ReviewLayoutCalculator::buildInitialPlaceholderDataUri(null, null, null);
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

        $mergedRowsByBreakpoint = $syntheticRowsByBreakpoint;
        foreach ($resultRowsByBreakpoint as $breakpoint => $rows) {
            if (!isset($mergedRowsByBreakpoint[$breakpoint]) || !is_array($mergedRowsByBreakpoint[$breakpoint])) {
                $mergedRowsByBreakpoint[$breakpoint] = $rows;
                continue;
            }

            // Keep source-observed rows first so init seed state can be applied in saved mode.
            $mergedRowsByBreakpoint[$breakpoint] = array_values(array_merge($rows, $mergedRowsByBreakpoint[$breakpoint]));
        }

        return $mergedRowsByBreakpoint;
    }

    /**
     * Build per-breakpoint init seed rows from persisted telemetry init options
     * for the given handle. No DOM relay; canonical source is the telemetry
     * row written on every front-end `bpi_image()` invocation.
     *
     * @param array<int, int> $transformBreakpoints
     * @return array{seedRows: array<int, array<string, mixed>>}
     */
    public function buildInitSeedStateByBreakpoint(
        string $transformName,
        array $transformBreakpoints,
        bool $allowInitSeed,
    ): array {
        if (!$allowInitSeed || trim($transformName) === '') {
            return ['seedRows' => []];
        }

        $row = $this->getTelemetryInitByHandle()[$transformName] ?? null;
        if (!is_array($row)) {
            return ['seedRows' => []];
        }

        $seedWidth = Support::normalizeNullablePositiveInt($row['initWidth'] ?? null);
        $seedHeight = Support::normalizeNullablePositiveInt($row['initHeight'] ?? null);
        $widthAuto = ($row['initWidthAuto'] ?? null) === true;
        $heightAuto = ($row['initHeightAuto'] ?? null) === true && !$widthAuto;
        $seedAutoDimension = $widthAuto ? 'width' : ($heightAuto ? 'height' : null);

        $ratioRaw = $row['initRatio'] ?? null;
        $ratioRawString = is_string($ratioRaw) ? trim($ratioRaw) : null;
        $ratio = is_string($ratioRaw) && is_numeric(trim($ratioRaw))
            ? (float)trim($ratioRaw)
            : (is_numeric($ratioRaw) ? (float)$ratioRaw : null);
        if ($ratio !== null && (!is_finite($ratio) || $ratio <= 0)) {
            $ratio = null;
        }

        // Prefer the original "x:y" pair the user supplied so values aren't reduced.
        $ratioPair = $this->buildRatioPairFromRawString($ratioRawString);
        if ($ratioPair['width'] !== null && $ratioPair['height'] !== null) {
            $ratio = $ratioPair['width'] / $ratioPair['height'];
        } elseif ($ratio !== null) {
            $ratioPair = $this->buildRatioPairFromFloat($ratio);
        }

        if ($seedWidth !== null && $seedHeight !== null) {
            $ratioPair = ['width' => null, 'height' => null];
        }

        $ratioWidth = $ratioPair['width'];
        $ratioHeight = $ratioPair['height'];

        $hasRatioSeed = $ratioWidth !== null && $ratioHeight !== null;
        $hasDimensionSeed = $seedWidth !== null || $seedHeight !== null || $seedAutoDimension !== null;
        if (!$hasRatioSeed && !$hasDimensionSeed) {
            return ['seedRows' => []];
        }

        $seedRow = [
            'width' => $seedWidth,
            'height' => $seedHeight,
            'autoDimension' => $seedAutoDimension,
            'ratioWidth' => $ratioWidth,
            'ratioHeight' => $ratioHeight,
            'ratioSourceDimension' => 'width',
            'ratioLocked' => $hasRatioSeed,
            'initSeedApplied' => true,
        ];

        $seedRows = [];
        foreach ($transformBreakpoints as $breakpoint) {
            if (!is_int($breakpoint) || $breakpoint <= 0) {
                continue;
            }
            $seedRows[$breakpoint] = $seedRow;
        }

        return ['seedRows' => $seedRows];
    }

    /**
     * @param array<int, array<string, mixed>> $currentRowsByBreakpoint
     * @param array<int, array<string, mixed>> $seedRowsByBreakpoint
     * @return array<int, array<string, mixed>>
     */
    public function applyInitSeedRowsToCurrentRows(array $currentRowsByBreakpoint, array $seedRowsByBreakpoint): array
    {
        foreach ($seedRowsByBreakpoint as $breakpoint => $seedRow) {
            if (!is_int($breakpoint) || !isset($currentRowsByBreakpoint[$breakpoint]) || !is_array($seedRow)) {
                continue;
            }

            $currentRowsByBreakpoint[$breakpoint] = array_merge(
                $currentRowsByBreakpoint[$breakpoint],
                [
                    'width' => $seedRow['width'] ?? null,
                    'height' => $seedRow['height'] ?? null,
                    'autoDimension' => $seedRow['autoDimension'] ?? null,
                    'ratioWidth' => $seedRow['ratioWidth'] ?? null,
                    'ratioHeight' => $seedRow['ratioHeight'] ?? null,
                    'ratioSourceDimension' => $seedRow['ratioSourceDimension'] ?? 'width',
                    'ratioLocked' => ($seedRow['ratioLocked'] ?? false) === true,
                    'initSeedApplied' => ($seedRow['initSeedApplied'] ?? false) === true,
                ],
            );
        }

        return $currentRowsByBreakpoint;
    }

    /**
     * @return array<string, array{handle: string, entryId: ?int, sourceUrl: ?string, lastSeenAt: string, initWidth: ?int, initHeight: ?int, initRatio: ?string, initWidthAuto: ?bool, initHeightAuto: ?bool, includeEscapeWidth: ?bool}>
     */
    private function getTelemetryInitByHandle(): array
    {
        if ($this->telemetryInitByHandleCache !== null) {
            return $this->telemetryInitByHandleCache;
        }

        $this->telemetryInitByHandleCache = $this->plugin->getTelemetry()->getMostRecentByHandle();

        return $this->telemetryInitByHandleCache;
    }

    /**
     * Parse a stored "x:y" ratio string into an integer pair without reducing.
     * Returns the user-supplied dimensions verbatim when both are positive whole numbers.
     *
     * @return array{width: ?int, height: ?int}
     */
    private function buildRatioPairFromRawString(?string $raw): array
    {
        if ($raw === null) {
            return ['width' => null, 'height' => null];
        }

        $trimmed = trim($raw);
        if ($trimmed === '' || strpos($trimmed, ':') === false) {
            return ['width' => null, 'height' => null];
        }

        $parts = explode(':', $trimmed, 2);
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return ['width' => null, 'height' => null];
        }

        $left = (float)$parts[0];
        $right = (float)$parts[1];
        if (!is_finite($left) || !is_finite($right) || $left <= 0 || $right <= 0) {
            return ['width' => null, 'height' => null];
        }

        $width = (int)round($left);
        $height = (int)round($right);
        if ($width <= 0 || $height <= 0) {
            return ['width' => null, 'height' => null];
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * @return array{width: ?int, height: ?int}
     */
    private function buildRatioPairFromFloat(?float $ratio): array
    {
        if ($ratio === null || !is_finite($ratio) || $ratio <= 0) {
            return ['width' => null, 'height' => null];
        }

        $maxDenominator = 1000;
        $bestNum = 1;
        $bestDen = 1;
        $bestError = abs($ratio - 1.0);

        for ($den = 1; $den <= $maxDenominator; $den++) {
            $num = max(1, (int)round($ratio * $den));
            $value = $num / $den;
            $error = abs($ratio - $value);
            if ($error < $bestError) {
                $bestError = $error;
                $bestNum = $num;
                $bestDen = $den;
                if ($error === 0.0) {
                    break;
                }
            }
        }

        $gcd = $this->greatestCommonDivisor($bestNum, $bestDen);

        return [
            'width' => (int)max(1, (int)round($bestNum / $gcd)),
            'height' => (int)max(1, (int)round($bestDen / $gcd)),
        ];
    }

    private function greatestCommonDivisor(int $left, int $right): int
    {
        $a = abs($left);
        $b = abs($right);

        while ($b !== 0) {
            $temp = $a % $b;
            $a = $b;
            $b = $temp;
        }

        return $a > 0 ? $a : 1;
    }

    /**
     * @return array<int, int>
     */
    private function getBreakpointsForTransform(bool $includeEscapeWidth): array
    {
        return $this->plugin->getConfigService()->getBreakpointWidths($includeEscapeWidth);
    }
}
