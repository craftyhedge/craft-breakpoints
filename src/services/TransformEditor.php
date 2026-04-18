<?php

namespace craftyhedge\craftbreakpointimages\services;

use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class TransformEditor extends Component
{
    private const REVIEW_MODE_PROCESSED = 'processed';
    private const REVIEW_MODE_SAVED = 'saved';

    private const INITIAL_PLACEHOLDER_FALLBACK_WIDTH = 1200;
    private const INITIAL_PLACEHOLDER_FALLBACK_HEIGHT = 800;
    private const INITIAL_PLACEHOLDER_DEFAULT_RATIO_WIDTH = 3;
    private const INITIAL_PLACEHOLDER_DEFAULT_RATIO_HEIGHT = 2;

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

    public function applyDraft(array $draft, ?string $expectedVersion = null): array
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

        $resolvedExpectedVersion = $expectedVersion ?? $this->_plugin->getTransformStore()->getCurrentVersion();
        $persistResult = $this->_plugin->getTransformStore()->persistTransforms($normalizedTransforms, $resolvedExpectedVersion);

        $persisted = ($persistResult['persisted'] ?? false) === true;
        $conflict = ($persistResult['conflict'] ?? false) === true;
        $currentVersion = (string)($persistResult['currentVersion'] ?? $resolvedExpectedVersion);
        $persistedTransforms = is_array($persistResult['transforms'] ?? null)
            ? $persistResult['transforms']
            : [];

        if ($conflict) {
            $this->addGlobalError($validation, 'Draft version is out of date. Reload and apply again.');
        }

        return [
            'draft' => [
                'transforms' => $this->buildDraftTransforms($persistedTransforms),
            ],
            'validation' => $validation,
            'persisted' => $persisted,
            'conflict' => $conflict,
            'currentVersion' => $currentVersion,
        ];
    }

    public function applySetDimensionOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $value,
        string $dimension,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
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

        return $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
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
        bool $forceAll = false,
        ?string $expectedVersion = null,
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

        $preserveAutos = $scopeMode !== 'breakpoint' && !$forceAll;

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

        return $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
    }

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

        return $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
    }

    public function applySetBreakpointEnabledOperation(
        string $transformName,
        ?int $scopeBreakpoint,
        ?bool $enabled,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
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

        if ($scopeBreakpoint === null) {
            $this->addGlobalError($validation, 'scopeBreakpoint is required when updating breakpoint state.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        if ($enabled === null) {
            $this->addGlobalError($validation, 'enabled must be a boolean value.');

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
        $breakpointIndex = array_search($scopeBreakpoint, $breakpoints, true);
        if (!is_int($breakpointIndex)) {
            $this->addGlobalError($validation, 'Selected breakpoint is not valid for the transform.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

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

        $entry = isset($entries[$breakpointIndex]) && is_array($entries[$breakpointIndex])
            ? $entries[$breakpointIndex]
            : $this->buildDefaultTransformEntry();
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

    public function applyRenderedValuesOperation(
        string $transformName,
        array $renderedRows,
        ?bool $includeEscapeWidth = null,
        bool $clearAuto = false,
        ?string $expectedVersion = null,
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

        if ($clearAuto) {
            $renderedRowsByBreakpoint = [];
            foreach ($renderedRows as $renderedRow) {
                if (!is_array($renderedRow)) {
                    continue;
                }
                $bp = $this->normalizeNullablePositiveInt($renderedRow['breakpoint'] ?? null);
                if ($bp !== null) {
                    $renderedRowsByBreakpoint[(string)$bp] = $renderedRow;
                }
            }

            $appliedCount = 0;
            foreach ($breakpoints as $index => $breakpoint) {
                $entry = isset($entries[$index]) && is_array($entries[$index])
                    ? $entries[$index]
                    : $this->buildDefaultTransformEntry();
                $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
                if ($autoDimension === null) {
                    continue;
                }

                $renderedRow = $renderedRowsByBreakpoint[(string)$breakpoint] ?? null;

                if ($autoDimension === 'width') {
                    $rendered = $renderedRow !== null
                        ? $this->normalizeNullablePositiveInt($renderedRow['width'] ?? null)
                        : null;
                    if ($rendered !== null) {
                        $entry['width'] = $rendered;
                    }
                } elseif ($autoDimension === 'height') {
                    $rendered = $renderedRow !== null
                        ? $this->normalizeNullablePositiveInt($renderedRow['height'] ?? null)
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
                    'currentVersion' => $this->_plugin->getTransformStore()->getCurrentVersion(),
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
                        'conflict' => false,
                        'currentVersion' => $this->_plugin->getTransformStore()->getCurrentVersion(),
                        'validation' => $validation,
                    ];
                }

                $this->addGlobalError($validation, 'No valid rendered values were provided.');

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

    public function deleteSetOperation(string $transformName, ?string $expectedVersion = null): array
    {
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
        if (!isset($transforms[$transformName]) || !is_array($transforms[$transformName])) {
            return [
                'persisted' => true,
                'conflict' => false,
                'currentVersion' => $this->_plugin->getTransformStore()->getCurrentVersion(),
                'validation' => $validation,
            ];
        }

        unset($transforms[$transformName]);
        return $this->persistOperationTransforms($transforms, $validation, $expectedVersion);
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

    private function persistOperationTransforms(array $transforms, array $validation, ?string $expectedVersion): array
    {
        if ($this->_plugin === null) {
            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $resolvedExpectedVersion = $expectedVersion ?? $this->_plugin->getTransformStore()->getCurrentVersion();
        $persistResult = $this->_plugin->getTransformStore()->persistTransforms($transforms, $resolvedExpectedVersion);
        $conflict = ($persistResult['conflict'] ?? false) === true;

        if ($conflict) {
            $this->addGlobalError($validation, 'Draft version is out of date. Reload and apply again.');
        }

        return [
            'persisted' => ($persistResult['persisted'] ?? false) === true,
            'conflict' => $conflict,
            'currentVersion' => (string)($persistResult['currentVersion'] ?? $resolvedExpectedVersion),
            'validation' => $validation,
        ];
    }

    public function renderResultReview(
        array $result,
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
        bool $hideRenderedApply = false,
        bool $hideAssetPagination = false,
        string $reviewMode = self::REVIEW_MODE_PROCESSED,
    ): array {
        $normalizedReviewMode = $this->normalizeReviewMode($reviewMode);
        $rowsByBreakpoint = $this->normalizeReviewRowsByBreakpoint($result['rowsByBreakpoint'] ?? []);
        $breakpoints = $this->normalizeReviewBreakpoints($result['breakpoints'] ?? []);
        if ($breakpoints === []) {
            $breakpoints = $this->getReviewConfiguredBreakpoints();
        }

        $warningsByTransform = $this->buildReviewWarningsByTransform($rowsByBreakpoint);
        $normalizedScopeState = [];
        $normalizedTabState = [];
        $normalizedSelectedAssetKeyBySet = [];

        return [
            'warningsHtml' => '',
            'visualResultsHtml' => $this->buildReviewCardsMarkup(
                $rowsByBreakpoint,
                $breakpoints,
                $warningsByTransform,
                $editScopeBySet,
                $editTabBySet,
                $selectedAssetKeyBySet,
                $preferredOrderBySet,
                $normalizedScopeState,
                $normalizedTabState,
                $hideRenderedApply,
                $normalizedSelectedAssetKeyBySet,
                $hideAssetPagination,
                $normalizedReviewMode,
            ),
            'warningCount' => $this->countReviewWarningsByTransform($warningsByTransform),
            'editScopeBySet' => $normalizedScopeState,
            'editTabBySet' => $normalizedTabState,
            'selectedAssetKeyBySet' => $normalizedSelectedAssetKeyBySet,
        ];
    }

    public function renderInitialStoredReview(
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
    ): array {
        $storedTransforms = $this->getReviewStoredTransforms();
        $snapshotRowsByTransformAndBreakpoint = $this->getLatestRunSnapshotRowsByTransformAndBreakpoint();
        $syntheticRowsByBreakpoint = [];

        foreach ($storedTransforms as $setName => $transformDefinition) {
            if (!is_string($setName) || $setName === '' || !is_array($transformDefinition)) {
                continue;
            }

            $includeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
            $breakpoints = $this->getBreakpointsForTransform($includeEscapeWidth);
            $entries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
                ? array_values($transformDefinition['transforms'])
                : [];

            foreach ($breakpoints as $index => $breakpoint) {
                if (!is_int($breakpoint) || $breakpoint <= 0) {
                    continue;
                }

                $entry = isset($entries[$index]) && is_array($entries[$index])
                    ? $entries[$index]
                    : [];

                $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
                $width = $this->normalizeNullablePositiveInt($entry['width'] ?? null);
                $height = $this->normalizeNullablePositiveInt($entry['height'] ?? null);

                if ($autoDimension === 'width') {
                    $width = null;
                }

                if ($autoDimension === 'height') {
                    $height = null;
                }

                $placeholderSrc = $this->buildInitialReviewPlaceholderDataUri(
                    $width,
                    $height,
                    $autoDimension,
                );

                $snapshotRow = $snapshotRowsByTransformAndBreakpoint[$setName . '|' . $breakpoint] ?? null;
                $savedDisplayAssetUrl = is_array($snapshotRow)
                    ? trim((string)($snapshotRow['displayAssetUrl'] ?? ''))
                    : '';
                $snapshotRenderedWidth = is_array($snapshotRow) && isset($snapshotRow['renderedWidth'])
                    ? max(0, (int)$snapshotRow['renderedWidth'])
                    : 0;
                $snapshotRenderedHeight = is_array($snapshotRow) && isset($snapshotRow['renderedHeight'])
                    ? max(0, (int)$snapshotRow['renderedHeight'])
                    : 0;
                $previewSrc = $savedDisplayAssetUrl !== '' ? $savedDisplayAssetUrl : $placeholderSrc;
                $rowStatus = is_array($snapshotRow)
                    ? trim((string)($snapshotRow['rowStatus'] ?? 'unprocessed'))
                    : 'unprocessed';
                $enabled = $rowStatus !== 'disabled';
                $loaded = $rowStatus === 'loaded' || $rowStatus === 'disabled';
                $broken = $rowStatus === 'broken';
                $unresolved = $rowStatus === 'unresolved';

                $syntheticRowsByBreakpoint[$breakpoint][] = [
                    'transform' => $setName,
                    'assetId' => '',
                    'title' => $setName . ' ' . $breakpoint . 'px placeholder',
                    'enabled' => $enabled,
                    'isVisible' => true,
                    'loaded' => $loaded,
                    'broken' => $broken,
                    'unresolved' => $unresolved,
                    'sourceUsed' => $previewSrc,
                    'src' => $previewSrc,
                    'rendered' => [
                        'width' => $snapshotRenderedWidth,
                        'height' => $snapshotRenderedHeight,
                    ],
                    'intrinsic' => [
                        'width' => $snapshotRenderedWidth,
                        'height' => $snapshotRenderedHeight,
                    ],
                    'transformDimensions' => [
                        'width' => $width,
                        'height' => $height,
                        'autoDimension' => $autoDimension,
                    ],
                ];
            }
        }

        return $this->renderResultReview(
            ['rowsByBreakpoint' => $syntheticRowsByBreakpoint],
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
            hideRenderedApply: true,
            hideAssetPagination: true,
            reviewMode: self::REVIEW_MODE_SAVED,
        );
    }

    private function renderReviewWarningsMarkup(array $warnings, bool $showEmptyState = true): string
    {
        if ($warnings === []) {
            return $showEmptyState
                ? '<div class="bpi-warning-item bpi-warning-item-success">No warnings detected.</div>'
                : '';
        }

        $chunks = [];
        foreach ($warnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }

            $code = $this->escapeReviewHtml(
                $this->buildReviewWarningLabel((string)($warning['code'] ?? 'warning'))
            );
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
            $warningActions = $this->buildReviewWarningActionsMarkup((string)($warning['code'] ?? 'warning'));
            $messageMarkup = '<div class="bpi-warning-detail"><p>' . $message . '</p></div>';

            $chunks[] = sprintf(
                '<div class="%s"><div class="bpi-warning-copy"><h3 class="bpi-warning-heading">%s</h3></div>%s%s%s%s</div>',
                $this->buildReviewWarningClass((string)($warning['code'] ?? 'warning')),
                $code,
                $messageMarkup,
                $transformDetail,
                $rowCount,
                $warningActions,
            );
        }

        if ($chunks === []) {
            return $showEmptyState
                ? '<div class="bpi-warning-item bpi-warning-item-success">No warnings detected.</div>'
                : '';
        }

        return implode('', $chunks);
    }

    private function buildReviewWarningClass(string $code): string
    {
        if ($code === 'missing-set-definitions') {
            return 'bpi-warning-item bpi-warning-item-danger';
        }

        return 'bpi-warning-item bpi-warning-item-neutral';
    }

    private function buildReviewWarningLabel(string $code): string
    {
        return match ($code) {
            'missing-set-definitions' => 'Transform Set Missing',
            default => $code,
        };
    }

    private function buildReviewWarningActionsMarkup(string $code): string
    {
        if ($code !== 'missing-set-definitions') {
            return '';
        }

        return '<div class="bpi-warning-actions">'
            . '<button type="button" class="btn small bpi-warning-apply-rendered"'
            . ' aria-label="Set all breakpoints to rendered values"'
            . ' title="Set all breakpoints to rendered values"'
            . ' data-on:click="@post(el.closest(\'.bpi-transforms-page\').dataset.applyCardOperationUrl || \'/actions/craft-breakpoint-images/transforms/apply-card-operation\', {contentType: \'json\', payload: {setName: el.closest(\'.bpi-transform-card\').dataset.set || \'\', field: \'renderedValues\', includeEscapeWidth: (el.closest(\'.bpi-transform-card\').dataset.includeEscapeWidth || \'0\') === \'1\', renderedRows: JSON.parse(el.closest(\'.bpi-transform-card\').dataset.renderedRows || \'[]\'), baseVersion: Number($editor.baseVersion || 1), ...(Craft && Craft.csrfTokenName && Craft.csrfTokenValue ? {[Craft.csrfTokenName]: Craft.csrfTokenValue} : {})}})">'
            . 'Set to rendered'
            . '</button>'
            . '</div>';
    }

    private function buildReviewCardsMarkup(
        array $rowsByBreakpoint,
        array $breakpoints,
        array $warningsByTransform,
        array $editScopeBySet,
        array $editTabBySet,
        array $selectedAssetKeyBySet,
        array $preferredOrderBySet,
        array &$normalizedScopeState,
        array &$normalizedTabState,
        bool $hideRenderedApply,
        array &$normalizedSelectedAssetKeyBySet,
        bool $hideAssetPagination,
        string $reviewMode,
    ): string {
        $isProcessedReview = $reviewMode === self::REVIEW_MODE_PROCESSED;
        $transformNames = $this->collectReviewTransformNames($rowsByBreakpoint);
        $transformNames = $this->orderReviewTransformNames($transformNames, $warningsByTransform, $preferredOrderBySet);
        if ($transformNames === []) {
            return '<div class="bpi-empty-state light">No transform sets found in results.</div>';
        }

        $configuredBreakpoints = $breakpoints !== [] ? $breakpoints : $this->getReviewConfiguredBreakpoints();
        $escapeBreakpoint = $this->getReviewEscapeBreakpoint();
        $storedTransforms = $this->getReviewStoredTransforms();
        $latestRunSnapshot = $this->getLatestRunSnapshotForReview();
        $latestRunSummariesByTransform = $this->buildLatestRunSummaryByTransform($latestRunSnapshot);
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
            $cardWarnings = $warningsByTransform[$transformName] ?? [];
            $cardWarningsMarkup = $this->renderReviewWarningsMarkup($cardWarnings, false);
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

            $assetCollection = $this->buildReviewAssetCollectionForTransform(
                $rowsByBreakpoint,
                $transformName,
                $transformBreakpoints,
            );
            $assetKeys = $assetCollection['assetKeys'];
            $selectedAssetKey = $this->normalizeReviewSelectedAssetKey(
                $selectedAssetKeyBySet[$transformName] ?? null,
                $assetKeys,
            );
            $normalizedSelectedAssetKeyBySet[$transformName] = $selectedAssetKey;
            $selectedAssetRowsByBreakpoint = $this->buildReviewSelectedAssetRowsByBreakpoint(
                $assetCollection['rowsByAssetByBreakpoint'],
                $selectedAssetKey,
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

            $cardSignalsStructural = [
                'editor' => [
                    'cards' => [
                        $signalKey => [
                            'ratioSourceDimension' => 'w',
                            'ratioSourceBreakpoint' => $ratioSourceBreakpointDefault,
                            'activeTab' => $tab,
                            'scopeMode' => $scope['mode'],
                            'scopeBreakpoint' => $scope['mode'] === 'breakpoint' ? (string)$scope['breakpoint'] : '',
                            'scopeActive' => $this->isReviewScopeActive($scope) ? '1' : '0',
                            'selectedAssetKey' => $selectedAssetKey,
                        ],
                    ],
                ],
            ];

            $cardSignalsVolatile = [
                'editor' => [
                    'cards' => [
                        $signalKey => [
                            'widthInput' => $scopeValues['widthInput'],
                            'heightInput' => $scopeValues['heightInput'],
                            'widthAuto' => $scopeValues['widthAuto'],
                            'heightAuto' => $scopeValues['heightAuto'],
                            'ratioWidthInput' => $scopeValues['widthInput'],
                            'ratioHeightInput' => $scopeValues['heightInput'],
                        ],
                    ],
                ],
            ];

            $cardSignalsStructuralJson = json_encode($cardSignalsStructural, JSON_UNESCAPED_SLASHES);
            if (!is_string($cardSignalsStructuralJson)) {
                $cardSignalsStructuralJson = '{"editor":{"cards":{}}}';
            }

            $cardSignalsVolatileJson = json_encode($cardSignalsVolatile, JSON_UNESCAPED_SLASHES);
            if (!is_string($cardSignalsVolatileJson)) {
                $cardSignalsVolatileJson = '{"editor":{"cards":{}}}';
            }

            $columnWidths = $this->calculateReviewBreakpointColumnWidths($transformBreakpoints);
            $previewLockHeightsByBreakpoint = $this->calculateReviewBreakpointPreviewLockHeights(
                $assetCollection['rowsByAssetByBreakpoint'],
                $transformBreakpoints,
                $columnWidths,
            );
            $referenceRenderedByBreakpoint = [];
            foreach ($transformBreakpoints as $breakpoint) {
                foreach ($assetKeys as $firstAssetKey) {
                    $firstRows = $assetCollection['rowsByAssetByBreakpoint'][$firstAssetKey][$breakpoint] ?? [];
                    if (!is_array($firstRows) || $firstRows === []) {
                        break;
                    }
                    $refSummary = $this->summarizeReviewRows($firstRows);
                    $refW = max(0, (int)($refSummary['renderedWidth'] ?? 0));
                    $refH = max(0, (int)($refSummary['renderedHeight'] ?? 0));
                    if ($refW > 0 && $refH > 0) {
                        $referenceRenderedByBreakpoint[$breakpoint] = ['width' => $refW, 'height' => $refH];
                    }
                    break;
                }
            }
            $breakpointColumns = '';
            $renderedRowsForTransform = [];
            foreach ($transformBreakpoints as $breakpoint) {
                $rows = $selectedAssetRowsByBreakpoint[$breakpoint] ?? [];
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
                    $previewLockHeightsByBreakpoint,
                    $signalKey,
                    $selectedBreakpoint,
                    $scope['mode'] === 'all',
                    $escapeBreakpoint,
                    $hideRenderedApply,
                    $reviewMode,
                    $referenceRenderedByBreakpoint[$breakpoint] ?? null,
                );
            }
            $assetMismatchByKey = ($isProcessedReview && !$hideAssetPagination)
                ? $this->buildReviewAssetMismatchByKey(
                    $assetKeys,
                    $assetCollection['rowsByAssetByBreakpoint'],
                    $transformBreakpoints,
                )
                : [];
            $assetPaginationHtml = $this->buildReviewAssetPaginationMarkup(
                $assetKeys,
                $assetCollection['assetLabelsByKey'],
                $assetMismatchByKey,
                $selectedAssetKey,
                $signalKey,
                $hideAssetPagination,
            );

            $slug = $this->slugifyReviewTransformName($transformName);
            $editPanelId = 'bpi-edit-panel-' . $slug;
            $activeDimensions = $tab === 'dimensions';
            $activeRatio = $tab === 'ratio';
            $scopeLabel = $scope['mode'] === 'all'
                ? 'All'
                : ($scope['mode'] === 'breakpoint' ? ($scope['breakpoint'] . 'px') : 'Select scope');
            $latestRunSummaryForTransform = $latestRunSummariesByTransform[$transformName] ?? null;
            $hasMismatchWarning = $isProcessedReview
                && is_array($latestRunSummaryForTransform)
                && (($latestRunSummaryForTransform['hasMismatch'] ?? false) === true);

            $lastProcessPanelHtml = $this->buildLastProcessPanelMarkup(
                $latestRunSnapshot,
                $latestRunSummaryForTransform,
                $transformName,
            );

            $renderedRowsForTransformJson = json_encode($renderedRowsForTransform, JSON_UNESCAPED_SLASHES);
            if (!is_string($renderedRowsForTransformJson)) {
                $renderedRowsForTransformJson = '[]';
            }

            $cards[] = $this->renderReviewTemplate('transform-card-template.twig', [
                'transformNameEscaped' => $this->escapeReviewHtml($transformName),
                'signalKey' => $this->escapeReviewHtml($signalKey),
                'cardSignalsStructural' => $this->escapeReviewHtml($cardSignalsStructuralJson),
                'cardSignalsVolatile' => $this->escapeReviewHtml($cardSignalsVolatileJson),
                'cardWarningStateClass' => ($cardWarningsMarkup !== '' || $hasMismatchWarning)
                    ? 'bpi-transform-card-warning'
                    : '',
                'cardWarningsHtml' => $cardWarningsMarkup !== ''
                    ? '<div class="bpi-transform-card-warnings">' . $cardWarningsMarkup . '</div>'
                    : '',
                'includeEscapeWidth' => $includeEscapeWidth ? '1' : '0',
                'renderedRowsForTransformJson' => $this->escapeReviewHtml($renderedRowsForTransformJson),
                'selectedAssetKey' => $this->escapeReviewHtml($selectedAssetKey),
                'renderedApplyHiddenClass' => $hideRenderedApply ? 'bpi-force-hidden' : '',
                'breakpointColumns' => $breakpointColumns,
                'assetPaginationHtml' => $assetPaginationHtml,
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
                'dimensionsPanelActiveClass' => $activeDimensions ? 'active' : '',
                'dimensionsPanelHiddenAttr' => $activeDimensions ? '' : 'hidden',
                'ratioPanelActiveClass' => $activeRatio ? 'active' : '',
                'ratioPanelHiddenAttr' => $activeRatio ? '' : 'hidden',
                'widthInputId' => $this->escapeReviewHtml($editPanelId . '-width'),
                'heightInputId' => $this->escapeReviewHtml($editPanelId . '-height'),
                'ratioWidthInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-width'),
                'ratioHeightInputId' => $this->escapeReviewHtml($editPanelId . '-ratio-height'),
                'ratioSourceName' => $this->escapeReviewHtml($editPanelId . '-ratio-source'),
                'ratioSourceBreakpointOptions' => $ratioSourceBreakpointOptions,
                'lastProcessPanelHtml' => $lastProcessPanelHtml,
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
        array $previewLockHeightsByBreakpoint,
        string $signalKey,
        ?int $selectedBreakpoint,
        bool $allSelected,
        ?int $escapeBreakpoint,
        bool $hideRenderedApply,
        string $reviewMode,
        ?array $referenceRendered = null,
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
        $currentWidth = $this->normalizeNullablePositiveInt($currentRow['width'] ?? null);
        $currentHeight = $this->normalizeNullablePositiveInt($currentRow['height'] ?? null);
        $autoDimension = $this->normalizeAutoDimension($currentRow['autoDimension'] ?? null);

        $displayWidth = $renderedWidth;
        $displayHeight = $renderedHeight;
        if (is_array($previewRow)) {
            $previewRenderedWidth = $this->toNonNegativeInt($previewRow['rendered']['width'] ?? 0);
            $previewRenderedHeight = $this->toNonNegativeInt($previewRow['rendered']['height'] ?? 0);
            if ($previewRenderedWidth > 0 && $previewRenderedHeight > 0) {
                $displayWidth = $previewRenderedWidth;
                $displayHeight = $previewRenderedHeight;
            }

            if ($displayWidth < 1 || $displayHeight < 1) {
                $previewTransformDimensions = is_array($previewRow['transformDimensions'] ?? null)
                    ? $previewRow['transformDimensions']
                    : [];
                [$fallbackWidth, $fallbackHeight] = $this->resolveInitialPreviewBoxDimensions(
                    $this->normalizeNullablePositiveInt($previewTransformDimensions['width'] ?? null),
                    $this->normalizeNullablePositiveInt($previewTransformDimensions['height'] ?? null),
                    $this->normalizeAutoDimension($previewTransformDimensions['autoDimension'] ?? null),
                );

                if ($fallbackWidth > 0 && $fallbackHeight > 0) {
                    $displayWidth = $fallbackWidth;
                    $displayHeight = $fallbackHeight;
                }
            }
        }

        if ($displayWidth < 1 || $displayHeight < 1) {
            if ($previewSrc !== '' && $breakpoint > 0) {
                // Keep unknown preview dimensions bounded to breakpoint box to avoid oversizing.
                $displayWidth = $breakpoint;
                $displayHeight = $breakpoint;
            } else {
                [$displayWidth, $displayHeight] = $this->resolveInitialPreviewBoxDimensions(
                    $currentWidth,
                    $currentHeight,
                    $autoDimension,
                );
            }
        }

        $aspectRatio = $displayWidth > 0 && $displayHeight > 0
            ? $displayWidth . ' / ' . $displayHeight
            : '1 / 1';
        $relativeWidth = $breakpoint > 0
            ? max(0.0, min(100.0, ($displayWidth / $breakpoint) * 100))
            : 0.0;

        $widthClass = $this->getReviewRenderedDimensionClass($renderedWidth, $currentWidth, $autoDimension, 'width');
        $heightClass = $this->getReviewRenderedDimensionClass($renderedHeight, $currentHeight, $autoDimension, 'height');
        $renderedApplyNoop = $this->isReviewRenderedApplyNoop(
            $renderedRowsPayload,
            $currentWidth,
            $currentHeight,
            $autoDimension,
        );

        $currentEnabled = ($currentRow['enabled'] ?? true) === true;
        $previewMedia = $currentEnabled
            ? ($previewSrc !== ''
                ? sprintf(
                    '<img src="%s" alt="%s" class="bpi_breakpoint-result-image" draggable="false" style="--bpi-aspect-ratio:%s;">',
                    $this->escapeReviewHtml($previewSrc),
                    $this->escapeReviewHtml('Preview ' . $transformName . ' ' . $breakpoint . 'px'),
                    $this->escapeReviewHtml($aspectRatio),
                )
                : sprintf(
                    '<div class="bpi_breakpoint-result-image" style="--bpi-aspect-ratio:%s;"></div>',
                    $this->escapeReviewHtml($aspectRatio),
                ))
            : '';

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
        $hasBreakpointMismatch = $reviewMode === self::REVIEW_MODE_PROCESSED
            && $this->hasReviewMismatchForRowsReference($rows, $referenceRendered);

        $isSelected = $allSelected || ($selectedBreakpoint !== null && $selectedBreakpoint === $breakpoint);
        $breakpointColumnWidth = (float)($breakpointColumnWidths[(string)$breakpoint] ?? 1.0);
        if ($breakpointColumnWidth < 1.0) {
            $breakpointColumnWidth = 1.0;
        }
        $previewLockHeight = max(48, (int)($previewLockHeightsByBreakpoint[(string)$breakpoint] ?? 48));

        return $this->renderReviewTemplate('breakpoint-column-template.twig', [
            'breakpointColumnSelectedClass' => $isSelected ? 'bpi-breakpoint-column-selected' : '',
            'breakpointColumnMismatchClass' => $hasBreakpointMismatch ? 'bpi-breakpoint-column-mismatch' : '',
            'breakpointColumnDisabledClass' => !$currentEnabled ? 'bpi-breakpoint-column-disabled' : '',
            'breakpoint' => (string)$breakpoint,
            'breakpointColumnWidth' => (string)$breakpointColumnWidth,
            'previewLockHeight' => (string)$previewLockHeight,
            'signalKey' => $this->escapeReviewHtml($signalKey),
            'currentWidthValue' => $currentWidth !== null ? (string)$currentWidth : '',
            'currentHeightValue' => $currentHeight !== null ? (string)$currentHeight : '',
            'currentEnabledValue' => $currentEnabled ? '1' : '0',
            'currentAutoDimension' => $autoDimension ?? '',
            'escapeBadge' => $escapeBadge,
            'hiddenBadge' => $hiddenBadge,
            'unloadedBadge' => $unloadedBadge,
            'breakpointEnableOnClass' => $currentEnabled ? 'on' : '',
            'breakpointEnableTitle' => $this->escapeReviewHtml(($currentEnabled ? 'Disable' : 'Enable') . ' ' . $breakpoint . 'px breakpoint'),
            'breakpointEnableAriaLabel' => $this->escapeReviewHtml(($currentEnabled ? 'Disable' : 'Enable') . ' ' . $breakpoint . 'px breakpoint'),
            'breakpointEnableAriaChecked' => $currentEnabled ? 'true' : 'false',
            'renderedRowsPayloadJson' => $this->escapeReviewHtml($renderedRowsPayloadJson),
            'breakpointDisabledAttr' => $renderedRowsPayload === [] ? 'disabled' : '',
            'breakpointRenderedApplyMatchClass' => $renderedApplyNoop ? 'bpi-rendered-apply-single-noop' : '',
            'breakpointRenderedApplyAriaLabel' => $this->escapeReviewHtml(
                ($renderedApplyNoop ? 'Rendered values already match for ' : 'Apply rendered values for ')
                . $breakpoint
                . 'px'
            ),
            'breakpointRenderedApplyTitle' => $this->escapeReviewHtml(
                ($renderedApplyNoop ? 'Rendered values already match for ' : 'Apply rendered values for ')
                . $breakpoint
                . 'px'
            ),
            'breakpointRenderedApplyIconName' => $renderedApplyNoop ? 'check' : 'arrow-down',
            'breakpointRenderedApplyHiddenClass' => $hideRenderedApply ? 'bpi-force-hidden' : '',
            'breakpointRenderedRowHiddenClass' => $hideRenderedApply ? 'bpi-force-hidden' : '',
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

                $loaded = ($row['loaded'] ?? false) === true;
                $broken = ($row['broken'] ?? false) === true;
                $unresolved = ($row['unresolved'] ?? false) === true;
                $transformName = (string)($row['transform'] ?? 'unknown');
                $assetId = trim((string)($row['assetId'] ?? ''));
                $sourceUsed = (string)($row['sourceUsed'] ?? '');
                $src = (string)($row['src'] ?? ($row['sourceUsed'] ?? ''));
                $title = (string)($row['title'] ?? '');

                if ($loaded) {
                    $broken = false;
                    $unresolved = false;
                } elseif ($broken) {
                    $loaded = false;
                    $unresolved = false;
                } elseif ($unresolved) {
                    $loaded = false;
                    $broken = false;
                }

                $normalizedRows[] = [
                    'assetId' => $assetId,
                    'assetKey' => $this->buildReviewAssetKey($transformName, $assetId, $sourceUsed, $src, $title),
                    'rowKey' => $this->buildReviewRowKey($breakpoint, $transformName, $assetId, $sourceUsed, $src, $title),
                    'transform' => $transformName,
                    'title' => $title,
                    'enabled' => ($row['enabled'] ?? true) === true,
                    'isVisible' => ($row['isVisible'] ?? false) === true,
                    'loaded' => $loaded,
                    'broken' => $broken,
                    'unresolved' => $unresolved,
                    'sourceUsed' => $sourceUsed,
                    'src' => $src,
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

    private function orderReviewTransformNames(
        array $transformNames,
        array $warningsByTransform,
        array $preferredOrderBySet = [],
    ): array
    {
        $preferredPositions = [];
        foreach ($preferredOrderBySet as $index => $transformName) {
            if (!is_string($transformName) || trim($transformName) === '') {
                continue;
            }

            $normalizedName = trim($transformName);
            if (array_key_exists($normalizedName, $preferredPositions)) {
                continue;
            }

            $preferredPositions[$normalizedName] = $index;
        }

        usort($transformNames, static function (string $left, string $right) use ($warningsByTransform, $preferredPositions): int {
            $leftHasWarnings = !empty($warningsByTransform[$left]);
            $rightHasWarnings = !empty($warningsByTransform[$right]);

            if ($leftHasWarnings !== $rightHasWarnings) {
                return $leftHasWarnings ? -1 : 1;
            }

            $leftPosition = $preferredPositions[$left] ?? null;
            $rightPosition = $preferredPositions[$right] ?? null;

            if ($leftPosition !== null && $rightPosition !== null) {
                return $leftPosition <=> $rightPosition;
            }

            if ($leftPosition !== null) {
                return -1;
            }

            if ($rightPosition !== null) {
                return 1;
            }

            return strcmp($left, $right);
        });

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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getLatestRunSnapshotRowsByTransformAndBreakpoint(): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $snapshot = $this->_plugin->getTelemetry()->getLatestRunSnapshot();
        if (!is_array($snapshot)) {
            return [];
        }

        $rows = isset($snapshot['rows']) && is_array($snapshot['rows'])
            ? $snapshot['rows']
            : [];
        if ($rows === []) {
            return [];
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

        return $indexed;
    }

    private function getLatestRunSnapshotForReview(): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        $snapshot = $this->_plugin->getTelemetry()->getLatestRunSnapshot();
        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, array<string, mixed>>
     */
    public function buildLatestRunHealthByTransform(?array $snapshot = null): array
    {
        $resolvedSnapshot = is_array($snapshot) ? $snapshot : $this->getLatestRunSnapshotForReview();
        if (!is_array($resolvedSnapshot)) {
            return [];
        }

        $rowsPayloadStatusReliable = ($resolvedSnapshot['rowsPayloadStatusReliable'] ?? true) === true;
        $storedAutoDimensionsByTransform = $this->buildStoredAutoDimensionsByTransformAndBreakpoint();

        $rowsPayload = isset($resolvedSnapshot['rowsPayload']) && is_array($resolvedSnapshot['rowsPayload'])
            ? $resolvedSnapshot['rowsPayload']
            : [];
        if ($rowsPayload === []) {
            return [];
        }

        $payloadByTransform = [];
        foreach ($rowsPayload as $payloadRow) {
            if (!is_array($payloadRow)) {
                continue;
            }

            $transformHandle = trim((string)($payloadRow['transformHandle'] ?? ''));
            $breakpointWidth = isset($payloadRow['breakpointWidth']) && is_numeric($payloadRow['breakpointWidth'])
                ? (int)$payloadRow['breakpointWidth']
                : 0;
            if ($transformHandle === '' || $breakpointWidth <= 0) {
                continue;
            }

            $autoDimension = $this->normalizeAutoDimension($payloadRow['autoDimension'] ?? null)
                ?? ($storedAutoDimensionsByTransform[$transformHandle][$breakpointWidth] ?? null);

            $payloadByTransform[$transformHandle][$breakpointWidth][] = [
                'assetId' => trim((string)($payloadRow['assetId'] ?? '')),
                'rowStatus' => $this->normalizeLatestRunRowStatus((string)($payloadRow['rowStatus'] ?? '')),
                'renderedWidth' => max(0, (int)($payloadRow['renderedWidth'] ?? 0)),
                'renderedHeight' => max(0, (int)($payloadRow['renderedHeight'] ?? 0)),
                'autoDimension' => $autoDimension,
            ];
        }

        if ($payloadByTransform === []) {
            return [];
        }

        $healthByTransform = [];

        foreach ($payloadByTransform as $transformHandle => $breakpointEntriesByWidth) {
            $breakpointRows = $this->buildLatestRunBreakpointHealthRows(
                $breakpointEntriesByWidth,
                $rowsPayloadStatusReliable,
            );

            $mismatchBreakpoints = [];
            foreach ($breakpointRows as $breakpointRow) {
                if (!is_array($breakpointRow)) {
                    continue;
                }

                $statusLabel = strtolower(trim((string)($breakpointRow['statusLabel'] ?? 'matching')));
                $breakpointWidth = isset($breakpointRow['breakpointWidth']) && is_numeric($breakpointRow['breakpointWidth'])
                    ? (int)$breakpointRow['breakpointWidth']
                    : 0;

                if ($statusLabel === 'mismatches' && $breakpointWidth > 0) {
                    $mismatchBreakpoints[] = $breakpointWidth;
                }
            }

            sort($mismatchBreakpoints, SORT_NUMERIC);
            $healthByTransform[$transformHandle] = [
                'hasMismatch' => $mismatchBreakpoints !== [],
                'mismatchBreakpointCount' => count($mismatchBreakpoints),
                'mismatchBreakpoints' => $mismatchBreakpoints,
                'breakpointRows' => $breakpointRows,
            ];
        }

        return $healthByTransform;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $breakpointEntriesByWidth
     * @param array<int, array<string, mixed>> $currentRowsByBreakpoint
     * @param bool $statusReliable
     * @return array<int, array<string, mixed>>
     */
    private function buildLatestRunBreakpointHealthRows(
        array $breakpointEntriesByWidth,
        bool $statusReliable,
    ): array
    {
        ksort($breakpointEntriesByWidth, SORT_NUMERIC);
        $rows = [];

        foreach ($breakpointEntriesByWidth as $breakpointWidth => $breakpointEntries) {
            if (!is_array($breakpointEntries) || $breakpointEntries === []) {
                continue;
            }

            $referenceEntry = $breakpointEntries[0];
            foreach ($breakpointEntries as $candidateEntry) {
                if (($candidateEntry['renderedWidth'] ?? 0) > 0 && ($candidateEntry['renderedHeight'] ?? 0) > 0) {
                    $referenceEntry = $candidateEntry;
                    break;
                }
            }

            $expectedAssetWidth = max(0, (int)($referenceEntry['renderedWidth'] ?? 0));
            $expectedAssetHeight = max(0, (int)($referenceEntry['renderedHeight'] ?? 0));
            $comparison = $this->resolveLatestRunDimensionComparison($breakpointEntries);
            $compareWidth = $comparison['compareWidth'];
            $compareHeight = $comparison['compareHeight'];
            $mismatchDetails = [];

            foreach ($breakpointEntries as $entryIndex => $entry) {
                $assetLabel = trim((string)($entry['assetId'] ?? ''));
                if ($assetLabel === '') {
                    $assetLabel = 'Asset ' . (string)($entryIndex + 1);
                }

                $status = $this->normalizeLatestRunRowStatus((string)($entry['rowStatus'] ?? ''));
                $renderedWidth = max(0, (int)($entry['renderedWidth'] ?? 0));
                $renderedHeight = max(0, (int)($entry['renderedHeight'] ?? 0));

                if ($statusReliable && $status !== 'loaded') {
                    $mismatchDetails[] = $assetLabel . ': status ' . $status;
                }

                if ($renderedWidth < 1 || $renderedHeight < 1) {
                    $missingComparedWidth = $compareWidth && $renderedWidth < 1;
                    $missingComparedHeight = $compareHeight && $renderedHeight < 1;
                    if ($statusReliable && ($missingComparedWidth || $missingComparedHeight)) {
                        $mismatchDetails[] = $assetLabel . ': size unavailable';
                    }
                    continue;
                }

                $widthMismatch = $compareWidth
                    && $expectedAssetWidth > 0
                    && abs($renderedWidth - $expectedAssetWidth) > 2;
                $heightMismatch = $compareHeight
                    && $expectedAssetHeight > 0
                    && abs($renderedHeight - $expectedAssetHeight) > 2;

                if ($widthMismatch || $heightMismatch) {
                    if ($widthMismatch && $heightMismatch) {
                        $mismatchDetails[] = $assetLabel . ': '
                            . $renderedWidth . 'x' . $renderedHeight
                            . ' expected asset ' . $expectedAssetWidth . 'x' . $expectedAssetHeight;
                    } elseif ($widthMismatch) {
                        $mismatchDetails[] = $assetLabel . ': '
                            . 'width ' . $renderedWidth
                            . ' expected asset width ' . $expectedAssetWidth;
                    } else {
                        $mismatchDetails[] = $assetLabel . ': '
                            . 'height ' . $renderedHeight
                            . ' expected asset height ' . $expectedAssetHeight;
                    }
                }
            }

            $isMismatch = $mismatchDetails !== [];
            $visibleDetails = array_slice($mismatchDetails, 0, 6);
            if (count($mismatchDetails) > 6) {
                $visibleDetails[] = '+' . (string)(count($mismatchDetails) - 6) . ' more';
            }

            $rows[] = [
                'breakpointWidth' => (int)$breakpointWidth,
                'statusLabel' => $isMismatch ? 'Mismatches' : 'Matching',
                'mismatchInfo' => $isMismatch ? implode('; ', $visibleDetails) : '-',
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, array<string, mixed>>
     */
    private function buildLatestRunSummaryByTransform(?array $snapshot): array
    {
        if (!is_array($snapshot)) {
            return [];
        }

        $rows = isset($snapshot['rows']) && is_array($snapshot['rows'])
            ? $snapshot['rows']
            : [];
        if ($rows === []) {
            return [];
        }

        $summaries = [];
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

            if (!isset($summaries[$transformHandle])) {
                $summaries[$transformHandle] = [
                    'rowsTotal' => 0,
                    'previewCount' => 0,
                    'statusCounts' => [
                        'loaded' => 0,
                        'broken' => 0,
                        'unresolved' => 0,
                        'disabled' => 0,
                        'unprocessed' => 0,
                    ],
                    'statusByBreakpoint' => [],
                    'hasMismatch' => false,
                    'mismatchBreakpointCount' => 0,
                    'mismatchBreakpoints' => [],
                ];
            }

            $rowStatus = $this->normalizeLatestRunRowStatus((string)($row['rowStatus'] ?? ''));
            $displayAssetUrl = trim((string)($row['displayAssetUrl'] ?? ''));

            $summaries[$transformHandle]['rowsTotal'] += 1;
            if ($displayAssetUrl !== '') {
                $summaries[$transformHandle]['previewCount'] += 1;
            }

            if (!isset($summaries[$transformHandle]['statusCounts'][$rowStatus])) {
                $summaries[$transformHandle]['statusCounts'][$rowStatus] = 0;
            }

            $summaries[$transformHandle]['statusCounts'][$rowStatus] += 1;
            $summaries[$transformHandle]['statusByBreakpoint'][(string)$breakpointWidth] = $rowStatus;
        }

        $healthByTransform = $this->buildLatestRunHealthByTransform($snapshot);
        foreach ($healthByTransform as $transformHandle => $health) {
            if (!isset($summaries[$transformHandle])) {
                $summaries[$transformHandle] = [
                    'rowsTotal' => 0,
                    'previewCount' => 0,
                    'statusCounts' => [
                        'loaded' => 0,
                        'broken' => 0,
                        'unresolved' => 0,
                        'disabled' => 0,
                        'unprocessed' => 0,
                    ],
                    'statusByBreakpoint' => [],
                    'hasMismatch' => false,
                    'mismatchBreakpointCount' => 0,
                    'mismatchBreakpoints' => [],
                ];
            }

            $summaries[$transformHandle]['hasMismatch'] = ($health['hasMismatch'] ?? false) === true;
            $summaries[$transformHandle]['mismatchBreakpointCount'] = isset($health['mismatchBreakpointCount'])
                ? max(0, (int)$health['mismatchBreakpointCount'])
                : 0;
            $summaries[$transformHandle]['mismatchBreakpoints'] = isset($health['mismatchBreakpoints']) && is_array($health['mismatchBreakpoints'])
                ? array_values($health['mismatchBreakpoints'])
                : [];
        }

        foreach ($summaries as $transformHandle => $summary) {
            $statusByBreakpoint = $summary['statusByBreakpoint'];
            if (is_array($statusByBreakpoint)) {
                ksort($statusByBreakpoint, SORT_NUMERIC);
                $summaries[$transformHandle]['statusByBreakpoint'] = $statusByBreakpoint;
            }
        }

        return $summaries;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @param array<string, mixed>|null $transformSummary
     * @param string $transformHandle
     */
    private function buildLastProcessPanelMarkup(?array $snapshot, ?array $transformSummary, string $transformHandle): string
    {
        if (!is_array($snapshot)) {
            return '<aside class="bpi-transform-last-process-pane"><div class="bpi-transform-last-process-header"><span class="bpi-transform-last-process-status-icon bpi-transform-last-process-status-icon-unknown" aria-label="Unknown" title="Unknown"><span data-icon="alert" aria-hidden="true"></span></span></div><p class="light bpi-transform-last-process-empty">No saved run data yet.</p></aside>';
        }

        $ranAtLabel = $this->formatLatestRunTimestamp($snapshot['ranAt'] ?? null);
        $entryId = is_numeric($snapshot['entryId'] ?? null) ? (int)$snapshot['entryId'] : 0;
        $mismatchBreakpointCount = is_array($transformSummary) && is_numeric($transformSummary['mismatchBreakpointCount'] ?? null)
            ? max(0, (int)$transformSummary['mismatchBreakpointCount'])
            : 0;
        $hasHealthData = is_array($transformSummary);
        $hasMismatch = $hasHealthData && $mismatchBreakpointCount > 0;

        if (!$hasHealthData) {
            $statusIconClass = 'bpi-transform-last-process-status-icon-unknown';
            $statusLabel = 'No Health Data';
            $statusIconName = 'alert';
        } elseif ($hasMismatch) {
            $statusIconClass = 'bpi-transform-last-process-status-icon-failed';
            $statusLabel = 'Needs Review';
            $statusIconName = 'alert';
        } else {
            $statusIconClass = 'bpi-transform-last-process-status-icon-success';
            $statusLabel = 'Transform Sets Valid';
            $statusIconName = 'check';
        }

        $actionLabel = $hasMismatch ? 'Review' : 'Details';
        $detailsButtonMarkup = sprintf(
            '<button type="button" class="btn small bpi-process-details-link" data-bpi-open-process-details="true" data-transform-handle="%s" data-entry-id="%s" title="%s" aria-label="%s">%s</button>',
            $this->escapeReviewHtml($transformHandle),
            $this->escapeReviewHtml((string)max(0, $entryId)),
            $this->escapeReviewHtml($actionLabel),
            $this->escapeReviewHtml($actionLabel),
            $this->escapeReviewHtml($actionLabel),
        );

        $mismatchMarkup = '';
        if ($hasMismatch) {
            $breakpointLabel = $mismatchBreakpointCount === 1 ? 'breakpoint' : 'breakpoints';
            $mismatchMarkup = sprintf(
                '<div class="bpi-transform-last-process-mismatch">'
                . '<span class="bpi-transform-last-process-mismatch-text">%s</span>'
                . '</div>',
                $this->escapeReviewHtml(sprintf('Mismatches in %d %s', $mismatchBreakpointCount, $breakpointLabel)),
            );
        }

        return sprintf(
            '<aside class="bpi-transform-last-process-pane%s">'
            . '<div class="bpi-transform-last-process-header">'
            . '<span class="bpi-transform-last-process-status-icon %s" aria-label="%s" title="%s"><span data-icon="%s" aria-hidden="true"></span></span>'
            . '<p class="bpi-transform-last-process-compact-meta">%s</p>'
            . '%s'
            . '</div>'
            . '%s'
            . '</aside>',
            $hasMismatch ? ' bpi-transform-last-process-pane-has-mismatch' : '',
            $this->escapeReviewHtml($statusIconClass),
            $this->escapeReviewHtml($statusLabel),
            $this->escapeReviewHtml($statusLabel),
            $this->escapeReviewHtml($statusIconName),
            $this->escapeReviewHtml($ranAtLabel),
            $detailsButtonMarkup,
            $mismatchMarkup,
        );
    }

    private function normalizeLatestRunRowStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        if ($normalized === 'success') {
            return 'loaded';
        }

        if ($normalized === 'failed' || $normalized === 'cancelled') {
            return 'unprocessed';
        }

        return match ($normalized) {
            'loaded', 'broken', 'unresolved', 'disabled', 'unprocessed' => $normalized,
            default => 'unprocessed',
        };
    }

    private function normalizeLatestRunStatus(string $status): string
    {
        $normalized = strtolower(trim($status));

        return match ($normalized) {
            'completed', 'success' => 'completed',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => 'unknown',
        };
    }

    private function formatLatestRunTimestamp(mixed $rawValue): string
    {
        $raw = trim((string)$rawValue);
        if ($raw === '') {
            return '-';
        }

        try {
            $date = new \DateTimeImmutable($raw);
            return $date->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $raw;
        }
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

    private function buildReviewAssetKey(
        string $transformName,
        string $assetId,
        string $sourceUsed,
        string $src,
        string $title,
    ): string {
        $normalizedTransform = trim($transformName) !== '' ? trim($transformName) : 'unknown';
        $normalizedAssetId = trim($assetId);

        if ($normalizedAssetId !== '') {
            return 'asset:' . $normalizedTransform . ':' . $normalizedAssetId;
        }

        $sourceSignature = $this->normalizeReviewSourceSignature($sourceUsed, $src, $title);
        return 'asset:' . $normalizedTransform . ':sig-' . substr(sha1($sourceSignature), 0, 16);
    }

    private function buildReviewRowKey(
        int $breakpoint,
        string $transformName,
        string $assetId,
        string $sourceUsed,
        string $src,
        string $title,
    ): string {
        return $this->buildReviewAssetKey($transformName, $assetId, $sourceUsed, $src, $title)
            . ':bp-' . (string)$breakpoint;
    }

    private function normalizeReviewSourceSignature(string $sourceUsed, string $src, string $title): string
    {
        $candidates = [
            trim($sourceUsed),
            trim($src),
            trim($title),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $querySplit = explode('?', $candidate, 2);
            $base = $querySplit[0] ?? $candidate;
            $hashSplit = explode('#', $base, 2);
            $normalized = trim((string)($hashSplit[0] ?? $base));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return 'missing-source';
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array{assetKeys: array<int, string>, rowsByAssetByBreakpoint: array<string, array<int, array<int, array<string, mixed>>>>, assetLabelsByKey: array<string, string>}
     */
    private function buildReviewAssetCollectionForTransform(
        array $rowsByBreakpoint,
        string $transformName,
        array $transformBreakpoints,
    ): array {
        $assetKeys = [];
        $assetSeen = [];
        $rowsByAssetByBreakpoint = [];
        $assetLabelsByKey = [];

        foreach ($transformBreakpoints as $breakpoint) {
            $rows = $this->getReviewRowsForTransformBreakpoint($rowsByBreakpoint, $transformName, $breakpoint);
            foreach ($rows as $row) {
                $assetKey = trim((string)($row['assetKey'] ?? ''));
                if ($assetKey === '') {
                    $assetKey = $this->buildReviewAssetKey(
                        (string)($row['transform'] ?? $transformName),
                        (string)($row['assetId'] ?? ''),
                        (string)($row['sourceUsed'] ?? ''),
                        (string)($row['src'] ?? ''),
                        (string)($row['title'] ?? ''),
                    );
                }

                if (!isset($assetSeen[$assetKey])) {
                    $assetSeen[$assetKey] = true;
                    $assetKeys[] = $assetKey;
                }

                if (!isset($rowsByAssetByBreakpoint[$assetKey])) {
                    $rowsByAssetByBreakpoint[$assetKey] = [];
                }

                if (!isset($rowsByAssetByBreakpoint[$assetKey][$breakpoint])) {
                    $rowsByAssetByBreakpoint[$assetKey][$breakpoint] = [];
                }

                $rowsByAssetByBreakpoint[$assetKey][$breakpoint][] = $row;

                if (!isset($assetLabelsByKey[$assetKey])) {
                    $assetLabelsByKey[$assetKey] = $this->buildReviewAssetLabel($row, count($assetKeys));
                }
            }
        }

        return [
            'assetKeys' => $assetKeys,
            'rowsByAssetByBreakpoint' => $rowsByAssetByBreakpoint,
            'assetLabelsByKey' => $assetLabelsByKey,
        ];
    }

    private function normalizeReviewSelectedAssetKey(mixed $rawSelectedAssetKey, array $assetKeys): string
    {
        if ($assetKeys === []) {
            return '';
        }

        $selectedAssetKey = is_string($rawSelectedAssetKey)
            ? trim($rawSelectedAssetKey)
            : '';

        if ($selectedAssetKey !== '' && in_array($selectedAssetKey, $assetKeys, true)) {
            return $selectedAssetKey;
        }

        return $assetKeys[0];
    }

    /**
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function buildReviewSelectedAssetRowsByBreakpoint(
        array $rowsByAssetByBreakpoint,
        string $selectedAssetKey,
        array $transformBreakpoints,
    ): array {
        $rowsByBreakpoint = [];
        foreach ($transformBreakpoints as $breakpoint) {
            $rows = $rowsByAssetByBreakpoint[$selectedAssetKey][$breakpoint] ?? [];
            $selectedRow = $this->pickReviewPreviewRow($rows);
            $rowsByBreakpoint[$breakpoint] = $selectedRow !== null ? [$selectedRow] : [];
        }

        return $rowsByBreakpoint;
    }

    private function buildReviewAssetLabel(array $row, int $fallbackIndex): string
    {
        $assetId = trim((string)($row['assetId'] ?? ''));
        if ($assetId !== '') {
            return $assetId;
        }

        $title = trim((string)($row['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        return 'Asset ' . (string)$fallbackIndex;
    }

    private function buildReviewAssetPaginationMarkup(
        array $assetKeys,
        array $assetLabelsByKey,
        array $assetMismatchByKey,
        string $selectedAssetKey,
        string $signalKey,
        bool $hideAssetPagination,
    ): string {
        if ($hideAssetPagination || count($assetKeys) < 2) {
            return '';
        }

        $buttons = '';
        foreach ($assetKeys as $assetIndex => $assetKey) {
            $label = trim((string)($assetLabelsByKey[$assetKey] ?? ''));
            if ($label === '') {
                $label = 'Asset ' . (string)($assetIndex + 1);
            }

            $assetKeyJs = json_encode($assetKey, JSON_UNESCAPED_SLASHES);
            if (!is_string($assetKeyJs)) {
                $assetKeyJs = '""';
            }

            $escapedAssetKey = $this->escapeReviewHtml($assetKey);
            $escapedLabel = $this->escapeReviewHtml($label);
            $escapedAssetKeyJs = $this->escapeReviewHtml($assetKeyJs);
            $isActive = $assetKey === $selectedAssetKey;
            $hasMismatch = ($assetMismatchByKey[$assetKey] ?? false) === true;

            $buttons .= sprintf(
                '<button type="button" class="btn small bpi-transform-asset-page%s%s" data-asset-key="%s" data-on:click="$editor.cards.%s.selectedAssetKey=%s" data-class:active="$editor.cards.%s.selectedAssetKey === %s" data-attr:aria-pressed="$editor.cards.%s.selectedAssetKey === %s ? \'true\' : \'false\'" aria-label="Show %s" title="Show %s"%s>%d</button>',
                $isActive ? ' active' : '',
                $hasMismatch ? ' bpi-transform-asset-page-mismatch' : '',
                $escapedAssetKey,
                $signalKey,
                $escapedAssetKeyJs,
                $signalKey,
                $escapedAssetKeyJs,
                $signalKey,
                $escapedAssetKeyJs,
                $escapedLabel,
                $escapedLabel,
                $isActive ? ' aria-pressed="true"' : '',
                $assetIndex + 1,
            );
        }

        return '<div class="bpi-transform-asset-pagination" role="toolbar" aria-label="Asset pagination">' . $buttons . '</div>';
    }

    /**
     * @param array<int, string> $assetKeys
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array<string, bool>
     */
    private function buildReviewAssetMismatchByKey(
        array $assetKeys,
        array $rowsByAssetByBreakpoint,
        array $transformBreakpoints,
    ): array {
        $referenceByBreakpoint = [];
        foreach ($transformBreakpoints as $breakpoint) {
            foreach ($assetKeys as $firstAssetKey) {
                $rows = $rowsByAssetByBreakpoint[$firstAssetKey][$breakpoint] ?? [];
                if (!is_array($rows) || $rows === []) {
                    break;
                }
                $comparison = $this->resolveReviewDimensionComparison($rows);
                $summary = $this->summarizeReviewRows($rows);
                $refW = max(0, (int)($summary['renderedWidth'] ?? 0));
                $refH = max(0, (int)($summary['renderedHeight'] ?? 0));

                $hasComparableWidth = !$comparison['compareWidth'] || $refW > 0;
                $hasComparableHeight = !$comparison['compareHeight'] || $refH > 0;
                if ($hasComparableWidth && $hasComparableHeight) {
                    $referenceByBreakpoint[$breakpoint] = ['width' => $refW, 'height' => $refH];
                }
                break;
            }
        }

        $assetMismatchByKey = [];

        foreach ($assetKeys as $assetKey) {
            $hasMismatch = false;

            foreach ($transformBreakpoints as $breakpoint) {
                $rows = $rowsByAssetByBreakpoint[$assetKey][$breakpoint] ?? [];
                if (!is_array($rows) || $rows === []) {
                    continue;
                }
                if ($this->hasReviewMismatchForRowsReference($rows, $referenceByBreakpoint[$breakpoint] ?? null)) {
                    $hasMismatch = true;
                    break;
                }
            }

            $assetMismatchByKey[$assetKey] = $hasMismatch;
        }

        return $assetMismatchByKey;
    }

    private function hasReviewMismatchForRowsReference(array $rows, ?array $referenceRendered): bool
    {
        $hasLoadedRow = false;

        foreach ($rows as $row) {
            $enabled = ($row['enabled'] ?? false) === true;
            if ($enabled && ($row['loaded'] ?? false) === true) {
                $hasLoadedRow = true;
            }

            if ($enabled && (($row['broken'] ?? false) === true || ($row['unresolved'] ?? false) === true)) {
                return true;
            }
        }

        if (!$hasLoadedRow || $referenceRendered === null) {
            return false;
        }

        $comparison = $this->resolveReviewDimensionComparison($rows);
        $compareWidth = $comparison['compareWidth'];
        $compareHeight = $comparison['compareHeight'];
        if (!$compareWidth && !$compareHeight) {
            return false;
        }

        $summary = $this->summarizeReviewRows($rows);
        $renderedWidth = max(0, (int)($summary['renderedWidth'] ?? 0));
        $renderedHeight = max(0, (int)($summary['renderedHeight'] ?? 0));

        if (($compareWidth && $renderedWidth < 1) || ($compareHeight && $renderedHeight < 1)) {
            return false;
        }

        $widthMismatch = $compareWidth
            && ($referenceRendered['width'] ?? 0) > 0
            && abs($renderedWidth - (int)$referenceRendered['width']) > 2;
        $heightMismatch = $compareHeight
            && ($referenceRendered['height'] ?? 0) > 0
            && abs($renderedHeight - (int)$referenceRendered['height']) > 2;

        return $widthMismatch || $heightMismatch;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{compareWidth: bool, compareHeight: bool}
     */
    private function resolveReviewDimensionComparison(array $rows): array
    {
        $compareWidth = true;
        $compareHeight = true;

        foreach ($rows as $row) {
            if (!is_array($row) || ($row['enabled'] ?? false) !== true) {
                continue;
            }

            $autoDimension = $this->normalizeAutoDimension($row['transformDimensions']['autoDimension'] ?? null);
            if ($autoDimension === 'width') {
                $compareWidth = false;
            }

            if ($autoDimension === 'height') {
                $compareHeight = false;
            }
        }

        return [
            'compareWidth' => $compareWidth,
            'compareHeight' => $compareHeight,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array{compareWidth: bool, compareHeight: bool}
     */
    private function resolveLatestRunDimensionComparison(array $entries): array
    {
        $compareWidth = true;
        $compareHeight = true;

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
            if ($autoDimension === 'width') {
                $compareWidth = false;
            }

            if ($autoDimension === 'height') {
                $compareHeight = false;
            }
        }

        return [
            'compareWidth' => $compareWidth,
            'compareHeight' => $compareHeight,
        ];
    }

    /**
     * @return array<string, array<int, string|null>>
     */
    private function buildStoredAutoDimensionsByTransformAndBreakpoint(): array
    {
        $storedTransforms = $this->getReviewStoredTransforms();
        if ($storedTransforms === []) {
            return [];
        }

        $autoDimensionsByTransform = [];

        foreach ($storedTransforms as $transformName => $transformDefinition) {
            if (!is_string($transformName) || $transformName === '' || !is_array($transformDefinition)) {
                continue;
            }

            $includeEscapeWidth = ($transformDefinition['includeEscapeWidth'] ?? false) === true;
            $breakpoints = $this->getBreakpointsForTransform($includeEscapeWidth);
            $entries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
                ? array_values($transformDefinition['transforms'])
                : [];

            foreach ($breakpoints as $index => $breakpoint) {
                if (!is_int($breakpoint) || $breakpoint <= 0) {
                    continue;
                }

                $entry = isset($entries[$index]) && is_array($entries[$index])
                    ? $entries[$index]
                    : [];

                $autoDimensionsByTransform[$transformName][$breakpoint] = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
            }
        }

        return $autoDimensionsByTransform;
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
            if (($row['broken'] ?? false) === true || ($row['unresolved'] ?? false) === true) {
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
            $widths[(string)$breakpoint] = ($breakpoint / $firstBreakpoint) * 160;
        }

        return $widths;
    }

    private function calculateReviewBreakpointPreviewLockHeights(
        array $rowsByAssetByBreakpoint,
        array $transformBreakpoints,
        array $breakpointColumnWidths,
    ): array {
        $globalLockHeight = 48;

        foreach ($transformBreakpoints as $breakpoint) {
            $columnWidth = (float)($breakpointColumnWidths[(string)$breakpoint] ?? 0.0);
            $availablePreviewWidth = max(1.0, $columnWidth - 20.0);

            foreach ($rowsByAssetByBreakpoint as $rowsByBreakpoint) {
                if (!is_array($rowsByBreakpoint)) {
                    continue;
                }

                $rows = $rowsByBreakpoint[$breakpoint] ?? [];
                if (!is_array($rows)) {
                    continue;
                }

                $summary = $this->summarizeReviewRows($rows);
                $displayWidth = max(0, (int)($summary['renderedWidth'] ?? 0));
                $displayHeight = max(0, (int)($summary['renderedHeight'] ?? 0));
                $previewRow = $this->pickReviewPreviewRow($rows);

                if (is_array($previewRow)) {
                    $previewRenderedWidth = $this->toNonNegativeInt($previewRow['rendered']['width'] ?? 0);
                    $previewRenderedHeight = $this->toNonNegativeInt($previewRow['rendered']['height'] ?? 0);
                    if ($previewRenderedWidth > 0 && $previewRenderedHeight > 0) {
                        $displayWidth = $previewRenderedWidth;
                        $displayHeight = $previewRenderedHeight;
                    }

                    if ($displayWidth < 1 || $displayHeight < 1) {
                        $previewTransformDimensions = is_array($previewRow['transformDimensions'] ?? null)
                            ? $previewRow['transformDimensions']
                            : [];
                        [$fallbackWidth, $fallbackHeight] = $this->resolveInitialPreviewBoxDimensions(
                            $this->normalizeNullablePositiveInt($previewTransformDimensions['width'] ?? null),
                            $this->normalizeNullablePositiveInt($previewTransformDimensions['height'] ?? null),
                            $this->normalizeAutoDimension($previewTransformDimensions['autoDimension'] ?? null),
                        );

                        if ($fallbackWidth > 0 && $fallbackHeight > 0) {
                            $displayWidth = $fallbackWidth;
                            $displayHeight = $fallbackHeight;
                        }
                    }
                }

                if (($displayWidth < 1 || $displayHeight < 1) && is_array($previewRow) && $breakpoint > 0) {
                    $previewSrc = (string)($previewRow['src'] ?? '');
                    if ($previewSrc !== '') {
                        $displayWidth = $breakpoint;
                        $displayHeight = $breakpoint;
                    }
                }

                if ($displayWidth < 1 || $displayHeight < 1 || $breakpoint < 1) {
                    continue;
                }

                $candidateHeight = (int)ceil(($availablePreviewWidth * $displayHeight) / $breakpoint);
                $globalLockHeight = max($globalLockHeight, $candidateHeight);
            }
        }

        $globalLockHeight = max(48, $globalLockHeight);
        $lockHeightsByBreakpoint = [];
        foreach ($transformBreakpoints as $breakpoint) {
            $lockHeightsByBreakpoint[(string)$breakpoint] = $globalLockHeight;
        }

        return $lockHeightsByBreakpoint;
    }

    private function getReviewRenderedDimensionClass(
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

        return '';
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

    /**
     * @param array<int, array<string, mixed>> $renderedRowsPayload
     */
    private function isReviewRenderedApplyNoop(
        array $renderedRowsPayload,
        ?int $currentWidth,
        ?int $currentHeight,
        ?string $autoDimension,
    ): bool {
        if ($renderedRowsPayload === []) {
            return false;
        }

        $candidateDimensionCount = 0;
        $hasComparedChange = false;

        foreach ($renderedRowsPayload as $renderedRow) {
            if (!is_array($renderedRow)) {
                continue;
            }

            $renderedWidth = $this->normalizeNullablePositiveInt($renderedRow['width'] ?? null);
            if ($renderedWidth !== null) {
                $candidateDimensionCount += 1;
                if ($autoDimension !== 'width' && $currentWidth !== $renderedWidth) {
                    $hasComparedChange = true;
                }
            }

            $renderedHeight = $this->normalizeNullablePositiveInt($renderedRow['height'] ?? null);
            if ($renderedHeight !== null) {
                $candidateDimensionCount += 1;
                if ($autoDimension !== 'height' && $currentHeight !== $renderedHeight) {
                    $hasComparedChange = true;
                }
            }
        }

        if ($candidateDimensionCount < 1) {
            return false;
        }

        return $hasComparedChange === false;
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
        $base = str_replace('-', '_', $this->slugifyReviewTransformName($transformName));
        return 't_' . $base . '_' . substr(sha1($transformName), 0, 8);
    }

    private function normalizeReviewTab(mixed $rawTab): string
    {
        $tab = is_string($rawTab) ? $rawTab : '';
        return in_array($tab, ['dimensions', 'ratio'], true) ? $tab : 'dimensions';
    }

    private function normalizeReviewMode(mixed $rawReviewMode): string
    {
        $reviewMode = is_string($rawReviewMode) ? strtolower(trim($rawReviewMode)) : '';
        return in_array($reviewMode, [self::REVIEW_MODE_PROCESSED, self::REVIEW_MODE_SAVED], true)
            ? $reviewMode
            : self::REVIEW_MODE_PROCESSED;
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

    private function buildReviewWarningsByTransform(array $rowsByBreakpoint): array
    {
        $warningsByTransform = [];
        $storedTransforms = $this->getReviewStoredTransforms();
        $configTransformNames = array_keys($storedTransforms);
        sort($configTransformNames, SORT_STRING);

        $observedTransformNames = $this->collectReviewTransformNames($rowsByBreakpoint);
        $missingDefinitions = array_values(array_diff($observedTransformNames, $configTransformNames));

        foreach ($missingDefinitions as $transformName) {
            $warningsByTransform[$transformName][] = $this->buildMissingSetDefinitionWarning();
        }

        return $warningsByTransform;
    }

    private function buildMissingSetDefinitionWarning(): array
    {
        return [
            'code' => 'missing-set-definitions',
            'message' => 'No transforms are saved for this set. Apply the rendered dimensions and/or edit the transforms.',
        ];
    }

    private function buildInitialReviewPlaceholderDataUri(
        ?int $width,
        ?int $height,
        ?string $autoDimension,
    ): string {
        [$boxWidth, $boxHeight] = $this->resolveInitialPreviewBoxDimensions($width, $height, $autoDimension);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" role="img" aria-label="Placeholder"><rect width="100%%" height="100%%" fill="#e7edf5"/><rect x="1" y="1" width="%3$d" height="%4$d" fill="none" stroke="#98a9be" stroke-width="2"/></svg>',
            $boxWidth,
            $boxHeight,
            max(1, $boxWidth - 2),
            max(1, $boxHeight - 2),
        );

        return 'data:image/svg+xml,' . rawurlencode($svg);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function resolveInitialPreviewBoxDimensions(?int $width, ?int $height, ?string $autoDimension): array
    {
        $effectiveWidth = $autoDimension === 'width' ? null : $width;
        $effectiveHeight = $autoDimension === 'height' ? null : $height;

        if ($effectiveWidth !== null && $effectiveHeight !== null) {
            return [$effectiveWidth, $effectiveHeight];
        }

        if ($effectiveWidth !== null) {
            $derivedHeight = (int)round(($effectiveWidth * self::INITIAL_PLACEHOLDER_DEFAULT_RATIO_HEIGHT) / self::INITIAL_PLACEHOLDER_DEFAULT_RATIO_WIDTH);
            return [$effectiveWidth, max(1, $derivedHeight)];
        }

        if ($effectiveHeight !== null) {
            $derivedWidth = (int)round(($effectiveHeight * self::INITIAL_PLACEHOLDER_DEFAULT_RATIO_WIDTH) / self::INITIAL_PLACEHOLDER_DEFAULT_RATIO_HEIGHT);
            return [max(1, $derivedWidth), $effectiveHeight];
        }

        return [
            self::INITIAL_PLACEHOLDER_FALLBACK_WIDTH,
            self::INITIAL_PLACEHOLDER_FALLBACK_HEIGHT,
        ];
    }

    private function countReviewWarningsByTransform(array $warningsByTransform): int
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