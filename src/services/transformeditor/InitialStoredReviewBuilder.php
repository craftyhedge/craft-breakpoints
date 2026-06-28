<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\Plugin;

/**
 * Builds saved-review source rows before rendering.
 */
final class InitialStoredReviewBuilder
{
    public function __construct(
        private readonly Plugin $plugin,
        private readonly SnapshotReader $snapshotReader,
    ) {
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

            $includeEscapeWidth = $this->plugin->getBreakpointPolicy()->resolveIncludeEscapeWidth([], $transformDefinition);
            $slots = $this->plugin->getBreakpointSlots()->getSlots($includeEscapeWidth);
            $entries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
                ? array_values($transformDefinition['transforms'])
                : [];

            foreach ($slots as $slot) {
                $slotKey = (string)($slot['key'] ?? '');
                $slotIndex = (int)($slot['index'] ?? -1);
                $slotId = $slotIndex + 1;
                $mediaWidth = (int)($slot['mediaWidth'] ?? 0);
                $measureWidth = (int)($slot['measureWidth'] ?? $mediaWidth);
                if ($slotIndex < 0 || $mediaWidth <= 0) {
                    continue;
                }

                $entry = isset($entries[$slotIndex]) && is_array($entries[$slotIndex])
                    ? $entries[$slotIndex]
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

                $cacheKey = $setName . '|' . $slotKey;
                $snapshotRow = $previewCacheByTransformAndBreakpoint[$cacheKey] ?? null;
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

                $syntheticRowsByBreakpoint[$slotId][] = [
                    'transform' => $setName,
                    'slotKey' => $slotKey,
                    'slotIndex' => $slotIndex,
                    'mediaWidth' => $mediaWidth,
                    'measureWidth' => $measureWidth,
                    'assetId' => 'saved-preview:' . $setName,
                    'title' => $setName . ' ' . ($slotKey !== '' ? $slotKey : (string)$mediaWidth) . ' placeholder',
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

        $mergedRowsByBreakpoint = $syntheticRowsByBreakpoint;
        foreach ($resultRowsByBreakpoint as $breakpoint => $rows) {
            if (!isset($mergedRowsByBreakpoint[$breakpoint]) || !is_array($mergedRowsByBreakpoint[$breakpoint])) {
                $mergedRowsByBreakpoint[$breakpoint] = $rows;
                continue;
            }

            $mergedRowsByBreakpoint[$breakpoint] = array_values(array_merge($rows, $mergedRowsByBreakpoint[$breakpoint]));
        }

        return $mergedRowsByBreakpoint;
    }
}
