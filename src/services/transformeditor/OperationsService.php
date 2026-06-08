<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use Throwable;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\BreakpointPolicy;
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
    private const NOTES_MAX_LENGTH = 4000;

    private BreakpointCatalog $breakpointCatalog;

    public function __construct(
        private readonly TransformStore $transformStore,
        ConfigService $configService,
        private readonly TelemetryService $telemetry,
        private readonly BreakpointPolicy $breakpointPolicy,
        private readonly ?SnapshotReader $snapshotReader = null,
    ) {
        $this->breakpointCatalog = new BreakpointCatalog($configService, $breakpointPolicy);
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

        ['sets' => $sets, 'set' => $setDefinition, 'includeEscapeWidth' => $resolvedIncludeEscapeWidth] =
            $this->loadOrInitSet($transformName, $includeEscapeWidth);

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

            if (array_key_exists('error', $targetResolution)) {
                Support::addGlobalError($validation, $targetResolution['error']);

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointKey = self::breakpointKeyFromResolution($targetResolution);
            $variants[$breakpointKey] = $applyDimensionValue(self::getOrInitVariant($variants, $breakpointKey));
        } else {
            $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($resolvedIncludeEscapeWidth);
            foreach ($definitions as $definition) {
                $breakpointKey = $definition['key'];
                $currentEntry = self::getOrInitVariant($variants, $breakpointKey);

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

        ['sets' => $sets, 'set' => $setDefinition, 'includeEscapeWidth' => $resolvedIncludeEscapeWidth] =
            $this->loadOrInitSet($transformName, $includeEscapeWidth);

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

            if (array_key_exists('error', $targetResolution)) {
                Support::addGlobalError($validation, $targetResolution['error']);

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointKey = self::breakpointKeyFromResolution($targetResolution);
            $currentEntry = self::getOrInitVariant($variants, $breakpointKey);

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
                $currentEntry = self::getOrInitVariant($variants, $breakpointKey);

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

        ['set' => $setDefinition, 'includeEscapeWidth' => $resolvedIncludeEscapeWidth] =
            $this->loadOrInitSet($transformName, $includeEscapeWidth);

        if ($scopeMode === 'all') {
            return $this->applySetToggleAutoDimensionForAll(
                $transformName,
                $setDefinition,
                $autoDimension,
                $assetKey,
                $resolvedIncludeEscapeWidth,
                $validation,
                $expectedVersion,
            );
        }

        $isAutoEnabledForScope = false;
        $targetResolution = null;
        if ($scopeMode === 'breakpoint') {
            $targetResolution = $this->breakpointCatalog->resolveOperationTargetOrReject(
                $scopeBreakpointKey,
                $scopeBreakpoint,
                $resolvedIncludeEscapeWidth,
            );

            if (array_key_exists('error', $targetResolution)) {
                Support::addGlobalError($validation, $targetResolution['error']);

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            if ($targetResolution !== null) {
                $breakpointKey = self::breakpointKeyFromResolution($targetResolution);
                $variants = $setDefinition['variants'] ?? [];
                $entry = self::getOrInitVariant($variants, $breakpointKey);
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
            $restoredValue = $this->resolveRenderedDimensionFromServer(
                $transformName,
                $scopeBreakpointWidth,
                $autoDimension,
                $assetKey,
                $breakpointKey ?? $scopeBreakpointKey,
            );

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

    /**
     * @param array<string, mixed> $setDefinition
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function applySetToggleAutoDimensionForAll(
        string $transformName,
        array $setDefinition,
        string $autoDimension,
        ?string $assetKey,
        bool $resolvedIncludeEscapeWidth,
        array $validation,
        ?string $expectedVersion,
    ): array {
        $sets = $this->transformStore->getSets();
        $variants = $setDefinition['variants'] ?? [];
        $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($resolvedIncludeEscapeWidth);
        $enabledDefinitions = [];

        foreach ($definitions as $definition) {
            $breakpointKey = (string)$definition['key'];
            $entry = self::getOrInitVariant($variants, $breakpointKey);
            if (($entry['enabled'] ?? true) !== true) {
                continue;
            }

            $enabledDefinitions[] = $definition;
        }

        if ($enabledDefinitions === []) {
            return [
                'persisted' => true,
                'conflict' => false,
                'currentVersion' => $this->transformStore->getCurrentVersion(),
                'validation' => $validation,
                'operationDetails' => [
                    'appliedBreakpoints' => [],
                    'skippedBreakpoints' => [],
                ],
            ];
        }

        $allEnabledAlreadyAuto = true;
        foreach ($enabledDefinitions as $definition) {
            $breakpointKey = (string)$definition['key'];
            $entry = self::getOrInitVariant($variants, $breakpointKey);
            if (Support::normalizeAutoDimension($entry['autoDimension'] ?? null) !== $autoDimension) {
                $allEnabledAlreadyAuto = false;
                break;
            }
        }

        $appliedBreakpoints = [];
        $skippedBreakpoints = [];

        if (!$allEnabledAlreadyAuto) {
            foreach ($enabledDefinitions as $definition) {
                $breakpointKey = (string)$definition['key'];
                $currentEntry = self::getOrInitVariant($variants, $breakpointKey);
                $currentEntry['autoDimension'] = $autoDimension;
                $currentEntry[$autoDimension] = null;
                $currentEntry['ratioLocked'] = false;
                $variants[$breakpointKey] = $currentEntry;
                $appliedBreakpoints[] = (int)$definition['width'];
            }
        } else {
            foreach ($enabledDefinitions as $definition) {
                $breakpointKey = (string)$definition['key'];
                $breakpointWidth = (int)$definition['width'];
                $currentEntry = self::getOrInitVariant($variants, $breakpointKey);
                $rendered = $this->resolveRenderedDimensionFromServer(
                    $transformName,
                    $breakpointWidth,
                    $autoDimension,
                    $assetKey,
                    $breakpointKey,
                );

                if ($rendered === null) {
                    $skippedBreakpoints[] = [
                        'breakpoint' => $breakpointWidth,
                        'reason' => 'rendered_dimension_missing',
                    ];
                    continue;
                }

                $currentEntry[$autoDimension] = $rendered;
                if (Support::normalizeAutoDimension($currentEntry['autoDimension'] ?? null) === $autoDimension) {
                    $currentEntry['autoDimension'] = null;
                }
                $variants[$breakpointKey] = $currentEntry;
                $appliedBreakpoints[] = $breakpointWidth;
            }
        }

        if ($appliedBreakpoints === []) {
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

    private function resolveRenderedDimensionFromServer(
        string $transformName,
        int $scopeBreakpoint,
        string $dimension,
        ?string $assetKey = null,
        ?string $scopeBreakpointKey = null,
    ): ?int
    {
        if ($this->snapshotReader === null) {
            return null;
        }

        $resolved = $this->snapshotReader->resolveRenderedWidthHeightByBreakpoint(
            $transformName,
            $scopeBreakpoint,
            $assetKey,
            $scopeBreakpointKey,
        );
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

        ['sets' => $sets, 'set' => $setDefinition, 'includeEscapeWidth' => $resolvedIncludeEscapeWidth] =
            $this->loadOrInitSet($transformName, $includeEscapeWidth);

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

            if (array_key_exists('error', $targetResolution)) {
                Support::addGlobalError($validation, $targetResolution['error']);

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointKey = self::breakpointKeyFromResolution($targetResolution);
            $breakpointWidth = self::breakpointWidthFromResolution($targetResolution);

            $currentEntry = self::getOrInitVariant($variants, $breakpointKey);

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

                $currentEntry = self::getOrInitVariant($variants, $breakpointKey);

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

        ['sets' => $sets, 'set' => $setDefinition, 'includeEscapeWidth' => $resolvedIncludeEscapeWidth] =
            $this->loadOrInitSet($transformName, $includeEscapeWidth);

        $targetResolution = $this->breakpointCatalog->resolveOperationTargetOrReject(
            $scopeBreakpointKey,
            $scopeBreakpoint,
            $resolvedIncludeEscapeWidth,
        );

        if (array_key_exists('error', $targetResolution)) {
            Support::addGlobalError($validation, $targetResolution['error']);

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $breakpointKey = self::breakpointKeyFromResolution($targetResolution);
        $variants = $setDefinition['variants'] ?? [];

        $currentEntry = self::getOrInitVariant($variants, $breakpointKey);

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
    public function applySetNotesOperation(
        string $transformName,
        mixed $value,
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

        $notes = $this->normalizeNotes((string)$value);
        if (strlen($notes) > self::NOTES_MAX_LENGTH) {
            Support::addGlobalError($validation, sprintf('Notes must be %d characters or fewer.', self::NOTES_MAX_LENGTH));

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        ['sets' => $sets, 'set' => $setDefinition, 'includeEscapeWidth' => $resolvedIncludeEscapeWidth] =
            $this->loadOrInitSet($transformName, $includeEscapeWidth);

        $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($resolvedIncludeEscapeWidth);
        $variants = $setDefinition['variants'] ?? [];

        foreach ($definitions as $definition) {
            $breakpointKey = $definition['key'];
            if (!isset($variants[$breakpointKey])) {
                $variants[$breakpointKey] = self::getOrInitVariant($variants, $breakpointKey);
            }
        }

        $setDefinition['variants'] = $variants;
        $setDefinition['notes'] = $notes;
        $setDefinition['name'] = (string)($setDefinition['name'] ?? $transformName);

        $sets[$transformName] = $setDefinition;

        $result = $this->persistOperationSets($sets, $validation, $expectedVersion);
        $result['notes'] = $notes;

        return $result;
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

        ['sets' => $sets, 'set' => $setDefinition, 'includeEscapeWidth' => $resolvedIncludeEscapeWidth] =
            $this->loadOrInitSet($transformName, $includeEscapeWidth);

        $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($resolvedIncludeEscapeWidth);
        $variants = $setDefinition['variants'] ?? [];

        foreach ($definitions as $definition) {
            $breakpointKey = $definition['key'];
            if (!isset($variants[$breakpointKey])) {
                $variants[$breakpointKey] = self::getOrInitVariant($variants, $breakpointKey);
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

    private function normalizeNotes(string $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    /**
     * @param array<int, int> $hiddenBreakpointSlotIds Slot ids (1-based) that the
     *        latest processing run flagged as hidden. Those breakpoints are
     *        disabled as part of applying the rendered values.
     * @return array<string, mixed>
     */
    public function applyRenderedValuesOperation(
        string $transformName,
        ?string $assetKey = null,
        ?bool $includeEscapeWidth = null,
        bool $clearAuto = false,
        ?string $expectedVersion = null,
        array $hiddenBreakpointSlotIds = [],
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

        ['sets' => $sets, 'set' => $setDefinition, 'includeEscapeWidth' => $resolvedIncludeEscapeWidth] =
            $this->loadOrInitSet($transformName, $includeEscapeWidth);

        $mutation = $this->buildRenderedValuesSetDefinition(
            $setDefinition,
            $resolvedIncludeEscapeWidth,
            $renderedRows,
            $hiddenBreakpointSlotIds,
            $clearAuto,
        );

        if (($mutation['changed'] ?? false) !== true) {
            if (($mutation['noop'] ?? false) === true) {
                return [
                    'persisted' => true,
                    'conflict' => false,
                    'currentVersion' => $this->transformStore->getCurrentVersion(),
                    'validation' => $validation,
                ];
            }

            Support::addGlobalError($validation, (string)($mutation['reason'] ?? 'No valid rendered values were provided.'));

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $setDefinition = is_array($mutation['set'] ?? null) ? $mutation['set'] : $setDefinition;
        $setDefinition['name'] = (string)($setDefinition['name'] ?? $transformName);

        $sets[$transformName] = $setDefinition;

        return $this->persistOperationSets($sets, $validation, $expectedVersion);
    }

    /**
     * @param array<int, array<string, mixed>> $requestedSets
     * @return array<string, mixed>
     */
    public function autoApplyRenderedValuesForNewSets(array $requestedSets, ?string $expectedVersion = null): array
    {
        $validation = Support::defaultValidation();
        $sets = $this->transformStore->getSets();
        $skipped = [];
        $appliedCount = 0;
        $seenNames = [];

        foreach ($requestedSets as $requestedSet) {
            if (!is_array($requestedSet)) {
                $skipped[] = ['name' => '', 'reason' => 'invalid_descriptor'];
                continue;
            }

            $transformName = Support::parseNullableNonEmptyString($requestedSet['name'] ?? null) ?? '';
            if ($transformName === '') {
                $skipped[] = ['name' => '', 'reason' => 'invalid_name'];
                continue;
            }

            if (isset($seenNames[$transformName])) {
                $skipped[] = ['name' => $transformName, 'reason' => 'duplicate'];
                continue;
            }
            $seenNames[$transformName] = true;

            if (isset($sets[$transformName]) && is_array($sets[$transformName])) {
                $skipped[] = ['name' => $transformName, 'reason' => 'already_saved'];
                continue;
            }

            $assetKey = Support::parseNullableNonEmptyString($requestedSet['selectedAssetKey'] ?? null);
            $metadata = $this->snapshotReader?->resolveTransformMetadata($transformName);
            $includeEscapeWidth = ($metadata['includeEscapeWidth'] ?? null) === true;
            $hiddenSlotIds = $this->snapshotReader?->resolveHiddenSlotIdsForTransform($transformName, $assetKey) ?? [];
            $renderedRows = $this->snapshotReader?->resolveRenderedRowsForTransform($transformName, $assetKey) ?? [];
            if ($renderedRows === []) {
                $skipped[] = ['name' => $transformName, 'reason' => 'no_rendered_evidence'];
                continue;
            }

            $setDefinition = [
                'name' => $transformName,
                'includeEscapeWidth' => $includeEscapeWidth,
                'variants' => [],
                'config' => [],
            ];

            $mutation = $this->buildRenderedValuesSetDefinition(
                $setDefinition,
                $includeEscapeWidth,
                $renderedRows,
                $hiddenSlotIds,
                false,
            );

            if (($mutation['changed'] ?? false) !== true || !is_array($mutation['set'] ?? null)) {
                $skipped[] = [
                    'name' => $transformName,
                    'reason' => (string)($mutation['reasonCode'] ?? 'no_valid_rendered_values'),
                ];
                continue;
            }

            $sets[$transformName] = $mutation['set'];
            $appliedCount += 1;
        }

        if ($appliedCount < 1) {
            return [
                'persisted' => true,
                'conflict' => false,
                'currentVersion' => $this->transformStore->getCurrentVersion(),
                'appliedCount' => 0,
                'skippedCount' => count($skipped),
                'skipped' => $skipped,
                'validation' => $validation,
            ];
        }

        $result = $this->persistOperationSets($sets, $validation, $expectedVersion);
        $result['appliedCount'] = (($result['persisted'] ?? false) === true) ? $appliedCount : 0;
        $result['skippedCount'] = count($skipped);
        $result['skipped'] = $skipped;

        return $result;
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
            $this->telemetry->deleteObservedUsageByTransformHandle($transformName);
        }

        return $result;
    }

    /**
     * @return array{sets: array<string, mixed>, set: array<string, mixed>, includeEscapeWidth: bool}
     */
    private function loadOrInitSet(string $transformName, ?bool $includeEscapeWidth): array
    {
        $sets = $this->transformStore->getSets();
        if (isset($sets[$transformName]) && is_array($sets[$transformName])) {
            $set = $sets[$transformName];
            $resolved = $this->resolveIncludeEscapeWidthForSet($set);
        } else {
            $resolved = $includeEscapeWidth === true;
            $set = [
                'name' => $transformName,
                'includeEscapeWidth' => $resolved,
                'variants' => [],
                'config' => [],
            ];
        }

        return ['sets' => $sets, 'set' => $set, 'includeEscapeWidth' => $resolved];
    }

    /**
     * @param array<string, mixed> $setDefinition
     * @param array<int, mixed> $renderedRows
     * @param array<int, int> $hiddenBreakpointSlotIds
     * @return array{changed: bool, noop?: bool, set?: array<string, mixed>, reason?: string, reasonCode?: string}
     */
    private function buildRenderedValuesSetDefinition(
        array $setDefinition,
        bool $includeEscapeWidth,
        array $renderedRows,
        array $hiddenBreakpointSlotIds,
        bool $clearAuto,
    ): array {
        $variants = isset($setDefinition['variants']) && is_array($setDefinition['variants'])
            ? $setDefinition['variants']
            : [];
        $definitions = $this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($includeEscapeWidth);

        $breakpointKeysByWidth = [];
        foreach ($definitions as $definition) {
            $breakpointKeysByWidth[(string)$definition['width']][] = $definition['key'];
        }

        // Slots that processing flagged as hidden are disabled when rendered values
        // are applied, mirroring the "not visible" eye badge in the review.
        $disabledHiddenCount = $this->disableHiddenBreakpointVariants(
            $variants,
            $definitions,
            $hiddenBreakpointSlotIds,
        );

        if ($clearAuto) {
            $renderedRowsByKey = [];
            $renderedRowsByWidth = [];
            foreach ($renderedRows as $renderedRow) {
                if (!is_array($renderedRow)) {
                    continue;
                }
                $slotKey = Support::parseNullableNonEmptyString($renderedRow['slotKey'] ?? null);
                if ($slotKey !== null) {
                    $renderedRowsByKey[$slotKey] = $renderedRow;
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

                $currentEntry = self::getOrInitVariant($variants, $breakpointKey);

                $autoDimension = Support::normalizeAutoDimension($currentEntry['autoDimension'] ?? null);
                if ($autoDimension === null) {
                    continue;
                }

                $renderedRow = $renderedRowsByKey[(string)$breakpointKey] ?? ($renderedRowsByWidth[(string)$breakpointWidth] ?? null);

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

            if ($appliedCount < 1 && $disabledHiddenCount < 1) {
                return [
                    'changed' => false,
                    'noop' => true,
                    'reasonCode' => 'nothing_to_apply',
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

                $renderedSlotKey = Support::parseNullableNonEmptyString($renderedRow['slotKey'] ?? null);
                $breakpointWidth = Support::normalizeNullablePositiveInt($renderedRow['breakpoint'] ?? null);
                if ($renderedSlotKey === null && $breakpointWidth === null) {
                    continue;
                }

                $breakpointKeys = $renderedSlotKey !== null
                    ? [$renderedSlotKey]
                    : ($breakpointKeysByWidth[(string)$breakpointWidth] ?? []);
                if ($breakpointKeys === []) {
                    continue;
                }

                foreach ($breakpointKeys as $breakpointKey) {
                    $currentEntry = self::getOrInitVariant($variants, (string)$breakpointKey);

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
                        $variants[(string)$breakpointKey] = $currentEntry;
                        $appliedCount += 1;
                    }
                }
            }

            if ($appliedCount < 1 && $disabledHiddenCount < 1) {
                if ($candidateDimensionCount > 0 && $candidateDimensionCount === $autoSkippedDimensionCount) {
                    return [
                        'changed' => false,
                        'noop' => true,
                        'reasonCode' => 'auto_dimensions_only',
                    ];
                }

                return [
                    'changed' => false,
                    'reason' => 'No valid rendered values were provided.',
                    'reasonCode' => 'no_valid_rendered_values',
                ];
            }
        }

        $setDefinition['variants'] = $variants;

        return [
            'changed' => true,
            'set' => $setDefinition,
        ];
    }

    /**
     * Disable the variants for breakpoints that processing flagged as hidden.
     *
     * @param array<string, mixed> $variants Mutated in place.
     * @param array<int, array<string, mixed>> $definitions Breakpoint catalog definitions (carry `index`/`key`).
     * @param array<int, int> $hiddenBreakpointSlotIds Slot ids (1-based) flagged as hidden.
     * @return int Number of breakpoints newly disabled.
     */
    private function disableHiddenBreakpointVariants(
        array &$variants,
        array $definitions,
        array $hiddenBreakpointSlotIds,
    ): int {
        if ($hiddenBreakpointSlotIds === []) {
            return 0;
        }

        $hiddenSlotIdSet = [];
        foreach ($hiddenBreakpointSlotIds as $slotId) {
            $normalized = Support::normalizeNullablePositiveInt($slotId);
            if ($normalized !== null) {
                $hiddenSlotIdSet[$normalized] = true;
            }
        }
        if ($hiddenSlotIdSet === []) {
            return 0;
        }

        $disabledCount = 0;
        foreach ($definitions as $definition) {
            $index = $definition['index'] ?? null;
            if (!is_int($index)) {
                continue;
            }

            $slotId = $index + 1;
            if (!isset($hiddenSlotIdSet[$slotId])) {
                continue;
            }

            $breakpointKey = (string)$definition['key'];
            $entry = self::getOrInitVariant($variants, $breakpointKey);
            if (($entry['enabled'] ?? true) === false) {
                continue;
            }

            $entry['enabled'] = false;
            $variants[$breakpointKey] = $entry;
            $disabledCount += 1;
        }

        return $disabledCount;
    }

    /**
     * @param array<string, mixed> $variants
     * @return array<string, mixed>
     */
    private static function getOrInitVariant(array $variants, string $key): array
    {
        return isset($variants[$key]) && is_array($variants[$key])
            ? $variants[$key]
            : Support::buildDefaultTransformEntry();
    }

    /**
     * @param array<string, mixed> $sets
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function persistOperationSets(array $sets, array $validation, ?string $expectedVersion): array
    {
        $sets = $this->normalizeCanonicalVariantsForSets($sets);
        $resolvedExpectedVersion = $expectedVersion ?? $this->transformStore->getCurrentVersion();
        try {
            $persistResult = $this->transformStore->persistSets($sets, $resolvedExpectedVersion);
        } catch (Throwable $exception) {
            Plugin::error('Transform operation persistence threw: ' . $this->formatPersistLogContext($sets, $validation, $resolvedExpectedVersion, [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]));

            throw $exception;
        }

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
     * @param array<string, mixed> $sets
     * @param array<string, mixed> $validation
     * @param array<string, mixed> $extra
     */
    private function formatPersistLogContext(array $sets, array $validation, string $expectedVersion, array $extra = []): string
    {
        $encoded = json_encode(array_merge([
            'expectedVersion' => $expectedVersion,
            'setNames' => array_values(array_filter(array_map(
                static fn(mixed $setName): string => is_string($setName) ? $setName : '',
                array_keys($sets),
            ), static fn(string $setName): bool => $setName !== '')),
            'validation' => $this->summarizeValidationForLog($validation),
        ], $extra), JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '[unencodable context]';
    }

    /**
     * @param array<string, mixed> $validation
     * @return array<string, mixed>
     */
    private function summarizeValidationForLog(array $validation): array
    {
        return array_filter([
            'hasErrors' => ($validation['hasErrors'] ?? false) === true,
            'global' => $this->stringListForLog($validation['global'] ?? null),
        ], static fn(mixed $value): bool => $value !== [] && $value !== false);
    }

    /**
     * @return array<int, string>
     */
    private function stringListForLog(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $item): string => trim((string)$item),
            $value,
        ), static fn(string $item): bool => $item !== ''));
    }

    /**
     * Ensure persisted sets keep exactly the canonical variant shape: `base`
     * plus every configured breakpoint name.
     *
     * @param array<string, mixed> $sets
     * @return array<string, mixed>
     */
    private function normalizeCanonicalVariantsForSets(array $sets): array
    {
        foreach ($sets as $setName => $setDefinition) {
            if (!is_string($setName) || !is_array($setDefinition)) {
                continue;
            }

            $includeEscapeWidth = $this->resolveIncludeEscapeWidthForSet($setDefinition);
            $existingVariants = isset($setDefinition['variants']) && is_array($setDefinition['variants'])
                ? $setDefinition['variants']
                : [];
            $canonicalVariants = [];

            foreach ($this->breakpointCatalog->getDefinitionsForIncludeEscapeWidth($includeEscapeWidth) as $definition) {
                $key = (string)$definition['key'];
                $variant = $existingVariants[$key] ?? null;
                $canonicalVariants[$key] = is_array($variant)
                    ? $variant
                    : Support::buildDefaultTransformEntry();
            }

            $setDefinition['variants'] = $canonicalVariants;
            $sets[$setName] = $setDefinition;
        }

        return $sets;
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public function resolveRenderedRatioByBreakpoint(string $transformName, string $breakpointKey): ?array
    {
        $breakpointKey = trim($breakpointKey);
        if ($transformName === '' || $breakpointKey === '') {
            return null;
        }

        $sets = $this->transformStore->getSets();
        $setDefinition = isset($sets[$transformName]) && is_array($sets[$transformName])
            ? $sets[$transformName]
            : null;
        if ($setDefinition === null) {
            return null;
        }

        $includeEscapeWidth = $this->resolveIncludeEscapeWidthForSet($setDefinition);
        $targetResolution = $this->breakpointCatalog->resolveOperationTarget($breakpointKey, null, $includeEscapeWidth);
        if ($targetResolution === null) {
            return null;
        }

        $breakpointKey = $targetResolution['key'];
        $variants = $setDefinition['variants'] ?? [];
        $entry = self::getOrInitVariant($variants, $breakpointKey);

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

    /**
     * @param array<string, mixed> $setDefinition
     */
    private function resolveIncludeEscapeWidthForSet(array $setDefinition): bool
    {
        return $this->breakpointPolicy->resolveIncludeEscapeWidth([], $setDefinition);
    }

    /**
     * @param array<string, mixed> $targetResolution
     */
    private static function breakpointKeyFromResolution(array $targetResolution): string
    {
        return (string)($targetResolution['key'] ?? '');
    }

    /**
     * @param array<string, mixed> $targetResolution
     */
    private static function breakpointWidthFromResolution(array $targetResolution): int
    {
        return (int)($targetResolution['width'] ?? 0);
    }

}
