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
        bool $hideRenderedApply,
        string $reviewMode,
        ?int $referenceWidth = null,
    ): array {
        $ref = ($referenceWidth !== null && $referenceWidth > 0) ? $referenceWidth : $breakpoint;

        $summary = ReviewLayoutCalculator::summarizeRows($rows);
        $renderedRowsPayload = ReviewLayoutCalculator::buildRenderedRowsPayload($rows, $breakpoint);
        $renderedWidth = (int)($summary['renderedWidth'] ?? 0);
        $renderedHeight = (int)($summary['renderedHeight'] ?? 0);

        $previewRow = ReviewLayoutCalculator::pickPreviewRow($rows);
        $previewSrc = is_array($previewRow) ? (string)($previewRow['src'] ?? '') : '';

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

        $widthClass = $this->getRenderedDimensionClass($renderedWidth, $currentWidth, $autoDimension, 'width');
        $heightClass = $this->getRenderedDimensionClass($renderedHeight, $currentHeight, $autoDimension, 'height');
        $renderedApplyNoop = $this->isRenderedApplyNoop(
            $renderedRowsPayload,
            $currentWidth,
            $currentHeight,
            $autoDimension,
        );

        $currentEnabled = ($currentRow['enabled'] ?? true) === true;
        $hasBreakpointMismatch = false;
        if ($reviewMode === self::REVIEW_MODE_PROCESSED && $currentEnabled) {
            $columnEvaluation = $this->healthAnalyzer->evaluateBreakpointMatch(
                $renderedWidth,
                $renderedHeight,
                $savedWidth,
                $savedHeight,
                $autoDimension,
                $passHeightWhenRenderedLteSaved,
                $allowAnyHeight,
            );
            $hasBreakpointMismatch = ($columnEvaluation['isBreakpointMismatch'] ?? false) === true;
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
        ];
    }

    private function getRenderedDimensionClass(
        int $renderedValue,
        ?int $transformValue,
        ?string $autoDimension,
        string $dimension,
    ): string {
        $status = $this->healthAnalyzer->evaluateDimensionMatch(
            max(0, $renderedValue),
            $transformValue,
            $autoDimension === $dimension,
        );

        return match ($status) {
            'auto' => 'bpi_dimension-auto',
            'no-transform', 'missing' => 'bpi_dimension-no-transform',
            'match' => 'bpi_dimension-match',
            'mismatch' => 'bpi_dimension-mismatch',
            default => '',
        };
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
