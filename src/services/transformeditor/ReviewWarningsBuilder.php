<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\services\ConfigService;
use craftyhedge\craftbreakpoints\services\TelemetryService;

/**
 * Builds per-transform review warnings (currently: missing set definitions)
 * and provides warning metadata used for rendering (class, label).
 *
 * Depends on SnapshotReader for observed entry data, ConfigService for
 * configured breakpoints, and TelemetryService for edit-permission decisions
 * on warning message text.
 */
final class ReviewWarningsBuilder
{
    public function __construct(
        private readonly SnapshotReader $snapshotReader,
        private readonly ConfigService $configService,
        private readonly TelemetryService $telemetry,
    ) {
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @param array<string, array<string, mixed>> $storedTransforms
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function buildWarningsByTransform(array $rowsByBreakpoint, array $storedTransforms): array
    {
        $warningsByTransform = [];
        $observedTransformNames = self::collectTransformNames($rowsByBreakpoint);
        $observedTransformSet = array_fill_keys($observedTransformNames, true);
        $configTransformNames = array_keys($storedTransforms);

        foreach ($storedTransforms as $transformName => $transformDefinition) {
            if (!is_string($transformName) || $transformName === '' || !is_array($transformDefinition)) {
                continue;
            }

            if (!isset($observedTransformSet[$transformName])) {
                continue;
            }

            $emptyBreakpoints = $this->collectEmptyEnabledBreakpoints($transformDefinition);
            if ($emptyBreakpoints !== []) {
                $warningsByTransform[$transformName][] = $this->buildEmptyEnabledBreakpointsWarning($emptyBreakpoints);
            }
        }

        $missingDefinitions = array_values(array_diff($observedTransformNames, $configTransformNames));
        if ($missingDefinitions === []) {
            return $warningsByTransform;
        }

        $observedDataByTransform = $this->snapshotReader->resolveObservedDataByTransform();

        foreach ($missingDefinitions as $transformName) {
            $observed = $observedDataByTransform[$transformName] ?? null;
            $entryId = 0;
            $entryAvailable = false;
            $entryMissing = false;
            if (is_array($observed)) {
                $entryCandidate = $observed['entry'] ?? null;
                if (is_array($entryCandidate) && (int)($entryCandidate['id'] ?? 0) > 0) {
                    $entryId = (int)$entryCandidate['id'];
                    $entryAvailable = ($entryCandidate['availableInCurrentSite'] ?? false) === true;
                } elseif (($observed['sourceElementId'] ?? 0) > 0) {
                    $entryId = (int)$observed['sourceElementId'];
                    $entryMissing = true;
                }
            }

            $warningsByTransform[$transformName][] = $this->buildMissingSetDefinitionWarning(
                $entryId,
                $entryAvailable,
                $entryMissing,
            );
        }

        return $warningsByTransform;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildMissingSetDefinitionWarning(
        int $entryId = 0,
        bool $entryAvailable = false,
        bool $entryMissing = false,
    ): array {
        $canEdit = $this->telemetry->canEditTransforms();
        $message = $canEdit
            ? 'No transforms are saved for this set. Process the observed entry to capture rendered dimensions, or edit the transforms.'
            : 'No transforms are saved for this set. Process in a development environment.';

        return [
            'code' => 'missing-set-definitions',
            'message' => $message,
            'entryId' => $entryId,
            'entryAvailable' => $entryAvailable,
            'entryMissing' => $entryMissing,
        ];
    }

    /**
     * @param int[] $breakpoints
     * @return array<string, mixed>
     */
    public function buildEmptyEnabledBreakpointsWarning(array $breakpoints): array
    {
        $normalizedBreakpoints = array_values(array_unique(array_filter(
            array_map(static fn(mixed $value): int => (int)$value, $breakpoints),
            static fn(int $value): bool => $value > 0,
        )));
        sort($normalizedBreakpoints, SORT_NUMERIC);

        $breakpointList = implode(', ', array_map(static fn(int $value): string => $value . 'px', $normalizedBreakpoints));

        return [
            'code' => 'empty-enabled-breakpoints',
            'message' => $breakpointList !== ''
                ? 'One or more enabled breakpoints have empty values: ' . $breakpointList . '.'
                : 'One or more enabled breakpoints have empty values.',
            'breakpoints' => $normalizedBreakpoints,
            'breakpointCount' => count($normalizedBreakpoints),
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $warningsByTransform
     */
    public static function countWarningsByTransform(array $warningsByTransform): int
    {
        $count = 0;
        foreach ($warningsByTransform as $warnings) {
            if (!is_array($warnings)) {
                continue;
            }

            $count += count($warnings);
        }

        return $count;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @return array<int, string>
     */
    private static function collectTransformNames(array $rowsByBreakpoint): array
    {
        $names = [];
        foreach ($rowsByBreakpoint as $rows) {
            foreach ($rows as $row) {
                $name = (string)($row['transform'] ?? '');
                if ($name !== '' && $name !== 'unknown') {
                    $names[$name] = true;
                }
            }
        }

        $transformNames = array_keys($names);
        sort($transformNames, SORT_STRING);

        return $transformNames;
    }

    /**
     * @param array<string, mixed> $transformDefinition
     * @return int[]
     */
    private function collectEmptyEnabledBreakpoints(array $transformDefinition): array
    {
        $includeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
        $breakpoints = $this->resolveBreakpointsForTransform($includeEscapeWidth);

        if ($breakpoints === []) {
            return [];
        }

        $rawEntries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
            ? array_values($transformDefinition['transforms'])
            : [];
        $entries = Support::normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

        $emptyBreakpoints = [];
        foreach ($breakpoints as $index => $breakpoint) {
            $entry = $entries[$index];

            if (($entry['enabled'] ?? true) !== true) {
                continue;
            }

            $width = $entry['width'] ?? null;
            $height = $entry['height'] ?? null;

            // Warn only for truly empty enabled breakpoints, not partial values.
            $isEmpty = $width === null && $height === null;

            if ($isEmpty) {
                $emptyBreakpoints[] = (int)$breakpoint;
            }
        }

        $emptyBreakpoints = array_values(array_unique($emptyBreakpoints));
        sort($emptyBreakpoints, SORT_NUMERIC);

        return $emptyBreakpoints;
    }

    /**
     * @return int[]
     */
    private function resolveBreakpointsForTransform(bool $includeEscapeWidth): array
    {
        $values = [];
        foreach ($this->configService->getBreakpointWidths($includeEscapeWidth) as $breakpoint) {
            $normalized = Support::normalizeNullablePositiveInt($breakpoint);
            if ($normalized !== null) {
                $values[] = $normalized;
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_NUMERIC);

        return $values;
    }
}
