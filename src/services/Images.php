<?php

namespace craftyhedge\craftbreakpoints\services;

use craft\elements\Asset;
use craftyhedge\craftbreakpoints\Plugin;
use Twig\Markup;
use yii\base\Component;

class Images extends Component
{
    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function render(?Asset $image, string $setName, array $config = []): Markup
    {
        $plugin = $this->plugin();
        if ($plugin === null) {
            return new Markup('<!-- Breakpoints: plugin unavailable -->', 'UTF-8');
        }

        return $plugin->getImageRenderer()->render($image, $setName, $config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function getBreakpointData(int $loopIndex, int $breakpoint, array $config, Asset $image): array
    {
        $plugin = $this->plugin();
        if ($plugin === null) {
            return [];
        }

        $effectiveImage = $this->resolveEffectiveImageForBreakpoint($plugin, $loopIndex, $config, $image);

        return $plugin->getImageTransforms()->getBreakpointData($loopIndex, $breakpoint, $config, $effectiveImage);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveEffectiveImageForBreakpoint(Plugin $plugin, int $loopIndex, array $config, Asset $image): Asset
    {
        $breakpoints = $plugin->getImageTransforms()->getBreakpointsForTemplate($config);
        $slotKey = array_keys($breakpoints)[$loopIndex] ?? null;
        if ($slotKey === null) {
            return $image;
        }

        $sourceAssetsBySlot = $plugin->getRenderContextBuilder()->getSourceAssetsBySlot($config, $image);
        $sourceAsset = $sourceAssetsBySlot[(string)$slotKey] ?? null;

        return $sourceAsset instanceof Asset ? $sourceAsset : $image;
    }

    private function plugin(): ?Plugin
    {
        return $this->_plugin;
    }
}
