<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\services\ConfigService;

/**
 * Provides lookup and resolution of breakpoint definitions from config.
 *
 * Breakpoints are named pixel-width thresholds (e.g. "sm" => 640). This catalog
 * exposes methods to list all definitions, find one by key or width, and resolve
 * an operation target from either identifier — returning an error descriptor
 * instead of null when a match is required.
 */
final class BreakpointCatalog
{
    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    /**
     * @return array<int, array{key: string, width: int, isEscape: bool}>
     */
    public function getDefinitionsForSet(array $setDefinition): array
    {
        $includeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;
        return $this->getDefinitionsForIncludeEscapeWidth($includeEscapeWidth);
    }

    /**
     * @return array<int, array{key: string, width: int, isEscape: bool}>
     */
    public function getDefinitionsForIncludeEscapeWidth(bool $includeEscapeWidth): array
    {
        // Variant key labels: the first slot is `base`, then the configured
        // breakpoint names shifted down by one. There is no `escape` key — when
        // the escape width is included it occupies the final slot under the last
        // configured breakpoint name. Widths stay paired to slots by position.
        $configuredNames = array_keys($this->configService->getBreakpointMap(false));
        $labels = ['base', ...$configuredNames];

        $definitions = [];
        $index = 0;
        foreach ($this->configService->getBreakpointMap($includeEscapeWidth) as $width) {
            $definitions[] = [
                'key' => (string)($labels[$index] ?? $configuredNames[count($configuredNames) - 1] ?? 'base'),
                'width' => (int)$width,
                'isEscape' => false,
            ];
            $index++;
        }

        return $definitions;
    }

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

    public function findDefinitionByWidth(int $width, bool $includeEscapeWidth): ?array
    {
        $definitions = $this->getDefinitionsForIncludeEscapeWidth($includeEscapeWidth);
        $matches = [];
        foreach ($definitions as $definition) {
            if ($definition['width'] === $width) {
                $matches[] = $definition;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
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
            return $definition;
        }

        if ($scopeBreakpointWidth !== null && $scopeBreakpointWidth > 0) {
            $definition = $this->findDefinitionByWidth($scopeBreakpointWidth, $includeEscapeWidth);
            return $definition;
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
            return $definition;
        }

        if ($scopeBreakpointWidth !== null && $scopeBreakpointWidth > 0) {
            $definitions = $this->getDefinitionsForIncludeEscapeWidth($includeEscapeWidth);
            $matches = [];
            foreach ($definitions as $definition) {
                if ($definition['width'] === $scopeBreakpointWidth) {
                    $matches[] = $definition;
                }
            }

            if (count($matches) === 0) {
                return ['error' => 'No breakpoint found for width.'];
            }

            if (count($matches) > 1) {
                return ['error' => 'Ambiguous breakpoint: multiple breakpoints have the same width.'];
            }

            return $matches[0];
        }

        return ['error' => 'Either breakpoint key or width is required.'];
    }
}
