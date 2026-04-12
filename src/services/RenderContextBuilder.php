<?php

namespace craftyhedge\craftbreakpointimages\services;

use craft\elements\Asset;
use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class RenderContextBuilder extends Component
{
    private const TRANSPARENT_PIXEL_DATA_URI = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';

    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function build(array $config, Asset $image): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);
        $imgAttributes = $this->getImageAttributes($mergedConfig, $image);
        if ($imgAttributes === null || empty($imgAttributes)) {
            return null;
        }

        return [
            'config' => $mergedConfig,
            'pictureTemplatePath' => $this->_plugin->getConfigService()->getPictureTemplatePath($mergedConfig),
            'pictureAttributes' => $this->getPictureAttributes($mergedConfig),
            'imgAttributes' => $imgAttributes,
            'breakpoints' => $this->_plugin->getImageTransforms()->getBreakpointsForTemplate($mergedConfig),
        ];
    }

    public function getPictureAttributes(array $config): array
    {
        $setName = (string)($config['setName'] ?? $config['transformName'] ?? 'default');
        $assetId = isset($config['imageId']) ? (string)$config['imageId'] : 'unknown';
        $pictureId = $this->buildPictureId($setName, $assetId, $config);
        $set = $this->_plugin?->getTransformSets()->getSet($setName);

        return [
            'class' => (string)($config['pictureClass'] ?? ''),
            'data-set' => $setName,
            'data-set-exists' => $set !== null ? 'true' : 'false',
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

        $setName = (string)($config['setName'] ?? $config['transformName'] ?? 'default');
        $transformedImages = $this->_plugin->getImageTransforms()->getTransformedImages($image, $setName, 'primary', $config);

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
        $isDisabled = is_array($firstImage) && (($firstImage['disabled'] ?? false) === true);

        if ($src === '' || $isDisabled) {
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
            'data-uid' => $this->buildPictureId($setName, (string)$image->id, $config) . '-img',
        ];

        if ((bool)($config['nativeLazyLoadingEnabled'] ?? true)) {
            $attributes['loading'] = (string)($config['loading'] ?? 'lazy');
        }

        return $attributes;
    }

    private function buildPictureId(string $setName, string $assetId, array $config): string
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

        return sprintf('%s-%s-%s', $setName, $assetId, $hash);
    }

    private function buildBreakpointStatesJson(array $config): string
    {
        if ($this->_plugin === null) {
            return '{}';
        }

        $states = $this->_plugin->getImageTransforms()->getBreakpointStates($config);
        $encoded = json_encode($states);

        return $encoded === false ? '{}' : $encoded;
    }
}