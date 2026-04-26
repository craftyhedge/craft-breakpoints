<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

final class CardStateBuilder
{
    /**
     * @param array<int, array<string, mixed>> $currentRowsByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @param array<string, mixed> $draftByBreakpoint
     * @return array{scope: array{mode: string, breakpoint: ?int}, tab: string, rowsByBreakpoint: array<string, array<string, mixed>>, scopeValues: array<string, string>, firstBreakpoint: ?int, initSeedAppliedAny: bool}
     */
    public function build(
        array $currentRowsByBreakpoint,
        array $transformBreakpoints,
        mixed $rawScope,
        mixed $rawTab,
        array $draftByBreakpoint = [],
    ): array {
        $scope = $this->normalizeScope($rawScope, $transformBreakpoints);
        $rowsByBreakpoint = $this->buildRowsByBreakpointState($currentRowsByBreakpoint, $transformBreakpoints);
        $tab = $this->normalizeTab($rawTab);
        $scopeValues = $this->resolveScopeValues($rowsByBreakpoint, $scope, $draftByBreakpoint);

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

    private function normalizeTab(mixed $rawTab): string
    {
        $tab = is_string($rawTab) ? strtolower(trim($rawTab)) : '';
        return in_array($tab, ['dimensions', 'ratio', 'settings'], true) ? $tab : 'dimensions';
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

        $fallbackRatioWidth = $widthAuto || $widthValue === null ? '' : (string)$widthValue;
        $fallbackRatioHeight = $heightAuto || $heightValue === null ? '' : (string)$heightValue;
        $resolvedRatioWidth = $ratioLocked ? (string)$ratioWidthValue : $fallbackRatioWidth;
        $resolvedRatioHeight = $ratioLocked ? (string)$ratioHeightValue : $fallbackRatioHeight;

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
            'enabled' => ($entry['enabled'] ?? true) === true,
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
     * @param array<string, mixed> $draftByBreakpoint
     * @return array<string, string>
     */
    private function resolveScopeValues(array $rowsByBreakpoint, array $scope, array $draftByBreakpoint): array
    {
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

        $draft = is_array($draftByBreakpoint[$breakpointKey] ?? null)
            ? $draftByBreakpoint[$breakpointKey]
            : null;

        if ($draft !== null) {
            $row = $this->applyDraftToRowState($row, $draft);
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
     * @param array<string, mixed> $row
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function applyDraftToRowState(array $row, array $draft): array
    {
        $autoDimension = Support::normalizeAutoDimension($draft['autoDimension'] ?? null) ?? '';
        $widthAuto = $autoDimension === 'width' ? '1' : '0';
        $heightAuto = $autoDimension === 'height' ? '1' : '0';

        $ratioWidthInput = trim((string)($draft['ratioWidthInput'] ?? $row['ratioWidthInput'] ?? ''));
        $ratioHeightInput = trim((string)($draft['ratioHeightInput'] ?? $row['ratioHeightInput'] ?? ''));
        $ratioFloatInput = trim((string)($draft['ratioFloatInput'] ?? $row['ratioFloatInput'] ?? ''));
        $ratioLocked = ((string)($draft['ratioLocked'] ?? '0')) === '1' ? '1' : '0';

        return [
            ...$row,
            'widthInput' => trim((string)($draft['widthInput'] ?? $row['widthInput'] ?? '')),
            'heightInput' => trim((string)($draft['heightInput'] ?? $row['heightInput'] ?? '')),
            'widthAuto' => $widthAuto,
            'heightAuto' => $heightAuto,
            'ratioWidthInput' => $ratioWidthInput,
            'ratioHeightInput' => $ratioHeightInput,
            'ratioFloatInput' => $ratioFloatInput,
            'ratioLocked' => $ratioLocked,
            'ratioSourceDimension' => Support::normalizeRatioSourceDimension($draft['ratioSourceDimension'] ?? null) ?? 'width',
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
}
