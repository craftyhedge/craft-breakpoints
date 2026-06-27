<?php

namespace craftyhedge\craftbreakpoints\services;

use craft\elements\Asset;
use craftyhedge\craftbreakpoints\helpers\ProcessingRequest;
use craftyhedge\craftbreakpoints\Plugin;
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

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
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
        $sourceConfigsBySlot = $this->getSourceConfigsBySlot($mergedConfig, $image);

        return [
            'config' => $mergedConfig,
            'pictureTemplatePath' => $this->_plugin->getConfigService()->getPictureTemplatePath($mergedConfig),
            'svgTemplatePath' => $this->_plugin->getConfigService()->getSvgTemplatePath($mergedConfig),
            'pictureAttributes' => $this->getPictureAttributes($mergedConfig),
            'imgAttributes' => $imgAttributes,
            'breakpoints' => $this->_plugin->getImageTransforms()->getBreakpointsForTemplate($mergedConfig),
            'sourceAssetsBySlot' => $this->sourceAssetsFromConfigs($sourceConfigsBySlot),
            'sourceConfigsBySlot' => $sourceConfigsBySlot,
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, Asset>
     */
    public function getSourceAssetsBySlot(array $config, Asset $defaultImage): array
    {
        return $this->sourceAssetsFromConfigs($this->getSourceConfigsBySlot($config, $defaultImage));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, array{asset: Asset, quality?: int}>
     */
    public function getSourceConfigsBySlot(array $config, Asset $defaultImage): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $sources = $config['sources'] ?? null;
        if (!is_array($sources) || $sources === []) {
            return [];
        }

        $set = null;
        $setName = trim((string)($config['setName'] ?? $config['transformName'] ?? ''));
        if ($setName !== '') {
            $set = $this->_plugin->getTransformSets()->getSet($setName);
        }

        $includeEscapeWidth = $this->_plugin->getBreakpointPolicy()->resolveIncludeEscapeWidth($config, $set);
        $slotsByKey = $this->_plugin->getBreakpointSlots()->getSlotsByKey($includeEscapeWidth);
        $configsBySlot = [];

        foreach ($sources as $sourceName => $sourceConfig) {
            if (!is_array($sourceConfig)) {
                continue;
            }

            $asset = $sourceConfig['asset'] ?? null;
            if (!$asset instanceof Asset) {
                continue;
            }

            $rawSlots = $sourceConfig['slots'] ?? ($sourceConfig['breakpoints'] ?? []);
            if (!is_array($rawSlots)) {
                $rawSlots = [$rawSlots];
            }

            foreach ($rawSlots as $rawSlot) {
                $slotKey = trim((string)$rawSlot);
                if ($slotKey === '') {
                    continue;
                }

                if (!isset($slotsByKey[$slotKey])) {
                    Plugin::warning(sprintf(
                        'Ignoring unknown art-directed source slot "%s" for source "%s".',
                        $slotKey,
                        (string)$sourceName,
                    ));
                    continue;
                }

                if ((string)$asset->id === (string)$defaultImage->id) {
                    unset($configsBySlot[$slotKey]);
                    continue;
                }

                $slotConfig = ['asset' => $asset];
                $quality = $this->normalizeSourceQuality($sourceConfig['quality'] ?? null);
                if ($quality !== null) {
                    $slotConfig['quality'] = $quality;
                }

                $configsBySlot[$slotKey] = $slotConfig;
            }
        }

        return $configsBySlot;
    }

    private function normalizeSourceQuality(mixed $quality): ?int
    {
        if (!is_numeric($quality)) {
            return null;
        }

        $normalized = (int)$quality;

        return $normalized >= 1 && $normalized <= 100 ? $normalized : null;
    }

    /**
     * @param array<string, array{asset: Asset, quality?: int}> $sourceConfigsBySlot
     * @return array<string, Asset>
     */
    private function sourceAssetsFromConfigs(array $sourceConfigsBySlot): array
    {
        return array_map(
            static fn(array $sourceConfig): Asset => $sourceConfig['asset'],
            $sourceConfigsBySlot,
        );
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function getPictureAttributes(array $config): array
    {
        $attributes = [
            'class' => (string)($config['pictureClass'] ?? ''),
        ];

        if (($this->_plugin?->getConfigService()->allowTransformEditing($config) ?? false) === true) {
            $attributes['data-set'] = $this->resolveSetName($config);
        }

        // The remaining data-* markers exist only for the client-side processing
        // pipeline (the __bpiProcessing preview iframe). Keep them out of normal
        // output.
        if (!ProcessingRequest::isActive()) {
            return $attributes;
        }

        return array_merge($attributes, $this->composePictureMarkers($config));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function composePictureMarkers(array $config): array
    {
        $setName = $this->resolveSetName($config);
        $assetId = isset($config['imageId']) ? (string)$config['imageId'] : 'unknown';
        $pictureId = $this->buildPictureId($setName, $assetId, $config);
        $set = $this->_plugin?->getTransformSets()->getSet($setName);

        return [
            'data-set' => $setName,
            'data-include-escape-width' => ($this->_plugin?->getBreakpointPolicy()->resolveIncludeEscapeWidth($config, $set) ?? false)
                ? 'true'
                : 'false',
            'data-picture-id' => $pictureId,
            'data-asset-id' => $assetId,
            'data-asset-title' => (string)($config['assetTitle'] ?? ''),
            'data-breakpoint-states' => $this->buildBreakpointStatesJson($config),
        ];
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

        $setName = $this->resolveSetName($config);
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
            'alt' => (string)($config['alt'] ?? $image->alt ?? ''),
        ];

        if ((bool)($config['nativeLazyLoadingEnabled'] ?? true)) {
            $attributes['loading'] = (string)($config['loading'] ?? 'lazy');
        }

        $fetchPriority = trim((string)($config['fetchpriority'] ?? $config['fetchPriority'] ?? ''));
        if ($fetchPriority !== '') {
            $attributes['fetchpriority'] = $fetchPriority;
        }

        // Processing-only markers (see getPictureAttributes): emitted only inside
        // the __bpiProcessing preview iframe, never on normal front-end output.
        if (ProcessingRequest::isActive()) {
            $attributes['data-asset-id'] = (string)$image->id;
            $attributes['data-uid'] = $this->buildPictureId($setName, (string)$image->id, $config) . '-img';
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveSetName(array $config): string
    {
        $setName = (string)($config['setName'] ?? $config['transformName'] ?? '');
        if (trim($setName) === '') {
            throw new \InvalidArgumentException('A non-empty set name is required in config.');
        }

        return $setName;
    }

    /**
     * @param array<string, mixed> $config
     */
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

    /**
     * @param array<string, mixed> $config
     */
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
