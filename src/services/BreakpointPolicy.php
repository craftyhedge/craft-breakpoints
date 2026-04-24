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

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints($mergedConfig);
        if (!$this->shouldIncludeEscapeWidth($config)) {
            unset($breakpoints['escape']);
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

        $states = [];
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            $isDisabled = $this->isBreakpointDisabled((string)$breakpointName, $config);
            $states[(string)$breakpointName] = $isDisabled ? 'disabled' : 'enabled';
        }

        return $states;
    }

    public function getEnabledBreakpoints(array $breakpoints, array $config): array
    {
        $enabled = [];
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            if ($this->isBreakpointDisabled((string)$breakpointName, $config)) {
                continue;
            }

            $enabled[(string)$breakpointName] = (int)$breakpointValue;
        }

        return $enabled;
    }

    public function isBreakpointDisabled(?string $breakpointName, array $config): bool
    {
        if ($breakpointName === null || $breakpointName === '') {
            return false;
        }

        if ($breakpointName === 'escape' && !$this->shouldIncludeEscapeWidth($config)) {
            return true;
        }

        $namedSet = $this->getNamedSet($config);
        if ($namedSet !== null) {
            $variant = $this->getVariantByBreakpointName($namedSet, $breakpointName);
            if ($variant !== null && isset($variant['enabled']) && $variant['enabled'] === false) {
                return true;
            }
        }

        return isset($config['disableBreakpoints'][$breakpointName])
            && $config['disableBreakpoints'][$breakpointName] === true;
    }

    private function shouldIncludeEscapeWidth(array $config): bool
    {
        if (array_key_exists('includeEscapeWidth', $config)) {
            return (bool)$config['includeEscapeWidth'] === true;
        }

        $namedSet = $this->getNamedSet($config);

        return $namedSet !== null
            && array_key_exists('includeEscapeWidth', $namedSet)
            && $namedSet['includeEscapeWidth'] === true;
    }

    private function getNamedSet(array $config): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        $setName = (string)($config['setName'] ?? $config['transformName'] ?? 'default');

        return $this->_plugin->getTransformSets()->getSet($setName);
    }

    private function getVariantByBreakpointName(?array $set, string $breakpointName): ?array
    {
        if ($set === null || !isset($set['variants']) || !is_array($set['variants'])) {
            return null;
        }

        if (!isset($set['variants'][$breakpointName]) || !is_array($set['variants'][$breakpointName])) {
            return null;
        }

        return $set['variants'][$breakpointName];
    }
}