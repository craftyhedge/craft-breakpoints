<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

/**
 * Assembles the full UI state array for a transform editor card.
 *
 * Takes raw persisted rows keyed by breakpoint width, the ordered list of active
 * breakpoints, and raw scope/tab values from the client, then produces a normalised
 * state snapshot consumed by the card template. Responsibilities include: validating
 * and defaulting the scope (all vs. single breakpoint), resolving the active tab
 * (falling back from ratio when auto-dimension is set), computing per-breakpoint row
 * state (inputs, auto flags, ratio lock, display strings), and extracting the
 * scope-specific values surfaced in the editor controls.
 */
final class CardStateBuilder
{
    /**
     * @param array<int, array<string, mixed>> $currentRowsByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array{scope: array{mode: string, breakpoint: ?int}, tab: string, rowsByBreakpoint: array<string, array<string, mixed>>, scopeValues: array<string, string>, firstBreakpoint: ?int, initSeedAppliedAny: bool}
     */
    public function build(
        array $currentRowsByBreakpoint,
        array $transformBreakpoints,
        mixed $rawScope,
        mixed $rawTab,
    ): array {
        $scope = $this->normalizeScope($rawScope, $transformBreakpoints);
        $rowsByBreakpoint = $this->buildRowsByBreakpointState($currentRowsByBreakpoint, $transformBreakpoints);
        $scopeValues = $this->resolveScopeValues($rowsByBreakpoint, $scope);
        $tab = $this->normalizeTab($rawTab, $scopeValues);

        $initSeedAppliedAny = false;
        foreach ($rowsByBreakpoint as $row) {
            if (($row['initSeedApplied'] ?? false) === true) {
                $initSeedAppliedAny = true;
                break;
            }
        }

        return [
            'scope' => $scope,
            'tab' => $tab,
            'rowsByBreakpoint' => $rowsByBreakpoint,
            'scopeValues' => $scopeValues,
            'firstBreakpoint' => $transformBreakpoints[0] ?? null,
            'initSeedAppliedAny' => $initSeedAppliedAny,
        ];
    }

    /**
     * @param array<int, int> $transformBreakpoints
     * @return array{mode: string, breakpoint: ?int}
     */
    private function normalizeScope(mixed $rawScope, array $transformBreakpoints): array
    {
        if (is_array($rawScope)) {
            $mode = strtolower(trim((string)($rawScope['mode'] ?? '')));
            if ($mode === 'all') {
                return ['mode' => 'all', 'breakpoint' => null];
            }

            if ($mode === 'breakpoint') {
                $breakpoint = Support::normalizeNullablePositiveInt($rawScope['breakpoint'] ?? null);
                if ($breakpoint !== null && in_array($breakpoint, $transformBreakpoints, true)) {
                    return ['mode' => 'breakpoint', 'breakpoint' => $breakpoint];
                }
            }
        }

        $firstBreakpoint = $transformBreakpoints[0] ?? null;
        if ($firstBreakpoint !== null) {
            return ['mode' => 'breakpoint', 'breakpoint' => $firstBreakpoint];
        }

        return ['mode' => 'all', 'breakpoint' => null];
    }

    /**
     * @param array<string, string> $scopeValues
     */
    private function normalizeTab(mixed $rawTab, array $scopeValues): string
    {
        $tab = is_string($rawTab) ? strtolower(trim($rawTab)) : '';
        $normalizedTab = in_array($tab, ['dimensions', 'ratio', 'settings', 'notes'], true) ? $tab : 'dimensions';

        $ratioBlockedByAuto = ($scopeValues['widthAuto'] ?? '0') === '1'
            || ($scopeValues['heightAuto'] ?? '0') === '1';

        if ($normalizedTab === 'ratio' && $ratioBlockedByAuto) {
            return 'dimensions';
        }

        return $normalizedTab;
    }

    /**
     * @param array<int, array<string, mixed>> $currentRowsByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array<string, array<string, mixed>>
     */
    private function buildRowsByBreakpointState(array $currentRowsByBreakpoint, array $transformBreakpoints): array
    {
        $rows = [];

        foreach ($transformBreakpoints as $breakpoint) {
            $entry = isset($currentRowsByBreakpoint[$breakpoint]) && is_array($currentRowsByBreakpoint[$breakpoint])
                ? $currentRowsByBreakpoint[$breakpoint]
                : Support::buildDefaultTransformEntry();

            $rows[(string)$breakpoint] = $this->buildRowState($entry);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function buildRowState(array $entry): array
    {
        $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
        $widthValue = Support::normalizeNullablePositiveInt($entry['width'] ?? null);
        $heightValue = Support::normalizeNullablePositiveInt($entry['height'] ?? null);
        $ratioWidthValue = Support::normalizeNullablePositiveInt($entry['ratioWidth'] ?? null);
        $ratioHeightValue = Support::normalizeNullablePositiveInt($entry['ratioHeight'] ?? null);
        $ratioSourceDimension = Support::normalizeRatioSourceDimension($entry['ratioSourceDimension'] ?? null) ?? 'width';

        $ratioLocked = ($entry['ratioLocked'] ?? false) === true
            && $ratioWidthValue !== null
            && $ratioHeightValue !== null;

        $widthAuto = $autoDimension === 'width';
        $heightAuto = $autoDimension === 'height';
        $enabled = ($entry['enabled'] ?? true) === true;

        $fallbackRatioWidth = $widthAuto || $widthValue === null ? '' : (string)$widthValue;
        $fallbackRatioHeight = $heightAuto || $heightValue === null ? '' : (string)$heightValue;
        $resolvedRatioWidth = $ratioLocked ? (string)$ratioWidthValue : $fallbackRatioWidth;
        $resolvedRatioHeight = $ratioLocked ? (string)$ratioHeightValue : $fallbackRatioHeight;

        if (!$enabled) {
            return [
                'widthInput' => '',
                'heightInput' => '',
                'widthAuto' => '0',
                'heightAuto' => '0',
                'ratioLocked' => '0',
                'ratioWidthInput' => '',
                'ratioHeightInput' => '',
                'ratioFloatInput' => '',
                'ratioSourceDimension' => 'width',
                'enabled' => false,
                'autoDimension' => '',
                'currentWidthDisplay' => '-',
                'currentHeightDisplay' => '-',
                'ratioOverlayText' => '',
                'initSeedApplied' => ($entry['initSeedApplied'] ?? false) === true,
            ];
        }

        return [
            'widthInput' => $widthAuto || $widthValue === null ? '' : (string)$widthValue,
            'heightInput' => $heightAuto || $heightValue === null ? '' : (string)$heightValue,
            'widthAuto' => $widthAuto ? '1' : '0',
            'heightAuto' => $heightAuto ? '1' : '0',
            'ratioLocked' => $ratioLocked ? '1' : '0',
            'ratioWidthInput' => $resolvedRatioWidth,
            'ratioHeightInput' => $resolvedRatioHeight,
            'ratioFloatInput' => Support::formatRatioFloatInput(
                Support::normalizeNullablePositiveInt($resolvedRatioWidth),
                Support::normalizeNullablePositiveInt($resolvedRatioHeight),
            ),
            'ratioSourceDimension' => $ratioLocked ? $ratioSourceDimension : 'width',
            'enabled' => true,
            'autoDimension' => $autoDimension ?? '',
            'currentWidthDisplay' => $widthAuto ? 'auto' : ($widthValue !== null ? (string)$widthValue : '-'),
            'currentHeightDisplay' => $heightAuto ? 'auto' : ($heightValue !== null ? (string)$heightValue : '-'),
            'ratioOverlayText' => $ratioLocked ? ($resolvedRatioWidth . ':' . $resolvedRatioHeight) : '',
            'initSeedApplied' => ($entry['initSeedApplied'] ?? false) === true,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $rowsByBreakpoint
     * @param array{mode: string, breakpoint: ?int} $scope
     * @return array<string, string>
     */
    private function resolveScopeValues(array $rowsByBreakpoint, array $scope): array
    {
        if (($scope['mode'] ?? 'all') === 'all') {
            $values = $this->emptyScopeValues();
            $values['widthAuto'] = $this->allEnabledRowsUseAutoDimension($rowsByBreakpoint, 'width') ? '1' : '0';
            $values['heightAuto'] = $this->allEnabledRowsUseAutoDimension($rowsByBreakpoint, 'height') ? '1' : '0';
            $sharedRatio = $this->resolveSharedEnabledRowsRatio($rowsByBreakpoint);
            if ($sharedRatio !== null) {
                $values = array_merge($values, $sharedRatio);
            }

            return $values;
        }

        if (($scope['mode'] ?? 'all') !== 'breakpoint') {
            return $this->emptyScopeValues();
        }

        $breakpoint = Support::normalizeNullablePositiveInt($scope['breakpoint'] ?? null);
        if ($breakpoint === null) {
            return $this->emptyScopeValues();
        }

        $breakpointKey = (string)$breakpoint;
        $row = is_array($rowsByBreakpoint[$breakpointKey] ?? null)
            ? $rowsByBreakpoint[$breakpointKey]
            : null;
        if ($row === null) {
            return $this->emptyScopeValues();
        }

        return [
            'widthInput' => (string)($row['widthInput'] ?? ''),
            'heightInput' => (string)($row['heightInput'] ?? ''),
            'widthAuto' => (string)($row['widthAuto'] ?? '0'),
            'heightAuto' => (string)($row['heightAuto'] ?? '0'),
            'ratioLocked' => (string)($row['ratioLocked'] ?? '0'),
            'ratioWidthInput' => (string)($row['ratioWidthInput'] ?? ''),
            'ratioHeightInput' => (string)($row['ratioHeightInput'] ?? ''),
            'ratioFloatInput' => (string)($row['ratioFloatInput'] ?? ''),
            'ratioSourceDimension' => (string)($row['ratioSourceDimension'] ?? 'width'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function emptyScopeValues(): array
    {
        return [
            'widthInput' => '',
            'heightInput' => '',
            'widthAuto' => '0',
            'heightAuto' => '0',
            'ratioLocked' => '0',
            'ratioWidthInput' => '',
            'ratioHeightInput' => '',
            'ratioFloatInput' => '',
            'ratioSourceDimension' => 'width',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $rowsByBreakpoint
     */
    private function allEnabledRowsUseAutoDimension(array $rowsByBreakpoint, string $dimension): bool
    {
        $enabledCount = 0;

        foreach ($rowsByBreakpoint as $row) {
            if (($row['enabled'] ?? true) !== true) {
                continue;
            }

            $enabledCount += 1;
            if (($row['autoDimension'] ?? '') !== $dimension) {
                return false;
            }
        }

        return $enabledCount > 0;
    }

    /**
     * @param array<string, array<string, mixed>> $rowsByBreakpoint
     * @return array<string, string>|null
     */
    private function resolveSharedEnabledRowsRatio(array $rowsByBreakpoint): ?array
    {
        $sharedRatio = null;
        $enabledCount = 0;

        foreach ($rowsByBreakpoint as $row) {
            if (($row['enabled'] ?? true) !== true) {
                continue;
            }

            $enabledCount += 1;
            if (($row['ratioLocked'] ?? '0') !== '1') {
                return null;
            }

            $ratioWidth = (string)($row['ratioWidthInput'] ?? '');
            $ratioHeight = (string)($row['ratioHeightInput'] ?? '');
            $ratioSourceDimension = (string)($row['ratioSourceDimension'] ?? 'width');
            if ($ratioWidth === '' || $ratioHeight === '') {
                return null;
            }

            $candidate = [
                'ratioLocked' => '1',
                'ratioWidthInput' => $ratioWidth,
                'ratioHeightInput' => $ratioHeight,
                'ratioFloatInput' => (string)($row['ratioFloatInput'] ?? ''),
                'ratioSourceDimension' => $ratioSourceDimension,
            ];

            if ($sharedRatio === null) {
                $sharedRatio = $candidate;
                continue;
            }

            if (
                $sharedRatio['ratioWidthInput'] !== $candidate['ratioWidthInput']
                || $sharedRatio['ratioHeightInput'] !== $candidate['ratioHeightInput']
                || $sharedRatio['ratioSourceDimension'] !== $candidate['ratioSourceDimension']
            ) {
                return null;
            }
        }

        return $enabledCount > 0 ? $sharedRatio : null;
    }
}
