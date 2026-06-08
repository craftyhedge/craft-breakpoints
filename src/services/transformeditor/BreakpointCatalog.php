<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\services\BreakpointPolicy;
use craftyhedge\craftbreakpoints\services\ConfigService;

/**
 * Provides lookup and resolution of breakpoint definitions from config.
 *
 * Breakpoints are named pixel-width thresholds (e.g. "sm" => 640). This catalog
 * exposes methods to list all definitions, find one by key, and resolve
 * an operation target from a canonical slot key — returning an error descriptor
 * instead of null when a match is required.
 */
final class BreakpointCatalog
{
    public function __construct(
        private readonly ConfigService $configService,
        private readonly BreakpointPolicy $breakpointPolicy,
    ) {
    }

    /**
     * @param array<string, mixed> $setDefinition
     * @return array<int, array{key: string, index: int|null, width: int, mediaWidth: int, measureWidth: int, isEscape: bool}>
     */
    public function getDefinitionsForSet(array $setDefinition): array
    {
        $includeEscapeWidth = $this->breakpointPolicy->resolveIncludeEscapeWidth([], $setDefinition);
        return $this->getDefinitionsForIncludeEscapeWidth($includeEscapeWidth);
    }

    /**
     * @return array<int, array{key: string, index: int|null, width: int, mediaWidth: int, measureWidth: int, isEscape: bool}>
     */
    public function getDefinitionsForIncludeEscapeWidth(bool $includeEscapeWidth): array
    {
        $definitions = [];
        foreach ($this->configService->getBreakpointSlotDefinitions($includeEscapeWidth) as $definition) {
            $definitions[] = [
                'key' => $definition['key'],
                'index' => $definition['index'] ?? null,
                'width' => $definition['mediaWidth'],
                'mediaWidth' => $definition['mediaWidth'],
                'measureWidth' => $definition['measureWidth'],
                'isEscape' => false,
            ];
        }

        return $definitions;
    }

    /**
     * @return array{key: string, index: int|null, width: int, mediaWidth: int, measureWidth: int, isEscape: bool}|null
     */
    public function findDefinitionByKey(string $key, bool $includeEscapeWidth): ?array
    {
        $definitions = $this->getDefinitionsForIncludeEscapeWidth($includeEscapeWidth);
        foreach ($definitions as $definition) {
            if ($definition['key'] === $key) {
                return $definition;
            }
        }
        return null;
    }

    /**
     * @return array{key: string, width: int, isEscape: bool}|null
     * @phpstan-return array{key: string, width: int, isEscape: bool}|null
     */
    public function resolveOperationTarget(
        ?string $scopeBreakpointKey,
        ?int $scopeBreakpointWidth,
        bool $includeEscapeWidth,
    ): ?array {
        if ($scopeBreakpointKey !== null && $scopeBreakpointKey !== '') {
            $definition = $this->findDefinitionByKey($scopeBreakpointKey, $includeEscapeWidth);
            return $definition !== null ? $this->toOperationTarget($definition) : null;
        }

        return null;
    }

    /**
     * @return array{key: string, width: int, isEscape: bool}|array{error: string}
     * @phpstan-return array{key: string, width: int, isEscape: bool}|array{error: string}
     */
    public function resolveOperationTargetOrReject(
        ?string $scopeBreakpointKey,
        ?int $scopeBreakpointWidth,
        bool $includeEscapeWidth,
    ): array {
        if ($scopeBreakpointKey !== null && $scopeBreakpointKey !== '') {
            $definition = $this->findDefinitionByKey($scopeBreakpointKey, $includeEscapeWidth);
            if ($definition === null) {
                return ['error' => 'Invalid breakpoint key.'];
            }
            return $this->toOperationTarget($definition);
        }

        return ['error' => 'breakpoint key is required.'];
    }

    /**
     * @param array{key: string, width: int, isEscape: bool} $definition
     * @return array{key: string, width: int, isEscape: bool}
     */
    private function toOperationTarget(array $definition): array
    {
        return [
            'key' => $definition['key'],
            'width' => $definition['width'],
            'isEscape' => $definition['isEscape'],
        ];
    }
}
