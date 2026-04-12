<?php

namespace craftyhedge\craftbreakpointimages\services;

use craft\elements\Asset;
use craftyhedge\craftbreakpointimages\Plugin;
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
            return new Markup('<!-- Breakpoint Images: plugin unavailable -->', 'UTF-8');
        }

        return $plugin->getImageRenderer()->render($image, $setName, $config);
    }

    public function renderPicture(array $config, Asset $image): Markup
    {
        $plugin = $this->plugin();
        if ($plugin === null) {
            return new Markup('<!-- Breakpoint Images: plugin unavailable -->', 'UTF-8');
        }

        return $plugin->getImageRenderer()->renderPicture($config, $image);
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
