<?php

namespace craftyhedge\craftbreakpoints\services;

use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\transformeditor\DraftService;
use craftyhedge\craftbreakpoints\services\transformeditor\HealthAnalyzer;
use craftyhedge\craftbreakpoints\services\transformeditor\OperationsService;
use craftyhedge\craftbreakpoints\services\transformeditor\ReviewRenderer;
use craftyhedge\craftbreakpoints\services\transformeditor\ReviewWarningsBuilder;
use craftyhedge\craftbreakpoints\services\transformeditor\SnapshotReader;
use craftyhedge\craftbreakpoints\services\transformeditor\Support;
use yii\base\Component;

/**
 * Facade orchestrating the transforms editor: draft persistence, operation
 * wrappers, review rendering, and health/saved-dimension readers. Delegates
 * all real work to collaborators in {@see \craftyhedge\craftbreakpoints\services\transformeditor}.
 */
class TransformEditor extends Component
{
    public const REVIEW_MODE_PROCESSED = 'processed';
    public const REVIEW_MODE_SAVED = 'saved';

    private ?Plugin $_plugin = null;
    private ?SnapshotReader $_snapshotReader = null;
    private ?HealthAnalyzer $_healthAnalyzer = null;
    private ?ReviewWarningsBuilder $_warningsBuilder = null;
    private ?DraftService $_draftService = null;
    private ?OperationsService $_operationsService = null;
    private ?ReviewRenderer $_reviewRenderer = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
        if ($this->_plugin !== null) {
            $this->_snapshotReader = new SnapshotReader(
                $this->_plugin->getTransformStore(),
                $this->_plugin->getTelemetry(),
            );
            $this->_healthAnalyzer = new HealthAnalyzer(
                $this->_snapshotReader,
                $this->_plugin->getConfigService(),
            );
            $this->_warningsBuilder = new ReviewWarningsBuilder(
                $this->_snapshotReader,
                $this->_plugin->getConfigService(),
                $this->_plugin->getTelemetry(),
            );
            $this->_draftService = new DraftService(
                $this->_plugin->getTransformStore(),
                $this->_plugin->getConfigService(),
            );
            $this->_operationsService = new OperationsService(
                $this->_plugin->getTransformStore(),
                $this->_plugin->getConfigService(),
                $this->_plugin->getTelemetry(),
                $this->_snapshotReader,
            );
            $this->_reviewRenderer = new ReviewRenderer(
                $this->_plugin,
                $this->_snapshotReader,
                $this->_healthAnalyzer,
                $this->_warningsBuilder,
            );
        }
    }

    // ---- Draft ----

    public function buildDraftFromStore(): array
    {
        if ($this->_draftService === null) {
            return ['transforms' => []];
        }

        return $this->_draftService->buildDraftFromStore();
    }

    public function encodeDraftJson(array $draft): string
    {
        if ($this->_draftService === null) {
            $encoded = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : '{"transforms":{}}';
        }

        return $this->_draftService->encodeDraftJson($draft);
    }

    public function applyDraft(array $draft, ?string $expectedVersion = null): array
    {
        if ($this->_draftService === null) {
            $validation = $this->defaultValidation();
            Support::addGlobalError($validation, 'Plugin instance is not available.');

            return [
                'draft' => $draft,
                'validation' => $validation,
                'persisted' => false,
            ];
        }

        return $this->_draftService->applyDraft($draft, $expectedVersion);
    }

    // ---- Sidebar ----

    /**
     * Builds sidebar rows combining observed-unsaved transform handles (first)
     * with configured transform sets. Used by the transforms page sidebar.
     *
     * @return array<int, array{name: string, isObservedUnsaved: bool, entryId: ?int, sourceUrl: ?string}>
     */
    public function buildSidebarTransformRows(): array
    {
        $configured = $this->_snapshotReader !== null
            ? $this->_snapshotReader->getStoredTransforms()
            : [];
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

    // ---- Operation wrappers ----

    public function applySetDimensionOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $value,
        string $dimension,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
        ?string $scopeBreakpointKey = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetDimensionOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            $value,
            $dimension,
            $includeEscapeWidth,
            $expectedVersion,
        );
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
        ?string $scopeBreakpointKey = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetDimensionsOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            $widthValue,
            $heightValue,
            $includeEscapeWidth,
            $widthAuto,
            $heightAuto,
            $forceAll,
            $expectedVersion,
        );
    }

    public function applySetToggleAutoWidthOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $heightValue,
        ?string $assetKey = null,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
        ?string $scopeBreakpointKey = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetToggleAutoWidthOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            $heightValue,
            $assetKey,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    public function applySetToggleAutoHeightOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $widthValue,
        ?string $assetKey = null,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
        ?string $scopeBreakpointKey = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetToggleAutoHeightOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            $widthValue,
            $assetKey,
            $includeEscapeWidth,
            $expectedVersion,
        );
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
        ?string $scopeBreakpointKey = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetRatioOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            $ratioWidth,
            $ratioHeight,
            $ratioSourceDimension,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    public function applySetCopyRatioFromRenderedBreakpointOperation(
        string $transformName,
        int $sourceBreakpoint,
    ): ?array {
        if ($this->_operationsService === null) {
            return null;
        }

        return $this->_operationsService->resolveRenderedRatioByBreakpoint($transformName, $sourceBreakpoint);
    }

    public function applySetBreakpointEnabledOperation(
        string $transformName,
        ?int $scopeBreakpoint,
        ?bool $enabled,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
        bool $enabledProvided = true,
        ?string $scopeBreakpointKey = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetBreakpointEnabledOperation(
            $transformName,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            $enabled,
            $includeEscapeWidth,
            $expectedVersion,
            $enabledProvided,
        );
    }

    public function applySetPassHeightWhenRenderedLteSavedOperation(
        string $transformName,
        mixed $value,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetPassHeightWhenRenderedLteSavedOperation(
            $transformName,
            $value,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    public function applySetAllowAnyHeightOperation(
        string $transformName,
        mixed $value,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetAllowAnyHeightOperation(
            $transformName,
            $value,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    public function applyRenderedValuesOperation(
        string $transformName,
        ?string $assetKey = null,
        ?bool $includeEscapeWidth = null,
        bool $clearAuto = false,
        ?string $expectedVersion = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applyRenderedValuesOperation(
            $transformName,
            $assetKey,
            $includeEscapeWidth,
            $clearAuto,
            $expectedVersion,
        );
    }

    public function applySetWidthOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?int $value,
        ?string $expectedVersion = null,
        ?string $scopeBreakpointKey = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetWidthOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            $value,
            $expectedVersion,
        );
    }

    public function deleteSetOperation(string $transformName, ?string $expectedVersion = null): array
    {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->deleteSetOperation($transformName, $expectedVersion);
    }

    // ---- Summary / Validation ----

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
            'assetCount' => Support::toNonNegativeInt($summary['assetCount'] ?? 0),
            'breakpointCount' => Support::toNonNegativeInt($summary['breakpointCount'] ?? $breakpointCount),
            'warningCount' => Support::toNonNegativeInt($summary['warningCount'] ?? 0),
        ];
    }

    public function defaultValidation(): array
    {
        return Support::defaultValidation();
    }

    // ---- Review rendering (delegated) ----

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
        if ($this->_reviewRenderer === null) {
            return [
                'warningsHtml' => '',
                'visualResultsHtml' => '',
                'warningCount' => 0,
                'editScopeBySet' => [],
                'editTabBySet' => [],
                'selectedAssetKeyBySet' => [],
                'savedSetNames' => [],
            ];
        }

        return $this->_reviewRenderer->renderResultReview(
            $result,
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
            $hideRenderedApply,
            $hideAssetPagination,
            $reviewMode,
        );
    }

    public function renderInitialStoredReview(
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
        array $result = [],
    ): array {
        if ($this->_reviewRenderer === null) {
            return [
                'warningsHtml' => '',
                'visualResultsHtml' => '',
                'warningCount' => 0,
                'editScopeBySet' => [],
                'editTabBySet' => [],
                'selectedAssetKeyBySet' => [],
                'savedSetNames' => [],
            ];
        }

        return $this->_reviewRenderer->renderInitialStoredReview(
            $editScopeBySet,
            $editTabBySet,
            $selectedAssetKeyBySet,
            $preferredOrderBySet,
            null,
            $result,
        );
    }

    /**
     * Compute the current signal deltas for all breakpoints of a transform.
     * Used after operations to send PatchSignals instead of re-rendering the card.
     *
     * @return array{signalKey: string, rowsByBreakpoint: array<string, array<string, mixed>>}
     */
    public function buildSignalDeltasForTransform(
        string $setName,
        ?string $selectedAssetKey = null,
        bool $hideRenderedApply = false,
        string $reviewMode = self::REVIEW_MODE_PROCESSED,
    ): array
    {
        if ($this->_reviewRenderer === null) {
            return ['signalKey' => '', 'rowsByBreakpoint' => []];
        }

        return $this->_reviewRenderer->buildSignalDeltasForTransform($setName, $selectedAssetKey, $hideRenderedApply, $reviewMode);
    }

    /**
     * Build scope values for a specific breakpoint when it becomes the selected scope.
     * Used by the scope.selectBreakpoint operation to update reactive signals.
     *
     * @return array<string, string>
     */
    public function buildScopeValuesForBreakpoint(string $setName, int $breakpoint, ?bool $includeEscapeWidth = null): array
    {
        if ($this->_reviewRenderer === null) {
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

        return $this->_reviewRenderer->buildScopeValuesForBreakpoint($setName, $breakpoint, $includeEscapeWidth);
    }

    // ---- Health / Saved dimensions (delegated) ----

    /**
     * @param array<string, mixed>|null $snapshot
     * @return array<string, array<string, mixed>>
     */
    public function buildLatestRunHealthByTransform(?array $snapshot = null): array
    {
        if ($this->_healthAnalyzer === null) {
            return [];
        }

        return $this->_healthAnalyzer->buildLatestRunHealthByTransform($snapshot);
    }

    /**
     * Builds a map of saved dimensions by transform and breakpoint.
     *
     * Used by snapshot persistence to capture saved dimensions at process time,
     * and by the review renderer to detect per-transform edits since processing.
     *
     * @return array<string, array<int, array{w: int|null, h: int|null}>>
     */
    public function buildSavedDimensionsByTransformAndBreakpoint(): array
    {
        if ($this->_healthAnalyzer === null) {
            return [];
        }

        return $this->_healthAnalyzer->buildSavedDimensionsByTransformAndBreakpoint();
    }
}
