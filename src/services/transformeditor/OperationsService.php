<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\services\ConfigService;
use craftyhedge\craftbreakpoints\services\TelemetryService;
use craftyhedge\craftbreakpoints\services\TransformStore;

/**
 * Encapsulates all transform-set mutation operations invoked by the
 * transforms controller (dimensions, ratio, breakpoint enabled flag,
 * pass-height / allow-any-height toggles, rendered-values apply, width
 * shortcut, delete). All methods persist through TransformStore and
 * report optimistic-concurrency conflicts via the validation bag.
 */
final class OperationsService
{
    public function __construct(
        private readonly TransformStore $transformStore,
        private readonly ConfigService $configService,
        private readonly TelemetryService $telemetry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetDimensionOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $value,
        string $dimension,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        $validation = Support::defaultValidation();

        if ($dimension !== 'width' && $dimension !== 'height') {
            Support::addGlobalError($validation, 'dimension must be width or height.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($transformName === '') {
            Support::addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->transformStore->getTransforms();
        $hasExistingTransform = isset($transforms[$transformName]) && is_array($transforms[$transformName]);

        if ($hasExistingTransform) {
            $transformDefinition = $transforms[$transformName];
            $resolvedIncludeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $transformDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'transforms' => [],
                'config' => [],
            ];
        }

        $breakpoints = $this->getBreakpointsForTransform($resolvedIncludeEscapeWidth);
        $rawEntries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
            ? array_values($transformDefinition['transforms'])
            : [];

        $entries = Support::normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

        $preserveAutos = $scopeMode !== 'breakpoint';

        $applyDimensionValue = static function (array $entry) use ($dimension, $value): array {
            $hasLockedRatio = ($entry['ratioLocked'] ?? false) === true
                && Support::normalizeNullablePositiveInt($entry['ratioWidth'] ?? null) !== null
                && Support::normalizeNullablePositiveInt($entry['ratioHeight'] ?? null) !== null;

            if ($hasLockedRatio) {
                $ratioSourceDimension = $entry['ratioSourceDimension'] ?? 'width';
                if ($ratioSourceDimension === $dimension) {
                    $ratioWidth = (int)$entry['ratioWidth'];
                    $ratioHeight = (int)$entry['ratioHeight'];

                    if ($dimension === 'width' && $value !== null) {
                        $entry['height'] = max(1, (int)round(($value * $ratioHeight) / $ratioWidth));
                    }

                    if ($dimension === 'height' && $value !== null) {
                        $entry['width'] = max(1, (int)round(($value * $ratioWidth) / $ratioHeight));
                    }
                } else {
                    $entry['ratioLocked'] = false;
                }
            }

            $entry[$dimension] = $value;
            if (($entry['autoDimension'] ?? null) === $dimension) {
                $entry['autoDimension'] = null;
            }

            return $entry;
        };

        if ($scopeMode === 'breakpoint') {
            if ($scopeBreakpoint === null) {
                Support::addGlobalError($validation, 'scopeBreakpoint is required when scopeMode is breakpoint.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointIndex = array_search($scopeBreakpoint, $breakpoints, true);
            if (!is_int($breakpointIndex)) {
                Support::addGlobalError($validation, 'Selected breakpoint is not valid for the transform.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $entry = isset($entries[$breakpointIndex]) && is_array($entries[$breakpointIndex])
                ? $entries[$breakpointIndex]
                : Support::buildDefaultTransformEntry();

            $entries[$breakpointIndex] = $applyDimensionValue($entry);
        } else {
            foreach ($breakpoints as $index => $_breakpoint) {
                $entry = isset($entries[$index]) && is_array($entries[$index])
                    ? $entries[$index]
                    : Support::buildDefaultTransformEntry();

                if ($preserveAutos && ($entry['autoDimension'] ?? null) === $dimension) {
                    $entries[$index] = $entry;
                    continue;
                }

                $entries[$index] = $applyDimensionValue($entry);
            }
        }

        $transforms[$transformName] = array_merge($transformDefinition, [
            'name' => (string)($transformDefinition['name'] ?? $transformName),
            'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
            'transforms' => array_values($entries),
            'config' => isset($transformDefinition['config']) && is_array($transformDefinition['config'])
                ? $transformDefinition['config']
                : [],
        ]);

        return $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetDimensionsOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $widthValue,
        ?int $heightValue,
        ?bool $includeEscapeWidth = null,
        ?bool $widthAuto = null,
        ?bool $heightAuto = null,
        bool $forceAll = false,
        ?string $expectedVersion = null,
    ): array {
        $validation = Support::defaultValidation();

        if ($transformName === '') {
            Support::addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->transformStore->getTransforms();
        $hasExistingTransform = isset($transforms[$transformName]) && is_array($transforms[$transformName]);

        if ($hasExistingTransform) {
            $transformDefinition = $transforms[$transformName];
            $resolvedIncludeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $transformDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'transforms' => [],
                'config' => [],
            ];
        }

        $breakpoints = $this->getBreakpointsForTransform($resolvedIncludeEscapeWidth);
        $rawEntries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
            ? array_values($transformDefinition['transforms'])
            : [];

        $entries = Support::normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

        $resolvedWidthAuto = $widthAuto === true;
        $resolvedHeightAuto = $heightAuto === true && !$resolvedWidthAuto;

        $preserveAutos = $scopeMode !== 'breakpoint' && !$forceAll;

        $applyIndex = static function (int $index) use (&$entries, $widthValue, $heightValue, $resolvedWidthAuto, $resolvedHeightAuto, $preserveAutos): void {
            $entry = isset($entries[$index]) && is_array($entries[$index])
                ? $entries[$index]
                : Support::buildDefaultTransformEntry();

            $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
            $preserveWidth = $preserveAutos && $autoDimension === 'width';
            $preserveHeight = $preserveAutos && $autoDimension === 'height';

            if ($resolvedWidthAuto) {
                if (!$preserveWidth) {
                    $entry['width'] = null;
                    $entry['autoDimension'] = 'width';
                    $entry['ratioLocked'] = false;
                }
            } else {
                if (!$preserveWidth) {
                    if ($widthValue !== null || !$resolvedHeightAuto) {
                        $entry['width'] = $widthValue;
                    }
                }
                if (($entry['autoDimension'] ?? null) === 'width') {
                    if (!$preserveWidth) {
                        $entry['autoDimension'] = null;
                    }
                }
            }

            if ($resolvedHeightAuto) {
                if (!$preserveHeight) {
                    $entry['height'] = null;
                    $entry['autoDimension'] = 'height';
                    $entry['ratioLocked'] = false;
                }
            } else {
                if (!$preserveHeight) {
                    if ($heightValue !== null || !$resolvedWidthAuto) {
                        $entry['height'] = $heightValue;
                    }
                }
                if (($entry['autoDimension'] ?? null) === 'height') {
                    if (!$preserveHeight) {
                        $entry['autoDimension'] = null;
                    }
                }
            }

            $hasLockedRatio = ($entry['ratioLocked'] ?? false) === true
                && Support::normalizeNullablePositiveInt($entry['ratioWidth'] ?? null) !== null
                && Support::normalizeNullablePositiveInt($entry['ratioHeight'] ?? null) !== null;

            if ($hasLockedRatio && !$resolvedWidthAuto && !$resolvedHeightAuto) {
                $ratioSourceDimension = $entry['ratioSourceDimension'] ?? 'width';
                $ratioWidth = (int)$entry['ratioWidth'];
                $ratioHeight = (int)$entry['ratioHeight'];

                if ($ratioSourceDimension === 'width') {
                    if ($widthValue !== null) {
                        $entry['height'] = max(1, (int)round(($widthValue * $ratioHeight) / $ratioWidth));
                    } elseif ($heightValue !== null) {
                        $entry['ratioLocked'] = false;
                    }
                } else {
                    if ($heightValue !== null) {
                        $entry['width'] = max(1, (int)round(($heightValue * $ratioWidth) / $ratioHeight));
                    } elseif ($widthValue !== null) {
                        $entry['ratioLocked'] = false;
                    }
                }
            }

            $entries[$index] = $entry;
        };

        if ($scopeMode === 'breakpoint') {
            if ($scopeBreakpoint === null) {
                Support::addGlobalError($validation, 'scopeBreakpoint is required when scopeMode is breakpoint.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointIndex = array_search($scopeBreakpoint, $breakpoints, true);
            if (!is_int($breakpointIndex)) {
                Support::addGlobalError($validation, 'Selected breakpoint is not valid for the transform.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $applyIndex($breakpointIndex);
        } else {
            foreach ($breakpoints as $index => $_breakpoint) {
                $applyIndex($index);
            }
        }

        $transforms[$transformName] = array_merge($transformDefinition, [
            'name' => (string)($transformDefinition['name'] ?? $transformName),
            'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
            'transforms' => array_values($entries),
            'config' => isset($transformDefinition['config']) && is_array($transformDefinition['config'])
                ? $transformDefinition['config']
                : [],
        ]);

        return $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetRatioOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $ratioWidth,
        ?int $ratioHeight,
        ?string $ratioSourceDimension,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        $validation = Support::defaultValidation();

        if ($transformName === '') {
            Support::addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($ratioWidth === null || $ratioHeight === null) {
            Support::addGlobalError($validation, 'ratioWidth and ratioHeight are required positive integers.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($ratioWidth > 100000 || $ratioHeight > 100000) {
            Support::addGlobalError($validation, 'ratioWidth and ratioHeight must be between 1 and 100000.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $sourceDimension = Support::normalizeRatioSourceDimension($ratioSourceDimension);
        if ($sourceDimension === null) {
            Support::addGlobalError($validation, 'ratioSourceDimension must be "width" or "height".');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->transformStore->getTransforms();
        $hasExistingTransform = isset($transforms[$transformName]) && is_array($transforms[$transformName]);

        if ($hasExistingTransform) {
            $transformDefinition = $transforms[$transformName];
            $resolvedIncludeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $transformDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'transforms' => [],
                'config' => [],
            ];
        }

        $breakpoints = $this->getBreakpointsForTransform($resolvedIncludeEscapeWidth);
        $rawEntries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
            ? array_values($transformDefinition['transforms'])
            : [];

        $entries = Support::normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

        $preserveAutos = $scopeMode !== 'breakpoint';
        $appliedBreakpoints = [];
        $skippedBreakpoints = [];

        $applyIndex = static function (int $index) use (&$entries, $breakpoints, $sourceDimension, $ratioWidth, $ratioHeight, $preserveAutos, &$appliedBreakpoints, &$skippedBreakpoints): bool {
            $entry = isset($entries[$index]) && is_array($entries[$index])
                ? $entries[$index]
                : Support::buildDefaultTransformEntry();

            $breakpoint = $breakpoints[$index] ?? null;
            if (!is_int($breakpoint) || $breakpoint <= 0) {
                return false;
            }

            if (($entry['enabled'] ?? true) !== true) {
                $skippedBreakpoints[] = [
                    'breakpoint' => $breakpoint,
                    'reason' => 'breakpoint_disabled',
                ];
                return false;
            }

            $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
            if ($preserveAutos && ($autoDimension === 'width' || $autoDimension === 'height')) {
                $skippedBreakpoints[] = [
                    'breakpoint' => $breakpoint,
                    'reason' => 'auto_dimension_active',
                ];
                return false;
            }

            if ($sourceDimension === 'width') {
                $sourceValue = Support::normalizeNullablePositiveInt($entry['width'] ?? null);
                if ($sourceValue === null) {
                    $skippedBreakpoints[] = [
                        'breakpoint' => $breakpoint,
                        'reason' => 'source_dimension_missing',
                    ];
                    return false;
                }

                $entry['height'] = max(1, (int)round(($sourceValue * $ratioHeight) / $ratioWidth));
                if (($entry['autoDimension'] ?? null) === 'height') {
                    $entry['autoDimension'] = null;
                }
            } else {
                $sourceValue = Support::normalizeNullablePositiveInt($entry['height'] ?? null);
                if ($sourceValue === null) {
                    $skippedBreakpoints[] = [
                        'breakpoint' => $breakpoint,
                        'reason' => 'source_dimension_missing',
                    ];
                    return false;
                }

                $entry['width'] = max(1, (int)round(($sourceValue * $ratioWidth) / $ratioHeight));
                if (($entry['autoDimension'] ?? null) === 'width') {
                    $entry['autoDimension'] = null;
                }
            }

            $entry['ratioWidth'] = $ratioWidth;
            $entry['ratioHeight'] = $ratioHeight;
            $entry['ratioSourceDimension'] = $sourceDimension;
            $entry['ratioLocked'] = true;

            $entries[$index] = $entry;
            $appliedBreakpoints[] = $breakpoint;
            return true;
        };

        $appliedCount = 0;

        if ($scopeMode === 'breakpoint') {
            if ($scopeBreakpoint === null) {
                Support::addGlobalError($validation, 'scopeBreakpoint is required when scopeMode is breakpoint.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointIndex = array_search($scopeBreakpoint, $breakpoints, true);
            if (!is_int($breakpointIndex)) {
                Support::addGlobalError($validation, 'Selected breakpoint is not valid for the transform.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            if (!$applyIndex($breakpointIndex)) {
                $skipReason = $skippedBreakpoints[0]['reason'] ?? 'source_dimension_missing';
                if ($skipReason === 'breakpoint_disabled') {
                    Support::addGlobalError($validation, 'Selected breakpoint is disabled.');
                } elseif ($skipReason === 'auto_dimension_active') {
                    Support::addGlobalError($validation, 'Ratio cannot be applied while auto dimension is active.');
                } else {
                    Support::addGlobalError($validation, 'Source dimension value is missing for the selected breakpoint.');
                }

                return [
                    'persisted' => false,
                    'validation' => $validation,
                    'operationDetails' => [
                        'appliedBreakpoints' => $appliedBreakpoints,
                        'skippedBreakpoints' => $skippedBreakpoints,
                    ],
                ];
            }

            $appliedCount = 1;
        } else {
            foreach ($breakpoints as $index => $_breakpoint) {
                if ($applyIndex($index)) {
                    $appliedCount += 1;
                }
            }

            if ($appliedCount < 1) {
                return [
                    'persisted' => true,
                    'conflict' => false,
                    'currentVersion' => $this->transformStore->getCurrentVersion(),
                    'validation' => $validation,
                    'operationDetails' => [
                        'appliedBreakpoints' => $appliedBreakpoints,
                        'skippedBreakpoints' => $skippedBreakpoints,
                    ],
                ];
            }
        }

        $transforms[$transformName] = array_merge($transformDefinition, [
            'name' => (string)($transformDefinition['name'] ?? $transformName),
            'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
            'transforms' => array_values($entries),
            'config' => isset($transformDefinition['config']) && is_array($transformDefinition['config'])
                ? $transformDefinition['config']
                : [],
        ]);

        $persistResult = $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
        $persistResult['operationDetails'] = [
            'appliedBreakpoints' => $appliedBreakpoints,
            'skippedBreakpoints' => $skippedBreakpoints,
        ];

        return $persistResult;
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetBreakpointEnabledOperation(
        string $transformName,
        ?int $scopeBreakpoint,
        ?bool $enabled,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        $validation = Support::defaultValidation();

        if ($transformName === '') {
            Support::addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($scopeBreakpoint === null) {
            Support::addGlobalError($validation, 'scopeBreakpoint is required when updating breakpoint state.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($enabled === null) {
            Support::addGlobalError($validation, 'enabled must be a boolean value.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->transformStore->getTransforms();
        $hasExistingTransform = isset($transforms[$transformName]) && is_array($transforms[$transformName]);

        if ($hasExistingTransform) {
            $transformDefinition = $transforms[$transformName];
            $resolvedIncludeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $transformDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'transforms' => [],
                'config' => [],
            ];
        }

        $breakpoints = $this->getBreakpointsForTransform($resolvedIncludeEscapeWidth);
        $breakpointIndex = array_search($scopeBreakpoint, $breakpoints, true);
        if (!is_int($breakpointIndex)) {
            Support::addGlobalError($validation, 'Selected breakpoint is not valid for the transform.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $rawEntries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
            ? array_values($transformDefinition['transforms'])
            : [];

        $entries = Support::normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

        $entry = isset($entries[$breakpointIndex]) && is_array($entries[$breakpointIndex])
            ? $entries[$breakpointIndex]
            : Support::buildDefaultTransformEntry();
        $entry['enabled'] = $enabled;
        $entries[$breakpointIndex] = $entry;

        $transforms[$transformName] = array_merge($transformDefinition, [
            'name' => (string)($transformDefinition['name'] ?? $transformName),
            'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
            'transforms' => array_values($entries),
            'config' => isset($transformDefinition['config']) && is_array($transformDefinition['config'])
                ? $transformDefinition['config']
                : [],
        ]);

        return $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetPassHeightWhenRenderedLteSavedOperation(
        string $transformName,
        mixed $value,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        return $this->applyConfigFlagOperation(
            $transformName,
            'passHeightWhenRenderedLteSaved',
            'allowAnyHeight',
            $value === true,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetAllowAnyHeightOperation(
        string $transformName,
        mixed $value,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        return $this->applyConfigFlagOperation(
            $transformName,
            'allowAnyHeight',
            'passHeightWhenRenderedLteSaved',
            $value === true,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function applyConfigFlagOperation(
        string $transformName,
        string $flag,
        string $mutuallyExclusiveFlag,
        bool $value,
        ?bool $includeEscapeWidth,
        ?string $expectedVersion,
    ): array {
        $validation = Support::defaultValidation();

        if ($transformName === '') {
            Support::addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->transformStore->getTransforms();
        $hasExistingTransform = isset($transforms[$transformName]) && is_array($transforms[$transformName]);

        if ($hasExistingTransform) {
            $transformDefinition = $transforms[$transformName];
            $resolvedIncludeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $transformDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'transforms' => [],
                'config' => [],
            ];
        }

        $config = isset($transformDefinition['config']) && is_array($transformDefinition['config'])
            ? $transformDefinition['config']
            : [];
        $config[$flag] = $value;
        if ($value === true) {
            $config[$mutuallyExclusiveFlag] = false;
        }

        $transforms[$transformName] = array_merge($transformDefinition, [
            'name' => (string)($transformDefinition['name'] ?? $transformName),
            'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
            'transforms' => isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
                ? array_values($transformDefinition['transforms'])
                : [],
            'config' => $config,
        ]);

        return $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
    }

    /**
     * @param array<int, mixed> $renderedRows
     * @return array<string, mixed>
     */
    public function applyRenderedValuesOperation(
        string $transformName,
        array $renderedRows,
        ?bool $includeEscapeWidth = null,
        bool $clearAuto = false,
        ?string $expectedVersion = null,
    ): array {
        $validation = Support::defaultValidation();

        if ($transformName === '') {
            Support::addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->transformStore->getTransforms();
        $hasExistingTransform = isset($transforms[$transformName]) && is_array($transforms[$transformName]);

        if ($hasExistingTransform) {
            $transformDefinition = $transforms[$transformName];
            $resolvedIncludeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $transformDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'transforms' => [],
                'config' => [],
            ];
        }

        $breakpoints = $this->getBreakpointsForTransform($resolvedIncludeEscapeWidth);
        $rawEntries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
            ? array_values($transformDefinition['transforms'])
            : [];

        $entries = Support::normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

        $breakpointIndexes = [];
        foreach ($breakpoints as $index => $breakpoint) {
            $breakpointIndexes[(string)$breakpoint] = $index;
        }

        if ($clearAuto) {
            $renderedRowsByBreakpoint = [];
            foreach ($renderedRows as $renderedRow) {
                if (!is_array($renderedRow)) {
                    continue;
                }
                $bp = Support::normalizeNullablePositiveInt($renderedRow['breakpoint'] ?? null);
                if ($bp !== null) {
                    $renderedRowsByBreakpoint[(string)$bp] = $renderedRow;
                }
            }

            $appliedCount = 0;
            foreach ($breakpoints as $index => $breakpoint) {
                $entry = isset($entries[$index]) && is_array($entries[$index])
                    ? $entries[$index]
                    : Support::buildDefaultTransformEntry();
                $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
                if ($autoDimension === null) {
                    continue;
                }

                $renderedRow = $renderedRowsByBreakpoint[(string)$breakpoint] ?? null;

                if ($autoDimension === 'width') {
                    $rendered = $renderedRow !== null
                        ? Support::normalizeNullablePositiveInt($renderedRow['width'] ?? null)
                        : null;
                    if ($rendered !== null) {
                        $entry['width'] = $rendered;
                    }
                } elseif ($autoDimension === 'height') {
                    $rendered = $renderedRow !== null
                        ? Support::normalizeNullablePositiveInt($renderedRow['height'] ?? null)
                        : null;
                    if ($rendered !== null) {
                        $entry['height'] = $rendered;
                    }
                }

                $entry['autoDimension'] = null;
                $entries[$index] = $entry;
                $appliedCount += 1;
            }

            if ($appliedCount < 1) {
                return [
                    'persisted' => true,
                    'conflict' => false,
                    'currentVersion' => $this->transformStore->getCurrentVersion(),
                    'validation' => $validation,
                ];
            }
        } else {
            $appliedCount = 0;
            $candidateDimensionCount = 0;
            $autoSkippedDimensionCount = 0;
            foreach ($renderedRows as $renderedRow) {
                if (!is_array($renderedRow)) {
                    continue;
                }

                $breakpoint = Support::normalizeNullablePositiveInt($renderedRow['breakpoint'] ?? null);
                if ($breakpoint === null) {
                    continue;
                }

                $index = $breakpointIndexes[(string)$breakpoint] ?? null;
                if (!is_int($index)) {
                    continue;
                }

                $entry = isset($entries[$index]) && is_array($entries[$index])
                    ? $entries[$index]
                    : Support::buildDefaultTransformEntry();
                $autoDimension = Support::normalizeAutoDimension($entry['autoDimension'] ?? null);

                $updated = false;

                $width = Support::normalizeNullablePositiveInt($renderedRow['width'] ?? null);
                if ($width !== null) {
                    $candidateDimensionCount += 1;

                    if ($autoDimension === 'width') {
                        $autoSkippedDimensionCount += 1;
                    } else {
                        $entry['width'] = $width;
                        $updated = true;
                    }
                }

                $height = Support::normalizeNullablePositiveInt($renderedRow['height'] ?? null);
                if ($height !== null) {
                    $candidateDimensionCount += 1;

                    if ($autoDimension === 'height') {
                        $autoSkippedDimensionCount += 1;
                    } else {
                        $entry['height'] = $height;
                        $updated = true;
                    }
                }

                if ($updated) {
                    $entries[$index] = $entry;
                    $appliedCount += 1;
                }
            }

            if ($appliedCount < 1) {
                if ($candidateDimensionCount > 0 && $candidateDimensionCount === $autoSkippedDimensionCount) {
                    return [
                        'persisted' => true,
                        'conflict' => false,
                        'currentVersion' => $this->transformStore->getCurrentVersion(),
                        'validation' => $validation,
                    ];
                }

                Support::addGlobalError($validation, 'No valid rendered values were provided.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }
        }

        $transforms[$transformName] = array_merge($transformDefinition, [
            'name' => (string)($transformDefinition['name'] ?? $transformName),
            'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
            'transforms' => array_values($entries),
            'config' => isset($transformDefinition['config']) && is_array($transformDefinition['config'])
                ? $transformDefinition['config']
                : [],
        ]);

        return $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetWidthOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $value,
        ?string $expectedVersion = null,
    ): array {
        return $this->applySetDimensionOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $value,
            'width',
            null,
            $expectedVersion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteSetOperation(string $transformName, ?string $expectedVersion = null): array
    {
        $validation = Support::defaultValidation();

        if ($transformName === '') {
            Support::addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->transformStore->getTransforms();
        if (!isset($transforms[$transformName]) || !is_array($transforms[$transformName])) {
            return [
                'persisted' => true,
                'conflict' => false,
                'currentVersion' => $this->transformStore->getCurrentVersion(),
                'validation' => $validation,
            ];
        }

        unset($transforms[$transformName]);
        $result = $this->persistOperationTransforms($transforms, $validation, $expectedVersion);

        if (($result['persisted'] ?? false) === true) {
            $this->telemetry->deletePreviewCacheByTransformHandle($transformName);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $transforms
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function persistOperationTransforms(array $transforms, array $validation, ?string $expectedVersion): array
    {
        $resolvedExpectedVersion = $expectedVersion ?? $this->transformStore->getCurrentVersion();
        $persistResult = $this->transformStore->persistTransforms($transforms, $resolvedExpectedVersion);
        $conflict = ($persistResult['conflict'] ?? false) === true;

        if ($conflict) {
            Support::addGlobalError($validation, 'Draft version is out of date. Reload and apply again.');
        }

        return [
            'persisted' => ($persistResult['persisted'] ?? false) === true,
            'conflict' => $conflict,
            'currentVersion' => (string)($persistResult['currentVersion'] ?? $resolvedExpectedVersion),
            'validation' => $validation,
        ];
    }

    /**
     * @return int[]
     */
    private function getBreakpointsForTransform(bool $includeEscapeWidth): array
    {
        $breakpoints = $this->configService->getBreakpoints();

        if (!$includeEscapeWidth) {
            unset($breakpoints['escape']);
        }

        return array_values(array_map(static fn(mixed $value): int => (int)$value, $breakpoints));
    }
}
