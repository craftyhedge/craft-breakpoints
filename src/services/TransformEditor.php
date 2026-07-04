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
                $this->_plugin->getBreakpointPolicy(),
            );
            $this->_warningsBuilder = new ReviewWarningsBuilder(
                $this->_snapshotReader,
                $this->_plugin->getConfigService(),
                $this->_plugin->getTelemetry(),
                $this->_plugin->getBreakpointPolicy(),
            );
            $this->_draftService = new DraftService(
                $this->_plugin->getTransformStore(),
                $this->_plugin->getConfigService(),
                $this->_plugin->getBreakpointPolicy(),
            );
            $this->_operationsService = new OperationsService(
                $this->_plugin->getTransformStore(),
                $this->_plugin->getConfigService(),
                $this->_plugin->getTelemetry(),
                $this->_plugin->getBreakpointPolicy(),
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

    /**
     * @return array<string, mixed>
     */
    public function buildDraftFromStore(): array
    {
        if ($this->_draftService === null) {
            return ['transforms' => []];
        }

        return $this->_draftService->buildDraftFromStore();
    }

    /**
     * @param array<string, mixed> $draft
     */
    public function encodeDraftJson(array $draft): string
    {
        if ($this->_draftService === null) {
            $encoded = json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? $encoded : '{"transforms":{}}';
        }

        return $this->_draftService->encodeDraftJson($draft);
    }

    /**
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
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
     * Builds sidebar rows for configured transform sets.
     *
     * @return array<int, array{name: string, entryId: ?int, sourceUrl: ?string}>
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

        foreach ($configuredNames as $name) {
            $rows[] = [
                'name' => $name,
                'entryId' => null,
                'sourceUrl' => null,
            ];
        }

        return $rows;
    }

    // ---- Operation wrappers ----

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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
    public function applySetRatioRemoveOperation(
        string $transformName,
        string $scopeMode,
        ?int $scopeBreakpoint,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
        ?string $scopeBreakpointKey = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetRatioRemoveOperation(
            $transformName,
            $scopeMode,
            $scopeBreakpoint,
            $scopeBreakpointKey,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function applySetCopyRatioFromRenderedBreakpointOperation(
        string $transformName,
        string $sourceBreakpointKey,
    ): ?array {
        if ($this->_operationsService === null) {
            return null;
        }

        return $this->_operationsService->resolveRenderedRatioByBreakpoint($transformName, $sourceBreakpointKey);
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
    public function applySetAllowHiddenDuringProcessingOperation(
        string $transformName,
        mixed $value,
        ?bool $includeEscapeWidth = null,
        ?string $expectedVersion = null,
    ): array {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetAllowHiddenDuringProcessingOperation(
            $transformName,
            $value,
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
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applySetNotesOperation(
            $transformName,
            $value,
            $includeEscapeWidth,
            $expectedVersion,
        );
    }

    /**
     * @param array<int, int> $hiddenBreakpointSlotIds Slot ids (1-based) flagged as hidden by processing.
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
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->applyRenderedValuesOperation(
            $transformName,
            $assetKey,
            $includeEscapeWidth,
            $clearAuto,
            $expectedVersion,
            $hiddenBreakpointSlotIds,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $requestedSets
     * @return array<string, mixed>
     */
    public function autoApplyRenderedValuesForNewSets(array $requestedSets, ?string $expectedVersion = null): array
    {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->autoApplyRenderedValuesForNewSets($requestedSets, $expectedVersion);
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

    /**
     * @return array<string, mixed>
     */
    public function deleteSetOperation(string $transformName, ?string $expectedVersion = null): array
    {
        if ($this->_operationsService === null) {
            return ['persisted' => false, 'validation' => $this->defaultValidation()];
        }

        return $this->_operationsService->deleteSetOperation($transformName, $expectedVersion);
    }

    // ---- Summary / Validation ----

    /**
     * @param array<string, mixed> $summary
     * @return array{assetCount: int, breakpointCount: int, warningCount: int}
     */
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

    /**
     * @return array<string, mixed>
     */
    public function defaultValidation(): array
    {
        return Support::defaultValidation();
    }

    // ---- Review rendering (delegated) ----

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $editScopeBySet
     * @param array<string, mixed> $editTabBySet
     * @param array<string, mixed> $selectedAssetKeyBySet
     * @param array<string, mixed> $preferredOrderBySet
     * @param array<int, string> $newSetNames
     * @return array<string, mixed>
     */
    public function renderResultReview(
        array $result,
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
        bool $hideRenderedApply = false,
        bool $hideAssetPagination = false,
        string $reviewMode = self::REVIEW_MODE_PROCESSED,
        ?string $onlyTransformName = null,
        array $newSetNames = [],
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
            $onlyTransformName,
            $newSetNames,
        );
    }

    /**
     * @param array<string, mixed> $editScopeBySet
     * @param array<string, mixed> $editTabBySet
     * @param array<string, mixed> $selectedAssetKeyBySet
     * @param array<string, mixed> $preferredOrderBySet
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function renderInitialStoredReview(
        array $editScopeBySet = [],
        array $editTabBySet = [],
        array $selectedAssetKeyBySet = [],
        array $preferredOrderBySet = [],
        array $result = [],
        ?string $onlyTransformName = null,
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
            $onlyTransformName,
            $result,
        );
    }

    /**
     * Compute the current signal deltas for all breakpoints of a transform.
     * Used after operations to send PatchSignals instead of re-rendering the card.
     *
     * @return array{signalKey: string, rowsByBreakpoint: array<int|string, array<string, mixed>>, hasCurrentBreakpointMismatch?: bool, hasResolvedBreakpointMismatchAwaitingVerification?: bool, hasCardWarningDanger?: bool}
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

    /**
     * Build all-scope edit values when the card enters Select All mode.
     *
     * @return array<string, string>
     */
    public function buildScopeValuesForAll(string $setName, ?bool $includeEscapeWidth = null): array
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

        return $this->_reviewRenderer->buildScopeValuesForAll($setName, $includeEscapeWidth);
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
     * Whether a transform's saved dimensions differ from the latest process snapshot.
     */
    public function isTransformEditedSinceLatestProcess(string $transformName): bool
    {
        if ($this->_healthAnalyzer === null || $this->_snapshotReader === null) {
            return false;
        }

        $editedTransforms = $this->_healthAnalyzer->buildEditedTransformsMap(
            $this->_snapshotReader->getLatestRunSnapshot(),
            true,
        );

        return ($editedTransforms[$transformName] ?? false) === true;
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

    /**
     * Builds a map of saved dimensions by transform and canonical slot key.
     *
     * Used by snapshot persistence so processed baselines keep the same stable
     * identity as run rows, even when media widths repeat.
     *
     * @return array<string, array<string, array{slotKey: string, slotIndex: int, breakpointWidth: int, measureWidth: int, w: int|null, h: int|null}>>
     */
    public function buildSavedDimensionsByTransformAndSlot(): array
    {
        if ($this->_healthAnalyzer === null) {
            return [];
        }

        return $this->_healthAnalyzer->buildSavedDimensionsByTransformAndSlot();
    }
}
