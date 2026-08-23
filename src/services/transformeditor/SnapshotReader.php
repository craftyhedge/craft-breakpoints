<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use Craft;
use craft\elements\Entry;
use craftyhedge\craftbreakpoints\services\TelemetryService;
use craftyhedge\craftbreakpoints\services\TransformStore;

/**
 * Read-only, request-scoped reader for the latest run snapshot and related
 * telemetry/store data consumed by the review pipeline.
 *
 * Results are memoized per instance; `TransformEditor` holds a single
 * `SnapshotReader` per request so snapshot parsing and Entry lookups run
 * at most once regardless of how many consumers read from it.
 */
final class SnapshotReader
{
    private bool $snapshotLoaded = false;
    /**
     * @var array<string, mixed>|null
     */
    private ?array $snapshot = null;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $latestRunRowsByTransformAndBreakpoint = null;
    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $previewCacheRows = null;

    /**
     * Null = not yet resolved; key 'value' holds the resolved value to distinguish from "no data".
     *
     * @var array{value: array<string, mixed>|null}|null
     */
    private ?array $resolvedRunEntryCache = null;

    public function __construct(
        private readonly TransformStore $transformStore,
        private readonly TelemetryService $telemetry,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLatestRunSnapshot(): ?array
    {
        if ($this->snapshotLoaded === false) {
            $snapshot = $this->telemetry->getLatestRunSnapshot();
            $this->snapshot = is_array($snapshot) ? $snapshot : null;
            $this->snapshotLoaded = true;
        }

        return $this->snapshot;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getLatestRunRowsByTransformAndBreakpoint(): array
    {
        if ($this->latestRunRowsByTransformAndBreakpoint !== null) {
            return $this->latestRunRowsByTransformAndBreakpoint;
        }

        $snapshot = $this->getLatestRunSnapshot();
        if ($snapshot === null) {
            return $this->latestRunRowsByTransformAndBreakpoint = [];
        }

        $rows = isset($snapshot['rows']) && is_array($snapshot['rows'])
            ? $snapshot['rows']
            : [];
        if ($rows === []) {
            return $this->latestRunRowsByTransformAndBreakpoint = [];
        }

        $indexed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $transformHandle = $this->extractTransformHandleFromRow($row);
            $slotKey = $this->extractSlotKeyFromRow($row);
            if ($transformHandle === '' || $slotKey === '') {
                continue;
            }

            $indexed[$this->buildTransformBreakpointKey($transformHandle, $slotKey)] = $row;
        }

        return $this->latestRunRowsByTransformAndBreakpoint = $indexed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getPreviewCacheRowsByTransformAndBreakpoint(): array
    {
        if ($this->previewCacheRows !== null) {
            return $this->previewCacheRows;
        }

        return $this->previewCacheRows = $this->telemetry->getPreviewCacheRows();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getStoredTransforms(): array
    {
        $transforms = $this->transformStore->getTransforms();

        return is_array($transforms) ? $transforms : [];
    }

    /**
     * @return array{transformHandle: string, includeEscapeWidth: ?bool}|null
     */
    public function resolveTransformMetadata(string $transformName): ?array
    {
        if ($transformName === '') {
            return null;
        }

        $snapshot = $this->getLatestRunSnapshot();
        $metadata = is_array($snapshot) && isset($snapshot['transformMetadata']) && is_array($snapshot['transformMetadata'])
            ? $snapshot['transformMetadata']
            : [];
        $entry = $metadata[$transformName] ?? null;

        if (!is_array($entry)) {
            return null;
        }

        return [
            'transformHandle' => (string)($entry['transformHandle'] ?? ''),
            'includeEscapeWidth' => is_bool($entry['includeEscapeWidth'] ?? null) ? $entry['includeEscapeWidth'] : null,
        ];
    }

    /**
     * @return array<int, int>
     */
    public function resolveHiddenSlotIdsForTransform(string $transformName, ?string $assetKey = null): array
    {
        if ($transformName === '') {
            return [];
        }

        $snapshot = $this->getLatestRunSnapshot();
        $perAssetRows = is_array($snapshot) && isset($snapshot['rowsPayload']) && is_array($snapshot['rowsPayload'])
            ? $snapshot['rowsPayload']
            : [];
        if ($perAssetRows === []) {
            return [];
        }

        $slotVisibility = [];
        foreach ($perAssetRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ($this->extractTransformHandleFromRow($row) !== $transformName) {
                continue;
            }

            if ($assetKey !== null && $assetKey !== '') {
                $rowAssetId = $this->extractAssetIdFromRow($row);
                if (!$this->assetKeyMatchesRowAsset($assetKey, $rowAssetId, $transformName)) {
                    continue;
                }
            }

            $slotId = $this->extractSlotIdFromRow($row);
            if ($slotId > 0) {
                if (!isset($slotVisibility[$slotId])) {
                    $slotVisibility[$slotId] = [
                        'hidden' => false,
                        'visible' => false,
                    ];
                }

                if (($row['isVisible'] ?? null) === false) {
                    $slotVisibility[$slotId]['hidden'] = true;
                } elseif (($row['isVisible'] ?? null) === true) {
                    $slotVisibility[$slotId]['visible'] = true;
                }
            }
        }

        $hiddenSlotIds = [];
        foreach ($slotVisibility as $slotId => $visibility) {
            if ($visibility['hidden'] === true && $visibility['visible'] !== true) {
                $hiddenSlotIds[] = (int)$slotId;
            }
        }

        sort($hiddenSlotIds, SORT_NUMERIC);

        return $hiddenSlotIds;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, mixed>|null
     */
    public function resolveRunEntryData(?array $snapshot): ?array
    {
        if ($this->resolvedRunEntryCache !== null) {
            return $this->resolvedRunEntryCache['value'];
        }

        $value = $this->computeRunEntryData($snapshot);
        $this->resolvedRunEntryCache = ['value' => $value];

        return $value;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, mixed>|null
     */
    private function computeRunEntryData(?array $snapshot): ?array
    {
        if (!is_array($snapshot)) {
            return null;
        }

        $entryId = is_numeric($snapshot['entryId'] ?? null) ? (int)$snapshot['entryId'] : 0;
        if ($entryId <= 0) {
            return null;
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $entry = Entry::find()
            ->id($entryId)
            ->status(null)
            ->siteId($siteId)
            ->one();

        if (!$entry instanceof Entry) {
            return [
                'id' => $entryId,
                'title' => '',
                'cpEditUrl' => '#',
                'siteId' => $siteId,
                'canProcessAgain' => false,
            ];
        }

        return [
            'id' => $entry->id,
            'title' => (string)$entry->title,
            'cpEditUrl' => $entry->cpEditUrl ?? '#',
            'siteId' => $entry->siteId,
            'canProcessAgain' => $entry->getStatus() === Entry::STATUS_LIVE,
        ];
    }

    /**
     * Resolve authoritative rendered width and height for a specific transform
     * and breakpoint from server-side telemetry sources.
     *
     * Resolution order:
     *   1. Match snapshot.rowsPayload by transformHandle + slotKey + assetId
     *   2. Fall back to previewCacheRows for first-asset evidence
     *   3. Return null (caller must produce an explicit user-facing error)
     *
     * @return array{renderedWidth: int, renderedHeight: int}|null
     */
    public function resolveRenderedWidthHeightByBreakpoint(
        string $transformName,
        int $breakpointWidth,
        ?string $assetKey = null,
        ?string $slotKey = null,
    ): ?array
    {
        $slotKey = $slotKey !== null ? trim($slotKey) : '';
        if ($transformName === '' || $slotKey === '') {
            return null;
        }

        $rowsByTransformAndBreakpoint = $this->getLatestRunRowsByTransformAndBreakpoint();
        $snapshot = $this->getLatestRunSnapshot();
        $perAssetRows = is_array($snapshot) && isset($snapshot['rowsPayload']) && is_array($snapshot['rowsPayload'])
            ? $snapshot['rowsPayload']
            : [];

        if ($perAssetRows !== []) {
            $matchedByAsset = null;
            $firstForRow = null;

            foreach ($perAssetRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (!$this->rowMatchesTransformSlot($row, $transformName, $breakpointWidth, $slotKey)) {
                    continue;
                }

                if ($firstForRow === null) {
                    $firstForRow = $row;
                }

                if ($assetKey !== null && $assetKey !== '') {
                    $rowAssetId = $this->extractAssetIdFromRow($row);
                    if ($this->assetKeyMatchesRowAsset($assetKey, $rowAssetId, $transformName)) {
                        $matchedByAsset = $row;
                        break;
                    }
                }
            }

            $resolvedRow = $matchedByAsset ?? $firstForRow;
            if ($resolvedRow !== null) {
                return $this->extractRenderedDimensionsFromRow($resolvedRow);
            }
        }

        $key = $this->buildTransformBreakpointKey($transformName, $slotKey);
        $previewRow = $rowsByTransformAndBreakpoint[$key] ?? null;
        if (is_array($previewRow)) {
            return $this->extractRenderedDimensionsFromRow($previewRow);
        }

        $previewCacheRows = $this->getPreviewCacheRowsByTransformAndBreakpoint();
        $cachedRow = $previewCacheRows[$key] ?? null;
        if (is_array($cachedRow)) {
            return $this->extractRenderedDimensionsFromRow($cachedRow);
        }

        return null;
    }

    /**
     * Resolve authoritative rendered rows for all breakpoints of a transform
     * from server-side telemetry sources. Used by renderedValues.apply and
     * toggle-auto restore-when-scope-is-all.
     *
     * @return array<int, array{breakpoint: int, width: ?int, height: ?int}>
     */
    public function resolveRenderedRowsForTransform(string $transformName, ?string $assetKey = null): array
    {
        if ($transformName === '') {
            return [];
        }

        $snapshot = $this->getLatestRunSnapshot();
        $perAssetRows = is_array($snapshot) && isset($snapshot['rowsPayload']) && is_array($snapshot['rowsPayload'])
            ? $snapshot['rowsPayload']
            : [];

        $renderedRows = [];

        if ($perAssetRows !== []) {
            $firstByBreakpoint = [];
            $assetMatchByBreakpoint = [];

            foreach ($perAssetRows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowTransformHandle = $this->extractTransformHandleFromRow($row);
                $rowBreakpointWidth = $this->extractSlotIdFromRow($row);
                if ($rowTransformHandle !== $transformName || $rowBreakpointWidth <= 0) {
                    continue;
                }

                if (!isset($firstByBreakpoint[$rowBreakpointWidth])) {
                    $firstByBreakpoint[$rowBreakpointWidth] = $row;
                }

                if ($assetKey !== null && $assetKey !== '') {
                    $rowAssetId = $this->extractAssetIdFromRow($row);
                    if ($this->assetKeyMatchesRowAsset($assetKey, $rowAssetId, $transformName)) {
                        $assetMatchByBreakpoint[$rowBreakpointWidth] = $row;
                    }
                }
            }

            $resolvedByBreakpoint = [];
            foreach ($firstByBreakpoint as $bp => $row) {
                $resolvedByBreakpoint[$bp] = $assetMatchByBreakpoint[$bp] ?? $row;
            }

            foreach ($resolvedByBreakpoint as $bp => $row) {
                $dimensions = $this->extractNullableDimensionsFromRow($row);
                if ($dimensions !== null) {
                    $renderedRows[] = [
                        'breakpoint' => $bp,
                        'slotKey' => $this->extractSlotKeyFromRow($row),
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                    ];
                }
            }
        }

        if ($renderedRows === []) {
            $previewCacheRows = $this->getPreviewCacheRowsByTransformAndBreakpoint();
            foreach ($previewCacheRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rowTransformHandle = $this->extractTransformHandleFromRow($row);
                $rowBreakpointWidth = $this->extractSlotIdFromRow($row);
                if ($rowTransformHandle !== $transformName || $rowBreakpointWidth <= 0) {
                    continue;
                }

                $dimensions = $this->extractNullableDimensionsFromRow($row);
                if ($dimensions !== null) {
                    $renderedRows[] = [
                        'breakpoint' => $rowBreakpointWidth,
                        'slotKey' => $this->extractSlotKeyFromRow($row),
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                    ];
                }
            }
        }

        return $renderedRows;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{renderedWidth: int, renderedHeight: int}|null
     */
    private function extractRenderedDimensionsFromRow(array $row): ?array
    {
        $dimensions = $this->extractNullableDimensionsFromRow($row);
        if ($dimensions === null) {
            return null;
        }

        return [
            'renderedWidth' => $dimensions['width'] ?? 0,
            'renderedHeight' => $dimensions['height'] ?? 0,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{width: ?int, height: ?int}|null
     */
    private function extractNullableDimensionsFromRow(array $row): ?array
    {
        $width = Support::parseNullablePositiveInt($row['renderedWidth'] ?? null);
        $height = Support::parseNullablePositiveInt($row['renderedHeight'] ?? null);

        if ($width === null && $height === null) {
            return null;
        }

        return [
            'width' => $width,
            'height' => $height,
        ];
    }

    private function buildTransformBreakpointKey(string $transformHandle, string $slotKey): string
    {
        return $transformHandle . '|' . $slotKey;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractTransformHandleFromRow(array $row): string
    {
        return trim((string)($row['transformHandle'] ?? ''));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractSlotKeyFromRow(array $row): string
    {
        return trim((string)($row['slotKey'] ?? ''));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractSlotIdFromRow(array $row): int
    {
        if (isset($row['slotIndex']) && is_numeric($row['slotIndex'])) {
            return ((int)$row['slotIndex']) + 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowMatchesTransformSlot(array $row, string $transformName, int $slotId, string $slotKey): bool
    {
        if ($this->extractTransformHandleFromRow($row) !== $transformName) {
            return false;
        }

        return $slotKey !== '' && $this->extractSlotKeyFromRow($row) === $slotKey;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractAssetIdFromRow(array $row): string
    {
        return trim((string)($row['assetId'] ?? ''));
    }

    private function assetKeyMatchesRowAsset(string $assetKey, string $rowAssetId, string $transformName): bool
    {
        $assetKey = trim($assetKey);
        $rowAssetId = trim($rowAssetId);
        if ($assetKey === '' || $rowAssetId === '') {
            return false;
        }

        if ($assetKey === $rowAssetId) {
            return true;
        }

        return $assetKey === 'asset:' . $transformName . ':' . $rowAssetId;
    }

}
