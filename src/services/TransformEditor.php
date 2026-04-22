<?php

namespace craftyhedge\craftbreakpointimages\services;

use Craft;
use craft\elements\Entry;
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

    /**
     * Builds sidebar rows combining observed-unsaved transform handles (first)
     * with configured transform sets. Used by the transforms page sidebar.
     *
     * @return array<int, array{name: string, isObservedUnsaved: bool, entryId: ?int, sourceUrl: ?string}>
     */
    public function buildSidebarTransformRows(): array
    {
        $configured = $this->getReviewStoredTransforms();
        $configuredNames = array_values(array_filter(
            array_keys($configured),
            static fn($name): bool => is_string($name) && $name !== '',
        ));

        $rows = [];

        if ($this->_plugin !== null) {
            $observed = $this->_plugin->getTelemetry()->getObservedUnsavedHandles($configuredNames);
            foreach ($observed as $entry) {
                $rows[] = [
                    'name' => (string)$entry['handle'],
                    'isObservedUnsaved' => true,
                    'entryId' => $entry['entryId'] ?? null,
                    'sourceUrl' => $entry['sourceUrl'] ?? null,
                ];
            }
        }

        foreach ($configuredNames as $name) {
            $rows[] = [
                'name' => $name,
                'isObservedUnsaved' => false,
                'entryId' => null,
                'sourceUrl' => null,
            ];
        }

        return $rows;
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

        $entries = $this->normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

        $preserveAutos = $scopeMode !== 'breakpoint';

        $applyDimensionValue = function (array $entry) use ($dimension, $value): array {
            $hasLockedRatio = ($entry['ratioLocked'] ?? false) === true
                && $this->normalizeNullablePositiveInt($entry['ratioWidth'] ?? null) !== null
                && $this->normalizeNullablePositiveInt($entry['ratioHeight'] ?? null) !== null;

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

            $entries[$breakpointIndex] = $applyDimensionValue($entry);
        } else {
            foreach ($breakpoints as $index => $_breakpoint) {
                $entry = isset($entries[$index]) && is_array($entries[$index])
                    ? $entries[$index]
                    : $this->buildDefaultTransformEntry();

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

        $entries = $this->normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

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
                && $this->normalizeNullablePositiveInt($entry['ratioWidth'] ?? null) !== null
                && $this->normalizeNullablePositiveInt($entry['ratioHeight'] ?? null) !== null;

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

        if ($ratioWidth > 100000 || $ratioHeight > 100000) {
            $this->addGlobalError($validation, 'ratioWidth and ratioHeight must be between 1 and 100000.');

            return [
                'persisted' => false,
                'validation' => $validation,
            ];
        }

        $sourceDimension = $this->normalizeRatioSourceDimension($ratioSourceDimension);
        if ($sourceDimension === null) {
            $this->addGlobalError($validation, 'ratioSourceDimension must be "width" or "height".');

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

        $entries = $this->normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

        $preserveAutos = $scopeMode !== 'breakpoint';
        $appliedBreakpoints = [];
        $skippedBreakpoints = [];

        $applyIndex = function (int $index) use (&$entries, $breakpoints, $sourceDimension, $ratioWidth, $ratioHeight, $preserveAutos, &$appliedBreakpoints, &$skippedBreakpoints): bool {
            $entry = isset($entries[$index]) && is_array($entries[$index])
                ? $entries[$index]
                : $this->buildDefaultTransformEntry();

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

            $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
            if ($preserveAutos && ($autoDimension === 'width' || $autoDimension === 'height')) {
                $skippedBreakpoints[] = [
                    'breakpoint' => $breakpoint,
                    'reason' => 'auto_dimension_active',
                ];
                return false;
            }

            if ($sourceDimension === 'width') {
                $sourceValue = $this->normalizeNullablePositiveInt($entry['width'] ?? null);
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
                $sourceValue = $this->normalizeNullablePositiveInt($entry['height'] ?? null);
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
                $skipReason = $skippedBreakpoints[0]['reason'] ?? 'source_dimension_missing';
                if ($skipReason === 'breakpoint_disabled') {
                    $this->addGlobalError($validation, 'Selected breakpoint is disabled.');
                } elseif ($skipReason === 'auto_dimension_active') {
                    $this->addGlobalError($validation, 'Ratio cannot be applied while auto dimension is active.');
                } else {
                $this->addGlobalError($validation, 'Source dimension value is missing for the selected breakpoint.');
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
                    'currentVersion' => $this->_plugin->getTransformStore()->getCurrentVersion(),
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

        $entries = $this->normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

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

    public function applySetPassHeightWhenRenderedLteSavedOperation(
        string $transformName,
        mixed $value,
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

        $config = isset($transformDefinition['config']) && is_array($transformDefinition['config'])
            ? $transformDefinition['config']
            : [];
        $config['passHeightWhenRenderedLteSaved'] = $value === true;

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

        $entries = $this->normalizeTransformEntriesForBreakpoints($breakpoints, $rawEntries);

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
        $result = $this->persistOperationTransforms($transforms, $validation, $expectedVersion);

        if (($result['persisted'] ?? false) === true) {
            $this->_plugin->getTelemetry()->deletePreviewCacheByTransformHandle($transformName);
        }

        return $result;
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
            'savedSetNames' => array_values(array_filter(
                array_keys($this->getReviewStoredTransforms()),
                static fn($name): bool => is_string($name) && $name !== '',
            )),
        ];
    }

    public function renderInitialStoredReview(
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
    ): array {
        $storedTransforms = $this->getReviewStoredTransforms();
        $previewCacheByTransformAndBreakpoint = $this->getPreviewCacheRowsByTransformAndBreakpoint();
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

                $snapshotRow = $previewCacheByTransformAndBreakpoint[$setName . '|' . $breakpoint] ?? null;
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

        if ($this->_plugin !== null) {
            $configuredNames = array_values(array_filter(
                array_keys($storedTransforms),
                static fn($name): bool => is_string($name) && $name !== '',
            ));
            $observedUnsaved = $this->_plugin->getTelemetry()->getObservedUnsavedHandles($configuredNames);
            $observedBreakpoints = $this->getBreakpointsForTransform(false);
            foreach ($observedUnsaved as $observedEntry) {
                $handle = (string)$observedEntry['handle'];
                if ($handle === '') {
                    continue;
                }

                $placeholderSrc = $this->buildInitialReviewPlaceholderDataUri(null, null, null);
                foreach ($observedBreakpoints as $breakpoint) {
                    if (!is_int($breakpoint) || $breakpoint <= 0) {
                        continue;
                    }

                    $syntheticRowsByBreakpoint[$breakpoint][] = [
                        'transform' => $handle,
                        'assetId' => '',
                        'title' => $handle . ' ' . $breakpoint . 'px placeholder',
                        'enabled' => true,
                        'isVisible' => true,
                        'loaded' => false,
                        'broken' => false,
                        'unresolved' => false,
                        'sourceUsed' => $placeholderSrc,
                        'src' => $placeholderSrc,
                        'rendered' => ['width' => 0, 'height' => 0],
                        'intrinsic' => ['width' => 0, 'height' => 0],
                        'transformDimensions' => [
                            'width' => null,
                            'height' => null,
                            'autoDimension' => null,
                        ],
                    ];
                }
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

    private function renderReviewWarningsMarkup(array $warnings, bool $showEmptyState = true, string $reviewMode = self::REVIEW_MODE_PROCESSED): string
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
            $warningActions = $this->buildReviewWarningActionsMarkup(is_array($warning) ? $warning : [], $reviewMode);
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

    private function buildReviewWarningActionsMarkup(array $warning, string $reviewMode = self::REVIEW_MODE_PROCESSED): string
    {
        $code = (string)($warning['code'] ?? '');
        if ($code !== 'missing-set-definitions') {
            return '';
        }

        if ($this->_plugin === null || !$this->_plugin->getTelemetry()->canEditTransforms()) {
            return '';
        }

        if ($reviewMode === self::REVIEW_MODE_PROCESSED) {
            return '<div class="bpi-warning-actions">'
                . '<button type="button" class="btn small bpi-warning-apply-rendered"'
                . ' data-bpi-action="renderedValues"'
                . ' aria-label="Set all breakpoints to rendered values"'
                . ' title="Set all breakpoints to rendered values"'
                . ' data-on:click="@post(el.closest(\'.bpi-transforms-page\').dataset.applyCardOperationUrl || \'/actions/craft-breakpoint-images/transforms/apply-card-operation\', {contentType: \'json\', payload: {setName: el.closest(\'.bpi-transform-card\').dataset.set || \'\', field: \'renderedValues\', includeEscapeWidth: (el.closest(\'.bpi-transform-card\').dataset.includeEscapeWidth || \'0\') === \'1\', renderedRows: JSON.parse(el.closest(\'.bpi-transform-card\').dataset.renderedRows || \'[]\'), baseVersion: Number($editor.baseVersion || 1), ...(Craft && Craft.csrfTokenName && Craft.csrfTokenValue ? {[Craft.csrfTokenName]: Craft.csrfTokenValue} : {})}})">'
                . 'Set to rendered'
                . '</button>'
                . '</div>';
        }

        $entryId = (int)($warning['entryId'] ?? 0);
        $entryAvailable = ($warning['entryAvailable'] ?? false) === true;
        $entryMissing = ($warning['entryMissing'] ?? false) === true;

        if ($entryId <= 0 || !$entryAvailable) {
            if ($entryId <= 0) {
                $tooltip = 'No observed entry is available to process.';
            } elseif ($entryMissing) {
                $tooltip = 'Observed entry could not be found.';
            } else {
                $tooltip = 'Observed entry is not available in the current site.';
            }

            return '<div class="bpi-warning-actions">'
                . '<button type="button" class="btn small bpi-warning-process-observed"'
                . ' data-bpi-action="processObservedEntry"'
                . ' disabled'
                . ' aria-label="' . $this->escapeReviewHtml($tooltip) . '"'
                . ' title="' . $this->escapeReviewHtml($tooltip) . '">'
                . 'Process observed entry'
                . '</button>'
                . '</div>';
        }

        return '<div class="bpi-warning-actions">'
            . '<button type="button" class="btn small bpi-warning-process-observed"'
            . ' data-bpi-action="processObservedEntry"'
            . ' data-entry-id="' . $entryId . '"'
            . ' aria-label="Process observed entry"'
            . ' title="Process observed entry">'
            . 'Process observed entry'
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

        $configuredBreakpoints = $breakpoints !== [] ? $breakpoints : $this->getReviewConfiguredBreakpoints();
        $escapeBreakpoint = $this->getReviewEscapeBreakpoint();
        $storedTransforms = $this->getReviewStoredTransforms();
        $storedSavedHeightsByTransform = $this->buildStoredSavedHeightsByTransformAndBreakpoint();
        $latestRunSnapshot = $this->getLatestRunSnapshotForReview();
        $latestRunSummariesByTransform = $this->buildLatestRunSummaryByTransform($latestRunSnapshot);

        $mismatchTransformNames = [];
        if ($isProcessedReview) {
            foreach ($latestRunSummariesByTransform as $handle => $summary) {
                if (is_string($handle) && ($summary['hasMismatch'] ?? false) === true) {
                    $mismatchTransformNames[$handle] = true;
                }
            }
        }

        $transformNames = $this->orderReviewTransformNames(
            $transformNames,
            $warningsByTransform,
            $preferredOrderBySet,
            $mismatchTransformNames,
        );
        if ($transformNames === []) {
            return '<div class="bpi-empty-state light">No transform sets found in results.</div>';
        }

        $runEntryData = $this->resolveRunEntryData($latestRunSnapshot);
        $observedDataByTransform = $this->resolveObservedDataByTransform();
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
            $cardWarningsMarkup = $this->renderReviewWarningsMarkup($cardWarnings, false, $reviewMode);
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
            $passHeightWhenRenderedLteSaved = $this->isPassHeightWhenRenderedLteSavedEnabled($storedTransformConfig);

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
                            'ratioLocked' => $scopeValues['ratioLocked'],
                            'ratioSourceDimension' => $scopeValues['ratioSourceDimension'],
                            'ratioSourceBreakpoint' => $ratioSourceBreakpointDefault,
                            'activeTab' => $tab,
                            'scopeMode' => $scope['mode'],
                            'scopeBreakpoint' => $scope['mode'] === 'breakpoint' ? (string)$scope['breakpoint'] : '',
                            'scopeActive' => $this->isReviewScopeActive($scope) ? '1' : '0',
                            'selectedAssetKey' => $selectedAssetKey,
                            'passHeightWhenRenderedLteSaved' => $passHeightWhenRenderedLteSaved,
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
                            'ratioWidthInput' => $scopeValues['ratioWidthInput'],
                            'ratioHeightInput' => $scopeValues['ratioHeightInput'],
                            'ratioFloatInput' => $scopeValues['ratioFloatInput'],
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
                    $passHeightWhenRenderedLteSaved,
                    $storedSavedHeightsByTransform[$transformName][$breakpoint] ?? null,
                );
            }
            $assetMismatchByKey = ($isProcessedReview && !$hideAssetPagination)
                ? $this->buildReviewAssetMismatchByKey(
                    $assetKeys,
                    $assetCollection['rowsByAssetByBreakpoint'],
                    $transformBreakpoints,
                    $passHeightWhenRenderedLteSaved,
                    $storedSavedHeightsByTransform[$transformName] ?? [],
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
            $activeSettings = $tab === 'settings';
            $scopeLabel = $scope['mode'] === 'all'
                ? 'All'
                : ($scope['mode'] === 'breakpoint' ? ($scope['breakpoint'] . 'px') : 'Select scope');
            $latestRunSummaryForTransform = $latestRunSummariesByTransform[$transformName] ?? null;
            $hasMismatchWarning = $isProcessedReview
                && is_array($latestRunSummaryForTransform)
                && (($latestRunSummaryForTransform['hasMismatch'] ?? false) === true);

            $hasMissingSetWarning = false;
            foreach ($cardWarnings as $w) {
                if (is_array($w) && ($w['code'] ?? '') === 'missing-set-definitions') {
                    $hasMissingSetWarning = true;
                    break;
                }
            }

            $mismatchWarningMarkup = ($hasMismatchWarning && !$hasMissingSetWarning)
                ? '<div class="bpi-warning-item bpi-warning-item-neutral">'
                    . '<div class="bpi-warning-copy"><h3 class="bpi-warning-heading">Asset Mismatch</h3></div>'
                    . '<div class="bpi-warning-detail"><p>One or more assets have mismatched values that need reviewed.</p></div>'
                    . '</div>'
                : '';

            $cardWarningsWithMismatch = $cardWarningsMarkup . $mismatchWarningMarkup;

            $lastProcessPanelHtml = $this->buildLastProcessPanelMarkup(
                $latestRunSnapshot,
                $latestRunSummaryForTransform,
                $transformName,
                $runEntryData,
                $observedDataByTransform[$transformName] ?? null,
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
                'cardWarningStateClass' => $cardWarningsWithMismatch !== ''
                    ? 'bpi-transform-card-warning'
                    : '',
                'cardWarningsHtml' => $cardWarningsWithMismatch !== ''
                    ? '<div class="bpi-transform-card-warnings">' . $cardWarningsWithMismatch . '</div>'
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
                'passHeightToggleId' => $this->escapeReviewHtml($editPanelId . '-pass-height-toggle'),
                'passHeightIndicatorHiddenClass' => $passHeightWhenRenderedLteSaved ? '' : 'bpi-force-hidden',
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
        bool $passHeightWhenRenderedLteSaved = false,
        ?int $savedHeight = null,
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
        $currentRatioWidth = $this->normalizeNullablePositiveInt($currentRow['ratioWidth'] ?? null);
        $currentRatioHeight = $this->normalizeNullablePositiveInt($currentRow['ratioHeight'] ?? null);
        $currentRatioSourceDimension = $this->normalizeRatioSourceDimension($currentRow['ratioSourceDimension'] ?? null) ?? 'width';
        $currentRatioLocked = ($currentRow['ratioLocked'] ?? false) === true
            && $currentRatioWidth !== null
            && $currentRatioHeight !== null;
        $currentRatioFloatValue = $currentRatioLocked
            ? $this->formatRatioFloatInput($currentRatioWidth, $currentRatioHeight)
            : '';
        $ratioIsDrivingDimensions = $currentRatioLocked && $autoDimension === null;
        $currentWidthDerivedClass = $ratioIsDrivingDimensions && $currentRatioSourceDimension === 'height'
            ? 'bpi_current-dimension-derived'
            : '';
        $currentHeightDerivedClass = $ratioIsDrivingDimensions && $currentRatioSourceDimension === 'width'
            ? 'bpi_current-dimension-derived'
            : '';

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
            && $this->hasReviewMismatchForRowsReference(
                $rows,
                $referenceRendered,
                $passHeightWhenRenderedLteSaved,
                $savedHeight,
            );

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
            'currentRatioWidthValue' => $currentRatioWidth !== null ? (string)$currentRatioWidth : '',
            'currentRatioHeightValue' => $currentRatioHeight !== null ? (string)$currentRatioHeight : '',
            'currentRatioFloatValue' => $currentRatioFloatValue,
            'currentRatioSourceDimension' => $currentRatioSourceDimension,
            'currentRatioLockedValue' => $currentRatioLocked ? '1' : '0',
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
            'currentWidthDerivedClass' => $currentWidthDerivedClass,
            'currentHeightDerivedClass' => $currentHeightDerivedClass,
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
        array $mismatchTransformNames = [],
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

        usort($transformNames, static function (string $left, string $right) use ($warningsByTransform, $preferredPositions, $mismatchTransformNames): int {
            $leftHasWarnings = !empty($warningsByTransform[$left]) || !empty($mismatchTransformNames[$left]);
            $rightHasWarnings = !empty($warningsByTransform[$right]) || !empty($mismatchTransformNames[$right]);

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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getPreviewCacheRowsByTransformAndBreakpoint(): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getTelemetry()->getPreviewCacheRows();
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
        $storedSavedHeightsByTransform = $this->buildStoredSavedHeightsByTransformAndBreakpoint();
        $storedTransforms = $this->getReviewStoredTransforms();

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
            $transformDefinition = isset($storedTransforms[$transformHandle]) && is_array($storedTransforms[$transformHandle])
                ? $storedTransforms[$transformHandle]
                : null;
            $breakpointRows = $this->buildLatestRunBreakpointHealthRows(
                $breakpointEntriesByWidth,
                $rowsPayloadStatusReliable,
                $this->isPassHeightWhenRenderedLteSavedEnabled($transformDefinition),
                $storedSavedHeightsByTransform[$transformHandle] ?? [],
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
        bool $passHeightWhenRenderedLteSaved,
        array $savedHeightsByBreakpoint,
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

                if ($heightMismatch && $this->shouldIgnoreHeightMismatch(
                    $passHeightWhenRenderedLteSaved,
                    $renderedHeight,
                    $savedHeightsByBreakpoint[(int)$breakpointWidth] ?? null,
                )) {
                    $heightMismatch = false;
                }

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
     * @param array<string, mixed>|null $runEntryData
     * @param array<string, mixed>|null $observedData
     */
    private function buildLastProcessPanelMarkup(
        ?array $snapshot,
        ?array $transformSummary,
        string $transformHandle,
        ?array $runEntryData,
        ?array $observedData,
    ): string {
        if (!is_array($snapshot)) {
            return '<aside class="bpi-transform-last-process-pane"><div class="bpi-transform-last-process-header"><span class="bpi-transform-last-process-status-icon bpi-transform-last-process-status-icon-unknown" aria-label="Unknown" title="Unknown"><span data-icon="alert" aria-hidden="true"></span></span></div><p class="light bpi-transform-last-process-empty">No saved run data yet.</p></aside>';
        }

        $ranAtLabel = $this->formatLatestRunTimestamp($snapshot['ranAt'] ?? null);
        $hasHealthData = is_array($transformSummary);
        $hasMismatch = $hasHealthData
            && is_numeric($transformSummary['mismatchBreakpointCount'] ?? null)
            && (int)$transformSummary['mismatchBreakpointCount'] > 0;

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

        $runEntryIconMarkup = '';
        if (is_array($runEntryData) && ($runEntryData['id'] ?? 0) > 0) {
            $runEntryTitle = trim((string)($runEntryData['title'] ?? ''));
            $runEntryIconTooltip = $runEntryTitle !== ''
                ? 'Processed entry: ' . $runEntryTitle
                : 'Open processed entry';
            $runEntryIconMarkup = $this->buildEntryIconLinkMarkup($runEntryData, 'newspaper', $runEntryIconTooltip);
        }

        $observedEntryIconMarkup = '';
        $observedUrlIconMarkup = '';
        if (is_array($observedData)) {
            $observedEntry = $observedData['entry'] ?? null;
            if (is_array($observedEntry) && ($observedEntry['id'] ?? 0) > 0) {
                $observedTitle = trim((string)($observedEntry['title'] ?? ''));
                $observedIconTooltip = $observedTitle !== ''
                    ? 'Last observed entry: ' . $observedTitle
                    : 'Open last observed entry';
                $observedEntryIconMarkup = $this->buildEntryIconLinkMarkup($observedEntry, 'view', $observedIconTooltip);
            }

            $sourceUrl = trim((string)($observedData['sourceUrl'] ?? ''));
            if ($sourceUrl !== '') {
                $observedUrlIconMarkup = sprintf(
                    '<a href="%s" target="_blank" rel="noopener" class="bpi-transform-last-process-icon-btn" title="%s" aria-label="%s"><span data-icon="world" aria-hidden="true"></span></a>',
                    $this->escapeReviewHtml($sourceUrl),
                    $this->escapeReviewHtml('Open observed page: ' . $sourceUrl),
                    $this->escapeReviewHtml('Open observed page'),
                );
            }
        }

        $canProcessAgain = is_array($runEntryData) && ($runEntryData['canProcessAgain'] ?? false) === true;
        $processAgainEntryId = is_array($runEntryData) ? (int)($runEntryData['id'] ?? 0) : 0;
        $processAgainMarkup = '';
        if ($processAgainEntryId > 0) {
            $disabledAttrs = $canProcessAgain ? '' : ' disabled aria-disabled="true"';
            $processAgainMarkup = sprintf(
                '<button type="button" class="bpi-transform-last-process-icon-btn bpi-process-again-button" data-bpi-process-again="true" data-entry-id="%s" title="Process this entry again" aria-label="Process this entry again"%s><span data-icon="refresh" aria-hidden="true"></span></button>',
                $this->escapeReviewHtml((string)$processAgainEntryId),
                $disabledAttrs,
            );
        }

        $statusTitle = $ranAtLabel !== '' && $ranAtLabel !== '-'
            ? sprintf('%s (last run: %s)', $statusLabel, $ranAtLabel)
            : $statusLabel;

        return sprintf(
            '<aside class="bpi-transform-last-process-pane">'
            . '<span class="bpi-transform-last-process-status-icon %s" aria-label="%s" title="%s"><span data-icon="%s" aria-hidden="true"></span></span>'
            . '%s'
            . '%s'
            . '%s'
            . '%s'
            . '</aside>',
            $this->escapeReviewHtml($statusIconClass),
            $this->escapeReviewHtml($statusTitle),
            $this->escapeReviewHtml($statusTitle),
            $this->escapeReviewHtml($statusIconName),
            $runEntryIconMarkup,
            $processAgainMarkup,
            $observedEntryIconMarkup,
            $observedUrlIconMarkup,
        );
    }

    /**
     * @param array<string, mixed> $entryData
     */
    private function buildEntryLinkMarkup(array $entryData): string
    {
        $id = (int)($entryData['id'] ?? 0);
        if ($id <= 0) {
            return '<span class="light">Entry unavailable</span>';
        }

        $title = trim((string)($entryData['title'] ?? ''));
        if ($title === '') {
            $title = 'Entry #' . $id;
        }

        $href = trim((string)($entryData['cpEditUrl'] ?? '#'));
        $siteId = (int)($entryData['siteId'] ?? 0);

        return sprintf(
            '<a class="bpi-entry-link" href="%s" data-bpi-open-entry="true" data-entry-id="%s" data-site-id="%s" title="%s">%s</a>',
            $this->escapeReviewHtml($href),
            $this->escapeReviewHtml((string)$id),
            $this->escapeReviewHtml((string)max(0, $siteId)),
            $this->escapeReviewHtml('Open entry in side panel'),
            $this->escapeReviewHtml($title),
        );
    }

    /**
     * @param array<string, mixed> $entryData
     */
    private function buildEntryIconLinkMarkup(array $entryData, string $iconName, string $tooltip = ''): string
    {
        $id = (int)($entryData['id'] ?? 0);
        if ($id <= 0) {
            return '';
        }

        if ($tooltip === '') {
            $title = trim((string)($entryData['title'] ?? ''));
            $tooltip = $title !== '' ? $title : 'Entry #' . $id;
        }

        $href = trim((string)($entryData['cpEditUrl'] ?? '#'));
        $siteId = (int)($entryData['siteId'] ?? 0);

        return sprintf(
            '<a class="bpi-transform-last-process-icon-btn bpi-entry-link" href="%s" data-bpi-open-entry="true" data-entry-id="%s" data-site-id="%s" title="%s" aria-label="%s"><span data-icon="%s" aria-hidden="true"></span></a>',
            $this->escapeReviewHtml($href),
            $this->escapeReviewHtml((string)$id),
            $this->escapeReviewHtml((string)max(0, $siteId)),
            $this->escapeReviewHtml($tooltip),
            $this->escapeReviewHtml($tooltip),
            $this->escapeReviewHtml($iconName),
        );
    }

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, mixed>|null
     */
    private function resolveRunEntryData(?array $snapshot): ?array
    {
        if (!is_array($snapshot) || $this->_plugin === null) {
            return null;
        }

        $entryId = is_numeric($snapshot['entryId'] ?? null) ? (int)$snapshot['entryId'] : 0;
        if ($entryId <= 0) {
            return null;
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $entry = Entry::find()
            ->id($entryId)
            ->status(null)
            ->siteId($siteId)
            ->one();

        if ($entry === null) {
            return [
                'id' => $entryId,
                'title' => '',
                'cpEditUrl' => '#',
                'siteId' => $siteId,
                'canProcessAgain' => false,
            ];
        }

        return [
            'id' => $entry->id,
            'title' => (string)$entry->title,
            'cpEditUrl' => $entry->cpEditUrl ?? '#',
            'siteId' => $entry->siteId,
            'canProcessAgain' => $entry->getStatus() === Entry::STATUS_LIVE,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveObservedDataByTransform(): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $telemetry = $this->_plugin->getTelemetry();
        if (!$telemetry->isTelemetryEnabled()) {
            return [];
        }

        $mostRecent = $telemetry->getMostRecentByHandle();
        if ($mostRecent === []) {
            return [];
        }

        $byTransform = [];
        $entryIds = [];
        foreach ($mostRecent as $handle => $row) {
            $sourceElementId = (int)($row['entryId'] ?? 0);
            $byTransform[$handle] = [
                'lastSeenAt' => $row['lastSeenAt'] ?? null,
                'sourceElementId' => $sourceElementId,
                'sourceUrl' => $row['sourceUrl'] ?? null,
                'entry' => null,
            ];

            if ($sourceElementId > 0) {
                $entryIds[$sourceElementId] = true;
            }
        }

        $entryIds = array_keys($entryIds);
        $entriesById = [];
        if ($entryIds !== []) {
            $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
            $currentSiteEntries = Entry::find()
                ->id($entryIds)
                ->status(null)
                ->siteId($currentSiteId)
                ->indexBy('id')
                ->all();

            foreach ($currentSiteEntries as $id => $entry) {
                $entriesById[(int)$id] = [
                    'id' => $entry->id,
                    'title' => (string)$entry->title,
                    'cpEditUrl' => $entry->cpEditUrl ?? '#',
                    'siteId' => $entry->siteId,
                    'availableInCurrentSite' => true,
                ];
            }

            $remainingIds = array_values(array_diff($entryIds, array_keys($entriesById)));
            if ($remainingIds !== []) {
                $otherSiteEntries = Entry::find()
                    ->id($remainingIds)
                    ->status(null)
                    ->site('*')
                    ->indexBy('id')
                    ->all();
                foreach ($otherSiteEntries as $id => $entry) {
                    $entriesById[(int)$id] = [
                        'id' => $entry->id,
                        'title' => (string)$entry->title,
                        'cpEditUrl' => $entry->cpEditUrl ?? '#',
                        'siteId' => $entry->siteId,
                        'availableInCurrentSite' => false,
                    ];
                }
            }
        }

        foreach ($byTransform as $handle => &$data) {
            $elementId = (int)($data['sourceElementId'] ?? 0);
            if ($elementId > 0 && isset($entriesById[$elementId])) {
                $data['entry'] = $entriesById[$elementId];
            }
        }
        unset($data);

        return $byTransform;
    }

    private function truncateUrl(string $url, int $maxLength): string
    {
        $display = preg_replace('#^https?://#', '', $url) ?? $url;
        if (mb_strlen($display) <= $maxLength) {
            return $display;
        }

        return mb_substr($display, 0, $maxLength - 1) . '…';
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
        bool $passHeightWhenRenderedLteSaved,
        array $savedHeightsByBreakpoint,
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
                if ($this->hasReviewMismatchForRowsReference(
                    $rows,
                    $referenceByBreakpoint[$breakpoint] ?? null,
                    $passHeightWhenRenderedLteSaved,
                    $savedHeightsByBreakpoint[$breakpoint] ?? null,
                )) {
                    $hasMismatch = true;
                    break;
                }
            }

            $assetMismatchByKey[$assetKey] = $hasMismatch;
        }

        return $assetMismatchByKey;
    }

    private function hasReviewMismatchForRowsReference(
        array $rows,
        ?array $referenceRendered,
        bool $passHeightWhenRenderedLteSaved,
        ?int $savedHeight,
    ): bool
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

        if ($heightMismatch && $this->shouldIgnoreHeightMismatch(
            $passHeightWhenRenderedLteSaved,
            $renderedHeight,
            $savedHeight,
        )) {
            $heightMismatch = false;
        }

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

    private function isPassHeightWhenRenderedLteSavedEnabled(?array $transformDefinition): bool
    {
        if (!is_array($transformDefinition)) {
            return false;
        }

        $config = $transformDefinition['config'] ?? null;
        if (!is_array($config)) {
            return false;
        }

        return ($config['passHeightWhenRenderedLteSaved'] ?? null) === true;
    }

    /**
     * @return array<string, array<int, int|null>>
     */
    private function buildStoredSavedHeightsByTransformAndBreakpoint(): array
    {
        $storedTransforms = $this->getReviewStoredTransforms();
        if ($storedTransforms === []) {
            return [];
        }

        $savedHeightsByTransform = [];

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

                $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
                if ($autoDimension === 'height') {
                    $savedHeightsByTransform[$transformName][$breakpoint] = null;
                    continue;
                }

                $savedHeightsByTransform[$transformName][$breakpoint] = $this->normalizeNullablePositiveInt(
                    $entry['height'] ?? null,
                );
            }
        }

        return $savedHeightsByTransform;
    }

    private function shouldIgnoreHeightMismatch(
        bool $passHeightWhenRenderedLteSaved,
        int $renderedHeight,
        ?int $savedHeight,
    ): bool {
        return $passHeightWhenRenderedLteSaved
            && $renderedHeight > 0
            && $savedHeight !== null
            && $savedHeight > 0
            && $renderedHeight <= $savedHeight;
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
        return in_array($tab, ['dimensions', 'ratio', 'settings'], true) ? $tab : 'dimensions';
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
                'ratioLocked' => '0',
                'ratioWidthInput' => '',
                'ratioHeightInput' => '',
                'ratioFloatInput' => '',
                'ratioSourceDimension' => 'width',
            ];
        }

        $breakpoint = $this->normalizeNullablePositiveInt($scope['breakpoint'] ?? null);
        if ($breakpoint === null || !isset($currentRowsByBreakpoint[$breakpoint])) {
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

        $entry = $currentRowsByBreakpoint[$breakpoint];
        $autoDimension = $this->normalizeAutoDimension($entry['autoDimension'] ?? null);
        $widthValue = $this->normalizeNullablePositiveInt($entry['width'] ?? null);
        $heightValue = $this->normalizeNullablePositiveInt($entry['height'] ?? null);
        $ratioWidthValue = $this->normalizeNullablePositiveInt($entry['ratioWidth'] ?? null);
        $ratioHeightValue = $this->normalizeNullablePositiveInt($entry['ratioHeight'] ?? null);
        $ratioSourceDimension = $this->normalizeRatioSourceDimension($entry['ratioSourceDimension'] ?? null) ?? 'width';
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
            'ratioFloatInput' => $this->formatRatioFloatInput(
                $this->normalizeNullablePositiveInt($resolvedRatioWidth),
                $this->normalizeNullablePositiveInt($resolvedRatioHeight),
            ),
            'ratioSourceDimension' => $ratioLocked ? $ratioSourceDimension : 'width',
        ];
    }

    private function formatRatioFloatInput(?int $ratioWidth, ?int $ratioHeight): string
    {
        if ($ratioWidth === null || $ratioHeight === null || $ratioWidth <= 0 || $ratioHeight <= 0) {
            return '';
        }

        $value = $ratioWidth / $ratioHeight;
        $formatted = number_format($value, 4, '.', '');
        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed !== '' ? $trimmed : '0';
    }

    private function buildReviewWarningsByTransform(array $rowsByBreakpoint): array
    {
        $warningsByTransform = [];
        $storedTransforms = $this->getReviewStoredTransforms();
        $configTransformNames = array_keys($storedTransforms);
        sort($configTransformNames, SORT_STRING);

        $observedTransformNames = $this->collectReviewTransformNames($rowsByBreakpoint);
        $missingDefinitions = array_values(array_diff($observedTransformNames, $configTransformNames));
        if ($missingDefinitions === []) {
            return $warningsByTransform;
        }

        $observedDataByTransform = $this->resolveObservedDataByTransform();

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

    private function buildMissingSetDefinitionWarning(int $entryId = 0, bool $entryAvailable = false, bool $entryMissing = false): array
    {
        $canEdit = $this->_plugin !== null && $this->_plugin->getTelemetry()->canEditTransforms();
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

            $rows[$breakpoint] = $this->normalizeTransformEntry($entry);
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
                $normalizedEntry = $this->normalizeTransformEntry($entry);
                $rows[(string)$breakpoint] = [
                    'width' => $normalizedEntry['width'],
                    'height' => $normalizedEntry['height'],
                    'enabled' => $normalizedEntry['enabled'],
                    'autoDimension' => $normalizedEntry['autoDimension'],
                    'ratioWidth' => $normalizedEntry['ratioWidth'],
                    'ratioHeight' => $normalizedEntry['ratioHeight'],
                    'ratioSourceDimension' => $normalizedEntry['ratioSourceDimension'],
                    'ratioLocked' => $normalizedEntry['ratioLocked'],
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

                $ratioWidthInput = $row['ratioWidth'] ?? null;
                $ratioHeightInput = $row['ratioHeight'] ?? null;
                $ratioWidth = $this->normalizeNullablePositiveInt($ratioWidthInput);
                $ratioHeight = $this->normalizeNullablePositiveInt($ratioHeightInput);

                if ($ratioWidthInput !== null && $ratioWidth === null) {
                    $this->addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.ratioWidth', $transformName, $breakpointKey),
                        'ratioWidth must be a positive integer or null.'
                    );
                }

                if ($ratioHeightInput !== null && $ratioHeight === null) {
                    $this->addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.ratioHeight', $transformName, $breakpointKey),
                        'ratioHeight must be a positive integer or null.'
                    );
                }

                if ($ratioWidth !== null && $ratioWidth > 100000) {
                    $this->addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.ratioWidth', $transformName, $breakpointKey),
                        'ratioWidth must be between 1 and 100000.'
                    );
                }

                if ($ratioHeight !== null && $ratioHeight > 100000) {
                    $this->addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.ratioHeight', $transformName, $breakpointKey),
                        'ratioHeight must be between 1 and 100000.'
                    );
                }

                $ratioSourceDimensionInput = $row['ratioSourceDimension'] ?? null;
                $ratioSourceDimension = $this->normalizeRatioSourceDimension($ratioSourceDimensionInput);
                if ($ratioSourceDimensionInput !== null && $ratioSourceDimension === null) {
                    $this->addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.ratioSourceDimension', $transformName, $breakpointKey),
                        'ratioSourceDimension must be "width" or "height".'
                    );
                }

                $entries[] = [
                    'width' => $width,
                    'height' => $height,
                    'enabled' => ($row['enabled'] ?? true) !== false,
                    'autoDimension' => $autoDimension,
                    'ratioWidth' => $ratioWidth,
                    'ratioHeight' => $ratioHeight,
                    'ratioSourceDimension' => $ratioSourceDimension ?? 'width',
                    'ratioLocked' => ($row['ratioLocked'] ?? false) === true,
                ];

                $entries[count($entries) - 1] = $this->normalizeTransformEntry($entries[count($entries) - 1]);
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

    private function normalizeRatioSourceDimension(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $candidate = strtolower(trim($value));
        if ($candidate === 'width' || $candidate === 'height') {
            return $candidate;
        }

        return null;
    }

    private function normalizeTransformEntry(mixed $entry): array
    {
        $entry = is_array($entry) ? $entry : [];

        $normalizedEntry = [
            'width' => $this->normalizeNullablePositiveInt($entry['width'] ?? null),
            'height' => $this->normalizeNullablePositiveInt($entry['height'] ?? null),
            'enabled' => ($entry['enabled'] ?? true) !== false,
            'autoDimension' => $this->normalizeAutoDimension($entry['autoDimension'] ?? null),
            'ratioWidth' => $this->normalizeNullablePositiveInt($entry['ratioWidth'] ?? null),
            'ratioHeight' => $this->normalizeNullablePositiveInt($entry['ratioHeight'] ?? null),
            'ratioSourceDimension' => $this->normalizeRatioSourceDimension($entry['ratioSourceDimension'] ?? null) ?? 'width',
            'ratioLocked' => ($entry['ratioLocked'] ?? false) === true,
        ];

        if ($normalizedEntry['autoDimension'] === 'width') {
            $normalizedEntry['width'] = null;
            $normalizedEntry['ratioLocked'] = false;
        }

        if ($normalizedEntry['autoDimension'] === 'height') {
            $normalizedEntry['height'] = null;
            $normalizedEntry['ratioLocked'] = false;
        }

        if ($normalizedEntry['ratioWidth'] === null || $normalizedEntry['ratioHeight'] === null) {
            $normalizedEntry['ratioLocked'] = false;
        }

        return $normalizedEntry;
    }

    private function normalizeTransformEntriesForBreakpoints(array $breakpoints, array $rawEntries): array
    {
        $entries = [];

        foreach ($breakpoints as $index => $_breakpoint) {
            $entry = isset($rawEntries[$index]) && is_array($rawEntries[$index])
                ? $rawEntries[$index]
                : [];

            $entries[$index] = $this->normalizeTransformEntry($entry);
        }

        return $entries;
    }

    private function buildDefaultTransformEntry(): array
    {
        return [
            'width' => null,
            'height' => null,
            'enabled' => true,
            'autoDimension' => null,
            'ratioWidth' => null,
            'ratioHeight' => null,
            'ratioSourceDimension' => 'width',
            'ratioLocked' => false,
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