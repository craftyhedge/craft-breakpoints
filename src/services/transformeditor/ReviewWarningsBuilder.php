<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\helpers\IncludeStack;
use craftyhedge\craftbreakpoints\services\BreakpointPolicy;
use craftyhedge\craftbreakpoints\services\ConfigService;
use craftyhedge\craftbreakpoints\services\TelemetryService;

/**
 * Builds per-transform review warnings (missing set definitions, empty enabled
 * breakpoints, multiple include origins) and provides warning metadata used
 * for rendering (class, label).
 *
 * Depends on SnapshotReader for latest run entry data, ConfigService for
 * configured breakpoints, and TelemetryService for edit-permission decisions
 * on warning message text.
 */
final class ReviewWarningsBuilder
{
    public const MISSING_SET_CAN_EDIT_MESSAGE = 'No transforms are saved for this set. Process the entry to capture rendered dimensions, or edit the transforms.';
    public const MISSING_SET_READ_ONLY_MESSAGE = 'No transforms are saved for this set. Process in a development environment.';
    public const MULTIPLE_INCLUDE_ORIGINS_CODE = 'multiple-include-origins';

    /**
     * Advisory warnings are shown on the card but do not make it red or count
     * towards the run's warning total.
     */
    public const ADVISORY_CODES = [self::MULTIPLE_INCLUDE_ORIGINS_CODE];

    public function __construct(
        private readonly SnapshotReader $snapshotReader,
        private readonly ConfigService $configService,
        private readonly TelemetryService $telemetry,
        private readonly BreakpointPolicy $breakpointPolicy,
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
        $renderedTransformNames = self::collectTransformNames($rowsByBreakpoint);
        $renderedTransformSet = array_fill_keys($renderedTransformNames, true);
        $configTransformNames = array_keys($storedTransforms);

        foreach ($storedTransforms as $transformName => $transformDefinition) {
            if (!is_string($transformName) || $transformName === '' || !is_array($transformDefinition)) {
                continue;
            }

            if (!isset($renderedTransformSet[$transformName])) {
                continue;
            }

            $emptyBreakpoints = $this->collectEmptyEnabledBreakpoints($transformDefinition);
            if ($emptyBreakpoints !== []) {
                $warningsByTransform[$transformName][] = $this->buildEmptyEnabledBreakpointsWarning($emptyBreakpoints);
            }
        }

        $missingDefinitions = array_values(array_diff($renderedTransformNames, $configTransformNames));
        if ($missingDefinitions !== []) {
            $snapshot = $this->snapshotReader->getLatestRunSnapshot();
            $runEntryData = $this->snapshotReader->resolveRunEntryData($snapshot);
            $entryId = is_array($runEntryData) ? (int)($runEntryData['id'] ?? 0) : 0;
            $entryAvailable = is_array($runEntryData)
                && $entryId > 0
                && (string)($runEntryData['cpEditUrl'] ?? '') !== '#';
            $entryMissing = $entryId > 0 && !$entryAvailable;

            foreach ($missingDefinitions as $transformName) {
                $warningsByTransform[$transformName][] = $this->buildMissingSetDefinitionWarning(
                    $entryId,
                    $entryAvailable,
                    $entryMissing,
                );
            }
        }

        foreach (self::collectIncludeStacksByTransform($rowsByBreakpoint) as $transformName => $stacks) {
            if (count($stacks) < 2) {
                continue;
            }

            $warningsByTransform[$transformName][] = self::buildMultipleIncludeOriginsWarning(
                self::resolveDivergentOrigins($stacks),
            );
        }

        return $warningsByTransform;
    }

    /**
     * @param string[] $origins
     * @return array<string, mixed>
     */
    public static function buildMultipleIncludeOriginsWarning(array $origins): array
    {
        $origins = array_values(array_unique(array_filter(
            $origins,
            static fn(string $origin): bool => $origin !== '',
        )));

        return [
            'code' => self::MULTIPLE_INCLUDE_ORIGINS_CODE,
            'message' => '',
            'origins' => $origins,
            'hint' => 'If they render at different sizes, each may need its own set.',
        ];
    }

    /**
     * Names each stack by its level at the first index where not all stacks
     * agree. A shorter stack that has run out uses its last level. Levels are
     * named by template, or described with their parents when the templates
     * are the same and only the layouts differ.
     *
     * @param array<int, array<int, mixed>> $stacks Root → leaf include levels (or bare template names).
     * @return string[]
     */
    public static function resolveDivergentOrigins(array $stacks): array
    {
        $stacks = array_values(array_filter(
            array_map(static fn(array $stack): array => IncludeStack::normalize($stack), $stacks),
            static fn(array $stack): bool => $stack !== [],
        ));
        if (count($stacks) < 2) {
            return [];
        }

        $maxDepth = max(array_map('count', $stacks));
        $divergeIndex = null;
        for ($index = 0; $index < $maxDepth; $index++) {
            $keys = array_map(
                static fn(array $stack): string => isset($stack[$index]) ? IncludeStack::encode([$stack[$index]]) : '',
                $stacks,
            );
            if (count(array_unique($keys)) > 1) {
                $divergeIndex = $index;
                break;
            }
        }

        if ($divergeIndex === null) {
            return [];
        }

        $levels = array_map(
            static fn(array $stack): array => $stack[$divergeIndex] ?? $stack[count($stack) - 1],
            $stacks,
        );
        $templates = array_map(static fn(array $level): string => $level['template'], $levels);
        $describe = count(array_unique($templates)) < count(array_unique(array_map(
            static fn(array $level): string => IncludeStack::encode([$level]),
            $levels,
        )));

        $origins = array_map(
            static fn(array $level): string => $describe ? IncludeStack::describeLevel($level) : $level['template'],
            $levels,
        );

        return array_values(array_unique($origins));
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
            ? self::MISSING_SET_CAN_EDIT_MESSAGE
            : self::MISSING_SET_READ_ONLY_MESSAGE;

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

            foreach ($warnings as $warning) {
                if (!self::isAdvisoryWarning($warning)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @param array<int, mixed> $warnings
     */
    public static function hasNonAdvisoryWarning(array $warnings): bool
    {
        foreach ($warnings as $warning) {
            if (!self::isAdvisoryWarning($warning)) {
                return true;
            }
        }

        return false;
    }

    public static function isAdvisoryWarning(mixed $warning): bool
    {
        return is_array($warning)
            && in_array((string)($warning['code'] ?? ''), self::ADVISORY_CODES, true);
    }

    /**
     * Distinct non-empty include stacks per rendered set. Rows without a stack
     * (PHP `craft.images.render()` callers, injected markup) are unknown and
     * never count as an origin.
     *
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @return array<string, array<int, array<int, array<string, mixed>>>>
     */
    private static function collectIncludeStacksByTransform(array $rowsByBreakpoint): array
    {
        $stacksByTransform = [];
        foreach ($rowsByBreakpoint as $rows) {
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $name = (string)($row['transform'] ?? '');
                $stack = IncludeStack::normalize($row['includeStack'] ?? []);
                if ($name === '' || $name === 'unknown' || $stack === []) {
                    continue;
                }

                $stacksByTransform[$name][IncludeStack::encode($stack)] = $stack;
            }
        }

        ksort($stacksByTransform, SORT_STRING);

        return array_map(static fn(array $stacks): array => array_values($stacks), $stacksByTransform);
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
        $includeEscapeWidth = $this->breakpointPolicy->resolveIncludeEscapeWidth([], $transformDefinition);
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
