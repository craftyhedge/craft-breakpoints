<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use craftyhedge\craftbreakpoints\services\ConfigService;

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
        $breakpoints = $this->configService->getBreakpoints();

        if (!$includeEscapeWidth) {
            unset($breakpoints['escape']);
        }

        $definitions = [];
        foreach ($breakpoints as $key => $width) {
            $definitions[] = [
                'key' => (string)$key,
                'width' => (int)$width,
                'isEscape' => $key === 'escape',
            ];
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
