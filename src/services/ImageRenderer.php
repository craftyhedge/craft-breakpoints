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
    private const TRANSPARENT_PIXEL_DATA_URI = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function render(?Asset $image, string $transform, array $config = []): Markup
    {
        if ($image === null) {
            return new Markup('<!-- Breakpoint Images: no image provided -->', 'UTF-8');
        }

        $config['imageId'] = $image->id;
        $config['transformName'] = $transform;
        $config['assetTitle'] = (string)($image->title ?? '');

        return $this->renderPicture($config, $image);
    }

    public function renderPicture(array $config, Asset $image): Markup
    {
        if ($this->_plugin === null) {
            return new Markup('<!-- Breakpoint Images: plugin unavailable -->', 'UTF-8');
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);

        $pictureAttributes = $this->getPictureAttributes($mergedConfig);
        $imgAttributes = $this->getImageAttributes($mergedConfig, $image);

        if ($imgAttributes === null || empty($imgAttributes)) {
            return new Markup('<!-- Breakpoint Images: could not build image attributes -->', 'UTF-8');
        }

        $breakpoints = $this->_plugin->getImageTransforms()->getBreakpointsForTemplate($mergedConfig);
        $pictureTemplatePath = $this->_plugin->getConfigService()->getPictureTemplatePath($mergedConfig);

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
            $markup = sprintf(
                '<img src="%s" alt="%s">',
                Html::encode((string)($imgAttributes['src'] ?? '')),
                Html::encode((string)($imgAttributes['alt'] ?? ''))
            );
        } finally {
            $view->setTemplateMode($oldMode);
        }

        return new Markup($markup, 'UTF-8');
    }

    public function getPictureAttributes(array $config): array
    {
        $transformName = (string)($config['transformName'] ?? 'default');
        $assetId = isset($config['imageId']) ? (string)$config['imageId'] : 'unknown';
        $pictureId = $this->buildPictureId($transformName, $assetId, $config);
        $transform = $this->_plugin?->getTransforms()->getTransform($transformName);

        return [
            'class' => (string)($config['pictureClass'] ?? ''),
            'data-transform' => $transformName,
            'data-transform-exists' => $transform !== null ? 'true' : 'false',
            'data-picture-id' => $pictureId,
            'data-asset-id' => $assetId,
            'data-asset-title' => (string)($config['assetTitle'] ?? ''),
            'data-breakpoint-states' => $this->buildBreakpointStatesJson($config),
        ];
    }

    public function getImageAttributes(array $config, Asset $image): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        $transformName = (string)($config['transformName'] ?? 'default');
        $transformedImages = $this->_plugin->getImageTransforms()->getTransformedImages($image, $transformName, 'primary', $config);

        $firstEnabledIndex = $this->_plugin->getImageTransforms()->getFirstEnabledBreakpointIndex($config);
        $firstImage = null;
        if ($firstEnabledIndex !== null && isset($transformedImages[$firstEnabledIndex])) {
            $firstImage = $transformedImages[$firstEnabledIndex];
        }

        if ($firstImage === null) {
            $firstImage = reset($transformedImages);
        }

        $src = is_array($firstImage) && isset($firstImage['url'])
            ? (string)$firstImage['url']
            : self::TRANSPARENT_PIXEL_DATA_URI;
        $width = is_array($firstImage) && isset($firstImage['width']) ? (int)$firstImage['width'] : 1;
        $height = is_array($firstImage) && isset($firstImage['height']) ? (int)$firstImage['height'] : 1;

        if ($src === '' || ($firstImage['disabled'] ?? false) === true) {
            $src = self::TRANSPARENT_PIXEL_DATA_URI;
            $width = 1;
            $height = 1;
        }

        $attributes = [
            'src' => $src,
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
            'class' => (string)($config['imgClass'] ?? ''),
            'decoding' => (string)($config['decoding'] ?? 'async'),
            'alt' => (string)($config['alt'] ?? $image->title ?? ''),
            'data-asset-id' => (string)$image->id,
            'data-uid' => $this->buildPictureId($transformName, (string)$image->id, $config) . '-img',
        ];

        if ((bool)($config['nativeLazyLoadingEnabled'] ?? true)) {
            $attributes['loading'] = (string)($config['loading'] ?? 'lazy');
        }

        return $attributes;
    }

    private function buildPictureId(string $transformName, string $assetId, array $config): string
    {
        $variantSeed = json_encode([
            'initWidth' => $config['initWidth'] ?? null,
            'initHeight' => $config['initHeight'] ?? null,
            'pictureClass' => $config['pictureClass'] ?? null,
            'imgClass' => $config['imgClass'] ?? null,
        ]);

        if ($variantSeed === false) {
            $variantSeed = serialize([
                $config['initWidth'] ?? null,
                $config['initHeight'] ?? null,
                $config['pictureClass'] ?? null,
                $config['imgClass'] ?? null,
            ]);
        }

        $hash = substr(sha1($variantSeed), 0, 8);

        return sprintf('%s-%s-%s', $transformName, $assetId, $hash);
    }

    private function buildBreakpointStatesJson(array $config): string
    {
        if ($this->_plugin === null) {
            return '{}';
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints($config);
        $transformName = (string)($config['transformName'] ?? 'default');
        $transform = $this->_plugin->getTransforms()->getTransform($transformName);
        $entries = is_array($transform['transforms'] ?? null) ? $transform['transforms'] : [];
        $disableBreakpoints = is_array($config['disableBreakpoints'] ?? null) ? $config['disableBreakpoints'] : [];
        $includeEscapeWidth = (bool)($transform['includeEscapeWidth'] ?? false);

        $states = [];
        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            $enabled = true;

            if ($breakpointName === 'escape' && !$includeEscapeWidth) {
                $enabled = false;
            }

            if (isset($entries[$index]) && is_array($entries[$index]) && array_key_exists('enabled', $entries[$index])) {
                $enabled = $entries[$index]['enabled'] !== false;
            }

            if (array_key_exists($breakpointName, $disableBreakpoints)) {
                $enabled = $disableBreakpoints[$breakpointName] !== true;
            }

            $states[$breakpointName] = $enabled ? 'enabled' : 'disabled';
            $index++;
        }

        $encoded = json_encode($states);

        return $encoded === false ? '{}' : $encoded;
    }
}
