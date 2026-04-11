<?php

namespace craftyhedge\craftbreakpointimages\services;

use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class BreakpointPolicy extends Component
{
    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function getBreakpointsForTransform(array $config, array $mergedConfig): array
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
        $breakpoints = $this->getBreakpointsForTransform($config, $mergedConfig);

        $states = [];
        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            $isDisabled = $this->isBreakpointDisabled((string)$breakpointName, $config, $index);
            $states[(string)$breakpointName] = $isDisabled ? 'disabled' : 'enabled';
            $index++;
        }

        return $states;
    }

    public function getEnabledBreakpoints(array $breakpoints, array $config): array
    {
        $enabled = [];
        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            if ($this->isBreakpointDisabled((string)$breakpointName, $config, $index)) {
                $index++;
                continue;
            }

            $enabled[(string)$breakpointName] = (int)$breakpointValue;
            $index++;
        }

        return $enabled;
    }

    public function isBreakpointDisabled(?string $breakpointName, array $config, ?int $index = null): bool
    {
        if ($breakpointName === null || $breakpointName === '') {
            return false;
        }

        if ($breakpointName === 'escape' && !$this->shouldIncludeEscapeWidth($config)) {
            return true;
        }

        $namedTransform = $this->getNamedTransform($config);
        if ($namedTransform !== null && $index !== null) {
            $entry = $this->getTransformEntryByIndex($namedTransform, $index);
            if ($entry !== null && isset($entry['enabled']) && $entry['enabled'] === false) {
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

        $namedTransform = $this->getNamedTransform($config);

        return $namedTransform !== null
            && array_key_exists('includeEscapeWidth', $namedTransform)
            && $namedTransform['includeEscapeWidth'] === true;
    }

    private function getNamedTransform(array $config): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        $transformName = (string)($config['transformName'] ?? 'default');

        return $this->_plugin->getTransforms()->getTransform($transformName);
    }

    private function getTransformEntryByIndex(?array $transform, int $index): ?array
    {
        if ($transform === null || !isset($transform['transforms']) || !is_array($transform['transforms'])) {
            return null;
        }

        if (!isset($transform['transforms'][$index]) || !is_array($transform['transforms'][$index])) {
            return null;
        }

        return $transform['transforms'][$index];
    }
}