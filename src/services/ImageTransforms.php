<?php

namespace craftyhedge\craftbreakpointimages\services;

use Craft;
use craft\elements\Asset;
use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class ImageTransforms extends Component
{
    private const TRANSPARENT_PIXEL_DATA_URI = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
    private const PROCESSING_MEDIA_OVERSIZE_PX = 20;
    private const PROCESSING_QUERY_PARAM = '__bpiProcessing';

    private ?Plugin $_plugin = null;
    private array $_transformedImagesCache = [];
    private array $_breakpointDataCache = [];

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function getBreakpointData(int $loopIndex, int $breakpoint, array $config, Asset $image): array
    {
        $cacheKey = $this->getBreakpointCacheKey($loopIndex, $breakpoint, $config, $image);
        if (isset($this->_breakpointDataCache[$cacheKey])) {
            return $this->_breakpointDataCache[$cacheKey];
        }

        if ($this->_plugin === null) {
            return [];
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);
        $effectiveSecondaryFormat = $this->resolveEffectiveSecondaryFormat($config, $mergedConfig);
        $allBreakpoints = $this->getBreakpointsForTransform($config, $mergedConfig);
        $breakpointNames = array_keys($allBreakpoints);
        $breakpointName = $breakpointNames[$loopIndex] ?? null;
        $namedTransform = $this->getNamedTransform($config);
        $transformEntry = $this->getTransformEntryByIndex($namedTransform, $loopIndex);
        $autoDimension = $this->resolveAutoDimension($transformEntry, $config);

        if ($this->isBreakpointDisabled($breakpointName, $config, $loopIndex)) {
            $sourceMediaQuery = $this->getDisabledMediaQuery($breakpoint);
            $primarySourceAttributes = array_merge([
                'srcset' => self::TRANSPARENT_PIXEL_DATA_URI,
                'type' => 'image/gif',
                'width' => 1,
                'height' => 1,
            ], $this->buildSourceDataAttributes(
                $breakpoint,
                false,
                $transformEntry,
                null,
                $autoDimension,
                'bpi_first-source-set'
            ));

            if ($sourceMediaQuery !== '') {
                $primarySourceAttributes['media'] = $sourceMediaQuery;
            }

            $secondarySourceAttributes = [];
            $secondaryFormat = null;
            if ($this->isSecondaryFormatEnabled(['secondaryFormat' => $effectiveSecondaryFormat])) {
                $secondarySourceAttributes = array_merge([
                    'srcset' => self::TRANSPARENT_PIXEL_DATA_URI,
                    'type' => 'image/gif',
                    'width' => 1,
                    'height' => 1,
                ], $this->buildSourceDataAttributes(
                    $breakpoint,
                    false,
                    $transformEntry,
                    null,
                    $autoDimension,
                    'bpi_secondary-source-set'
                ));

                if ($sourceMediaQuery !== '') {
                    $secondarySourceAttributes['media'] = $sourceMediaQuery;
                }

                $secondaryFormat = ['disabled' => true, 'format' => 'gif'];
            }

            $result = [
                'primarySourceAttributes' => $primarySourceAttributes,
                'secondarySourceAttributes' => $secondarySourceAttributes,
                'primaryFormat' => ['disabled' => true, 'format' => 'gif', 'url' => self::TRANSPARENT_PIXEL_DATA_URI],
                'secondaryFormat' => $secondaryFormat,
                'disabled' => true,
            ];

            $this->_breakpointDataCache[$cacheKey] = $result;

            return $result;
        }

        $transformName = (string)($config['transformName'] ?? 'default');

        $transformedPrimary = $this->getTransformedImages($image, $transformName, 'primary', $config);
        $transformedSecondary = $this->getTransformedImages($image, $transformName, 'secondary', $config);

        $primary = $transformedPrimary[$loopIndex] ?? null;
        $secondary = $transformedSecondary[$loopIndex] ?? null;

        if ($primary === null) {
            $primary = [
                'url' => self::TRANSPARENT_PIXEL_DATA_URI,
                'format' => 'gif',
                'width' => 1,
                'height' => 1,
            ];
        }

        $enabledBreakpoints = $this->getEnabledBreakpoints($allBreakpoints, $config);
        $enabledValues = array_values($enabledBreakpoints);
        $secondLastBreakpoint = count($enabledValues) >= 2
            ? (int)$enabledValues[count($enabledValues) - 2]
            : null;

        $lastEnabledBreakpointName = array_key_last($enabledBreakpoints);
        $isLastLoop = $breakpointName !== null && $breakpointName === $lastEnabledBreakpointName;
        $mediaQuery = $this->sourceMediaQuery($breakpoint, $secondLastBreakpoint, $isLastLoop);

        $primarySourceAttributes = array_merge([
            'srcset' => $this->generateDprSrcset($image, $primary, $mergedConfig),
            'type' => $this->getMimeTypeForFormat((string)($primary['format'] ?? 'jpg')),
            'width' => isset($primary['width']) && is_numeric($primary['width']) ? (int)$primary['width'] : null,
            'height' => isset($primary['height']) && is_numeric($primary['height']) ? (int)$primary['height'] : null,
        ], $this->buildSourceDataAttributes(
            $breakpoint,
            true,
            $transformEntry,
            $primary,
            $autoDimension,
            'bpi_first-source-set'
        ));

        if ($mediaQuery !== '') {
            $primarySourceAttributes['media'] = $mediaQuery;
        }

        $secondarySourceAttributes = [];
        if ($secondary !== null) {
            $secondarySourceAttributes = array_merge([
                'srcset' => $this->generateDprSrcset($image, $secondary, $mergedConfig),
                'type' => $this->getMimeTypeForFormat((string)($secondary['format'] ?? 'jpg')),
                'width' => isset($secondary['width']) && is_numeric($secondary['width']) ? (int)$secondary['width'] : null,
                'height' => isset($secondary['height']) && is_numeric($secondary['height']) ? (int)$secondary['height'] : null,
            ], $this->buildSourceDataAttributes(
                $breakpoint,
                true,
                $transformEntry,
                $secondary,
                $autoDimension,
                'bpi_secondary-source-set'
            ));

            if ($mediaQuery !== '') {
                $secondarySourceAttributes['media'] = $mediaQuery;
            }
        }

        $result = [
            'primarySourceAttributes' => $primarySourceAttributes,
            'secondarySourceAttributes' => $secondarySourceAttributes,
            'primaryFormat' => $primary,
            'secondaryFormat' => $secondary,
        ];

        $this->_breakpointDataCache[$cacheKey] = $result;

        return $result;
    }

    public function getFirstEnabledBreakpointIndex(array $config = []): ?int
    {
        if ($this->_plugin === null) {
            return null;
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);
        $breakpoints = $this->getBreakpointsForTransform($config, $mergedConfig);
        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            if (!$this->isBreakpointDisabled((string)$breakpointName, $config, $index)) {
                return $index;
            }

            $index++;
        }

        return null;
    }

    public function sourceMediaQuery(int $breakpoint, ?int $secondLastBreakpoint, bool $isLastLoop): string
    {
        if ($secondLastBreakpoint === null) {
            return '';
        }

        $processingOversizePx = $this->getProcessingMediaOversizePx();

        if ($isLastLoop) {
            $pixelValue = max(($secondLastBreakpoint - 1) + $processingOversizePx, 1);
            $remValue = $pixelValue / 16;
            return sprintf('(min-width: %srem)', $remValue);
        }

        $pixelValue = max(($breakpoint - 1) + $processingOversizePx, 1);
        $remValue = $pixelValue / 16;

        return sprintf('(max-width: %srem)', $remValue);
    }

    public function getBreakpoints(array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getConfigService()->getBreakpoints($config);
    }

    public function getBreakpointsForTemplate(array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);

        return $this->getBreakpointsForTransform($config, $mergedConfig);
    }

    public function getTransformedImages(Asset $image, string $transform = 'default', string $formatIndex = 'primary', array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        if (!isset($config['transformName']) || $config['transformName'] === '') {
            $config['transformName'] = $transform;
        }

        $cacheKey = $this->getTransformCacheKey($image, $formatIndex, $config);
        if (isset($this->_transformedImagesCache[$cacheKey])) {
            return $this->_transformedImagesCache[$cacheKey];
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);
        $breakpoints = $this->getBreakpointsForTransform($config, $mergedConfig);
        $namedTransform = $this->getNamedTransform($config);
        $namedTransformConfig = $this->getNamedTransformConfig($namedTransform);

        $primaryFormat = $this->normalizeTargetFormat(
            (string)($namedTransformConfig['format'] ?? $mergedConfig['format'] ?? 'jpg'),
            'jpg'
        );
        $secondaryFormat = $this->normalizeSecondaryFormat(
            (string)($namedTransformConfig['secondaryFormat'] ?? $mergedConfig['secondaryFormat'] ?? 'none')
        );

        if ($formatIndex === 'secondary' && $secondaryFormat === 'none') {
            return [];
        }

        $targetFormat = $this->normalizeTargetFormat(
            $formatIndex === 'secondary' ? $secondaryFormat : $primaryFormat,
            'jpg'
        );
        $mode = (string)($namedTransformConfig['mode'] ?? $mergedConfig['mode'] ?? 'crop');
        $position = (string)($namedTransformConfig['position'] ?? $mergedConfig['position'] ?? 'center-center');
        $quality = (int)($namedTransformConfig['quality'] ?? $mergedConfig['quality'] ?? 80);

        $sourceWidth = (int)($image->getWidth() ?? 0);
        $sourceHeight = (int)($image->getHeight() ?? 0);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            $sourceWidth = (int)($mergedConfig['defaultWidth'] ?? 1600);
            $sourceHeight = (int)($mergedConfig['defaultHeight'] ?? 900);
        }

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            $sourceWidth = 1600;
            $sourceHeight = 900;
        }

        $aspectRatio = $sourceWidth / max($sourceHeight, 1);
        $initWidth = $this->normalizePositiveInt($config['initWidth'] ?? null);
        $initHeight = $this->normalizePositiveInt($config['initHeight'] ?? null);

        $transformed = [];

        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointWidth) {
            if ($this->isBreakpointDisabled((string)$breakpointName, $config, $index)) {
                $transformed[$index] = $this->getPlaceholderTransform((int)$image->id);
                $index++;
                continue;
            }

            $namedTransformEntry = $this->getTransformEntryByIndex($namedTransform, $index);

            $targetWidth = (int)$breakpointWidth;
            if ($targetWidth <= 0) {
                $targetWidth = $sourceWidth;
            }

            if ($namedTransformEntry !== null && isset($namedTransformEntry['width']) && is_numeric($namedTransformEntry['width'])) {
                $targetWidth = (int)$namedTransformEntry['width'];
            }

            $targetHeight = (int)round($targetWidth / $aspectRatio);
            if ($targetHeight <= 0) {
                $targetHeight = $sourceHeight;
            }

            if ($namedTransformEntry !== null && isset($namedTransformEntry['height']) && is_numeric($namedTransformEntry['height'])) {
                $targetHeight = (int)$namedTransformEntry['height'];
            }

            if ($initWidth !== null) {
                $targetWidth = $initWidth;
            }

            if ($initHeight !== null) {
                $targetHeight = $initHeight;
            }

            if ($initWidth === null && $initHeight !== null) {
                $targetWidth = (int)round($targetHeight * $aspectRatio);
            }

            if ($initHeight === null && $initWidth !== null) {
                $targetHeight = (int)round($targetWidth / $aspectRatio);
            }

            $autoDimension = $this->resolveAutoDimension($namedTransformEntry, $config);

            $transformWidth = $targetWidth;
            $transformHeight = $targetHeight;

            if ($autoDimension === 'width') {
                $transformWidth = null;
            } elseif ($autoDimension === 'height') {
                $transformHeight = null;
            }

            $computedWidth = $transformWidth;
            $computedHeight = $transformHeight;

            if ($computedWidth === null && $computedHeight !== null && $computedHeight > 0) {
                $computedWidth = (int)round($computedHeight * $aspectRatio);
            }

            if ($computedHeight === null && $computedWidth !== null && $computedWidth > 0) {
                $computedHeight = (int)round($computedWidth / $aspectRatio);
            }

            if ($computedWidth === null || $computedWidth <= 0) {
                $computedWidth = $sourceWidth;
            }

            if ($computedHeight === null || $computedHeight <= 0) {
                $computedHeight = $sourceHeight;
            }

            $transformConfig = [
                'width' => $transformWidth,
                'height' => $transformHeight,
                'mode' => is_array($namedTransformEntry) && isset($namedTransformEntry['mode']) ? (string)$namedTransformEntry['mode'] : $mode,
                'position' => is_array($namedTransformEntry) && isset($namedTransformEntry['position']) ? (string)$namedTransformEntry['position'] : $position,
                'quality' => is_array($namedTransformEntry) && isset($namedTransformEntry['quality']) && is_numeric($namedTransformEntry['quality'])
                    ? (int)$namedTransformEntry['quality']
                    : $quality,
                'format' => $targetFormat,
            ];

            try {
                $url = $image->getUrl($transformConfig);
            } catch (\Throwable $e) {
                Plugin::warning('Failed to build transformed image URL for asset ' . $image->id . '.');
                $url = null;
            }

            if ($url === null || $url === '') {
                $url = self::TRANSPARENT_PIXEL_DATA_URI;
            }

            $transformed[$index] = [
                'url' => $url,
                'width' => $computedWidth,
                'height' => $computedHeight,
                'format' => $targetFormat,
                'id' => $image->id,
                'mode' => $transformConfig['mode'],
                'position' => $transformConfig['position'],
                'quality' => $transformConfig['quality'],
                'autoDimension' => $autoDimension,
            ];

            $index++;
        }

        $this->_transformedImagesCache[$cacheKey] = $transformed;

        return $transformed;
    }

    private function resolveAutoDimension(?array $namedTransformEntry, array $config): ?string
    {
        if ($namedTransformEntry !== null && isset($namedTransformEntry['autoDimension'])) {
            $autoDimension = $namedTransformEntry['autoDimension'];
            if ($autoDimension === 'width' || $autoDimension === 'height') {
                return $autoDimension;
            }

            return null;
        }

        if (($config['widthAuto'] ?? false) === true) {
            return 'width';
        }

        if (($config['heightAuto'] ?? false) === true) {
            return 'height';
        }

        $initWidth = $this->normalizePositiveInt($config['initWidth'] ?? null);
        $initHeight = $this->normalizePositiveInt($config['initHeight'] ?? null);

        if ($initWidth !== null && $initHeight === null) {
            return 'height';
        }

        if ($initHeight !== null && $initWidth === null) {
            return 'width';
        }

        return null;
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $parsed = (int)$value;

        return $parsed > 0 ? $parsed : null;
    }

    private function normalizeTargetFormat(string $format, string $fallback): string
    {
        $normalized = strtolower(trim($format));
        if ($normalized === '') {
            return $fallback;
        }

        $supportedFormats = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'];
        if (!in_array($normalized, $supportedFormats, true)) {
            return $fallback;
        }

        return $normalized;
    }

    private function getEnabledBreakpoints(array $breakpoints, array $config): array
    {
        $enabled = [];
        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            if ($this->isBreakpointDisabled((string)$breakpointName, $config, $index)) {
                $index++;
                continue;
            }

            $enabled[(string)$breakpointName] = (int)$breakpointValue;
            $index++;
        }

        return $enabled;
    }

    private function isBreakpointDisabled(?string $breakpointName, array $config, ?int $index = null): bool
    {
        if ($breakpointName === null || $breakpointName === '') {
            return false;
        }

        $namedTransform = $this->getNamedTransform($config);
        $includeEscapeWidth = $this->shouldIncludeEscapeWidth($config);
        if ($breakpointName === 'escape' && $includeEscapeWidth === false) {
            return true;
        }

        if ($namedTransform !== null) {
            if ($index !== null) {
                $entry = $this->getTransformEntryByIndex($namedTransform, $index);
                if ($entry !== null && isset($entry['enabled']) && $entry['enabled'] === false) {
                    return true;
                }
            }
        }

        return isset($config['disableBreakpoints'][$breakpointName])
            && $config['disableBreakpoints'][$breakpointName] === true;
    }

    private function getDisabledMediaQuery(int $breakpoint): string
    {
        $processingOversizePx = $this->getProcessingMediaOversizePx();
        $pixelValue = max(($breakpoint - 1) + $processingOversizePx, 1);
        $remValue = $pixelValue / 16;

        return sprintf('(max-width: %srem)', $remValue);
    }

    private function getProcessingMediaOversizePx(): int
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return 0;
        }

        $rawFlag = $request->getQueryParam(self::PROCESSING_QUERY_PARAM);
        if ($rawFlag === null) {
            return 0;
        }

        if (is_bool($rawFlag)) {
            return $rawFlag ? self::PROCESSING_MEDIA_OVERSIZE_PX : 0;
        }

        $normalized = strtolower(trim((string)$rawFlag));
        if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
            return 0;
        }

        return self::PROCESSING_MEDIA_OVERSIZE_PX;
    }

    private function getPlaceholderTransform(?int $assetId = null): array
    {
        return [
            'url' => self::TRANSPARENT_PIXEL_DATA_URI,
            'width' => 1,
            'height' => 1,
            'format' => 'gif',
            'id' => $assetId,
            'disabled' => true,
        ];
    }

    private function isSecondaryFormatEnabled(array $config): bool
    {
        $secondaryFormat = $this->normalizeSecondaryFormat((string)($config['secondaryFormat'] ?? 'none'));

        return $secondaryFormat !== 'none';
    }

    private function getDprRatios(array $config): array
    {
        $rawRatios = $config['dpr'] ?? [1];
        if (!is_array($rawRatios)) {
            $rawRatios = [$rawRatios];
        }

        $ratios = [];
        foreach ($rawRatios as $ratio) {
            if (!is_numeric($ratio)) {
                continue;
            }

            $parsed = (float)$ratio;
            if ($parsed <= 0) {
                continue;
            }

            $ratios[] = $parsed;
        }

        if (!in_array(1.0, $ratios, true)) {
            $ratios[] = 1.0;
        }

        $ratios = array_values(array_unique($ratios));
        sort($ratios, SORT_NUMERIC);

        return $ratios;
    }

    private function generateDprSrcset(Asset $image, array $baseTransform, array $config): string
    {
        $baseUrl = (string)($baseTransform['url'] ?? self::TRANSPARENT_PIXEL_DATA_URI);
        $baseWidth = (int)($baseTransform['width'] ?? 0);
        $baseHeight = (int)($baseTransform['height'] ?? 0);

        if ($baseWidth <= 0 || $baseHeight <= 0) {
            return $baseUrl;
        }

        $ratios = $this->getDprRatios($config);
        if (count($ratios) <= 1) {
            return $baseUrl;
        }

        $format = $this->normalizeTargetFormat((string)($baseTransform['format'] ?? 'jpg'), 'jpg');
        $mode = (string)($baseTransform['mode'] ?? $config['mode'] ?? 'crop');
        $position = (string)($baseTransform['position'] ?? $config['position'] ?? 'center-center');
        $quality = (int)($baseTransform['quality'] ?? $config['quality'] ?? 80);

        $candidates = [];
        foreach ($ratios as $ratio) {
            $ratioLabel = rtrim(rtrim(sprintf('%.2F', $ratio), '0'), '.');

            if ((float)$ratio === 1.0) {
                $candidates[] = $baseUrl . ' ' . $ratioLabel . 'x';
                continue;
            }

            $ratioWidth = (int)round($baseWidth * $ratio);
            $ratioHeight = (int)round($baseHeight * $ratio);

            if ($ratioWidth <= 0 || $ratioHeight <= 0) {
                continue;
            }

            try {
                $url = $image->getUrl([
                    'width' => $ratioWidth,
                    'height' => $ratioHeight,
                    'mode' => $mode,
                    'position' => $position,
                    'quality' => $quality,
                    'format' => $format,
                ]);
            } catch (\Throwable $e) {
                Plugin::warning('Failed to build DPR srcset candidate for asset ' . $image->id . '.');
                continue;
            }

            if ($url === null || $url === '') {
                continue;
            }

            $candidates[] = $url . ' ' . $ratioLabel . 'x';
        }

        if (empty($candidates)) {
            return $baseUrl;
        }

        return implode(', ', $candidates);
    }

    private function normalizeSecondaryFormat(string $format): string
    {
        $normalized = strtolower(trim($format));
        if ($normalized === '' || $normalized === 'none') {
            return 'none';
        }

        return $this->normalizeTargetFormat($normalized, 'none');
    }

    private function buildSourceDataAttributes(
        int $breakpoint,
        bool $enabled,
        ?array $transformEntry,
        ?array $fallbackTransform,
        ?string $autoDimension,
        string $className
    ): array {
        $explicitWidth = isset($transformEntry['width']) && is_numeric($transformEntry['width'])
            ? (int)$transformEntry['width']
            : null;
        $explicitHeight = isset($transformEntry['height']) && is_numeric($transformEntry['height'])
            ? (int)$transformEntry['height']
            : null;

        $fallbackWidth = isset($fallbackTransform['width']) && is_numeric($fallbackTransform['width'])
            ? (int)$fallbackTransform['width']
            : null;
        $fallbackHeight = isset($fallbackTransform['height']) && is_numeric($fallbackTransform['height'])
            ? (int)$fallbackTransform['height']
            : null;

        $transformWidth = $explicitWidth ?? $fallbackWidth;
        $transformHeight = $explicitHeight ?? $fallbackHeight;

        if ($autoDimension === 'width') {
            $transformWidth = null;
        } elseif ($autoDimension === 'height') {
            $transformHeight = null;
        }

        $attributes = [
            'class' => $className,
            'data-bp-size' => $breakpoint,
            'data-bp-enabled' => $enabled ? 'true' : 'false',
            'data-transform-width' => $transformWidth,
            'data-transform-height' => $transformHeight,
        ];

        if ($autoDimension !== null) {
            $attributes['data-auto-dimension'] = $autoDimension;
        }

        return $attributes;
    }

    private function getNamedTransform(array $config): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        $transformName = (string)($config['transformName'] ?? 'default');

        return $this->_plugin->getTransforms()->getTransform($transformName);
    }

    private function getTransformEntryByIndex(?array $transform, int $index): ?array
    {
        if ($transform === null || !isset($transform['transforms']) || !is_array($transform['transforms'])) {
            return null;
        }

        if (!isset($transform['transforms'][$index]) || !is_array($transform['transforms'][$index])) {
            return null;
        }

        return $transform['transforms'][$index];
    }

    private function getNamedTransformConfig(?array $transform): array
    {
        if ($transform === null || !isset($transform['config']) || !is_array($transform['config'])) {
            return [];
        }

        return $transform['config'];
    }

    private function resolveEffectiveSecondaryFormat(array $config, array $mergedConfig): string
    {
        $namedTransform = $this->getNamedTransform($config);
        $namedConfig = $this->getNamedTransformConfig($namedTransform);

        return $this->normalizeSecondaryFormat(
            (string)($namedConfig['secondaryFormat'] ?? $mergedConfig['secondaryFormat'] ?? 'none')
        );
    }

    private function getBreakpointsForTransform(array $config, array $mergedConfig): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints($mergedConfig);
        $includeEscapeWidth = $this->shouldIncludeEscapeWidth($config);
        if (!$includeEscapeWidth) {
            unset($breakpoints['escape']);
        }

        return $breakpoints;
    }

    private function shouldIncludeEscapeWidth(array $config): bool
    {
        return array_key_exists('includeEscapeWidth', $config)
            && (bool)$config['includeEscapeWidth'] === true;
    }

    private function getTransformCacheKey(Asset $image, string $formatIndex, array $config): string
    {
        $configJson = json_encode($config);
        if ($configJson === false) {
            $configJson = serialize($config);
        }

        return implode(':', [
            (string)$image->id,
            $formatIndex,
            md5($configJson),
        ]);
    }

    private function getBreakpointCacheKey(int $loopIndex, int $breakpoint, array $config, Asset $image): string
    {
        $configJson = json_encode($config);
        if ($configJson === false) {
            $configJson = serialize($config);
        }

        return implode(':', [
            (string)$image->id,
            (string)$loopIndex,
            (string)$breakpoint,
            md5($configJson),
        ]);
    }

    private function getMimeTypeForFormat(string $format): string
    {
        return match (strtolower($format)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
