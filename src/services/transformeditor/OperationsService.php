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
 *
 * Rendered evidence is resolved server-side via SnapshotReader at apply
 * time. Client-supplied renderedRows are never used as authoritative
 * mutation inputs.
 */
final class OperationsService
{
    private BreakpointCatalog $breakpointCatalog;

    public function __construct(
        private readonly TransformStore $transformStore,
        private readonly ConfigService $configService,
        private readonly TelemetryService $telemetry,
        private readonly ?SnapshotReader $snapshotReader = null,
    ) {
        $this->breakpointCatalog = new BreakpointCatalog($configService);
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetDimensionOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?string $scopeBreakpointKey,
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

        $sets = $this->transformStore->getSets();
        $hasExistingSet = isset($sets[$transformName]) && is_array($sets[$transformName]);

        if ($hasExistingSet) {
            $setDefinition = $sets[$transformName];
            $resolvedIncludeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $setDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'variants' => [],
                'config' => [],
            ];
        }

        $variants = $setDefinition['variants'] ?? [];
        $applyDimensionValue = static function (array $currentEntry) use ($dimension, $value): array {
            $hasLockedRatio = ($currentEntry['ratioLocked'] ?? false) === true
                && Support::normalizeNullablePositiveInt($currentEntry['ratioWidth'] ?? null) !== null
                && Support::normalizeNullablePositiveInt($currentEntry['ratioHeight'] ?? null) !== null;

            if ($hasLockedRatio) {
                $ratioSourceDimension = $currentEntry['ratioSourceDimension'] ?? 'width';
                if ($ratioSourceDimension === $dimension) {
                    $ratioWidth = (int)$currentEntry['ratioWidth'];
                    $ratioHeight = (int)$currentEntry['ratioHeight'];

                    if ($dimension === 'width' && $value !== null) {
                        $currentEntry['height'] = max(1, (int)round(($value * $ratioHeight) / $ratioWidth));
                    }

                    if ($dimension === 'height' && $value !== null) {
                        $currentEntry['width'] = max(1, (int)round(($value * $ratioWidth) / $ratioHeight));
                    }
                } else {
                    $currentEntry['ratioLocked'] = false;
                }
            }

            $currentEntry[$dimension] = $value;
            if (($currentEntry['autoDimension'] ?? null) === $dimension) {
                $currentEntry['autoDimension'] = null;
            }

            return $currentEntry;
        };

        if ($scopeMode === 'breakpoint') {
            $targetResolution = $this->breakpointCatalog->resolveOperationTargetOrReject(
                $scopeBreakpointKey,
                $scopeBreakpoint,
                $resolvedIncludeEscapeWidth,
            );

            if (isset($targetResolution['error'])) {
                Support::addGlobalError($validation, $targetResolution['error']);

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointKey = $targetResolution['key'];
            $currentEntry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
                ? $variants[$breakpointKey]
                : Support::buildDefaultTransformEntry();

            $variants[$breakpointKey] = $applyDimensionValue($currentEntry);
        } else {
            $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($resolvedIncludeEscapeWidth);
            foreach ($definitions as $definition) {
                $breakpointKey = $definition['key'];
                $currentEntry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
                    ? $variants[$breakpointKey]
                    : Support::buildDefaultTransformEntry();

                if (($currentEntry['autoDimension'] ?? null) === $dimension) {
                    $variants[$breakpointKey] = $currentEntry;
                    continue;
                }

                $variants[$breakpointKey] = $applyDimensionValue($currentEntry);
            }
        }

        $setDefinition['variants'] = $variants;
        $setDefinition['name'] = (string)($setDefinition['name'] ?? $transformName);

        $sets[$transformName] = $setDefinition;

        return $this->persistOperationSets($sets, $validation, $expectedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetDimensionsOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?string $scopeBreakpointKey,
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

        $sets = $this->transformStore->getSets();
        $hasExistingSet = isset($sets[$transformName]) && is_array($sets[$transformName]);

        if ($hasExistingSet) {
            $setDefinition = $sets[$transformName];
            $resolvedIncludeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $setDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'variants' => [],
                'config' => [],
            ];
        }

        $variants = $setDefinition['variants'] ?? [];
        $resolvedWidthAuto = $widthAuto === true;
        $resolvedHeightAuto = $heightAuto === true && !$resolvedWidthAuto;
        $preserveAutos = $scopeMode !== 'breakpoint' && !$forceAll;

        if ($scopeMode === 'breakpoint') {
            $targetResolution = $this->breakpointCatalog->resolveOperationTargetOrReject(
                $scopeBreakpointKey,
                $scopeBreakpoint,
                $resolvedIncludeEscapeWidth,
            );

            if (isset($targetResolution['error'])) {
                Support::addGlobalError($validation, $targetResolution['error']);

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointKey = $targetResolution['key'];
            $currentEntry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
                ? $variants[$breakpointKey]
                : Support::buildDefaultTransformEntry();

            $autoDimension = Support::normalizeAutoDimension($currentEntry['autoDimension'] ?? null);
            $preserveWidth = $preserveAutos && $autoDimension === 'width';
            $preserveHeight = $preserveAutos && $autoDimension === 'height';

            if ($resolvedWidthAuto) {
                if (!$preserveWidth) {
                    $currentEntry['width'] = null;
                    $currentEntry['autoDimension'] = 'width';
                    $currentEntry['ratioLocked'] = false;
                }
            } else {
                if (!$preserveWidth) {
                    if ($widthValue !== null || !$resolvedHeightAuto) {
                        $currentEntry['width'] = $widthValue;
                    }
                }
                if (($currentEntry['autoDimension'] ?? null) === 'width') {
                    if (!$preserveWidth) {
                        $currentEntry['autoDimension'] = null;
                    }
                }
            }

            if ($resolvedHeightAuto) {
                if (!$preserveHeight) {
                    $currentEntry['height'] = null;
                    $currentEntry['autoDimension'] = 'height';
                    $currentEntry['ratioLocked'] = false;
                }
            } else {
                if (!$preserveHeight) {
                    if ($heightValue !== null || !$resolvedWidthAuto) {
                        $currentEntry['height'] = $heightValue;
                    }
                }
                if (($currentEntry['autoDimension'] ?? null) === 'height') {
                    if (!$preserveHeight) {
                        $currentEntry['autoDimension'] = null;
                    }
                }
            }

            $hasLockedRatio = ($currentEntry['ratioLocked'] ?? false) === true
                && Support::normalizeNullablePositiveInt($currentEntry['ratioWidth'] ?? null) !== null
                && Support::normalizeNullablePositiveInt($currentEntry['ratioHeight'] ?? null) !== null;

            if ($hasLockedRatio && !$resolvedWidthAuto && !$resolvedHeightAuto) {
                $ratioSourceDimension = $currentEntry['ratioSourceDimension'] ?? 'width';
                $ratioWidth = (int)$currentEntry['ratioWidth'];
                $ratioHeight = (int)$currentEntry['ratioHeight'];

                if ($ratioSourceDimension === 'width') {
                    if ($widthValue !== null) {
                        $currentEntry['height'] = max(1, (int)round(($widthValue * $ratioHeight) / $ratioWidth));
                    } elseif ($heightValue !== null) {
                        $currentEntry['ratioLocked'] = false;
                    }
                } else {
                    if ($heightValue !== null) {
                        $currentEntry['width'] = max(1, (int)round(($heightValue * $ratioWidth) / $ratioHeight));
                    } elseif ($widthValue !== null) {
                        $currentEntry['ratioLocked'] = false;
                    }
                }
            }

            $variants[$breakpointKey] = $currentEntry;
        } else {
            $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($resolvedIncludeEscapeWidth);
            foreach ($definitions as $definition) {
                $breakpointKey = $definition['key'];
                $currentEntry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
                    ? $variants[$breakpointKey]
                    : Support::buildDefaultTransformEntry();

                $autoDimension = Support::normalizeAutoDimension($currentEntry['autoDimension'] ?? null);
                $preserveWidth = $preserveAutos && $autoDimension === 'width';
                $preserveHeight = $preserveAutos && $autoDimension === 'height';

                if ($resolvedWidthAuto) {
                    if (!$preserveWidth) {
                        $currentEntry['width'] = null;
                        $currentEntry['autoDimension'] = 'width';
                        $currentEntry['ratioLocked'] = false;
                    }
                } else {
                    if (!$preserveWidth) {
                        if ($widthValue !== null || !$resolvedHeightAuto) {
                            $currentEntry['width'] = $widthValue;
                        }
                    }
                    if (($currentEntry['autoDimension'] ?? null) === 'width') {
                        if (!$preserveWidth) {
                            $currentEntry['autoDimension'] = null;
                        }
                    }
                }

                if ($resolvedHeightAuto) {
                    if (!$preserveHeight) {
                        $currentEntry['height'] = null;
                        $currentEntry['autoDimension'] = 'height';
                        $currentEntry['ratioLocked'] = false;
                    }
                } else {
                    if (!$preserveHeight) {
                        if ($heightValue !== null || !$resolvedWidthAuto) {
                            $currentEntry['height'] = $heightValue;
                        }
                    }
                    if (($currentEntry['autoDimension'] ?? null) === 'height') {
                        if (!$preserveHeight) {
                            $currentEntry['autoDimension'] = null;
                        }
                    }
                }

                $hasLockedRatio = ($currentEntry['ratioLocked'] ?? false) === true
                    && Support::normalizeNullablePositiveInt($currentEntry['ratioWidth'] ?? null) !== null
                    && Support::normalizeNullablePositiveInt($currentEntry['ratioHeight'] ?? null) !== null;

                if ($hasLockedRatio && !$resolvedWidthAuto && !$resolvedHeightAuto) {
                    $ratioSourceDimension = $currentEntry['ratioSourceDimension'] ?? 'width';
                    $ratioWidth = (int)$currentEntry['ratioWidth'];
                    $ratioHeight = (int)$currentEntry['ratioHeight'];

                    if ($ratioSourceDimension === 'width') {
                        if ($widthValue !== null) {
                            $currentEntry['height'] = max(1, (int)round(($widthValue * $ratioHeight) / $ratioWidth));
                        } elseif ($heightValue !== null) {
                            $currentEntry['ratioLocked'] = false;
                        }
                    } else {
                        if ($heightValue !== null) {
                            $currentEntry['width'] = max(1, (int)round(($heightValue * $ratioWidth) / $ratioHeight));
                        } elseif ($widthValue !== null) {
                            $currentEntry['ratioLocked'] = false;
                        }
                    }
                }

                $variants[$breakpointKey] = $currentEntry;
            }
        }

        $setDefinition['variants'] = $variants;
        $setDefinition['name'] = (string)($setDefinition['name'] ?? $transformName);

        $sets[$transformName] = $setDefinition;

        return $this->persistOperationSets($sets, $validation, $expectedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetToggleAutoWidthOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?string $scopeBreakpointKey,
        ?int $heightValue,
        ?string $assetKey = null,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        return $this->applySetToggleAutoDimensionOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            'width',
            $heightValue,
            $assetKey,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetToggleAutoHeightOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?string $scopeBreakpointKey,
        ?int $widthValue,
        ?string $assetKey = null,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        return $this->applySetToggleAutoDimensionOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            'height',
            $widthValue,
            $assetKey,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function applySetToggleAutoDimensionOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?string $scopeBreakpointKey,
        string $autoDimension,
        ?int $companionValue,
        ?string $assetKey,
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

        if ($autoDimension !== 'width' && $autoDimension !== 'height') {
            Support::addGlobalError($validation, 'autoDimension must be width or height.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $sets = $this->transformStore->getSets();
        $hasExistingSet = isset($sets[$transformName]) && is_array($sets[$transformName]);

        if ($hasExistingSet) {
            $setDefinition = $sets[$transformName];
            $resolvedIncludeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $setDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'variants' => [],
                'config' => [],
            ];
        }

        $isAutoEnabledForScope = false;
        $targetResolution = null;
        if ($scopeMode === 'breakpoint') {
            $targetResolution = $this->breakpointCatalog->resolveOperationTargetOrReject(
                $scopeBreakpointKey,
                $scopeBreakpoint,
                $resolvedIncludeEscapeWidth,
            );

            if (isset($targetResolution['error'])) {
                Support::addGlobalError($validation, $targetResolution['error']);

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            if ($targetResolution !== null) {
                $breakpointKey = $targetResolution['key'];
                $variants = $setDefinition['variants'] ?? [];
                $entry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
                    ? $variants[$breakpointKey]
                    : Support::buildDefaultTransformEntry();
                $isAutoEnabledForScope = Support::normalizeAutoDimension($entry['autoDimension'] ?? null) === $autoDimension;
            }
        }

        if ($isAutoEnabledForScope) {
            if ($scopeMode === 'all') {
                return $this->applyRenderedValuesOperation(
                    $transformName,
                    $assetKey,
                    $includeEscapeWidth,
                    true,
                    $expectedVersion,
                );
            }

            $scopeBreakpointWidth = is_array($targetResolution) && isset($targetResolution['width'])
                ? (int)$targetResolution['width']
                : (int)$scopeBreakpoint;
            $restoredValue = $this->resolveRenderedDimensionFromServer($transformName, $scopeBreakpointWidth, $autoDimension, $assetKey);

            if ($autoDimension === 'width') {
                return $this->applySetDimensionsOperation(
                    $transformName,
                    'breakpoint',
                    $scopeBreakpointWidth,
                    $scopeBreakpointKey,
                    $restoredValue,
                    $companionValue,
                    $includeEscapeWidth,
                    false,
                    false,
                    false,
                    $expectedVersion,
                );
            }

            return $this->applySetDimensionsOperation(
                $transformName,
                'breakpoint',
                $scopeBreakpointWidth,
                $scopeBreakpointKey,
                $companionValue,
                $restoredValue,
                $includeEscapeWidth,
                false,
                false,
                false,
                $expectedVersion,
            );
        }

        $forceAll = $scopeMode === 'all';

        if ($autoDimension === 'width') {
            return $this->applySetDimensionsOperation(
                $transformName,
                $scopeMode,
                $scopeBreakpoint,
                $scopeBreakpointKey,
                null,
                $companionValue,
                $includeEscapeWidth,
                true,
                false,
                $forceAll,
                $expectedVersion,
            );
        }

        return $this->applySetDimensionsOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            $companionValue,
            null,
            $includeEscapeWidth,
            false,
            true,
            $forceAll,
            $expectedVersion,
        );
    }

    private function resolveRenderedDimensionFromServer(string $transformName, int $scopeBreakpoint, string $dimension, ?string $assetKey = null): ?int
    {
        if ($this->snapshotReader === null) {
            return null;
        }

        $resolved = $this->snapshotReader->resolveRenderedWidthHeightByBreakpoint($transformName, $scopeBreakpoint, $assetKey);
        if ($resolved === null) {
            return null;
        }

        if ($dimension === 'width') {
            return $resolved['renderedWidth'] > 0 ? $resolved['renderedWidth'] : null;
        }

        return $resolved['renderedHeight'] > 0 ? $resolved['renderedHeight'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetRatioOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?string $scopeBreakpointKey,
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

        $sets = $this->transformStore->getSets();
        $hasExistingSet = isset($sets[$transformName]) && is_array($sets[$transformName]);

        if ($hasExistingSet) {
            $setDefinition = $sets[$transformName];
            $resolvedIncludeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $setDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'variants' => [],
                'config' => [],
            ];
        }

        $variants = $setDefinition['variants'] ?? [];
        $preserveAutos = $scopeMode !== 'breakpoint';
        $appliedBreakpoints = [];
        $skippedBreakpoints = [];

        if ($scopeMode === 'breakpoint') {
            $targetResolution = $this->breakpointCatalog->resolveOperationTargetOrReject(
                $scopeBreakpointKey,
                $scopeBreakpoint,
                $resolvedIncludeEscapeWidth,
            );

            if (isset($targetResolution['error'])) {
                Support::addGlobalError($validation, $targetResolution['error']);

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointKey = $targetResolution['key'];
            $breakpointWidth = $targetResolution['width'];

            $currentEntry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
                ? $variants[$breakpointKey]
                : Support::buildDefaultTransformEntry();

            if (($currentEntry['enabled'] ?? true) !== true) {
                Support::addGlobalError($validation, 'Selected breakpoint is disabled.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $autoDimension = Support::normalizeAutoDimension($currentEntry['autoDimension'] ?? null);
            if ($preserveAutos && ($autoDimension === 'width' || $autoDimension === 'height')) {
                Support::addGlobalError($validation, 'Ratio cannot be applied while auto dimension is active.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            if ($sourceDimension === 'width') {
                $sourceValue = Support::normalizeNullablePositiveInt($currentEntry['width'] ?? null);
                if ($sourceValue === null) {
                    Support::addGlobalError($validation, 'Source dimension value is missing for the selected breakpoint.');

                    return [
                        'persisted' => false,
                        'validation' => $validation,
                    ];
                }

                $currentEntry['height'] = max(1, (int)round(($sourceValue * $ratioHeight) / $ratioWidth));
                if (($currentEntry['autoDimension'] ?? null) === 'height') {
                    $currentEntry['autoDimension'] = null;
                }
            } else {
                $sourceValue = Support::normalizeNullablePositiveInt($currentEntry['height'] ?? null);
                if ($sourceValue === null) {
                    Support::addGlobalError($validation, 'Source dimension value is missing for the selected breakpoint.');

                    return [
                        'persisted' => false,
                        'validation' => $validation,
                    ];
                }

                $currentEntry['width'] = max(1, (int)round(($sourceValue * $ratioWidth) / $ratioHeight));
                if (($currentEntry['autoDimension'] ?? null) === 'width') {
                    $currentEntry['autoDimension'] = null;
                }
            }

            $currentEntry['ratioWidth'] = $ratioWidth;
            $currentEntry['ratioHeight'] = $ratioHeight;
            $currentEntry['ratioSourceDimension'] = $sourceDimension;
            $currentEntry['ratioLocked'] = true;

            $variants[$breakpointKey] = $currentEntry;
            $appliedBreakpoints[] = $breakpointWidth;
        } else {
            $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($resolvedIncludeEscapeWidth);
            foreach ($definitions as $definition) {
                $breakpointKey = $definition['key'];
                $breakpointWidth = $definition['width'];

                $currentEntry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
                    ? $variants[$breakpointKey]
                    : Support::buildDefaultTransformEntry();

                if (($currentEntry['enabled'] ?? true) !== true) {
                    $skippedBreakpoints[] = [
                        'breakpoint' => $breakpointWidth,
                        'reason' => 'breakpoint_disabled',
                    ];
                    continue;
                }

                $autoDimension = Support::normalizeAutoDimension($currentEntry['autoDimension'] ?? null);
                if ($preserveAutos && ($autoDimension === 'width' || $autoDimension === 'height')) {
                    $skippedBreakpoints[] = [
                        'breakpoint' => $breakpointWidth,
                        'reason' => 'auto_dimension_active',
                    ];
                    continue;
                }

                if ($sourceDimension === 'width') {
                    $sourceValue = Support::normalizeNullablePositiveInt($currentEntry['width'] ?? null);
                    if ($sourceValue === null) {
                        $skippedBreakpoints[] = [
                            'breakpoint' => $breakpointWidth,
                            'reason' => 'source_dimension_missing',
                        ];
                        continue;
                    }

                    $currentEntry['height'] = max(1, (int)round(($sourceValue * $ratioHeight) / $ratioWidth));
                    if (($currentEntry['autoDimension'] ?? null) === 'height') {
                        $currentEntry['autoDimension'] = null;
                    }
                } else {
                    $sourceValue = Support::normalizeNullablePositiveInt($currentEntry['height'] ?? null);
                    if ($sourceValue === null) {
                        $skippedBreakpoints[] = [
                            'breakpoint' => $breakpointWidth,
                            'reason' => 'source_dimension_missing',
                        ];
                        continue;
                    }

                    $currentEntry['width'] = max(1, (int)round(($sourceValue * $ratioWidth) / $ratioHeight));
                    if (($currentEntry['autoDimension'] ?? null) === 'width') {
                        $currentEntry['autoDimension'] = null;
                    }
                }

                $currentEntry['ratioWidth'] = $ratioWidth;
                $currentEntry['ratioHeight'] = $ratioHeight;
                $currentEntry['ratioSourceDimension'] = $sourceDimension;
                $currentEntry['ratioLocked'] = true;

                $variants[$breakpointKey] = $currentEntry;
                $appliedBreakpoints[] = $breakpointWidth;
            }

            if (count($appliedBreakpoints) < 1) {
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

        $setDefinition['variants'] = $variants;
        $setDefinition['name'] = (string)($setDefinition['name'] ?? $transformName);

        $sets[$transformName] = $setDefinition;

        $persistResult = $this->persistOperationSets($sets, $validation, $expectedVersion);
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
        ?string $scopeBreakpointKey,
        ?bool $enabled,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
        bool $enabledProvided = true,
    ): array {
        $validation = Support::defaultValidation();

        if ($transformName === '') {
            Support::addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($enabledProvided && $enabled === null) {
            Support::addGlobalError($validation, 'enabled must be a boolean value.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $sets = $this->transformStore->getSets();
        $hasExistingSet = isset($sets[$transformName]) && is_array($sets[$transformName]);

        if ($hasExistingSet) {
            $setDefinition = $sets[$transformName];
            $resolvedIncludeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $setDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'variants' => [],
                'config' => [],
            ];
        }

        $targetResolution = $this->breakpointCatalog->resolveOperationTargetOrReject(
            $scopeBreakpointKey,
            $scopeBreakpoint,
            $resolvedIncludeEscapeWidth,
        );

        if (isset($targetResolution['error'])) {
            Support::addGlobalError($validation, $targetResolution['error']);

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $breakpointKey = $targetResolution['key'];
        $variants = $setDefinition['variants'] ?? [];

        $currentEntry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
            ? $variants[$breakpointKey]
            : Support::buildDefaultTransformEntry();

        if (!$enabledProvided) {
            $enabled = (($currentEntry['enabled'] ?? true) === true) ? false : true;
        }

        $currentEntry['enabled'] = $enabled;
        $variants[$breakpointKey] = $currentEntry;

        $setDefinition['variants'] = $variants;
        $setDefinition['name'] = (string)($setDefinition['name'] ?? $transformName);

        $sets[$transformName] = $setDefinition;

        return $this->persistOperationSets($sets, $validation, $expectedVersion);
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

        $sets = $this->transformStore->getSets();
        $hasExistingSet = isset($sets[$transformName]) && is_array($sets[$transformName]);

        if ($hasExistingSet) {
            $setDefinition = $sets[$transformName];
            $resolvedIncludeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $setDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'variants' => [],
                'config' => [],
            ];
        }

        $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($resolvedIncludeEscapeWidth);
        $variants = $setDefinition['variants'] ?? [];

        foreach ($definitions as $definition) {
            $breakpointKey = $definition['key'];
            if (!isset($variants[$breakpointKey])) {
                $variants[$breakpointKey] = Support::buildDefaultTransformEntry();
            }
        }

        $config = $setDefinition['config'] ?? [];
        $config[$flag] = $value;
        if ($value === true) {
            $config[$mutuallyExclusiveFlag] = false;
        }

        $setDefinition['variants'] = $variants;
        $setDefinition['config'] = $config;
        $setDefinition['name'] = (string)($setDefinition['name'] ?? $transformName);

        $sets[$transformName] = $setDefinition;

        return $this->persistOperationSets($sets, $validation, $expectedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function applyRenderedValuesOperation(
        string $transformName,
        ?string $assetKey = null,
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

        $renderedRows = $this->snapshotReader?->resolveRenderedRowsForTransform($transformName, $assetKey) ?? [];

        if ($renderedRows === []) {
            Support::addGlobalError($validation, 'No rendered evidence found for this breakpoint. If a processing run exists, refresh the review panel.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $sets = $this->transformStore->getSets();
        $hasExistingSet = isset($sets[$transformName]) && is_array($sets[$transformName]);

        if ($hasExistingSet) {
            $setDefinition = $sets[$transformName];
            $resolvedIncludeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
        } else {
            $resolvedIncludeEscapeWidth = $includeEscapeWidth === true;
            $setDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
                'variants' => [],
                'config' => [],
            ];
        }

        $variants = $setDefinition['variants'] ?? [];
        $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($resolvedIncludeEscapeWidth);

        if ($this->hasDuplicateDefinitionWidths($definitions)) {
            Support::addGlobalError($validation, 'Ambiguous breakpoint: multiple breakpoints have the same width.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $breakpointKeyByWidth = [];
        foreach ($definitions as $definition) {
            $breakpointKeyByWidth[(string)$definition['width']] = $definition['key'];
        }

        if ($clearAuto) {
            $renderedRowsByWidth = [];
            foreach ($renderedRows as $renderedRow) {
                if (!is_array($renderedRow)) {
                    continue;
                }
                $bp = Support::normalizeNullablePositiveInt($renderedRow['breakpoint'] ?? null);
                if ($bp !== null) {
                    $renderedRowsByWidth[(string)$bp] = $renderedRow;
                }
            }

            $appliedCount = 0;
            foreach ($definitions as $definition) {
                $breakpointKey = $definition['key'];
                $breakpointWidth = $definition['width'];

                $currentEntry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
                    ? $variants[$breakpointKey]
                    : Support::buildDefaultTransformEntry();

                $autoDimension = Support::normalizeAutoDimension($currentEntry['autoDimension'] ?? null);
                if ($autoDimension === null) {
                    continue;
                }

                $renderedRow = $renderedRowsByWidth[(string)$breakpointWidth] ?? null;

                if ($autoDimension === 'width') {
                    $rendered = $renderedRow !== null
                        ? Support::normalizeNullablePositiveInt($renderedRow['width'] ?? null)
                        : null;
                    if ($rendered !== null) {
                        $currentEntry['width'] = $rendered;
                    }
                } elseif ($autoDimension === 'height') {
                    $rendered = $renderedRow !== null
                        ? Support::normalizeNullablePositiveInt($renderedRow['height'] ?? null)
                        : null;
                    if ($rendered !== null) {
                        $currentEntry['height'] = $rendered;
                    }
                }

                $currentEntry['autoDimension'] = null;
                $variants[$breakpointKey] = $currentEntry;
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

                $breakpointWidth = Support::normalizeNullablePositiveInt($renderedRow['breakpoint'] ?? null);
                if ($breakpointWidth === null) {
                    continue;
                }

                $breakpointKey = $breakpointKeyByWidth[(string)$breakpointWidth] ?? null;
                if ($breakpointKey === null) {
                    continue;
                }

                $currentEntry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
                    ? $variants[$breakpointKey]
                    : Support::buildDefaultTransformEntry();

                $autoDimension = Support::normalizeAutoDimension($currentEntry['autoDimension'] ?? null);

                $updated = false;

                $width = Support::normalizeNullablePositiveInt($renderedRow['width'] ?? null);
                if ($width !== null) {
                    $candidateDimensionCount += 1;

                    if ($autoDimension === 'width') {
                        $autoSkippedDimensionCount += 1;
                    } else {
                        $currentEntry['width'] = $width;
                        $updated = true;
                    }
                }

                $height = Support::normalizeNullablePositiveInt($renderedRow['height'] ?? null);
                if ($height !== null) {
                    $candidateDimensionCount += 1;

                    if ($autoDimension === 'height') {
                        $autoSkippedDimensionCount += 1;
                    } else {
                        $currentEntry['height'] = $height;
                        $updated = true;
                    }
                }

                if ($updated) {
                    $variants[$breakpointKey] = $currentEntry;
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

        $setDefinition['variants'] = $variants;
        $setDefinition['name'] = (string)($setDefinition['name'] ?? $transformName);

        $sets[$transformName] = $setDefinition;

        return $this->persistOperationSets($sets, $validation, $expectedVersion);
    }

    /**
     * @return array<string, mixed>
     */
    public function applySetWidthOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?string $scopeBreakpointKey,
        ?int $value,
        ?string $expectedVersion = null,
    ): array {
        return $this->applySetDimensionOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
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

        $sets = $this->transformStore->getSets();
        if (!isset($sets[$transformName]) || !is_array($sets[$transformName])) {
            return [
                'persisted' => true,
                'conflict' => false,
                'currentVersion' => $this->transformStore->getCurrentVersion(),
                'validation' => $validation,
            ];
        }

        unset($sets[$transformName]);
        $result = $this->persistOperationSets($sets, $validation, $expectedVersion);

        if (($result['persisted'] ?? false) === true) {
            $this->telemetry->deletePreviewCacheByTransformHandle($transformName);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $sets
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function persistOperationSets(array $sets, array $validation, ?string $expectedVersion): array
    {
        $resolvedExpectedVersion = $expectedVersion ?? $this->transformStore->getCurrentVersion();
        $persistResult = $this->transformStore->persistSets($sets, $resolvedExpectedVersion);
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
     * @param array<int, array{key: string, width: int, isEscape: bool}> $definitions
     */
    private function hasDuplicateDefinitionWidths(array $definitions): bool
    {
        $widthCounts = [];
        foreach ($definitions as $definition) {
            $width = (string)$definition['width'];
            $widthCounts[$width] = ($widthCounts[$width] ?? 0) + 1;
            if ($widthCounts[$width] > 1) {
                return true;
            }
        }

        return false;
    }

    private function resolveBreakpointAutoDimension(string $transformName, int $scopeBreakpoint, ?bool $includeEscapeWidth): ?string
    {
        $sets = $this->transformStore->getSets();
        $setDefinition = isset($sets[$transformName]) && is_array($sets[$transformName])
            ? $sets[$transformName]
            : null;
        if ($setDefinition === null) {
            return null;
        }

        $resolvedIncludeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true
            || ($includeEscapeWidth === true && !isset($setDefinition['includeEscapeWidth']));

        $targetResolution = $this->breakpointCatalog->resolveOperationTarget(
            null,
            $scopeBreakpoint,
            $resolvedIncludeEscapeWidth,
        );

        if ($targetResolution === null) {
            return null;
        }

        $breakpointKey = $targetResolution['key'];
        $variants = $setDefinition['variants'] ?? [];
        $entry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
            ? $variants[$breakpointKey]
            : Support::buildDefaultTransformEntry();

        return Support::normalizeAutoDimension($entry['autoDimension'] ?? null);
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public function resolveRenderedRatioByBreakpoint(string $transformName, int $breakpoint): ?array
    {
        if ($transformName === '' || $breakpoint <= 0) {
            return null;
        }

        $sets = $this->transformStore->getSets();
        $setDefinition = isset($sets[$transformName]) && is_array($sets[$transformName])
            ? $sets[$transformName]
            : null;
        if ($setDefinition === null) {
            return null;
        }

        $includeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
        $targetResolution = $this->breakpointCatalog->resolveOperationTarget(null, $breakpoint, $includeEscapeWidth);
        if ($targetResolution === null) {
            return null;
        }

        $breakpointKey = $targetResolution['key'];
        $variants = $setDefinition['variants'] ?? [];
        $entry = isset($variants[$breakpointKey]) && is_array($variants[$breakpointKey])
            ? $variants[$breakpointKey]
            : Support::buildDefaultTransformEntry();

        $ratioLocked = ($entry['ratioLocked'] ?? false) === true;
        $ratioWidth = Support::normalizeNullablePositiveInt($entry['ratioWidth'] ?? null);
        $ratioHeight = Support::normalizeNullablePositiveInt($entry['ratioHeight'] ?? null);

        if ($ratioLocked && $ratioWidth !== null && $ratioHeight !== null && $ratioWidth > 0 && $ratioHeight > 0) {
            return ['width' => $ratioWidth, 'height' => $ratioHeight];
        }

        $width = Support::normalizeNullablePositiveInt($entry['width'] ?? null);
        $height = Support::normalizeNullablePositiveInt($entry['height'] ?? null);
        if ($width !== null && $height !== null && $width > 0 && $height > 0) {
            $gcd = Support::greatestCommonDivisor($width, $height);
            return ['width' => (int)round($width / $gcd), 'height' => (int)round($height / $gcd)];
        }

        return null;
    }

}
