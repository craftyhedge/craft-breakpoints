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
    private ?array $snapshot = null;

    private ?array $latestRunRowsByTransformAndBreakpoint = null;
    private ?array $previewCacheRows = null;
    private ?array $observedDataByTransform = null;

    /** Null = not yet resolved; key 'value' holds the resolved value to distinguish from "no data". */
    private ?array $resolvedRunEntryCache = null;

    public function __construct(
        private readonly TransformStore $transformStore,
        private readonly TelemetryService $telemetry,
    ) {
    }

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
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getStoredTransforms(): array
    {
        $transforms = $this->transformStore->getTransforms();

        return is_array($transforms) ? $transforms : [];
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

        if ($entry === null) {
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
    public function resolveRenderedWidthHeightByBreakpoint(string $transformName, int $breakpointWidth, ?string $assetKey = null): ?array
    {
        if ($transformName === '' || $breakpointWidth <= 0) {
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

                $rowTransformHandle = $this->extractTransformHandleFromRow($row);
                $rowSlotId = $this->extractSlotIdFromRow($row);
                if ($rowTransformHandle !== $transformName || $rowSlotId !== $breakpointWidth) {
                    continue;
                }

                if ($firstForRow === null) {
                    $firstForRow = $row;
                }

                if ($assetKey !== null && $assetKey !== '') {
                    $rowAssetId = $this->extractAssetIdFromRow($row);
                    if ($rowAssetId === $assetKey) {
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

        $key = $this->buildTransformBreakpointKey($transformName, (string)($breakpointWidth - 1));
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

        $rowsByBreakpoint = [];

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
                    if ($rowAssetId === $assetKey) {
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
                    $rowsByBreakpoint[] = [
                        'breakpoint' => $bp,
                        'slotKey' => $this->extractSlotKeyFromRow($row),
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                    ];
                }
            }
        }

        if ($rowsByBreakpoint === []) {
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
                    $rowsByBreakpoint[] = [
                        'breakpoint' => $rowBreakpointWidth,
                        'slotKey' => $this->extractSlotKeyFromRow($row),
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                    ];
                }
            }
        }

        return $rowsByBreakpoint;
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

    /**
     * @return array<string, array<string, mixed>>
     */
    public function resolveObservedDataByTransform(): array
    {
        if ($this->observedDataByTransform !== null) {
            return $this->observedDataByTransform;
        }

        if (!$this->telemetry->isTelemetryEnabled()) {
            return $this->observedDataByTransform = [];
        }

        $mostRecent = $this->telemetry->getMostRecentByHandle();
        if ($mostRecent === []) {
            return $this->observedDataByTransform = [];
        }

        $byTransform = [];
        $entryIds = [];
        foreach ($mostRecent as $handle => $row) {
            $sourceElementId = (int)($row['entryId'] ?? 0);
            $byTransform[$handle] = [
                'lastSeenAt' => $row['lastSeenAt'] ?? null,
                'sourceElementId' => $sourceElementId,
                'sourceUrl' => $row['sourceUrl'] ?? null,
                'includeEscapeWidth' => $row['includeEscapeWidth'] ?? null,
                'entry' => null,
            ];

            if ($sourceElementId > 0) {
                $entryIds[$sourceElementId] = true;
            }
        }

        $entryIds = array_keys($entryIds);
        $entriesById = [];
        if ($entryIds !== []) {
            $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
            $currentSiteEntries = Entry::find()
                ->id($entryIds)
                ->status(null)
                ->siteId($currentSiteId)
                ->indexBy('id')
                ->all();

            foreach ($currentSiteEntries as $id => $entry) {
                $entriesById[(int)$id] = $this->mapObservedEntryData($entry, true);
            }

            $remainingIds = array_values(array_diff($entryIds, array_keys($entriesById)));
            if ($remainingIds !== []) {
                $otherSiteEntries = Entry::find()
                    ->id($remainingIds)
                    ->status(null)
                    ->site('*')
                    ->indexBy('id')
                    ->all();
                foreach ($otherSiteEntries as $id => $entry) {
                    $entriesById[(int)$id] = $this->mapObservedEntryData($entry, false);
                }
            }
        }

        foreach ($byTransform as $handle => &$data) {
            $elementId = (int)($data['sourceElementId'] ?? 0);
            if ($elementId > 0 && isset($entriesById[$elementId])) {
                $data['entry'] = $entriesById[$elementId];
            }
        }
        unset($data);

        return $this->observedDataByTransform = $byTransform;
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
    private function extractBreakpointWidthFromRow(array $row): int
    {
        return isset($row['breakpointWidth']) && is_numeric($row['breakpointWidth'])
            ? (int)$row['breakpointWidth']
            : 0;
    }

    private function extractSlotKeyFromRow(array $row): string
    {
        return trim((string)($row['slotKey'] ?? ''));
    }

    private function extractSlotIdFromRow(array $row): int
    {
        if (isset($row['slotIndex']) && is_numeric($row['slotIndex'])) {
            return ((int)$row['slotIndex']) + 1;
        }

        return $this->extractBreakpointWidthFromRow($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractAssetIdFromRow(array $row): string
    {
        return trim((string)($row['assetId'] ?? ''));
    }

    /**
     * @return array{id: int, title: string, cpEditUrl: string, siteId: int, availableInCurrentSite: bool}
     */
    private function mapObservedEntryData(Entry $entry, bool $availableInCurrentSite): array
    {
        return [
            'id' => $entry->id,
            'title' => (string)$entry->title,
            'cpEditUrl' => $entry->cpEditUrl ?? '#',
            'siteId' => $entry->siteId,
            'availableInCurrentSite' => $availableInCurrentSite,
        ];
    }
}
