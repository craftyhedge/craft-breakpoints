<?php

namespace craftyhedge\craftbreakpoints\services;

use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

class BreakpointSlotResolver extends Component
{
    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    /**
     * Canonical slots for saved variants and runtime rendering.
     *
     * `mediaWidth` is the viewport/source boundary. `measureWidth` is the
     * transform size to request. includeEscapeWidth only changes measureWidth on
     * the final slot.
     *
     * @return array<int, array{key: string, index: int, mediaWidth: int, measureWidth: int, isBase: bool, isFinal: bool}>
     */
    public function getSlots(bool $includeEscapeWidth): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints();
        $escapeWidth = isset($breakpoints['escape']) ? (int)$breakpoints['escape'] : null;
        unset($breakpoints['escape']);

        $names = array_keys($breakpoints);
        $widths = array_values(array_map('intval', $breakpoints));
        if ($widths === []) {
            return [];
        }

        $keys = ['base', ...$names];
        $lastIndex = count($keys) - 1;
        $lastWidth = (int)$widths[count($widths) - 1];
        $slots = [];

        foreach ($keys as $index => $key) {
            $mediaWidth = $widths[$index] ?? $lastWidth;
            $isFinal = $index === $lastIndex;
            $measureWidth = $isFinal && $includeEscapeWidth && $escapeWidth !== null
                ? $escapeWidth
                : $mediaWidth;

            $slots[] = [
                'key' => (string)$key,
                'index' => $index,
                'mediaWidth' => (int)$mediaWidth,
                'measureWidth' => (int)$measureWidth,
                'isBase' => $index === 0,
                'isFinal' => $isFinal,
            ];
        }

        return $slots;
    }

    /** @return string[] */
    public function getKeys(): array
    {
        return array_map(static fn(array $slot): string => $slot['key'], $this->getSlots(false));
    }

    /** @return array<string, array{key: string, index: int, mediaWidth: int, measureWidth: int, isBase: bool, isFinal: bool}> */
    public function getSlotsByKey(bool $includeEscapeWidth): array
    {
        $byKey = [];
        foreach ($this->getSlots($includeEscapeWidth) as $slot) {
            $byKey[$slot['key']] = $slot;
        }
        return $byKey;
    }
}
