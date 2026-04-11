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

    public function render(?Asset $image, string $transform, array $config = []): Markup
    {
        if ($this->_plugin === null) {
            return new Markup('<!-- Breakpoint Images: plugin unavailable -->', 'UTF-8');
        }

        return $this->_plugin->getImageRenderer()->render($image, $transform, $config);
    }

    public function renderPicture(array $config, Asset $image): Markup
    {
        if ($this->_plugin === null) {
            return new Markup('<!-- Breakpoint Images: plugin unavailable -->', 'UTF-8');
        }

        return $this->_plugin->getImageRenderer()->renderPicture($config, $image);
    }

    public function getBreakpointData(int $loopIndex, int $breakpoint, array $config, Asset $image): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getImageTransforms()->getBreakpointData($loopIndex, $breakpoint, $config, $image);
    }

    public function sourceMediaQuery(int $breakpoint, ?int $secondLastBreakpoint, bool $isLastLoop): string
    {
        if ($this->_plugin === null) {
            return '';
        }

        return $this->_plugin->getImageTransforms()->sourceMediaQuery($breakpoint, $secondLastBreakpoint, $isLastLoop);
    }

    public function getBreakpoints(array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getImageTransforms()->getBreakpoints($config);
    }

    public function getTransformedImages(Asset $image, string $transform = 'default', string $formatIndex = 'primary', array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getImageTransforms()->getTransformedImages($image, $transform, $formatIndex, $config);
    }

    public function getPictureAttributes(array $config): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getImageRenderer()->getPictureAttributes($config);
    }

    public function getImageAttributes(array $config, Asset $image): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        return $this->_plugin->getImageRenderer()->getImageAttributes($config, $image);
    }
}
