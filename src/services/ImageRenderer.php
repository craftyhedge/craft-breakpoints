<?php

namespace craftyhedge\craftbreakpoints\services;

use Craft;
use craft\elements\Asset;
use craft\web\View;
use craftyhedge\craftbreakpoints\helpers\ProcessingRequest;
use craftyhedge\craftbreakpoints\Plugin;
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

    /**
     * @param array<string, mixed> $config
     */
    public function render(?Asset $image, string $setName, array $config = []): Markup
    {
        if ($image === null) {
            return new Markup('<!-- Breakpoints: no image provided -->', 'UTF-8');
        }

        $config['imageId'] = $image->id;
        $config['setName'] = $setName;
        $config['assetTitle'] = (string)($image->title ?? '');

        return $this->renderTemplateMarkup($config, $image);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderTemplateMarkup(array $config, Asset $image): Markup
    {
        if ($this->_plugin === null) {
            return new Markup('<!-- Breakpoints: plugin unavailable -->', 'UTF-8');
        }

        $context = $this->_plugin->getRenderContextBuilder()->build($config, $image);
        if ($context === null) {
            return new Markup('<!-- Breakpoints: could not build image attributes -->', 'UTF-8');
        }

        $pictureTemplatePath = (string)($context['pictureTemplatePath'] ?? '');
        $svgTemplatePath = (string)($context['svgTemplatePath'] ?? '');
        $pictureAttributes = is_array($context['pictureAttributes'] ?? null) ? $context['pictureAttributes'] : [];
        $imgAttributes = is_array($context['imgAttributes'] ?? null) ? $context['imgAttributes'] : [];
        $breakpoints = is_array($context['breakpoints'] ?? null) ? $context['breakpoints'] : [];
        $mergedConfig = is_array($context['config'] ?? null) ? $context['config'] : [];
        $templatePath = $this->isSvgAsset($image) ? $svgTemplatePath : $pictureTemplatePath;

        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            $markup = $view->renderTemplate($templatePath, [
                'image' => $image,
                'config' => $mergedConfig,
                'pictureAttributes' => $pictureAttributes,
                'imgAttributes' => $imgAttributes,
                'breakpoints' => $breakpoints,
                'isProcessing' => ProcessingRequest::isActive(),
            ]);
        } catch (\Throwable $e) {
            Plugin::error(sprintf(
                'Failed to render picture template "%s": %s in %s:%d',
                $templatePath,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            // A failure in the plugin's own bundled template should degrade
            // gracefully — never take down the developer's page over our bug.
            // A failure in a developer's custom template is their bug to fix,
            // so let it propagate and surface the full Twig/Craft stack trace.
            // The `finally` below restores the template mode before the
            // exception escapes this method.
            if (!$this->_plugin->getConfigService()->isDefaultTemplatePath($templatePath)) {
                throw $e;
            }

            $markup = $this->renderFallbackImage($imgAttributes);
        } finally {
            $view->setTemplateMode($oldMode);
        }

        return new Markup($markup, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function getPictureAttributes(array $config): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getRenderContextBuilder()->getPictureAttributes($config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    public function getImageAttributes(array $config, Asset $image): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        return $this->_plugin->getRenderContextBuilder()->getImageAttributes($config, $image);
    }

    /**
     * @param array<string, mixed> $imgAttributes
     */
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

    private function isSvgAsset(Asset $image): bool
    {
        try {
            $extension = strtolower(trim($image->getExtension()));
            if ($extension === 'svg') {
                return true;
            }
        } catch (\Throwable) {
            // Ignore extension lookup failures for partially mocked assets.
        }

        try {
            $mimeType = strtolower(trim((string)$image->getMimeType()));

            return $mimeType === 'image/svg+xml';
        } catch (\Throwable) {
            return false;
        }
    }
}
