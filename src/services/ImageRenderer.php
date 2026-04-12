<?php

namespace craftyhedge\craftbreakpointimages\services;

use Craft;
use craft\elements\Asset;
use craft\web\View;
use craftyhedge\craftbreakpointimages\Plugin;
use Twig\Markup;
use yii\helpers\Html;
use yii\base\Component;

class ImageRenderer extends Component
{
    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function render(?Asset $image, string $setName, array $config = []): Markup
    {
        if ($image === null) {
            return new Markup('<!-- Breakpoint Images: no image provided -->', 'UTF-8');
        }

        $config['imageId'] = $image->id;
        $config['setName'] = $setName;
        $config['assetTitle'] = (string)($image->title ?? '');

        return $this->renderPicture($config, $image);
    }

    public function renderPicture(array $config, Asset $image): Markup
    {
        if ($this->_plugin === null) {
            return new Markup('<!-- Breakpoint Images: plugin unavailable -->', 'UTF-8');
        }

        $context = $this->_plugin->getRenderContextBuilder()->build($config, $image);
        if ($context === null) {
            return new Markup('<!-- Breakpoint Images: could not build image attributes -->', 'UTF-8');
        }

        $pictureTemplatePath = (string)($context['pictureTemplatePath'] ?? '');
        $pictureAttributes = is_array($context['pictureAttributes'] ?? null) ? $context['pictureAttributes'] : [];
        $imgAttributes = is_array($context['imgAttributes'] ?? null) ? $context['imgAttributes'] : [];
        $breakpoints = is_array($context['breakpoints'] ?? null) ? $context['breakpoints'] : [];
        $mergedConfig = is_array($context['config'] ?? null) ? $context['config'] : [];

        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            $markup = $view->renderTemplate($pictureTemplatePath, [
                'image' => $image,
                'config' => $mergedConfig,
                'pictureAttributes' => $pictureAttributes,
                'imgAttributes' => $imgAttributes,
                'breakpoints' => $breakpoints,
            ]);
        } catch (\Throwable $e) {
            Plugin::warning('Could not render picture template path: ' . $pictureTemplatePath . '.');
            $markup = $this->renderFallbackImage($imgAttributes);
        } finally {
            $view->setTemplateMode($oldMode);
        }

        return new Markup($markup, 'UTF-8');
    }

    public function getPictureAttributes(array $config): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getRenderContextBuilder()->getPictureAttributes($config);
    }

    public function getImageAttributes(array $config, Asset $image): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        return $this->_plugin->getRenderContextBuilder()->getImageAttributes($config, $image);
    }

    private function renderFallbackImage(array $imgAttributes): string
    {
        $normalizedAttributes = [];
        foreach ($imgAttributes as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalizedAttributes[(string)$name] = $value;
        }

        return '<img' . Html::renderTagAttributes($normalizedAttributes) . '>';
    }
}
