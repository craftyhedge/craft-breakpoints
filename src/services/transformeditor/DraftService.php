<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\services\BreakpointPolicy;
use craftyhedge\craftbreakpoints\services\ConfigService;
use craftyhedge\craftbreakpoints\services\TransformStore;

/**
 * Draft lifecycle helper: builds drafts from stored transforms, encodes
 * them to JSON, normalizes user-submitted drafts, and persists them via
 * TransformStore.
 *
 * Pure-PHP collaborator with typed readonly dependencies; not registered
 * as a Craft plugin component.
 */
final class DraftService
{
    public function __construct(
        private readonly TransformStore $transformStore,
        private readonly ConfigService $configService,
        private readonly BreakpointPolicy $breakpointPolicy,
    ) {
    }

    /**
     * @return array{transforms: array<string, array{includeEscapeWidth: bool, rows: array<string, array<string, mixed>>}>}
     */
    public function buildDraftFromStore(): array
    {
        return [
            'transforms' => $this->buildDraftTransforms($this->transformStore->getTransforms()),
        ];
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function encodeDraftJson(array $draft): string
    {
        $encoded = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{"transforms":{}}';
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    public function applyDraft(array $draft, ?string $expectedVersion = null): array
    {
        $validation = Support::defaultValidation();

        $normalizedTransforms = $this->normalizeTransformsFromDraft($draft, $validation);

        if ($validation['hasErrors'] === true) {
            return [
                'draft' => $draft,
                'validation' => $validation,
                'persisted' => false,
            ];
        }

        $resolvedExpectedVersion = $expectedVersion ?? $this->transformStore->getCurrentVersion();
        $persistResult = $this->transformStore->persistTransforms($normalizedTransforms, $resolvedExpectedVersion);

        $persisted = ($persistResult['persisted'] ?? false) === true;
        $conflict = ($persistResult['conflict'] ?? false) === true;
        $currentVersion = (string)($persistResult['currentVersion'] ?? $resolvedExpectedVersion);
        $persistedTransforms = is_array($persistResult['transforms'] ?? null)
            ? $persistResult['transforms']
            : [];

        if ($conflict) {
            Support::addGlobalError($validation, 'Draft version is out of date. Reload and apply again.');
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

    /**
     * @param array<string, mixed> $storedTransforms
     * @return array<string, array{includeEscapeWidth: bool, rows: array<string, array<string, mixed>>}>
     */
    public function buildDraftTransforms(array $storedTransforms): array
    {
        $draftTransforms = [];
        foreach ($storedTransforms as $transformName => $transformDefinition) {
            if (!is_string($transformName) || $transformName === '' || !is_array($transformDefinition)) {
                continue;
            }

            $includeEscapeWidth = $this->breakpointPolicy->resolveIncludeEscapeWidth([], $transformDefinition);
            $breakpoints = $this->getBreakpointsForTransform($includeEscapeWidth);
            $rows = [];

            $entries = isset($transformDefinition['transforms']) && is_array($transformDefinition['transforms'])
                ? array_values($transformDefinition['transforms'])
                : [];

            foreach ($breakpoints as $index => $breakpoint) {
                $entry = isset($entries[$index]) && is_array($entries[$index]) ? $entries[$index] : [];
                $normalizedEntry = Support::normalizeTransformEntry($entry);
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

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $validation
     * @return array<string, array<string, mixed>>
     */
    public function normalizeTransformsFromDraft(array $draft, array &$validation): array
    {
        $draftTransforms = $draft['transforms'] ?? null;
        if (!is_array($draftTransforms) || $draftTransforms === []) {
            Support::addGlobalError($validation, 'Draft must include at least one transform.');
            return [];
        }

        $existingTransforms = $this->transformStore->getTransforms();
        $normalized = [];

        foreach ($draftTransforms as $transformName => $transformDraft) {
            if (!is_string($transformName) || trim($transformName) === '') {
                Support::addGlobalError($validation, 'Transform name must be a non-empty string.');
                continue;
            }

            if (!is_array($transformDraft)) {
                Support::addGlobalError($validation, sprintf('Transform "%s" must be an object.', $transformName));
                continue;
            }

            $includeEscapeWidth = $this->breakpointPolicy->resolveIncludeEscapeWidth([], $transformDraft);
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
                $width = Support::normalizeNullablePositiveInt($widthInput);
                $height = Support::normalizeNullablePositiveInt($heightInput);

                if ($widthInput !== null && $width === null) {
                    Support::addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.width', $transformName, $breakpointKey),
                        'Width must be a positive integer or null.'
                    );
                }

                if ($heightInput !== null && $height === null) {
                    Support::addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.height', $transformName, $breakpointKey),
                        'Height must be a positive integer or null.'
                    );
                }

                $autoDimensionInput = $row['autoDimension'] ?? null;
                $autoDimension = Support::normalizeAutoDimension($autoDimensionInput);

                if ($autoDimensionInput !== null && $autoDimension === null && $autoDimensionInput !== '') {
                    Support::addFieldError(
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
                $ratioWidth = Support::normalizeNullablePositiveInt($ratioWidthInput);
                $ratioHeight = Support::normalizeNullablePositiveInt($ratioHeightInput);

                if ($ratioWidthInput !== null && $ratioWidth === null) {
                    Support::addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.ratioWidth', $transformName, $breakpointKey),
                        'ratioWidth must be a positive integer or null.'
                    );
                }

                if ($ratioHeightInput !== null && $ratioHeight === null) {
                    Support::addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.ratioHeight', $transformName, $breakpointKey),
                        'ratioHeight must be a positive integer or null.'
                    );
                }

                if ($ratioWidth !== null && $ratioWidth > 100000) {
                    Support::addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.ratioWidth', $transformName, $breakpointKey),
                        'ratioWidth must be between 1 and 100000.'
                    );
                }

                if ($ratioHeight !== null && $ratioHeight > 100000) {
                    Support::addFieldError(
                        $validation,
                        sprintf('draft.transforms.%s.rows.%s.ratioHeight', $transformName, $breakpointKey),
                        'ratioHeight must be between 1 and 100000.'
                    );
                }

                $ratioSourceDimensionInput = $row['ratioSourceDimension'] ?? null;
                $ratioSourceDimension = Support::normalizeRatioSourceDimension($ratioSourceDimensionInput);
                if ($ratioSourceDimensionInput !== null && $ratioSourceDimension === null) {
                    Support::addFieldError(
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

                $entries[count($entries) - 1] = Support::normalizeTransformEntry($entries[count($entries) - 1]);
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
            Support::addGlobalError($validation, 'Draft did not contain any valid transform definitions.');
        }

        return $normalized;
    }

    /**
     * @return int[]
     */
    private function getBreakpointsForTransform(bool $includeEscapeWidth): array
    {
        return $this->configService->getBreakpointWidths($includeEscapeWidth);
    }
}
