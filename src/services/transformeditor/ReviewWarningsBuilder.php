<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\services\TelemetryService;

/**
 * Builds per-transform review warnings (currently: missing set definitions)
 * and provides warning metadata used for rendering (class, label).
 *
 * Depends on SnapshotReader for observed entry data and TelemetryService for
 * edit-permission decisions on the warning message text.
 */
final class ReviewWarningsBuilder
{
    public function __construct(
        private readonly SnapshotReader $snapshotReader,
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
        $configTransformNames = array_keys($storedTransforms);
        sort($configTransformNames, SORT_STRING);

        $observedTransformNames = self::collectTransformNames($rowsByBreakpoint);
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
}
