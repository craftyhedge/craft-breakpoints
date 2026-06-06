<?php

namespace craftyhedge\craftbreakpoints\services;

use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

class BreakpointPolicy extends Component
{
    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function getBreakpointsForSet(array $config, array $mergedConfig): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $includeEscapeWidth = $this->resolveIncludeEscapeWidth($config);
        $breakpoints = [];
        foreach ($this->_plugin->getBreakpointSlots()->getSlots($includeEscapeWidth) as $slot) {
            $breakpoints[$slot['key']] = (int)$slot['mediaWidth'];
        }

        return $breakpoints;
    }

    public function getBreakpointStates(array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);
        $breakpoints = $this->getBreakpointsForSet($config, $mergedConfig);

        // Key the states map by the canonical variant labels (`base`-first, the
        // same names the editor UI and `disableBreakpoints` use), resolved by
        // slot position — not the width-map name.
        $states = [];
        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            $isDisabled = $this->isBreakpointDisabled((string)$breakpointName, $index, $config);
            $canonicalKey = $this->getCanonicalKeyForIndex($index, $config) ?? (string)$breakpointName;
            $states[$canonicalKey] = $isDisabled ? 'disabled' : 'enabled';
            $index++;
        }

        return $states;
    }

    public function getEnabledBreakpoints(array $breakpoints, array $config): array
    {
        $enabled = [];
        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            if ($this->isBreakpointDisabled((string)$breakpointName, $index, $config)) {
                $index++;
                continue;
            }

            $enabled[(string)$breakpointName] = (int)$breakpointValue;
            $index++;
        }

        return $enabled;
    }

    /**
     * @param int $index The breakpoint's slot position. The variant `enabled`
     *   flag is resolved positionally (variant keys are not assumed to match
     *   the configured breakpoint names). The public `disableBreakpoints` config
     *   is keyed by the canonical variant labels (`base`-first, the same names
     *   shown in the editor UI), resolved from the slot position — NOT the
     *   width-map name. So `disableBreakpoints['base']` disables the smallest
     *   slot, matching the UI.
     */
    public function isBreakpointDisabled(?string $breakpointName, int $index, array $config): bool
    {
        if ($breakpointName === null || $breakpointName === '') {
            return false;
        }

        $namedSet = $this->getNamedSet($config);
        if ($namedSet !== null) {
            $variant = $this->getVariantByIndex($namedSet, $index);
            if ($variant !== null && isset($variant['enabled']) && $variant['enabled'] === false) {
                return true;
            }
        }

        $disableBreakpoints = $config['disableBreakpoints'] ?? null;
        if (!is_array($disableBreakpoints)) {
            return false;
        }

        $canonicalKey = $this->getCanonicalKeyForIndex($index, $config);
        if ($canonicalKey === null) {
            return false;
        }

        return ($disableBreakpoints[$canonicalKey] ?? null) === true;
    }

    /**
     * Canonical variant label for a slot position, matching saved-set keys.
     */
    private function getCanonicalKeyForIndex(int $index, array $config): ?string
    {
        if ($this->_plugin === null || $index < 0) {
            return null;
        }

        $labels = $this->_plugin->getBreakpointSlots()->getKeys();

        return $labels[$index] ?? null;
    }

    public function resolveIncludeEscapeWidth(array $config = [], ?array $set = null): bool
    {
        if (array_key_exists('includeEscapeWidth', $config)) {
            return (bool)$config['includeEscapeWidth'] === true;
        }

        $namedSet = $set ?? $this->getNamedSetIfConfigured($config);

        return $namedSet !== null
            && array_key_exists('includeEscapeWidth', $namedSet)
            && $namedSet['includeEscapeWidth'] === true;
    }

    private function getNamedSet(array $config): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        $setName = (string)($config['setName'] ?? $config['transformName'] ?? '');
        if (trim($setName) === '') {
            throw new \InvalidArgumentException('A non-empty set name is required in config.');
        }

        return $this->_plugin->getTransformSets()->getSet($setName);
    }

    private function getNamedSetIfConfigured(array $config): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        $setName = trim((string)($config['setName'] ?? $config['transformName'] ?? ''));
        if ($setName === '') {
            return null;
        }

        return $this->_plugin->getTransformSets()->getSet($setName);
    }

    /**
     * Resolve a variant by its slot position rather than its key, so callers
     * do not depend on variant keys matching the configured breakpoint names.
     */
    public function getVariantByIndex(?array $set, int $index): ?array
    {
        if ($set === null || !isset($set['variants']) || !is_array($set['variants'])) {
            return null;
        }

        if ($index < 0) {
            return null;
        }

        $variant = array_values($set['variants'])[$index] ?? null;

        return is_array($variant) ? $variant : null;
    }
}
