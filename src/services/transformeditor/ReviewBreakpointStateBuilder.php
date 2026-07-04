<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

/**
 * Builds shared per-breakpoint review UI state for both initial HTML rendering
 * and reactive signal deltas.
 */
final class ReviewBreakpointStateBuilder
{
    private const REVIEW_MODE_PROCESSED = 'processed';

    public function __construct(
        private readonly HealthAnalyzer $healthAnalyzer,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $currentRow
     * @param int|null $referenceWidth media px for relativeWidth + square fallback (falls back to $breakpoint if null)
     * @param array{w: int|null, h: int|null}|null $processSavedDimensions saved dimensions captured at process
     *        time for this breakpoint; null when no run snapshot baseline exists
     * @return array<string, mixed>
     */
    public function build(
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
        $ref = ($referenceWidth !== null && $referenceWidth > 0) ? $referenceWidth : $breakpoint;

        $summary = ReviewLayoutCalculator::summarizeRows($rows);
        $renderedRowsPayload = ReviewLayoutCalculator::buildRenderedRowsPayload($rows, $breakpoint);
        $renderedWidth = (int)($summary['renderedWidth'] ?? 0);
        $renderedHeight = (int)($summary['renderedHeight'] ?? 0);

        $previewRow = ReviewLayoutCalculator::pickPreviewRow($rows);
        $previewSrc = is_array($previewRow) ? $this->resolvePreviewSrc($previewRow) : '';

        $currentWidth = Support::normalizeNullablePositiveInt($currentRow['width'] ?? null);
        $currentHeight = Support::normalizeNullablePositiveInt($currentRow['height'] ?? null);
        $autoDimension = Support::normalizeAutoDimension($currentRow['autoDimension'] ?? null);
        $currentRatioWidth = Support::normalizeNullablePositiveInt($currentRow['ratioWidth'] ?? null);
        $currentRatioHeight = Support::normalizeNullablePositiveInt($currentRow['ratioHeight'] ?? null);
        $currentRatioSourceDimension = Support::normalizeRatioSourceDimension($currentRow['ratioSourceDimension'] ?? null) ?? 'width';
        $currentRatioLocked = ($currentRow['ratioLocked'] ?? false) === true
            && $currentRatioWidth !== null
            && $currentRatioHeight !== null;
        $currentRatioFloatValue = $currentRatioLocked
            ? Support::formatRatioFloatInput($currentRatioWidth, $currentRatioHeight)
            : '';
        $ratioIsDrivingDimensions = $currentRatioLocked && $autoDimension === null;
        $currentWidthDerived = $ratioIsDrivingDimensions && $currentRatioSourceDimension === 'height';
        $currentHeightDerived = $ratioIsDrivingDimensions && $currentRatioSourceDimension === 'width';

        $display = ReviewLayoutCalculator::resolvePreviewDisplayDimensions($rows, $breakpoint, $ref > 0 ? $ref : null);
        $displayWidth = max(0, (int)($display['width'] ?? 0));
        $displayHeight = max(0, (int)($display['height'] ?? 0));

        if ($displayWidth < 1 || $displayHeight < 1) {
            if ($previewSrc !== '' && $ref > 0) {
                $displayWidth = $ref;
                $displayHeight = $ref;
            } else {
                [$displayWidth, $displayHeight] = ReviewLayoutCalculator::resolveInitialPreviewBoxDimensions(
                    $currentWidth,
                    $currentHeight,
                    $autoDimension,
                );
            }
        }

        $aspectRatio = $displayWidth > 0 && $displayHeight > 0
            ? $displayWidth . ' / ' . $displayHeight
            : '1 / 1';
        $relativeWidth = $ref > 0
            ? max(0.0, min(100.0, ($displayWidth / $ref) * 100))
            : 0.0;

        // A rendered match/mismatch is only meaningful while the saved dimensions still
        // equal what they were at process time. Once the user edits a dimension, the
        // measurement is stale for it: the rendered value goes neutral and the edited
        // current value is flagged instead (until a re-process refreshes the baseline).
        $hasProcessBaseline = $processSavedDimensions !== null && $reviewMode === self::REVIEW_MODE_PROCESSED;
        $widthEdited = $hasProcessBaseline
            && ($autoDimension === 'width' ? null : $currentWidth) !== ($processSavedDimensions['w'] ?? null);
        $heightEdited = $hasProcessBaseline
            && ($autoDimension === 'height' ? null : $currentHeight) !== ($processSavedDimensions['h'] ?? null);

        $widthStatus = $this->healthAnalyzer->evaluateDimensionMatch(
            max(0, $renderedWidth),
            $currentWidth,
            $autoDimension === 'width',
        );
        $heightStatus = $this->healthAnalyzer->evaluateDimensionMatch(
            max(0, $renderedHeight),
            $currentHeight,
            $autoDimension === 'height',
        );
        $widthStale = $widthStatus === 'mismatch' && $widthEdited;
        $heightStale = $heightStatus === 'mismatch' && $heightEdited;
        $widthClass = $widthStale ? 'bpi_dimension-stale' : $this->getRenderedDimensionClass($widthStatus);
        $heightClass = $heightStale ? 'bpi_dimension-stale' : $this->getRenderedDimensionClass($heightStatus);
        $renderedApplyNoop = $this->isRenderedApplyNoop(
            $renderedRowsPayload,
            $currentWidth,
            $currentHeight,
            $autoDimension,
        );

        $currentEnabled = ($currentRow['enabled'] ?? true) === true;
        $hasBreakpointMismatch = false;
        $hiddenOnlyAllowed = $allowHiddenDuringProcessing
            && $currentEnabled
            && (int)($summary['hiddenCount'] ?? 0) > 0
            && $renderedWidth < 1
            && $renderedHeight < 1;
        if ($reviewMode === self::REVIEW_MODE_PROCESSED && $currentEnabled && !$hiddenOnlyAllowed) {
            $columnEvaluation = $this->healthAnalyzer->evaluateBreakpointMatch(
                $renderedWidth,
                $renderedHeight,
                $savedWidth,
                $savedHeight,
                $autoDimension,
                $passHeightWhenRenderedLteSaved,
                $allowAnyHeight,
            );
            // An edited dimension can't produce a genuine mismatch — its measurement is
            // stale. The card-level awaitingReprocess banner covers that state instead.
            $columnWidthStatus = (string)($columnEvaluation['widthStatus'] ?? '');
            $columnHeightStatus = (string)($columnEvaluation['heightStatus'] ?? '');
            $hasBreakpointMismatch = ($columnWidthStatus === 'missing'
                    || ($columnWidthStatus === 'mismatch' && !$widthEdited))
                || ($columnHeightStatus === 'missing'
                    || ($columnHeightStatus === 'mismatch' && !$heightEdited));
        }

        return [
            'summary' => $summary,
            'renderedRowsPayload' => $renderedRowsPayload,
            'renderedWidth' => $renderedWidth,
            'renderedHeight' => $renderedHeight,
            'previewSrc' => $previewSrc,
            'currentWidth' => $currentWidth,
            'currentHeight' => $currentHeight,
            'currentRatioWidth' => $currentRatioWidth,
            'currentRatioHeight' => $currentRatioHeight,
            'currentRatioFloatValue' => $currentRatioFloatValue,
            'currentRatioSourceDimension' => $currentRatioSourceDimension,
            'currentRatioLocked' => $currentRatioLocked,
            'autoDimension' => $autoDimension,
            'aspectRatio' => $aspectRatio,
            'relativeWidth' => $relativeWidth,
            'widthClass' => $widthClass,
            'heightClass' => $heightClass,
            'renderedApplyNoop' => $renderedApplyNoop,
            'currentEnabled' => $currentEnabled,
            'hasBreakpointMismatch' => $hasBreakpointMismatch,
            'currentWidthDerivedClass' => $currentWidthDerived ? 'bpi_current-dimension-derived' : '',
            'currentHeightDerivedClass' => $currentHeightDerived ? 'bpi_current-dimension-derived' : '',
            'currentWidthEditedClass' => $widthStale ? 'bpi_current-dimension-edited' : '',
            'currentHeightEditedClass' => $heightStale ? 'bpi_current-dimension-edited' : '',
        ];
    }

    private function getRenderedDimensionClass(string $status): string
    {
        return match ($status) {
            'auto' => 'bpi_dimension-auto',
            'no-transform', 'missing' => 'bpi_dimension-no-transform',
            'match' => 'bpi_dimension-match',
            'mismatch' => 'bpi_dimension-mismatch',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $previewRow
     */
    private function resolvePreviewSrc(array $previewRow): string
    {
        $sourceUsed = trim((string)($previewRow['sourceUsed'] ?? ''));
        if ($sourceUsed !== '') {
            return $sourceUsed;
        }

        return trim((string)($previewRow['src'] ?? ''));
    }

    /**
     * @param array<int, array<string, mixed>> $renderedRowsPayload
     */
    private function isRenderedApplyNoop(
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

            $renderedWidth = Support::normalizeNullablePositiveInt($renderedRow['width'] ?? null);
            if ($renderedWidth !== null) {
                $candidateDimensionCount += 1;
                if ($autoDimension !== 'width' && $currentWidth !== $renderedWidth) {
                    $hasComparedChange = true;
                }
            }

            $renderedHeight = Support::normalizeNullablePositiveInt($renderedRow['height'] ?? null);
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
}
