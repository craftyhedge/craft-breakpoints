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

        $sourceConfig = $this->resolveEffectiveSourceConfigForBreakpoint($plugin, $loopIndex, $config, $image);
        $effectiveImage = $sourceConfig['asset'] ?? $image;
        $effectiveConfig = $this->mergeSourceConfigForBreakpoint($config, $sourceConfig);

        return $plugin->getImageTransforms()->getBreakpointData($loopIndex, $breakpoint, $effectiveConfig, $effectiveImage);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{asset?: Asset, quality?: int}
     */
    private function resolveEffectiveSourceConfigForBreakpoint(Plugin $plugin, int $loopIndex, array $config, Asset $image): array
    {
        $breakpoints = $plugin->getImageTransforms()->getBreakpointsForTemplate($config);
        $slotKey = array_keys($breakpoints)[$loopIndex] ?? null;
        if ($slotKey === null) {
            return ['asset' => $image];
        }

        $sourceConfigsBySlot = $plugin->getRenderContextBuilder()->getSourceConfigsBySlot($config, $image);
        $sourceConfig = $sourceConfigsBySlot[(string)$slotKey] ?? null;

        return is_array($sourceConfig) ? $sourceConfig : ['asset' => $image];
    }

    /**
     * @param array<string, mixed> $config
     * @param array{asset?: Asset, quality?: int} $sourceConfig
     * @return array<string, mixed>
     */
    private function mergeSourceConfigForBreakpoint(array $config, array $sourceConfig): array
    {
        if (isset($sourceConfig['quality']) && is_int($sourceConfig['quality'])) {
            $config['quality'] = $sourceConfig['quality'];
        }

        return $config;
    }

    private function plugin(): ?Plugin
    {
        return $this->_plugin;
    }
}
