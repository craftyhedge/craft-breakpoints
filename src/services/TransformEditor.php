<?php

namespace craftyhedge\craftbreakpointimages\services;

use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class TransformEditor extends Component
{
    private ?Plugin $_plugin = null;
    private array $_reviewTemplateCache = [];

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function buildDraftFromStore(): array
    {
        if ($this->_plugin === null) {
            return [
                'transforms' => [],
            ];
        }

        return [
            'transforms' => $this->buildDraftTransforms($this->_plugin->getTransformStore()->getTransforms()),
        ];
    }

    public function encodeDraftJson(array $draft): string
    {
        $encoded = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{"transforms":{}}';
    }

    public function applyDraft(array $draft): array
    {
        $validation = $this->defaultValidation();

        if ($this->_plugin === null) {
            $this->addGlobalError($validation, 'Plugin instance is not available.');

            return [
                'draft' => $draft,
                'validation' => $validation,
                'persisted' => false,
            ];
        }

        $normalizedTransforms = $this->normalizeTransformsFromDraft($draft, $validation);

        if ($validation['hasErrors'] === true) {
            return [
                'draft' => $draft,
                'validation' => $validation,
                'persisted' => false,
            ];
        }

        $persistedTransforms = $this->_plugin->getTransformStore()->persistTransforms($normalizedTransforms);

        return [
            'draft' => [
                'transforms' => $this->buildDraftTransforms($persistedTransforms),
            ],
            'validation' => $validation,
            'persisted' => true,
        ];
    }

    public function applySetDimensionOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $value,
        string $dimension,
        ?bool $includeEscapeWidth = null,
    ): array {
        $validation = $this->defaultValidation();

        if ($dimension !== 'width' && $dimension !== 'height') {
            $this->addGlobalError($validation, 'dimension must be width or height.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($this->_plugin === null) {
            $this->addGlobalError($validation, 'Plugin instance is not available.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($transformName === '') {
            $this->addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->_plugin->getTransformStore()->getTransforms();
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

        $entries = [];
        foreach ($breakpoints as $index => $_breakpoint) {
            $entry = isset($rawEntries[$index]) && is_array($rawEntries[$index])
                ? $rawEntries[$index]
                : [];

            $normalizedEntry = [
                'width' => $this->normalizeNullablePositiveInt($entry['width'] ?? null),
                'height' => $this->normalizeNullablePositiveInt($entry['height'] ?? null),
                'enabled' => ($entry['enabled'] ?? true) !== false,
                'autoDimension' => $this->normalizeAutoDimension($entry['autoDimension'] ?? null),
            ];

            if ($normalizedEntry['autoDimension'] === 'width') {
                $normalizedEntry['width'] = null;
            }

            if ($normalizedEntry['autoDimension'] === 'height') {
                $normalizedEntry['height'] = null;
            }

            $entries[$index] = $normalizedEntry;
        }

        $preserveAutos = $scopeMode !== 'breakpoint';

        if ($scopeMode === 'breakpoint') {
            if ($scopeBreakpoint === null) {
                $this->addGlobalError($validation, 'scopeBreakpoint is required when scopeMode is breakpoint.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointIndex = array_search($scopeBreakpoint, $breakpoints, true);
            if (!is_int($breakpointIndex)) {
                $this->addGlobalError($validation, 'Selected breakpoint is not valid for the transform.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $entry = isset($entries[$breakpointIndex]) && is_array($entries[$breakpointIndex])
                ? $entries[$breakpointIndex]
                : $this->buildDefaultTransformEntry();
            $entry[$dimension] = $value;
            if (($entry['autoDimension'] ?? null) === $dimension) {
                $entry['autoDimension'] = null;
            }
            $entries[$breakpointIndex] = $entry;
        } else {
            foreach ($breakpoints as $index => $_breakpoint) {
                $entry = isset($entries[$index]) && is_array($entries[$index])
                    ? $entries[$index]
                    : $this->buildDefaultTransformEntry();

                if ($preserveAutos && ($entry['autoDimension'] ?? null) === $dimension) {
                    $entries[$index] = $entry;
                    continue;
                }

                $entry[$dimension] = $value;
                if (($entry['autoDimension'] ?? null) === $dimension) {
                    $entry['autoDimension'] = null;
                }
                $entries[$index] = $entry;
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

        $this->_plugin->getTransformStore()->persistTransforms($transforms);

        return [
            'persisted' => true,
            'validation' => $validation,
        ];
    }

    public function applySetDimensionsOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $widthValue,
        ?int $heightValue,
        ?bool $includeEscapeWidth = null,
        ?bool $widthAuto = null,
        ?bool $heightAuto = null,
    ): array {
        $validation = $this->defaultValidation();

        if ($this->_plugin === null) {
            $this->addGlobalError($validation, 'Plugin instance is not available.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($transformName === '') {
            $this->addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->_plugin->getTransformStore()->getTransforms();
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

        $entries = [];
        foreach ($breakpoints as $index => $_breakpoint) {
            $entry = isset($rawEntries[$index]) && is_array($rawEntries[$index])
                ? $rawEntries[$index]
                : [];

            $normalizedEntry = [
                'width' => $this->normalizeNullablePositiveInt($entry['width'] ?? null),
                'height' => $this->normalizeNullablePositiveInt($entry['height'] ?? null),
                'enabled' => ($entry['enabled'] ?? true) !== false,
                'autoDimension' => $this->normalizeAutoDimension($entry['autoDimension'] ?? null),
            ];

            if ($normalizedEntry['autoDimension'] === 'width') {
                $normalizedEntry['width'] = null;
            }

            if ($normalizedEntry['autoDimension'] === 'height') {
                $normalizedEntry['height'] = null;
            }

            $entries[$index] = $normalizedEntry;
        }

        $resolvedWidthAuto = $widthAuto === true;
        $resolvedHeightAuto = $heightAuto === true && !$resolvedWidthAuto;

        $preserveAutos = $scopeMode !== 'breakpoint';

        $applyIndex = function (int $index) use (&$entries, $widthValue, $heightValue, $resolvedWidthAuto, $resolvedHeightAuto, $preserveAutos): void {
            $entry = isset($entries[$index]) && is_array($entries[$index])
                ? $entries[$index]
                : $this->buildDefaultTransformEntry();

            $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
            $preserveWidth = $preserveAutos && $autoDimension === 'width';
            $preserveHeight = $preserveAutos && $autoDimension === 'height';

            if ($resolvedWidthAuto) {
                if (!$preserveWidth) {
                    $entry['width'] = null;
                    $entry['autoDimension'] = 'width';
                }
            } else {
                if (!$preserveWidth) {
                    $entry['width'] = $widthValue;
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
                }
            } else {
                if (!$preserveHeight) {
                    $entry['height'] = $heightValue;
                }
                if (($entry['autoDimension'] ?? null) === 'height') {
                    if (!$preserveHeight) {
                        $entry['autoDimension'] = null;
                    }
                }
            }

            $entries[$index] = $entry;
        };

        if ($scopeMode === 'breakpoint') {
            if ($scopeBreakpoint === null) {
                $this->addGlobalError($validation, 'scopeBreakpoint is required when scopeMode is breakpoint.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointIndex = array_search($scopeBreakpoint, $breakpoints, true);
            if (!is_int($breakpointIndex)) {
                $this->addGlobalError($validation, 'Selected breakpoint is not valid for the transform.');

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

        $this->_plugin->getTransformStore()->persistTransforms($transforms);

        return [
            'persisted' => true,
            'validation' => $validation,
        ];
    }

    public function applySetRatioOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $ratioWidth,
        ?int $ratioHeight,
        ?string $ratioSourceDimension,
        ?bool $includeEscapeWidth = null,
    ): array {
        $validation = $this->defaultValidation();

        if ($this->_plugin === null) {
            $this->addGlobalError($validation, 'Plugin instance is not available.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($transformName === '') {
            $this->addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($ratioWidth === null || $ratioHeight === null) {
            $this->addGlobalError($validation, 'ratioWidth and ratioHeight are required positive integers.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $sourceDimension = strtolower(trim((string)($ratioSourceDimension ?? 'w')));
        if ($sourceDimension === 'width') {
            $sourceDimension = 'w';
        }
        if ($sourceDimension === 'height') {
            $sourceDimension = 'h';
        }
        if ($sourceDimension !== 'w' && $sourceDimension !== 'h') {
            $sourceDimension = 'w';
        }

        $transforms = $this->_plugin->getTransformStore()->getTransforms();
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

        $entries = [];
        foreach ($breakpoints as $index => $_breakpoint) {
            $entry = isset($rawEntries[$index]) && is_array($rawEntries[$index])
                ? $rawEntries[$index]
                : [];

            $normalizedEntry = [
                'width' => $this->normalizeNullablePositiveInt($entry['width'] ?? null),
                'height' => $this->normalizeNullablePositiveInt($entry['height'] ?? null),
                'enabled' => ($entry['enabled'] ?? true) !== false,
                'autoDimension' => $this->normalizeAutoDimension($entry['autoDimension'] ?? null),
            ];

            if ($normalizedEntry['autoDimension'] === 'width') {
                $normalizedEntry['width'] = null;
            }

            if ($normalizedEntry['autoDimension'] === 'height') {
                $normalizedEntry['height'] = null;
            }

            $entries[$index] = $normalizedEntry;
        }

        $preserveAutos = $scopeMode !== 'breakpoint';

        $applyIndex = function (int $index) use (&$entries, $sourceDimension, $ratioWidth, $ratioHeight, $preserveAutos): bool {
            $entry = isset($entries[$index]) && is_array($entries[$index])
                ? $entries[$index]
                : $this->buildDefaultTransformEntry();

            $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
            if ($preserveAutos && ($autoDimension === 'width' || $autoDimension === 'height')) {
                return false;
            }

            if ($sourceDimension === 'w') {
                $sourceValue = $this->normalizeNullablePositiveInt($entry['width'] ?? null);
                if ($sourceValue === null) {
                    return false;
                }

                $entry['height'] = max(1, (int)round(($sourceValue * $ratioHeight) / $ratioWidth));
                if (($entry['autoDimension'] ?? null) === 'height') {
                    $entry['autoDimension'] = null;
                }
            } else {
                $sourceValue = $this->normalizeNullablePositiveInt($entry['height'] ?? null);
                if ($sourceValue === null) {
                    return false;
                }

                $entry['width'] = max(1, (int)round(($sourceValue * $ratioWidth) / $ratioHeight));
                if (($entry['autoDimension'] ?? null) === 'width') {
                    $entry['autoDimension'] = null;
                }
            }

            $entries[$index] = $entry;
            return true;
        };

        $appliedCount = 0;

        if ($scopeMode === 'breakpoint') {
            if ($scopeBreakpoint === null) {
                $this->addGlobalError($validation, 'scopeBreakpoint is required when scopeMode is breakpoint.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $breakpointIndex = array_search($scopeBreakpoint, $breakpoints, true);
            if (!is_int($breakpointIndex)) {
                $this->addGlobalError($validation, 'Selected breakpoint is not valid for the transform.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            if (!$applyIndex($breakpointIndex)) {
                $this->addGlobalError($validation, 'Source dimension value is missing for the selected breakpoint.');

                return [
                    'persisted' => false,
                    'validation' => $validation,
                ];
            }

            $appliedCount = 1;
        } else {
            $eligibleCount = 0;
            foreach ($breakpoints as $index => $_breakpoint) {
                $entry = isset($entries[$index]) && is_array($entries[$index]) ? $entries[$index] : [];
                $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
                if ($preserveAutos && ($autoDimension === 'width' || $autoDimension === 'height')) {
                    continue;
                }

                $eligibleCount += 1;
                if ($applyIndex($index)) {
                    $appliedCount += 1;
                }
            }

            if ($eligibleCount > 0 && $appliedCount < 1) {
                $this->addGlobalError($validation, 'Source dimension values are missing for the selected ratio operation.');

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

        $this->_plugin->getTransformStore()->persistTransforms($transforms);

        return [
            'persisted' => true,
            'validation' => $validation,
        ];
    }

    public function applySetSettingsOperation(
        string $transformName,
        ?string $mode,
        ?int $quality,
        ?string $position,
        ?bool $includeEscapeWidth = null,
    ): array {
        $validation = $this->defaultValidation();

        if ($this->_plugin === null) {
            $this->addGlobalError($validation, 'Plugin instance is not available.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($transformName === '') {
            $this->addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $allowedModes = ['crop', 'fit', 'stretch', 'letterbox'];
        $allowedPositions = [
            'top-left',
            'top-center',
            'top-right',
            'center-left',
            'center-center',
            'center-right',
            'bottom-left',
            'bottom-center',
            'bottom-right',
        ];

        $normalizedMode = strtolower(trim((string)($mode ?? 'crop')));
        if (!in_array($normalizedMode, $allowedModes, true)) {
            $normalizedMode = 'crop';
        }

        $normalizedPosition = strtolower(trim((string)($position ?? 'center-center')));
        if (!in_array($normalizedPosition, $allowedPositions, true)) {
            $normalizedPosition = 'center-center';
        }

        $normalizedQuality = $quality;
        if ($normalizedQuality === null || $normalizedQuality < 1) {
            $normalizedQuality = 80;
        }

        if ($normalizedQuality > 100) {
            $normalizedQuality = 100;
        }

        $transforms = $this->_plugin->getTransformStore()->getTransforms();
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

        $existingConfig = isset($transformDefinition['config']) && is_array($transformDefinition['config'])
            ? $transformDefinition['config']
            : [];

        $existingConfig['mode'] = $normalizedMode;
        $existingConfig['quality'] = $normalizedQuality;
        $existingConfig['position'] = $normalizedPosition;

        $transforms[$transformName] = array_merge($transformDefinition, [
            'name' => (string)($transformDefinition['name'] ?? $transformName),
            'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
            'transforms' => isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
                ? array_values($transformDefinition['transforms'])
                : [],
            'config' => $existingConfig,
        ]);

        $this->_plugin->getTransformStore()->persistTransforms($transforms);

        return [
            'persisted' => true,
            'validation' => $validation,
        ];
    }

    public function applyRenderedValuesOperation(
        string $transformName,
        array $renderedRows,
        ?bool $includeEscapeWidth = null,
    ): array {
        $validation = $this->defaultValidation();

        if ($this->_plugin === null) {
            $this->addGlobalError($validation, 'Plugin instance is not available.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($transformName === '') {
            $this->addGlobalError($validation, 'setName is required.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms = $this->_plugin->getTransformStore()->getTransforms();
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

        $entries = [];
        foreach ($breakpoints as $index => $_breakpoint) {
            $entry = isset($rawEntries[$index]) && is_array($rawEntries[$index])
                ? $rawEntries[$index]
                : [];

            $normalizedEntry = [
                'width' => $this->normalizeNullablePositiveInt($entry['width'] ?? null),
                'height' => $this->normalizeNullablePositiveInt($entry['height'] ?? null),
                'enabled' => ($entry['enabled'] ?? true) !== false,
                'autoDimension' => $this->normalizeAutoDimension($entry['autoDimension'] ?? null),
            ];

            if ($normalizedEntry['autoDimension'] === 'width') {
                $normalizedEntry['width'] = null;
            }

            if ($normalizedEntry['autoDimension'] === 'height') {
                $normalizedEntry['height'] = null;
            }

            $entries[$index] = $normalizedEntry;
        }

        $breakpointIndexes = [];
        foreach ($breakpoints as $index => $breakpoint) {
            $breakpointIndexes[(string)$breakpoint] = $index;
        }

        $appliedCount = 0;
        $candidateDimensionCount = 0;
        $autoSkippedDimensionCount = 0;
        foreach ($renderedRows as $renderedRow) {
            if (!is_array($renderedRow)) {
                continue;
            }

            $breakpoint = $this->normalizeNullablePositiveInt($renderedRow['breakpoint'] ?? null);
            if ($breakpoint === null) {
                continue;
            }

            $index = $breakpointIndexes[(string)$breakpoint] ?? null;
            if (!is_int($index)) {
                continue;
            }

            $entry = isset($entries[$index]) && is_array($entries[$index])
                ? $entries[$index]
                : $this->buildDefaultTransformEntry();
            $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);

            $updated = false;

            $width = $this->normalizeNullablePositiveInt($renderedRow['width'] ?? null);
            if ($width !== null) {
                $candidateDimensionCount += 1;

                if ($autoDimension === 'width') {
                    $autoSkippedDimensionCount += 1;
                } else {
                    $entry['width'] = $width;
                    $updated = true;
                }
            }

            $height = $this->normalizeNullablePositiveInt($renderedRow['height'] ?? null);
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
                    'validation' => $validation,
                ];
            }

            $this->addGlobalError($validation, 'No valid rendered values were provided.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $transforms[$transformName] = array_merge($transformDefinition, [
            'name' => (string)($transformDefinition['name'] ?? $transformName),
            'includeEscapeWidth' => $resolvedIncludeEscapeWidth,
            'transforms' => array_values($entries),
            'config' => isset($transformDefinition['config']) && is_array($transformDefinition['config'])
                ? $transformDefinition['config']
                : [],
        ]);

        $this->_plugin->getTransformStore()->persistTransforms($transforms);

        return [
            'persisted' => true,
            'validation' => $validation,
        ];
    }

    public function applySetWidthOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $value,
    ): array {
        return $this->applySetDimensionOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $value,
            'width',
        );
    }

    public function buildResultSummary(array $summary = []): array
    {
        if ($this->_plugin === null) {
            return [
                'assetCount' => 0,
                'breakpointCount' => 0,
                'warningCount' => 0,
            ];
        }

        $breakpointCount = count($this->_plugin->getConfigService()->getBreakpoints());

        return [
            'assetCount' => $this->toNonNegativeInt($summary['assetCount'] ?? 0),
            'breakpointCount' => $this->toNonNegativeInt($summary['breakpointCount'] ?? $breakpointCount),
            'warningCount' => $this->toNonNegativeInt($summary['warningCount'] ?? 0),
        ];
    }

    public function defaultValidation(): array
    {
        return [
            'hasErrors' => false,
            'global' => [],
            'fields' => [],
        ];
    }

    public function renderResultReview(
        array $result,
        array $editScopeBySet = [],
        array $editTabBySet = [],
    ): array {
        $rowsByBreakpoint = $this->normalizeReviewRowsByBreakpoint($result['rowsByBreakpoint'] ?? []);
        $breakpoints = $this->normalizeReviewBreakpoints($result['breakpoints'] ?? []);
        if ($breakpoints === []) {
            $breakpoints = $this->getReviewConfiguredBreakpoints();
        }

        $warnings = $this->buildReviewWarnings($rowsByBreakpoint);
        $normalizedScopeState = [];
        $normalizedTabState = [];

        return [
            'warningsHtml' => $this->renderReviewWarningsMarkup($warnings),
            'visualResultsHtml' => $this->buildReviewCardsMarkup(
                $rowsByBreakpoint,
                $breakpoints,
                $editScopeBySet,
                $editTabBySet,
                $normalizedScopeState,
                $normalizedTabState,
            ),
            'warningCount' => count($warnings),
            'editScopeBySet' => $normalizedScopeState,
            'editTabBySet' => $normalizedTabState,
        ];
    }

    private function renderReviewWarningsMarkup(array $warnings): string
    {
        if ($warnings === []) {
            return '<div class="bpi-warning-item bpi-warning-item-success">No warnings detected.</div>';
        }

        $chunks = [];
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }

            $code = $this->escapeReviewHtml((string)($warning['code'] ?? 'warning'));
            $message = $this->escapeReviewHtml((string)($warning['message'] ?? 'Warning'));
            $transforms = isset($warning['transforms']) && is_array($warning['transforms'])
                ? $warning['transforms']
                : [];
            $transformDetail = '';
            if ($transforms !== []) {
                $transformList = array_map(
                    fn(mixed $name): string => $this->escapeReviewHtml((string)$name),
                    $transforms,
                );
                $transformDetail = '<div class="bpi-warning-detail">' . implode(', ', $transformList) . '</div>';
            }

            $rowCount = isset($warning['rowCount']) && is_numeric($warning['rowCount'])
                ? '<div class="bpi-warning-detail">rows: ' . (int)$warning['rowCount'] . '</div>'
                : '';

            $chunks[] = sprintf(
                '<div class="%s"><span class="bpi-warning-code">%s</span> - %s%s%s</div>',
                $this->buildReviewWarningClass((string)($warning['code'] ?? 'warning')),
                $code,
                $message,
                $transformDetail,
                $rowCount,
            );
        }

        return $chunks === []
            ? '<div class="bpi-warning-item bpi-warning-item-success">No warnings detected.</div>'
            : implode('', $chunks);
    }

    private function buildReviewWarningClass(string $code): string
    {
        if ($code === 'missing-set-definitions' || $code === 'unknown-set-rows') {
            return 'bpi-warning-item bpi-warning-item-danger';
        }

        return 'bpi-warning-item bpi-warning-item-neutral';
    }

    private function buildReviewCardsMarkup(
        array $rowsByBreakpoint,
        array $breakpoints,
        array $editScopeBySet,
        array $editTabBySet,
        array &$normalizedScopeState,
        array &$normalizedTabState,
    ): string {
        $transformNames = $this->collectReviewTransformNames($rowsByBreakpoint);
        if ($transformNames === []) {
            return '<div class="bpi-empty-state light">No transform sets found in results.</div>';
        }

        $configuredBreakpoints = $breakpoints !== [] ? $breakpoints : $this->getReviewConfiguredBreakpoints();
        $escapeBreakpoint = $this->getReviewEscapeBreakpoint();
        $storedTransforms = $this->getReviewStoredTransforms();
        $cards = [];

        foreach ($transformNames as $transformName) {
            $observedBreakpoints = [];
            foreach ($configuredBreakpoints as $breakpoint) {
                $rows = $rowsByBreakpoint[$breakpoint] ?? [];
                foreach ($rows as $row) {
                    if (($row['transform'] ?? '') === $transformName) {
                        $observedBreakpoints[] = $breakpoint;
                        break;
                    }
                }
            }

            $storedTransformConfig = $this->getReviewTransformConfig($storedTransforms, $transformName);
            $includeEscapeWidth = ($storedTransformConfig['includeEscapeWidth'] ?? false) === true;
            if ($storedTransformConfig === null) {
                $includeEscapeWidth = $escapeBreakpoint !== null && in_array($escapeBreakpoint, $observedBreakpoints, true);
            }

            $transformBreakpoints = $observedBreakpoints !== []
                ? $observedBreakpoints
                : $this->getReviewBreakpointsForTransformConfig($includeEscapeWidth, $configuredBreakpoints);

            if ($transformBreakpoints === []) {
                continue;
            }

            $currentRows = $this->buildReviewCurrentRowsForTransform(
                $storedTransformConfig,
                $transformBreakpoints,
            );

            $scope = $this->normalizeReviewScope(
                $editScopeBySet[$transformName] ?? null,
                $transformBreakpoints,
            );
            $tab = $this->normalizeReviewTab($editTabBySet[$transformName] ?? null);

            $selectedBreakpoint = $scope['mode'] === 'breakpoint' ? $scope['breakpoint'] : null;
            $signalKey = $this->getReviewTransformSignalKey($transformName);
            $signalPathBase = 'editor.cards.' . $signalKey;
            $scopeValues = $this->getReviewScopeDimensionInputValues($currentRows, $scope);
            $settingsSignalValues = $this->getReviewSettingsSignalValues($storedTransformConfig);

            $ratioTabDisabled = $scope['mode'] === 'breakpoint'
                && ($scopeValues['widthAuto'] === '1' || $scopeValues['heightAuto'] === '1');
            if ($ratioTabDisabled && $tab === 'ratio') {
                $tab = 'dimensions';
            }

            $normalizedScopeState[$transformName] = $scope;
            $normalizedTabState[$transformName] = $tab;

            $ratioSourceBreakpointDefault = $selectedBreakpoint !== null
                ? (string)$selectedBreakpoint
                : (string)$transformBreakpoints[0];

            $ratioSourceBreakpointOptions = '';
            foreach ($transformBreakpoints as $transformBreakpoint) {
                $value = (string)$transformBreakpoint;
                $selectedAttr = $value === $ratioSourceBreakpointDefault ? ' selected' : '';
                $ratioSourceBreakpointOptions .= sprintf(
                    '<option value="%s"%s>%spx</option>',
                    $this->escapeReviewHtml($value),
                    $selectedAttr,
                    $this->escapeReviewHtml($value),
                );
            }

            $cardSignals = [
                'editor' => [
                    'cards' => [
                        $signalKey => [
                            'widthInput' => $scopeValues['widthInput'],
                            'heightInput' => $scopeValues['heightInput'],
                            'widthAuto' => $scopeValues['widthAuto'],
                            'heightAuto' => $scopeValues['heightAuto'],
                            'ratioSourceDimension' => 'w',
                            'ratioWidthInput' => $scopeValues['widthInput'],
                            'ratioHeightInput' => $scopeValues['heightInput'],
                            'ratioSourceBreakpoint' => $ratioSourceBreakpointDefault,
                            'cropMode' => $settingsSignalValues['cropMode'],
                            'qualityInput' => $settingsSignalValues['qualityInput'],
                            'cropPosition' => $settingsSignalValues['cropPosition'],
                            'activeTab' => $tab,
                            'scopeMode' => $scope['mode'],
                            'scopeBreakpoint' => $scope['mode'] === 'breakpoint' ? (string)$scope['breakpoint'] : '',
                            'scopeActive' => $this->isReviewScopeActive($scope) ? '1' : '0',
                        ],
                    ],
                ],
            ];

            $cardSignalsJson = json_encode($cardSignals, JSON_UNESCAPED_SLASHES);
            if (!is_string($cardSignalsJson)) {
                $cardSignalsJson = '{"editor":{"cards":{}}}';
            }

            $columnWidths = $this->calculateReviewBreakpointColumnWidths($transformBreakpoints);
            $breakpointColumns = '';
            $renderedRowsForTransform = [];
            foreach ($transformBreakpoints as $breakpoint) {
                $rows = $this->getReviewRowsForTransformBreakpoint($rowsByBreakpoint, $transformName, $breakpoint);
                $renderedRowsForTransform = array_merge(
                    $renderedRowsForTransform,
                    $this->buildReviewRenderedRowsPayload($rows, $breakpoint),
                );
                $breakpointColumns .= $this->renderReviewBreakpointColumn(
                    $transformName,
                    $breakpoint,
                    $rows,
                    $currentRows[$breakpoint] ?? $this->buildDefaultTransformEntry(),
                    $columnWidths,
                    $signalKey,
                    $selectedBreakpoint,
                    $scope['mode'] === 'all',
                    $escapeBreakpoint,
                );
            }

            $slug = $this->slugifyReviewTransformName($transformName);
            $editPanelId = 'bpi-edit-panel-' . $slug;
            $activeDimensions = $tab === 'dimensions';
            $activeRatio = $tab === 'ratio';
            $activeSettings = $tab === 'settings';
            $scopeLabel = $scope['mode'] === 'all'
                ? 'All'
                : ($scope['mode'] === 'breakpoint' ? ($scope['breakpoint'] . 'px') : 'Select scope');

            $renderedRowsForTransformJson = json_encode($renderedRowsForTransform, JSON_UNESCAPED_SLASHES);
            if (!is_string($renderedRowsForTransformJson)) {
                $renderedRowsForTransformJson = '[]';
            }

            $cards[] = $this->renderReviewTemplate('transform-card-template.twig', [
                'transformNameEscaped' => $this->escapeReviewHtml($transformName),
                'signalKey' => $this->escapeReviewHtml($signalKey),
                'cardSignals' => $this->escapeReviewHtml($cardSignalsJson),
                'includeEscapeWidth' => $includeEscapeWidth ? '1' : '0',
                'transformAssetCount' => (string)$this->getReviewTransformAssetCount($rowsByBreakpoint, $transformName),
                'renderedRowsForTransformJson' => $this->escapeReviewHtml($renderedRowsForTransformJson),
                'breakpointColumns' => $breakpointColumns,
                'editPanelId' => $this->escapeReviewHtml($editPanelId),
                'signalPathBase' => $this->escapeReviewHtml($signalPathBase),
                'editScopeDefaultLabel' => $this->escapeReviewHtml($scopeLabel),
                'editScopeAllCheckedAttr' => $scope['mode'] === 'all' ? 'checked' : '',
                'dimensionsTabActiveClass' => $activeDimensions ? 'active' : '',
                'dimensionsTabSelected' => $activeDimensions ? 'true' : 'false',
                'dimensionsTabTabindex' => $activeDimensions ? '0' : '-1',
                'ratioTabActiveClass' => $activeRatio ? 'active' : '',
                'ratioTabSelected' => $activeRatio ? 'true' : 'false',
                'ratioTabTabindex' => $activeRatio ? '0' : '-1',
                'settingsTabActiveClass' => $activeSettings ? 'active' : '',
                'settingsTabSelected' => $activeSettings ? 'true' : 'false',
                'settingsTabTabindex' => $activeSettings ? '0' : '-1',
                'dimensionsPanelActiveClass' => $activeDimensions ? 'active' : '',
                'dimensionsPanelHiddenAttr' => $activeDimensions ? '' : 'hidden',
                'ratioPanelActiveClass' => $activeRatio ? 'active' : '',
                'ratioPanelHiddenAttr' => $activeRatio ? '' : 'hidden',
                'settingsPanelActiveClass' => $activeSettings ? 'active' : '',
                'settingsPanelHiddenAttr' => $activeSettings ? '' : 'hidden',
                'widthInputId' => $this->escapeReviewHtml($editPanelId . '-width'),
                'heightInputId' => $this->escapeReviewHtml($editPanelId . '-height'),
                'ratioWidthInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-width'),
                'ratioHeightInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-height'),
                'ratioSourceName' => $this->escapeReviewHtml($editPanelId . '-ratio-source'),
                'ratioSourceBreakpointOptions' => $ratioSourceBreakpointOptions,
                'settingsModeInputId' => $this->escapeReviewHtml($editPanelId . '-settings-mode'),
                'settingsQualityInputId' => $this->escapeReviewHtml($editPanelId . '-settings-quality'),
                'settingsPositionInputId' => $this->escapeReviewHtml($editPanelId . '-settings-position'),
            ]);
        }

        if ($cards === []) {
            return '<div class="bpi-empty-state light">No transform sets found in results.</div>';
        }

        return implode('', $cards);
    }

    private function renderReviewBreakpointColumn(
        string $transformName,
        int $breakpoint,
        array $rows,
        array $currentRow,
        array $breakpointColumnWidths,
        string $signalKey,
        ?int $selectedBreakpoint,
        bool $allSelected,
        ?int $escapeBreakpoint,
    ): string {
        $summary = $this->summarizeReviewRows($rows);
        $renderedRowsPayload = $this->buildReviewRenderedRowsPayload($rows, $breakpoint);
        $renderedRowsPayloadJson = json_encode($renderedRowsPayload, JSON_UNESCAPED_SLASHES);
        if (!is_string($renderedRowsPayloadJson)) {
            $renderedRowsPayloadJson = '[]';
        }

        $renderedWidth = (int)($summary['renderedWidth'] ?? 0);
        $renderedHeight = (int)($summary['renderedHeight'] ?? 0);
        $previewRow = $this->pickReviewPreviewRow($rows);
        $previewSrc = is_array($previewRow) ? (string)($previewRow['src'] ?? '') : '';
        $aspectRatio = $renderedWidth > 0 && $renderedHeight > 0
            ? $renderedWidth . ' / ' . $renderedHeight
            : '1 / 1';
        $relativeWidth = $breakpoint > 0
            ? max(0.0, min(100.0, ($renderedWidth / $breakpoint) * 100))
            : 0.0;

        $currentWidth = $this->normalizeNullablePositiveInt($currentRow['width'] ?? null);
        $currentHeight = $this->normalizeNullablePositiveInt($currentRow['height'] ?? null);
        $autoDimension = $this->normalizeAutoDimension($currentRow['autoDimension'] ?? null);

        $widthClass = $this->getReviewDimensionClass($renderedWidth, $currentWidth, $autoDimension, 'width');
        $heightClass = $this->getReviewDimensionClass($renderedHeight, $currentHeight, $autoDimension, 'height');

        $previewMedia = $previewSrc !== ''
            ? sprintf(
                '<img src="%s" alt="%s" class="bpi_breakpoint-result-image" draggable="false" style="--bpi-aspect-ratio:%s;">',
                $this->escapeReviewHtml($previewSrc),
                $this->escapeReviewHtml('Preview ' . $transformName . ' ' . $breakpoint . 'px'),
                $this->escapeReviewHtml($aspectRatio),
            )
            : sprintf(
                '<div class="bpi_breakpoint-result-image" style="--bpi-aspect-ratio:%s;"></div>',
                $this->escapeReviewHtml($aspectRatio),
            );

        $hiddenCount = (int)($summary['hiddenCount'] ?? 0);
        $unloadedCount = (int)($summary['unloadedCount'] ?? 0);
        $hiddenBadge = $hiddenCount > 0
            ? '<span class="bpi_hidden-notice">Hidden ' . $hiddenCount . '</span>'
            : '';
        $unloadedBadge = $unloadedCount > 0
            ? '<span class="bpi-row-badge">Unloaded ' . $unloadedCount . '</span>'
            : '';
        $escapeBadge = $escapeBreakpoint !== null && $escapeBreakpoint === $breakpoint
            ? '<span class="bpi_escaped-notice">ESC</span>'
            : '';

        $isSelected = $allSelected || ($selectedBreakpoint !== null && $selectedBreakpoint === $breakpoint);
        $breakpointColumnWidth = (float)($breakpointColumnWidths[(string)$breakpoint] ?? 1.0);
        if ($breakpointColumnWidth < 1.0) {
            $breakpointColumnWidth = 1.0;
        }

        return $this->renderReviewTemplate('breakpoint-column-template.twig', [
            'breakpointColumnSelectedClass' => $isSelected ? 'bpi-breakpoint-column-selected' : '',
            'breakpoint' => (string)$breakpoint,
            'breakpointColumnWidth' => (string)$breakpointColumnWidth,
            'signalKey' => $this->escapeReviewHtml($signalKey),
            'currentWidthValue' => $currentWidth !== null ? (string)$currentWidth : '',
            'currentHeightValue' => $currentHeight !== null ? (string)$currentHeight : '',
            'currentAutoDimension' => $autoDimension ?? '',
            'escapeBadge' => $escapeBadge,
            'hiddenBadge' => $hiddenBadge,
            'unloadedBadge' => $unloadedBadge,
            'renderedRowsPayloadJson' => $this->escapeReviewHtml($renderedRowsPayloadJson),
            'breakpointDisabledAttr' => $renderedRowsPayload === [] ? 'disabled' : '',
            'relativeWidth' => (string)$relativeWidth,
            'previewMedia' => $previewMedia,
            'widthClass' => $widthClass,
            'heightClass' => $heightClass,
            'renderedWidth' => $renderedWidth > 0 ? (string)$renderedWidth : '-',
            'renderedHeight' => $renderedHeight > 0 ? (string)$renderedHeight : '-',
            'currentWidth' => $this->escapeReviewHtml($this->getReviewCurrentDimensionDisplay($currentWidth, $autoDimension, 'width')),
            'currentHeight' => $this->escapeReviewHtml($this->getReviewCurrentDimensionDisplay($currentHeight, $autoDimension, 'height')),
        ]);
    }

    private function renderReviewTemplate(string $templateFile, array $replacements): string
    {
        $templateHtml = $this->getReviewTemplateHtml($templateFile);
        if ($templateHtml === '') {
            return '';
        }

        $output = $templateHtml;
        foreach ($replacements as $key => $value) {
            $normalizedKey = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', (string)$key);
            $normalizedKey = strtoupper((string)preg_replace('/[^a-zA-Z0-9_]+/', '_', (string)$normalizedKey));
            $token = '__' . $normalizedKey . '__';
            $output = str_replace($token, (string)($value ?? ''), $output);
        }

        return $output;
    }

    private function getReviewTemplateHtml(string $templateFile): string
    {
        if (isset($this->_reviewTemplateCache[$templateFile])) {
            return $this->_reviewTemplateCache[$templateFile];
        }

        $path = dirname(__DIR__) . '/templates/cp/_partials/' . $templateFile;
        if (!is_file($path)) {
            $this->_reviewTemplateCache[$templateFile] = '';
            return '';
        }

        $templateSource = file_get_contents($path);
        if (!is_string($templateSource) || $templateSource === '') {
            $this->_reviewTemplateCache[$templateFile] = '';
            return '';
        }

        $matches = [];
        if (!preg_match('/<template[^>]*>([\s\S]*)<\/template>/', $templateSource, $matches)) {
            $this->_reviewTemplateCache[$templateFile] = '';
            return '';
        }

        $innerHtml = (string)($matches[1] ?? '');
        $this->_reviewTemplateCache[$templateFile] = $innerHtml;

        return $innerHtml;
    }

    private function normalizeReviewRowsByBreakpoint(mixed $rawRowsByBreakpoint): array
    {
        if (!is_array($rawRowsByBreakpoint)) {
            return [];
        }

        $normalized = [];
        foreach ($rawRowsByBreakpoint as $breakpointKey => $rows) {
            $breakpoint = $this->normalizeNullablePositiveInt($breakpointKey);
            if ($breakpoint === null || !is_array($rows)) {
                continue;
            }

            $normalizedRows = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $normalizedRows[] = [
                    'assetId' => (string)($row['assetId'] ?? ''),
                    'transform' => (string)($row['transform'] ?? 'unknown'),
                    'title' => (string)($row['title'] ?? ''),
                    'enabled' => ($row['enabled'] ?? true) === true,
                    'isVisible' => ($row['isVisible'] ?? false) === true,
                    'loaded' => ($row['loaded'] ?? false) === true,
                    'src' => (string)($row['src'] ?? ''),
                    'rendered' => [
                        'width' => $this->toNonNegativeInt($row['rendered']['width'] ?? 0),
                        'height' => $this->toNonNegativeInt($row['rendered']['height'] ?? 0),
                    ],
                    'intrinsic' => [
                        'width' => $this->toNonNegativeInt($row['intrinsic']['width'] ?? 0),
                        'height' => $this->toNonNegativeInt($row['intrinsic']['height'] ?? 0),
                    ],
                    'transformDimensions' => [
                        'width' => $this->normalizeNullablePositiveInt($row['transformDimensions']['width'] ?? null),
                        'height' => $this->normalizeNullablePositiveInt($row['transformDimensions']['height'] ?? null),
                        'autoDimension' => (string)($row['transformDimensions']['autoDimension'] ?? ''),
                    ],
                ];
            }

            $normalized[$breakpoint] = $normalizedRows;
        }

        ksort($normalized, SORT_NUMERIC);
        return $normalized;
    }

    private function normalizeReviewBreakpoints(mixed $rawBreakpoints): array
    {
        if (!is_array($rawBreakpoints)) {
            return [];
        }

        $normalized = [];
        foreach ($rawBreakpoints as $rawBreakpoint) {
            $breakpoint = $this->normalizeNullablePositiveInt($rawBreakpoint);
            if ($breakpoint !== null) {
                $normalized[] = $breakpoint;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function collectReviewTransformNames(array $rowsByBreakpoint): array
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

    private function getReviewBreakpointsForTransformConfig(bool $includeEscapeWidth, array $breakpoints): array
    {
        $escapeBreakpoint = $this->getReviewEscapeBreakpoint();
        if ($includeEscapeWidth || $escapeBreakpoint === null) {
            return $breakpoints;
        }

        return array_values(array_filter(
            $breakpoints,
            static fn(int $breakpoint): bool => $breakpoint !== $escapeBreakpoint,
        ));
    }

    private function getReviewEscapeBreakpoint(): ?int
    {
        if ($this->_plugin === null) {
            return null;
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints();
        if (!is_array($breakpoints) || !array_key_exists('escape', $breakpoints)) {
            return null;
        }

        return $this->normalizeNullablePositiveInt($breakpoints['escape']);
    }

    private function getReviewConfiguredBreakpoints(): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints();
        if (!is_array($breakpoints)) {
            return [];
        }

        $values = [];
        foreach ($breakpoints as $value) {
            $normalized = $this->normalizeNullablePositiveInt($value);
            if ($normalized !== null) {
                $values[] = $normalized;
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_NUMERIC);

        return $values;
    }

    private function getReviewStoredTransforms(): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $transforms = $this->_plugin->getTransformStore()->getTransforms();
        return is_array($transforms) ? $transforms : [];
    }

    private function getReviewTransformConfig(array $storedTransforms, string $transformName): ?array
    {
        $config = $storedTransforms[$transformName] ?? null;
        return is_array($config) ? $config : null;
    }

    private function getReviewRowsForTransformBreakpoint(
        array $rowsByBreakpoint,
        string $transformName,
        int $breakpoint,
    ): array {
        $rows = $rowsByBreakpoint[$breakpoint] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((string)($row['transform'] ?? '') !== $transformName) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    private function summarizeReviewRows(array $rows): array
    {
        $enabledRows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['enabled'] ?? false) === true,
        ));

        $visibleRows = array_values(array_filter(
            $enabledRows,
            static fn(array $row): bool => ($row['isVisible'] ?? false) === true,
        ));

        $preferredRows = $visibleRows !== [] ? $visibleRows : $enabledRows;

        $renderedWidth = 0;
        $renderedHeight = 0;
        foreach ($preferredRows as $row) {
            $renderedWidth = max($renderedWidth, $this->toNonNegativeInt($row['rendered']['width'] ?? 0));
            $renderedHeight = max($renderedHeight, $this->toNonNegativeInt($row['rendered']['height'] ?? 0));
        }

        $hiddenCount = 0;
        foreach ($enabledRows as $row) {
            if (($row['isVisible'] ?? false) !== true) {
                $hiddenCount += 1;
            }
        }

        $unloadedCount = 0;
        foreach ($rows as $row) {
            if (($row['loaded'] ?? false) !== true) {
                $unloadedCount += 1;
            }
        }

        return [
            'renderedWidth' => $renderedWidth,
            'renderedHeight' => $renderedHeight,
            'hiddenCount' => $hiddenCount,
            'unloadedCount' => $unloadedCount,
        ];
    }

    private function buildReviewRenderedRowsPayload(array $rows, int $breakpoint): array
    {
        $summary = $this->summarizeReviewRows($rows);
        $width = $summary['renderedWidth'] > 0 ? (int)round($summary['renderedWidth']) : null;
        $height = $summary['renderedHeight'] > 0 ? (int)round($summary['renderedHeight']) : null;

        if ($width === null && $height === null) {
            return [];
        }

        return [[
            'breakpoint' => $breakpoint,
            'width' => $width,
            'height' => $height,
        ]];
    }

    private function pickReviewPreviewRow(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $filters = [
            static fn(array $row): bool => ($row['loaded'] ?? false) === true
                && ($row['isVisible'] ?? false) === true
                && ($row['enabled'] ?? false) === true
                && (string)($row['src'] ?? '') !== '',
            static fn(array $row): bool => ($row['loaded'] ?? false) === true
                && ($row['enabled'] ?? false) === true
                && (string)($row['src'] ?? '') !== '',
            static fn(array $row): bool => ($row['loaded'] ?? false) === true
                && (string)($row['src'] ?? '') !== '',
            static fn(array $row): bool => (string)($row['src'] ?? '') !== '',
        ];

        foreach ($filters as $filter) {
            foreach ($rows as $row) {
                if ($filter($row)) {
                    return $row;
                }
            }
        }

        return $rows[0] ?? null;
    }

    private function calculateReviewBreakpointColumnWidths(array $breakpoints): array
    {
        if ($breakpoints === []) {
            return [];
        }

        $firstBreakpoint = $breakpoints[0] > 0 ? $breakpoints[0] : 1;
        $widths = [];
        foreach ($breakpoints as $breakpoint) {
            $widths[(string)$breakpoint] = ($breakpoint / $firstBreakpoint) * 120;
        }

        return $widths;
    }

    private function getReviewDimensionClass(
        int $renderedValue,
        ?int $transformValue,
        ?string $autoDimension,
        string $dimension,
    ): string {
        if ($autoDimension === $dimension) {
            return 'bpi_dimension-auto';
        }

        if ($transformValue === null) {
            return 'bpi_dimension-no-transform';
        }

        if ($renderedValue <= 0) {
            return 'bpi_dimension-no-transform';
        }

        return abs($renderedValue - $transformValue) <= 1
            ? 'bpi_dimension-match'
            : 'bpi_dimension-mismatch';
    }

    private function getReviewCurrentDimensionDisplay(?int $value, ?string $autoDimension, string $dimension): string
    {
        if ($autoDimension === $dimension) {
            return 'auto';
        }

        if ($value === null) {
            return '-';
        }

        return (string)$value;
    }

    private function slugifyReviewTransformName(string $transformName): string
    {
        $slug = strtolower($transformName);
        $slug = (string)preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'transform';
    }

    private function getReviewTransformSignalKey(string $transformName): string
    {
        return 't_' . str_replace('-', '_', $this->slugifyReviewTransformName($transformName));
    }

    private function normalizeReviewTab(mixed $rawTab): string
    {
        $tab = is_string($rawTab) ? $rawTab : '';
        return in_array($tab, ['dimensions', 'ratio', 'settings'], true) ? $tab : 'dimensions';
    }

    private function normalizeReviewScope(mixed $rawScope, array $transformBreakpoints): array
    {
        if (!is_array($rawScope)) {
            return ['mode' => 'unset', 'breakpoint' => null];
        }

        $mode = strtolower(trim((string)($rawScope['mode'] ?? 'unset')));
        if ($mode === 'all') {
            return ['mode' => 'all', 'breakpoint' => null];
        }

        if ($mode === 'breakpoint') {
            $breakpoint = $this->normalizeNullablePositiveInt($rawScope['breakpoint'] ?? null);
            if ($breakpoint !== null && in_array($breakpoint, $transformBreakpoints, true)) {
                return ['mode' => 'breakpoint', 'breakpoint' => $breakpoint];
            }
        }

        return ['mode' => 'unset', 'breakpoint' => null];
    }

    private function isReviewScopeActive(array $scope): bool
    {
        return ($scope['mode'] ?? 'unset') === 'all' || ($scope['mode'] ?? 'unset') === 'breakpoint';
    }

    private function getReviewScopeDimensionInputValues(array $currentRowsByBreakpoint, array $scope): array
    {
        if (($scope['mode'] ?? 'unset') !== 'breakpoint') {
            return [
                'widthInput' => '',
                'heightInput' => '',
                'widthAuto' => '0',
                'heightAuto' => '0',
            ];
        }

        $breakpoint = $this->normalizeNullablePositiveInt($scope['breakpoint'] ?? null);
        if ($breakpoint === null || !isset($currentRowsByBreakpoint[$breakpoint])) {
            return [
                'widthInput' => '',
                'heightInput' => '',
                'widthAuto' => '0',
                'heightAuto' => '0',
            ];
        }

        $entry = $currentRowsByBreakpoint[$breakpoint];
        $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
        $widthValue = $this->normalizeNullablePositiveInt($entry['width'] ?? null);
        $heightValue = $this->normalizeNullablePositiveInt($entry['height'] ?? null);
        $widthAuto = $autoDimension === 'width';
        $heightAuto = $autoDimension === 'height';

        return [
            'widthInput' => $widthAuto || $widthValue === null ? '' : (string)$widthValue,
            'heightInput' => $heightAuto || $heightValue === null ? '' : (string)$heightValue,
            'widthAuto' => $widthAuto ? '1' : '0',
            'heightAuto' => $heightAuto ? '1' : '0',
        ];
    }

    private function normalizeReviewCropMode(mixed $mode): string
    {
        $normalized = strtolower(trim((string)$mode));
        return in_array($normalized, ['crop', 'fit', 'stretch', 'letterbox'], true)
            ? $normalized
            : 'crop';
    }

    private function normalizeReviewCropPosition(mixed $position): string
    {
        $normalized = strtolower(trim((string)$position));
        $allowed = [
            'top-left',
            'top-center',
            'top-right',
            'center-left',
            'center-center',
            'center-right',
            'bottom-left',
            'bottom-center',
            'bottom-right',
        ];

        return in_array($normalized, $allowed, true) ? $normalized : 'center-center';
    }

    private function normalizeReviewQuality(mixed $quality): int
    {
        if (!is_numeric($quality)) {
            return 80;
        }

        $parsed = (int)$quality;
        if ($parsed < 1) {
            return 80;
        }

        return min(100, $parsed);
    }

    private function getReviewSettingsSignalValues(?array $transformConfig): array
    {
        $config = isset($transformConfig['config']) && is_array($transformConfig['config'])
            ? $transformConfig['config']
            : [];

        return [
            'cropMode' => $this->normalizeReviewCropMode($config['mode'] ?? null),
            'qualityInput' => (string)$this->normalizeReviewQuality($config['quality'] ?? null),
            'cropPosition' => $this->normalizeReviewCropPosition($config['position'] ?? null),
        ];
    }

    private function buildReviewWarnings(array $rowsByBreakpoint): array
    {
        $warnings = [];
        $storedTransforms = $this->getReviewStoredTransforms();
        $manifestTransformNames = array_keys($storedTransforms);
        sort($manifestTransformNames, SORT_STRING);

        $observedTransformNames = $this->collectReviewTransformNames($rowsByBreakpoint);
        $missingDefinitions = array_values(array_diff($observedTransformNames, $manifestTransformNames));

        if ($missingDefinitions !== []) {
            $warnings[] = [
                'code' => 'missing-set-definitions',
                'message' => 'Transform sets found in markup are missing from manifest configuration.',
                'transforms' => $missingDefinitions,
            ];
        }

        $unknownRows = $this->countReviewUnknownTransformRows($rowsByBreakpoint);
        if ($unknownRows > 0) {
            $warnings[] = [
                'code' => 'unknown-set-rows',
                'message' => 'Some rows were missing the data-set attribute.',
                'rowCount' => $unknownRows,
            ];
        }

        return $warnings;
    }

    private function countReviewUnknownTransformRows(array $rowsByBreakpoint): int
    {
        $count = 0;
        foreach ($rowsByBreakpoint as $rows) {
            foreach ($rows as $row) {
                $transform = trim((string)($row['transform'] ?? ''));
                if ($transform === '' || $transform === 'unknown') {
                    $count += 1;
                }
            }
        }

        return $count;
    }

    private function buildReviewCurrentRowsForTransform(?array $transformConfig, array $transformBreakpoints): array
    {
        $rows = [];
        $entries = isset($transformConfig['transforms']) && is_array($transformConfig['transforms'])
            ? array_values($transformConfig['transforms'])
            : [];

        foreach ($transformBreakpoints as $index => $breakpoint) {
            $entry = isset($entries[$index]) && is_array($entries[$index])
                ? $entries[$index]
                : [];

            $rows[$breakpoint] = [
                'width' => $this->normalizeNullablePositiveInt($entry['width'] ?? null),
                'height' => $this->normalizeNullablePositiveInt($entry['height'] ?? null),
                'enabled' => ($entry['enabled'] ?? true) !== false,
                'autoDimension' => $this->normalizeAutoDimension($entry['autoDimension'] ?? null),
            ];

            if ($rows[$breakpoint]['autoDimension'] === 'width') {
                $rows[$breakpoint]['width'] = null;
            }

            if ($rows[$breakpoint]['autoDimension'] === 'height') {
                $rows[$breakpoint]['height'] = null;
            }
        }

        return $rows;
    }

    private function getReviewTransformAssetCount(array $rowsByBreakpoint, string $transformName): int
    {
        $assetIds = [];
        foreach ($rowsByBreakpoint as $rows) {
            foreach ($rows as $row) {
                if (($row['transform'] ?? '') !== $transformName) {
                    continue;
                }

                $assetId = (string)($row['assetId'] ?? '');
                if ($assetId !== '') {
                    $assetIds[$assetId] = true;
                }
            }
        }

        return count($assetIds);
    }

    private function escapeReviewHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function buildDraftTransforms(array $storedTransforms): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $draftTransforms = [];
        foreach ($storedTransforms as $transformName => $transformDefinition) {
            if (!is_string($transformName) || $transformName === '' || !is_array($transformDefinition)) {
                continue;
            }

            $includeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
            $breakpoints = $this->getBreakpointsForTransform($includeEscapeWidth);
            $rows = [];

            $entries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
                ? array_values($transformDefinition['transforms'])
                : [];

            foreach ($breakpoints as $index => $breakpoint) {
                $entry = isset($entries[$index]) && is_array($entries[$index]) ? $entries[$index] : [];
                $rows[(string)$breakpoint] = [
                    'width' => $this->normalizeNullablePositiveInt($entry['width'] ?? null),
                    'height' => $this->normalizeNullablePositiveInt($entry['height'] ?? null),
                    'enabled' => ($entry['enabled'] ?? true) !== false,
                    'autoDimension' => $this->normalizeAutoDimension($entry['autoDimension'] ?? null),
                    'ratioMode' => 'none',
                    'ratioSourceDimension' => 'width',
                ];
            }

            $draftTransforms[$transformName] = [
                'includeEscapeWidth' => $includeEscapeWidth,
                'rows' => $rows,
            ];
        }

        return $draftTransforms;
    }

    private function normalizeTransformsFromDraft(array $draft, array &$validation): array
    {
        if ($this->_plugin === null) {
            $this->addGlobalError($validation, 'Plugin instance is not available.');
            return [];
        }

        $draftTransforms = $draft['transforms'] ?? null;
        if (!is_array($draftTransforms) || $draftTransforms === []) {
            $this->addGlobalError($validation, 'Draft must include at least one transform.');
            return [];
        }

        $existingTransforms = $this->_plugin->getTransformStore()->getTransforms();
        $normalized = [];

        foreach ($draftTransforms as $transformName => $transformDraft) {
            if (!is_string($transformName) || trim($transformName) === '') {
                $this->addGlobalError($validation, 'Transform name must be a non-empty string.');
                continue;
            }

            if (!is_array($transformDraft)) {
                $this->addGlobalError($validation, sprintf('Transform "%s" must be an object.', $transformName));
                continue;
            }

            $includeEscapeWidth = ($transformDraft['includeEscapeWidth'] ?? false) === true;
            $rowsByBreakpoint = isset($transformDraft['rows']) && is_array($transformDraft['rows'])
                ? $transformDraft['rows']
                : [];
            $breakpoints = $this->getBreakpointsForTransform($includeEscapeWidth);

            $entries = [];
            foreach ($breakpoints as $breakpoint) {
                $breakpointKey = (string)$breakpoint;
                $row = $rowsByBreakpoint[$breakpointKey] ?? [];
                if (!is_array($row)) {
                    $row = [];
                }

                $widthInput = $row['width'] ?? null;
                $heightInput = $row['height'] ?? null;
                $width = $this->normalizeNullablePositiveInt($widthInput);
                $height = $this->normalizeNullablePositiveInt($heightInput);

                if ($widthInput !== null && $width === null) {
                    $this->addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.width', $transformName, $breakpointKey),
                        'Width must be a positive integer or null.'
                    );
                }

                if ($heightInput !== null && $height === null) {
                    $this->addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.height', $transformName, $breakpointKey),
                        'Height must be a positive integer or null.'
                    );
                }

                $autoDimensionInput = $row['autoDimension'] ?? null;
                $autoDimension = $this->normalizeAutoDimension($autoDimensionInput);

                if ($autoDimensionInput !== null && $autoDimension === null && $autoDimensionInput !== '') {
                    $this->addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.autoDimension', $transformName, $breakpointKey),
                        'autoDimension must be null, "width", or "height".'
                    );
                }

                if ($autoDimension === 'width') {
                    $width = null;
                }

                if ($autoDimension === 'height') {
                    $height = null;
                }

                $entries[] = [
                    'width' => $width,
                    'height' => $height,
                    'enabled' => ($row['enabled'] ?? true) !== false,
                    'autoDimension' => $autoDimension,
                ];
            }

            $existingConfig = [];
            if (isset($existingTransforms[$transformName]['config']) && is_array($existingTransforms[$transformName]['config'])) {
                $existingConfig = $existingTransforms[$transformName]['config'];
            }

            $normalized[$transformName] = [
                'name' => $transformName,
                'includeEscapeWidth' => $includeEscapeWidth,
                'transforms' => $entries,
                'config' => $existingConfig,
            ];
        }

        if ($normalized === []) {
            $this->addGlobalError($validation, 'Draft did not contain any valid transform definitions.');
        }

        return $normalized;
    }

    private function getBreakpointsForTransform(bool $includeEscapeWidth): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints();

        if (!$includeEscapeWidth) {
            unset($breakpoints['escape']);
        }

        return array_values(array_map(static fn(mixed $value): int => (int)$value, $breakpoints));
    }

    private function normalizeNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $parsed = (int)$value;

        return $parsed > 0 ? $parsed : null;
    }

    private function normalizeAutoDimension(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $candidate = strtolower(trim((string)$value));
        if ($candidate === 'width' || $candidate === 'height') {
            return $candidate;
        }

        return null;
    }

    private function buildDefaultTransformEntry(): array
    {
        return [
            'width' => null,
            'height' => null,
            'enabled' => true,
            'autoDimension' => null,
        ];
    }

    private function toNonNegativeInt(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $parsed = (int)$value;
        return $parsed >= 0 ? $parsed : 0;
    }

    private function addGlobalError(array &$validation, string $message): void
    {
        $validation['hasErrors'] = true;
        $validation['global'][] = $message;
    }

    private function addFieldError(array &$validation, string $fieldPath, string $message): void
    {
        $validation['hasErrors'] = true;

        if (!isset($validation['fields'][$fieldPath]) || !is_array($validation['fields'][$fieldPath])) {
            $validation['fields'][$fieldPath] = [];
        }

        $validation['fields'][$fieldPath][] = $message;
    }
}