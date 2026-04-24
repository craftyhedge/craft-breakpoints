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

            $transformHandle = trim((string)($row['transformHandle'] ?? ''));
            $breakpointWidth = isset($row['breakpointWidth']) && is_numeric($row['breakpointWidth'])
                ? (int)$row['breakpointWidth']
                : 0;
            if ($transformHandle === '' || $breakpointWidth <= 0) {
                continue;
            }

            $indexed[$transformHandle . '|' . $breakpointWidth] = $row;
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
                $entriesById[(int)$id] = [
                    'id' => $entry->id,
                    'title' => (string)$entry->title,
                    'cpEditUrl' => $entry->cpEditUrl ?? '#',
                    'siteId' => $entry->siteId,
                    'availableInCurrentSite' => true,
                ];
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
                    $entriesById[(int)$id] = [
                        'id' => $entry->id,
                        'title' => (string)$entry->title,
                        'cpEditUrl' => $entry->cpEditUrl ?? '#',
                        'siteId' => $entry->siteId,
                        'availableInCurrentSite' => false,
                    ];
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
}
