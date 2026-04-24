<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

/**
 * Pure-math helpers for review preview layout: column widths, lock heights,
 * placeholder sizing, row summarization, and preview row selection.
 *
 * All methods are static; the class holds no state and has no dependencies.
 */
final class ReviewLayoutCalculator
{
    private const INITIAL_PLACEHOLDER_FALLBACK_WIDTH = 1200;
    private const INITIAL_PLACEHOLDER_FALLBACK_HEIGHT = 800;
    private const INITIAL_PLACEHOLDER_DEFAULT_RATIO_WIDTH = 3;
    private const INITIAL_PLACEHOLDER_DEFAULT_RATIO_HEIGHT = 2;

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{renderedWidth: int, renderedHeight: int, hiddenCount: int, unloadedCount: int}
     */
    public static function summarizeRows(array $rows): array
    {
        $enabledRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['enabled'] ?? false) === true,
        ));

        $visibleRows = array_values(array_filter(
            $enabledRows,
            static fn(array $row): bool => ($row['isVisible'] ?? false) === true,
        ));

        $preferredRows = $visibleRows !== [] ? $visibleRows : $enabledRows;

        $renderedWidth = 0;
        $renderedHeight = 0;
        foreach ($preferredRows as $row) {
            $renderedWidth = max($renderedWidth, Support::toNonNegativeInt($row['rendered']['width'] ?? 0));
            $renderedHeight = max($renderedHeight, Support::toNonNegativeInt($row['rendered']['height'] ?? 0));
        }

        $hiddenCount = 0;
        foreach ($enabledRows as $row) {
            if (($row['isVisible'] ?? false) !== true) {
                $hiddenCount += 1;
            }
        }

        $unloadedCount = 0;
        foreach ($rows as $row) {
            if (($row['broken'] ?? false) === true || ($row['unresolved'] ?? false) === true) {
                $unloadedCount += 1;
            }
        }

        return [
            'renderedWidth' => $renderedWidth,
            'renderedHeight' => $renderedHeight,
            'hiddenCount' => $hiddenCount,
            'unloadedCount' => $unloadedCount,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    public static function pickPreviewRow(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $filters = [
            static fn(array $row): bool => ($row['loaded'] ?? false) === true
                && ($row['isVisible'] ?? false) === true
                && ($row['enabled'] ?? false) === true
                && (string)($row['src'] ?? '') !== '',
            static fn(array $row): bool => ($row['loaded'] ?? false) === true
                && ($row['enabled'] ?? false) === true
                && (string)($row['src'] ?? '') !== '',
            static fn(array $row): bool => ($row['loaded'] ?? false) === true
                && (string)($row['src'] ?? '') !== '',
            static fn(array $row): bool => (string)($row['src'] ?? '') !== '',
        ];

        foreach ($filters as $filter) {
            foreach ($rows as $row) {
                if ($filter($row)) {
                    return $row;
                }
            }
        }

        return $rows[0] ?? null;
    }

    /**
     * @param array<int, int> $breakpoints
     * @return array<string, float>
     */
    public static function calculateBreakpointColumnWidths(array $breakpoints): array
    {
        if ($breakpoints === []) {
            return [];
        }

        $firstBreakpoint = $breakpoints[0] > 0 ? $breakpoints[0] : 1;
        $widths = [];
        foreach ($breakpoints as $breakpoint) {
            $widths[(string)$breakpoint] = ($breakpoint / $firstBreakpoint) * 160;
        }

        return $widths;
    }

    /**
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @param array<string, float> $breakpointColumnWidths
     * @return array<string, int>
     */
    public static function calculateBreakpointPreviewLockHeights(
        array $rowsByAssetByBreakpoint,
        array $transformBreakpoints,
        array $breakpointColumnWidths,
    ): array {
        $globalLockHeight = 48;

        foreach ($transformBreakpoints as $breakpoint) {
            $columnWidth = (float)($breakpointColumnWidths[(string)$breakpoint] ?? 0.0);
            $availablePreviewWidth = max(1.0, $columnWidth - 20.0);

            foreach ($rowsByAssetByBreakpoint as $rowsByBreakpoint) {
                if (!is_array($rowsByBreakpoint)) {
                    continue;
                }

                $rows = $rowsByBreakpoint[$breakpoint] ?? [];
                if (!is_array($rows)) {
                    continue;
                }

                $summary = self::summarizeRows($rows);
                $displayWidth = max(0, (int)($summary['renderedWidth'] ?? 0));
                $displayHeight = max(0, (int)($summary['renderedHeight'] ?? 0));
                $previewRow = self::pickPreviewRow($rows);

                if (is_array($previewRow)) {
                    $previewRenderedWidth = Support::toNonNegativeInt($previewRow['rendered']['width'] ?? 0);
                    $previewRenderedHeight = Support::toNonNegativeInt($previewRow['rendered']['height'] ?? 0);
                    if ($previewRenderedWidth > 0 && $previewRenderedHeight > 0) {
                        $displayWidth = $previewRenderedWidth;
                        $displayHeight = $previewRenderedHeight;
                    }

                    if ($displayWidth < 1 || $displayHeight < 1) {
                        $previewTransformDimensions = is_array($previewRow['transformDimensions'] ?? null)
                            ? $previewRow['transformDimensions']
                            : [];
                        [$fallbackWidth, $fallbackHeight] = self::resolveInitialPreviewBoxDimensions(
                            Support::normalizeNullablePositiveInt($previewTransformDimensions['width'] ?? null),
                            Support::normalizeNullablePositiveInt($previewTransformDimensions['height'] ?? null),
                            Support::normalizeAutoDimension($previewTransformDimensions['autoDimension'] ?? null),
                        );

                        if ($fallbackWidth > 0 && $fallbackHeight > 0) {
                            $displayWidth = $fallbackWidth;
                            $displayHeight = $fallbackHeight;
                        }
                    }
                }

                if (($displayWidth < 1 || $displayHeight < 1) && is_array($previewRow) && $breakpoint > 0) {
                    $previewSrc = (string)($previewRow['src'] ?? '');
                    if ($previewSrc !== '') {
                        $displayWidth = $breakpoint;
                        $displayHeight = $breakpoint;
                    }
                }

                if ($displayWidth < 1 || $displayHeight < 1 || $breakpoint < 1) {
                    continue;
                }

                $candidateHeight = (int)ceil(($availablePreviewWidth * $displayHeight) / $breakpoint);
                $globalLockHeight = max($globalLockHeight, $candidateHeight);
            }
        }

        $globalLockHeight = max(48, $globalLockHeight);
        $lockHeightsByBreakpoint = [];
        foreach ($transformBreakpoints as $breakpoint) {
            $lockHeightsByBreakpoint[(string)$breakpoint] = $globalLockHeight;
        }

        return $lockHeightsByBreakpoint;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array{breakpoint: int, width: int|null, height: int|null}>
     */
    public static function buildRenderedRowsPayload(array $rows, int $breakpoint): array
    {
        $summary = self::summarizeRows($rows);
        $width = $summary['renderedWidth'] > 0 ? (int)round($summary['renderedWidth']) : null;
        $height = $summary['renderedHeight'] > 0 ? (int)round($summary['renderedHeight']) : null;

        if ($width === null && $height === null) {
            return [];
        }

        return [[
            'breakpoint' => $breakpoint,
            'width' => $width,
            'height' => $height,
        ]];
    }

    /**
     * @return array{0:int,1:int}
     */
    public static function resolveInitialPreviewBoxDimensions(?int $width, ?int $height, ?string $autoDimension): array
    {
        $effectiveWidth = $autoDimension === 'width' ? null : $width;
        $effectiveHeight = $autoDimension === 'height' ? null : $height;

        if ($effectiveWidth !== null && $effectiveHeight !== null) {
            return [$effectiveWidth, $effectiveHeight];
        }

        if ($effectiveWidth !== null) {
            $derivedHeight = (int)round(($effectiveWidth * self::INITIAL_PLACEHOLDER_DEFAULT_RATIO_HEIGHT) / self::INITIAL_PLACEHOLDER_DEFAULT_RATIO_WIDTH);
            return [$effectiveWidth, max(1, $derivedHeight)];
        }

        if ($effectiveHeight !== null) {
            $derivedWidth = (int)round(($effectiveHeight * self::INITIAL_PLACEHOLDER_DEFAULT_RATIO_WIDTH) / self::INITIAL_PLACEHOLDER_DEFAULT_RATIO_HEIGHT);
            return [max(1, $derivedWidth), $effectiveHeight];
        }

        return [
            self::INITIAL_PLACEHOLDER_FALLBACK_WIDTH,
            self::INITIAL_PLACEHOLDER_FALLBACK_HEIGHT,
        ];
    }

    public static function buildInitialPlaceholderDataUri(
        ?int $width,
        ?int $height,
        ?string $autoDimension,
    ): string {
        [$boxWidth, $boxHeight] = self::resolveInitialPreviewBoxDimensions($width, $height, $autoDimension);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="Placeholder"><rect width="100%%" height="100%%" fill="#e7edf5"/><rect x="1" y="1" width="%3$d" height="%4$d" fill="none" stroke="#98a9be" stroke-width="2"/></svg>',
            $boxWidth,
            $boxHeight,
            max(1, $boxWidth - 2),
            max(1, $boxHeight - 2),
        );

        return 'data:image/svg+xml,' . rawurlencode($svg);
    }
}
