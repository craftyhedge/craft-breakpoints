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

    public function render(?Asset $image, string $setName, array $config = []): Markup
    {
        $plugin = $this->plugin();
        if ($plugin === null) {
            return new Markup('<!-- Breakpoints: plugin unavailable -->', 'UTF-8');
        }

        return $plugin->getImageRenderer()->render($image, $setName, $config);
    }

    public function getBreakpointData(int $loopIndex, int $breakpoint, array $config, Asset $image): array
    {
        $plugin = $this->plugin();
        if ($plugin === null) {
            return [];
        }

        return $plugin->getImageTransforms()->getBreakpointData($loopIndex, $breakpoint, $config, $image);
    }

    private function plugin(): ?Plugin
    {
        return $this->_plugin;
    }
}
