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
    private const INITIAL_PLACEHOLDER_ICON_VIEWBOX_X = -1.0;
    private const INITIAL_PLACEHOLDER_ICON_VIEWBOX_Y = -1.0;
    private const INITIAL_PLACEHOLDER_ICON_VIEWBOX_WIDTH = 23.947266;
    private const INITIAL_PLACEHOLDER_ICON_VIEWBOX_HEIGHT = 17.894531;
    private const INITIAL_PLACEHOLDER_ICON_PATH = 'M 7.3339844,-1 C 6.628056,-1 6.0371094,-0.40905336 6.0371094,0.296875 V 3.9863281 H 3.7324219 c -0.6715314,0 -1.234375,0.564797 -1.234375,1.2363281 V 8.609375 H 0.15820312 C -0.4706666,8.609375 -1,9.1367553 -1,9.765625 v 5.972656 c 0,0.62887 0.52933379,1.15625 1.15820312,1.15625 H 8.1269531 c 0.6288693,0 1.1582032,-0.52738 1.1582032,-1.15625 v -2.271484 h 5.2539067 c 0.671531,0 1.234375,-0.562844 1.234375,-1.234375 V 9.4433594 h 5.878906 c 0.705928,0 1.294922,-0.5909461 1.294922,-1.296875 V 0.296875 C 22.947266,-0.40905395 22.358272,-1 21.652344,-1 Z M 7.4667969,0.4296875 H 21.517578 v 7.5820313 h -5.74414 V 5.2226562 c 0,-0.6715311 -0.562844,-1.2363281 -1.234375,-1.2363281 H 7.4667969 Z M 3.9277344,5.4179687 H 14.34375 V 12.037109 H 3.9277344 Z M 0.4296875,10.039063 h 2.0683594 v 2.193359 c 0,0.671531 0.5628438,1.234375 1.234375,1.234375 h 4.1230468 v 1.998047 H 0.4296875 Z';

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

        $bestRow = null;
        $bestScore = -1;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $src = (string)($row['src'] ?? '');
            if ($src === '') {
                continue;
            }

            $score = 1;
            if (($row['loaded'] ?? false) === true) {
                $score = 2;
            }

            if (($row['loaded'] ?? false) === true && ($row['enabled'] ?? false) === true) {
                $score = 3;
            }

            if (
                ($row['loaded'] ?? false) === true
                && ($row['enabled'] ?? false) === true
                && ($row['isVisible'] ?? false) === true
            ) {
                $score = 4;
            }

            if ($score > $bestScore) {
                $bestRow = $row;
                $bestScore = $score;
                if ($bestScore === 4) {
                    break;
                }
            }
        }

        if (is_array($bestRow)) {
            return $bestRow;
        }

        return $rows[0] ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param int $breakpoint slot id (for identity)
     * @param int|null $referenceWidth media/measure px for scaling + square fallback
     * @return array{width:int,height:int,previewRow:array<string,mixed>|null}
     */
    public static function resolvePreviewDisplayDimensions(array $rows, int $breakpoint, ?int $referenceWidth = null): array
    {
        $ref = ($referenceWidth !== null && $referenceWidth > 0) ? $referenceWidth : $breakpoint;

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
                $transformWidth = Support::normalizeNullablePositiveInt($previewTransformDimensions['width'] ?? null);
                $transformHeight = Support::normalizeNullablePositiveInt($previewTransformDimensions['height'] ?? null);
                $transformAutoDimension = Support::normalizeAutoDimension($previewTransformDimensions['autoDimension'] ?? null);
                $sourceUsed = trim((string)($previewRow['sourceUsed'] ?? ''));

                if (
                    ($previewRow['loaded'] ?? false) === true
                    && $sourceUsed !== ''
                    && $transformWidth === null
                    && $transformHeight === null
                    && $transformAutoDimension === null
                    && $ref > 0
                ) {
                    $displayWidth = $ref;
                    $displayHeight = $ref;
                }

                [$fallbackWidth, $fallbackHeight] = self::resolveInitialPreviewBoxDimensions(
                    $transformWidth,
                    $transformHeight,
                    $transformAutoDimension,
                );

                if (($displayWidth < 1 || $displayHeight < 1) && $fallbackWidth > 0 && $fallbackHeight > 0) {
                    $displayWidth = $fallbackWidth;
                    $displayHeight = $fallbackHeight;
                }
            }
        }

        if (($displayWidth < 1 || $displayHeight < 1) && is_array($previewRow) && $ref > 0) {
            $previewSrc = trim((string)($previewRow['sourceUsed'] ?? ''));
            if ($previewSrc === '') {
                $previewSrc = trim((string)($previewRow['src'] ?? ''));
            }
            if ($previewSrc !== '') {
                $displayWidth = $ref;
                $displayHeight = $ref;
            }
        }

        return [
            'width' => $displayWidth,
            'height' => $displayHeight,
            'previewRow' => $previewRow,
        ];
    }

    /**
     * @param array<int, int> $breakpoints
     * @param array<int|string, int> $referenceWidthsByBreakpoint
     * @return array<int|string, float>
     */
    public static function calculateBreakpointColumnWidths(array $breakpoints, array $referenceWidthsByBreakpoint = []): array
    {
        if ($breakpoints === []) {
            return [];
        }

        $firstRef = null;
        foreach ($breakpoints as $bp) {
            $ref = $referenceWidthsByBreakpoint[(string)$bp] ?? $bp;
            if ($ref > 0) {
                $firstRef = $ref;
                break;
            }
        }
        $firstRef = $firstRef ?? 1;

        /** @var array<int|string, float> $widths */
        $widths = [];
        foreach ($breakpoints as $breakpoint) {
            $ref = $referenceWidthsByBreakpoint[(string)$breakpoint] ?? $breakpoint;
            $widths[(string)$breakpoint] = ($ref / $firstRef) * 160;
        }

        return $widths;
    }

    /**
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @param array<int|string, float> $breakpointColumnWidths
     * @param array<int|string, int> $referenceWidthsByBreakpoint
     * @param array<int, int> $excludedBreakpoints Slot ids whose previews are not shown
     *        (disabled or processing-hidden) and so must not drive the shared height.
     * @return array<int|string, int>
     */
    public static function calculateBreakpointPreviewLockHeights(
        array $rowsByAssetByBreakpoint,
        array $transformBreakpoints,
        array $breakpointColumnWidths,
        array $referenceWidthsByBreakpoint = [],
        array $excludedBreakpoints = [],
    ): array {
        $globalLockHeight = 48;
        $excludedSet = array_fill_keys($excludedBreakpoints, true);

        foreach ($transformBreakpoints as $breakpoint) {
            if (isset($excludedSet[$breakpoint])) {
                continue;
            }

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

                $ref = $referenceWidthsByBreakpoint[(string)$breakpoint] ?? $breakpoint;
                $display = self::resolvePreviewDisplayDimensions($rows, $breakpoint, $ref > 0 ? $ref : null);
                $displayWidth = $display['width'];
                $displayHeight = $display['height'];

                if ($displayWidth < 1 || $displayHeight < 1 || $ref < 1) {
                    continue;
                }

                $candidateHeight = (int)ceil(($availablePreviewWidth * $displayHeight) / $ref);
                $globalLockHeight = max($globalLockHeight, $candidateHeight);
            }
        }

        $globalLockHeight = max(48, $globalLockHeight);
        /** @var array<int|string, int> $lockHeightsByBreakpoint */
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
        $iconWidth = max(72.0, min($boxWidth * 0.26, $boxHeight * 0.36, 260.0));
        $iconScale = $iconWidth / self::INITIAL_PLACEHOLDER_ICON_VIEWBOX_WIDTH;
        $iconHeight = self::INITIAL_PLACEHOLDER_ICON_VIEWBOX_HEIGHT * $iconScale;
        $iconX = (($boxWidth - $iconWidth) / 2) - (self::INITIAL_PLACEHOLDER_ICON_VIEWBOX_X * $iconScale);
        $iconY = (($boxHeight - $iconHeight) / 2) - (self::INITIAL_PLACEHOLDER_ICON_VIEWBOX_Y * $iconScale);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="Preview pending"><defs><linearGradient id="bpts-placeholder-bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f4f7fb"/><stop offset="1" stop-color="#e7edf5"/></linearGradient><pattern id="bpts-placeholder-stripes" width="28" height="28" patternUnits="userSpaceOnUse" patternTransform="rotate(35)"><rect width="12" height="28" fill="#ffffff" opacity="0.28"/></pattern></defs><rect width="100%%" height="100%%" fill="url(#bpts-placeholder-bg)"/><rect width="100%%" height="100%%" fill="url(#bpts-placeholder-stripes)" opacity="0.55"/><rect x="1" y="1" width="%3$d" height="%4$d" fill="none"/><path d="%5$s" fill="#8fa1b8" opacity="0.58" transform="translate(%6$.3F %7$.3F) scale(%8$.5F)"/></svg>',
            $boxWidth,
            $boxHeight,
            max(1, $boxWidth - 2),
            max(1, $boxHeight - 2),
            self::INITIAL_PLACEHOLDER_ICON_PATH,
            $iconX,
            $iconY,
            $iconScale,
        );

        return 'data:image/svg+xml,' . rawurlencode($svg);
    }
}
