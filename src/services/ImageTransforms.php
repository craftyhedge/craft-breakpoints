<?php

namespace craftyhedge\craftbreakpoints\services;

use craft\elements\Asset;
use craftyhedge\craftbreakpoints\helpers\ProcessingRequest;
use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

class ImageTransforms extends Component
{
    private const TRANSPARENT_PIXEL_DATA_URI = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
    private const PROCESSING_MEDIA_OVERSIZE_PX = 20;

    private ?Plugin $_plugin = null;
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $_transformedImagesCache = [];
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $_breakpointDataCache = [];

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function resetCaches(): void
    {
        $this->_transformedImagesCache = [];
        $this->_breakpointDataCache = [];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
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
        $allBreakpoints = $this->_plugin->getBreakpointPolicy()->getBreakpointsForSet($config, $mergedConfig);
        $breakpointNames = array_map('strval', array_keys($allBreakpoints));
        $breakpointName = $breakpointNames[$loopIndex] ?? null;
        $namedSet = $this->getNamedSet($config);
        $initOptions = InitOptions::fromConfig($config, $namedSet !== null);
        $variant = $this->getVariantByIndex($namedSet, $loopIndex);
        $autoDimension = $this->resolveAutoDimension($variant, $initOptions);

        $slotDefs = $this->_plugin->getConfigService()->getBreakpointSlotDefinitions($this->isEscapeWidthIncluded($config, $namedSet));
        $isFinalSlot = !empty($slotDefs) && $loopIndex === count($slotDefs) - 1;

        $setName = $this->resolveSetName($config);

        $transformedPrimary = $this->getTransformedImages($image, $setName, 'primary', $config);
        $transformedSecondary = $this->getTransformedImages($image, $setName, 'secondary', $config);

        if ($this->_plugin->getBreakpointPolicy()->isBreakpointDisabled($breakpointName, $loopIndex, $config)) {
            $sourceMediaQuery = $this->getDisabledMediaQuery($breakpoint);
            $disabledPrimary = $transformedPrimary[$loopIndex] ?? null;
            $disabledWidth = is_array($disabledPrimary) && isset($disabledPrimary['width']) ? (int)$disabledPrimary['width'] : 1;
            $disabledHeight = is_array($disabledPrimary) && isset($disabledPrimary['height']) ? (int)$disabledPrimary['height'] : 1;
            $disabledUrl = is_array($disabledPrimary) && isset($disabledPrimary['url'])
                ? (string)$disabledPrimary['url']
                : self::TRANSPARENT_PIXEL_DATA_URI;

            $primarySourceAttributes = array_merge([
                'srcset' => $disabledUrl,
                'type' => 'image/svg+xml',
                'width' => $disabledWidth,
                'height' => $disabledHeight,
            ], $this->buildSourceDataAttributes(
                $breakpoint,
                false,
                $variant,
                null,
                $autoDimension,
                'primary',
                $breakpointName,
                $loopIndex,
            ));

            if ($sourceMediaQuery !== '') {
                $primarySourceAttributes['media'] = $sourceMediaQuery;
            }

            $secondarySourceAttributes = [];
            $secondaryFormat = null;
            if ($this->isSecondaryFormatEnabled(['secondaryFormat' => $effectiveSecondaryFormat])) {
                $disabledSecondary = $transformedSecondary[$loopIndex] ?? null;
                $disabledSecondaryWidth = is_array($disabledSecondary) && isset($disabledSecondary['width']) ? (int)$disabledSecondary['width'] : $disabledWidth;
                $disabledSecondaryHeight = is_array($disabledSecondary) && isset($disabledSecondary['height']) ? (int)$disabledSecondary['height'] : $disabledHeight;
                $disabledSecondaryUrl = is_array($disabledSecondary) && isset($disabledSecondary['url'])
                    ? (string)$disabledSecondary['url']
                    : $disabledUrl;

                $secondarySourceAttributes = array_merge([
                    'srcset' => $disabledSecondaryUrl,
                    'type' => 'image/svg+xml',
                    'width' => $disabledSecondaryWidth,
                    'height' => $disabledSecondaryHeight,
                ], $this->buildSourceDataAttributes(
                    $breakpoint,
                    false,
                    $variant,
                    null,
                    $autoDimension,
                    'secondary',
                    $breakpointName,
                    $loopIndex,
                ));

                if ($sourceMediaQuery !== '') {
                    $secondarySourceAttributes['media'] = $sourceMediaQuery;
                }

                $secondaryFormat = ['disabled' => true, 'format' => 'svg'];
            }

            $result = [
                'primarySourceAttributes' => $primarySourceAttributes,
                'secondarySourceAttributes' => $secondarySourceAttributes,
                'primaryFormat' => ['disabled' => true, 'format' => 'svg', 'url' => $disabledUrl],
                'secondaryFormat' => $secondaryFormat,
                'disabled' => true,
            ];

            $this->_breakpointDataCache[$cacheKey] = $result;

            return $result;
        }

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

        $enabledBreakpoints = $this->_plugin->getBreakpointPolicy()->getEnabledBreakpoints($allBreakpoints, $config);
        $enabledValues = array_values($enabledBreakpoints);
        $secondLastBreakpoint = count($enabledValues) >= 2
            ? (int)$enabledValues[count($enabledValues) - 2]
            : null;

        $lastEnabledBreakpointName = array_key_last($enabledBreakpoints);
        $isLastLoop = $isFinalSlot || ($breakpointName !== null && $breakpointName === $lastEnabledBreakpointName);
        $mediaQuery = $this->sourceMediaQuery($breakpoint, $secondLastBreakpoint, $isLastLoop);

        $primarySourceAttributes = array_merge([
            'srcset' => $this->generateDprSrcset($image, $primary, $mergedConfig),
            'type' => $this->getMimeTypeForFormat((string)($primary['format'] ?? 'jpg')),
            'width' => isset($primary['width']) && is_numeric($primary['width']) ? (int)$primary['width'] : null,
            'height' => isset($primary['height']) && is_numeric($primary['height']) ? (int)$primary['height'] : null,
        ], $this->buildSourceDataAttributes(
            $breakpoint,
            true,
            $variant,
            $primary,
            $autoDimension,
            'primary',
            $breakpointName,
            $loopIndex,
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
                $variant,
                $secondary,
                $autoDimension,
                'secondary',
                $breakpointName,
                $loopIndex,
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

    /**
     * @param array<string, mixed> $config
     */
    public function getFirstEnabledBreakpointIndex(array $config = []): ?int
    {
        if ($this->_plugin === null) {
            return null;
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);
        $breakpoints = $this->_plugin->getBreakpointPolicy()->getBreakpointsForSet($config, $mergedConfig);
        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointValue) {
            if (!$this->_plugin->getBreakpointPolicy()->isBreakpointDisabled((string)$breakpointName, $index, $config)) {
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

    /**
     * @param array<string, mixed> $config
     * @return array<string, int>
     */
    public function getBreakpoints(array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getConfigService()->getBreakpoints($config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, int>
     */
    public function getBreakpointsForTemplate(array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);

        return $this->_plugin->getBreakpointPolicy()->getBreakpointsForSet($config, $mergedConfig);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public function getBreakpointStates(array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        return $this->_plugin->getBreakpointPolicy()->getBreakpointStates($config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    public function getTransformedImages(Asset $image, string $setName, string $formatIndex = 'primary', array $config = []): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        if (trim($setName) === '') {
            throw new \InvalidArgumentException('A non-empty set name is required.');
        }

        $namedSet = $this->getNamedSet($config);
        $initOptions = InitOptions::fromConfig($config, $namedSet !== null);

        if (!$this->isSvgAsset($image)) {
            $this->_plugin->getTelemetry()->recordUsage(
                $setName,
                $initOptions,
                $this->isEscapeWidthIncluded($config, $namedSet),
            );
        }

        $config['setName'] = $setName;

        $cacheKey = $this->getTransformCacheKey($image, $formatIndex, $config);
        if (isset($this->_transformedImagesCache[$cacheKey])) {
            return $this->_transformedImagesCache[$cacheKey];
        }

        $mergedConfig = $this->_plugin->getConfigService()->getConfig($config);
        $breakpoints = $this->_plugin->getBreakpointPolicy()->getBreakpointsForSet($config, $mergedConfig);
        $slots = $this->_plugin->getBreakpointSlots()->getSlots($this->isEscapeWidthIncluded($config, $namedSet));
        $namedSetConfig = $this->getNamedSetConfig($namedSet);

        $primaryFormat = $this->normalizeTargetFormat(
            (string)($namedSetConfig['format'] ?? $mergedConfig['format'] ?? 'jpg'),
            'jpg'
        );
        $secondaryFormat = $this->normalizeSecondaryFormat(
            (string)($namedSetConfig['secondaryFormat'] ?? $mergedConfig['secondaryFormat'] ?? 'none')
        );

        if ($formatIndex === 'secondary' && $secondaryFormat === 'none') {
            return [];
        }

        $targetFormat = $this->normalizeTargetFormat(
            $formatIndex === 'secondary' ? $secondaryFormat : $primaryFormat,
            'jpg'
        );
        $mode = (string)($namedSetConfig['mode'] ?? $mergedConfig['mode'] ?? 'crop');
        $position = (string)($namedSetConfig['position'] ?? $mergedConfig['position'] ?? 'center-center');
        $quality = (int)($namedSetConfig['quality'] ?? $mergedConfig['quality'] ?? 80);

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
        $initWidth = $initOptions->width;
        $initHeight = $initOptions->height;

        $transformed = [];

        $index = 0;
        foreach ($breakpoints as $breakpointName => $breakpointWidth) {
            $isDisabled = $this->_plugin->getBreakpointPolicy()->isBreakpointDisabled((string)$breakpointName, $index, $config);
            $namedSetVariant = $this->getVariantByIndex($namedSet, $index);

            $slot = $slots[$index] ?? null;
            $targetWidth = is_array($slot) ? (int)$slot['measureWidth'] : (int)$breakpointWidth;
            if ($targetWidth <= 0) {
                $targetWidth = $sourceWidth;
            }

            if ($namedSetVariant !== null && isset($namedSetVariant['width']) && is_numeric($namedSetVariant['width'])) {
                $targetWidth = (int)$namedSetVariant['width'];
            }

            $targetHeight = (int)round($targetWidth / $aspectRatio);
            if ($targetHeight <= 0) {
                $targetHeight = $sourceHeight;
            }

            if ($namedSetVariant !== null && isset($namedSetVariant['height']) && is_numeric($namedSetVariant['height'])) {
                $targetHeight = (int)$namedSetVariant['height'];
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

            $autoDimension = $this->resolveAutoDimension($namedSetVariant, $initOptions);

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

            if ($isDisabled) {
                $transformed[$index] = [
                    'url' => $this->buildSizedPlaceholderUrl($computedWidth, $computedHeight),
                    'width' => $computedWidth,
                    'height' => $computedHeight,
                    'format' => 'svg',
                    'id' => $image->id,
                    'disabled' => true,
                ];
                $index++;
                continue;
            }

            $transformConfig = [
                'width' => $transformWidth,
                'height' => $transformHeight,
                'mode' => is_array($namedSetVariant) && isset($namedSetVariant['mode']) ? (string)$namedSetVariant['mode'] : $mode,
                'position' => is_array($namedSetVariant) && isset($namedSetVariant['position']) ? (string)$namedSetVariant['position'] : $position,
                'quality' => is_array($namedSetVariant) && isset($namedSetVariant['quality']) && is_numeric($namedSetVariant['quality'])
                    ? (int)$namedSetVariant['quality']
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

    /**
     * @param array<string, mixed>|null $namedSetVariant
     */
    private function resolveAutoDimension(?array $namedSetVariant, InitOptions $initOptions): ?string
    {
        if ($namedSetVariant !== null && isset($namedSetVariant['autoDimension'])) {
            $autoDimension = $namedSetVariant['autoDimension'];
            if ($autoDimension === 'width' || $autoDimension === 'height') {
                return $autoDimension;
            }

            return null;
        }

        if ($initOptions->widthAuto) {
            return 'width';
        }

        if ($initOptions->heightAuto) {
            return 'height';
        }

        return null;
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

    private function getDisabledMediaQuery(int $breakpoint): string
    {
        $processingOversizePx = $this->getProcessingMediaOversizePx();
        $pixelValue = max(($breakpoint - 1) + $processingOversizePx, 1);
        $remValue = $pixelValue / 16;

        return sprintf('(max-width: %srem)', $remValue);
    }

    private function getProcessingMediaOversizePx(): int
    {
        return ProcessingRequest::isActive() ? self::PROCESSING_MEDIA_OVERSIZE_PX : 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isSecondaryFormatEnabled(array $config): bool
    {
        $secondaryFormat = $this->normalizeSecondaryFormat((string)($config['secondaryFormat'] ?? 'none'));

        return $secondaryFormat !== 'none';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, float>
     */
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
            if (!is_finite($parsed) || $parsed <= 0) {
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

    /**
     * @param array<string, mixed> $baseTransform
     * @param array<string, mixed> $config
     */
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

    /**
     * @param array<string, mixed>|null $variant
     * @param array<string, mixed>|null $fallbackTransform
     * @return array<string, mixed>
     */
    private function buildSourceDataAttributes(
        int $breakpoint,
        bool $enabled,
        ?array $variant,
        ?array $fallbackTransform,
        ?string $autoDimension,
        string $sourceRole,
        ?string $slotKey = null,
        ?int $slotIndex = null,
    ): array {
        // These markers exist solely for the client-side processing pipeline,
        // which only ever runs inside the processing preview iframe. Keep them
        // out of normal front-end output so production HTML stays clean.
        if (!ProcessingRequest::isActive()) {
            return [];
        }

        return $this->composeSourceDataAttributes(
            $breakpoint,
            $enabled,
            $variant,
            $fallbackTransform,
            $autoDimension,
            $sourceRole,
            $slotKey,
            $slotIndex,
        );
    }

    /**
     * @param array<string, mixed>|null $variant
     * @param array<string, mixed>|null $fallbackTransform
     * @return array<string, mixed>
     */
    private function composeSourceDataAttributes(
        int $breakpoint,
        bool $enabled,
        ?array $variant,
        ?array $fallbackTransform,
        ?string $autoDimension,
        string $sourceRole,
        ?string $slotKey,
        ?int $slotIndex,
    ): array {
        $explicitWidth = isset($variant['width']) && is_numeric($variant['width'])
            ? (int)$variant['width']
            : null;
        $explicitHeight = isset($variant['height']) && is_numeric($variant['height'])
            ? (int)$variant['height']
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
            'data-bp-source' => $sourceRole,
            'data-bp-size' => $breakpoint,
            'data-bp-key' => $slotKey,
            'data-bp-index' => $slotIndex,
            'data-bp-enabled' => $enabled ? 'true' : 'false',
            'data-bp-measure-width' => $fallbackWidth,
            'data-set-width' => $transformWidth,
            'data-set-height' => $transformHeight,
        ];

        if ($autoDimension !== null) {
            $attributes['data-auto-dimension'] = $autoDimension;
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private function getNamedSet(array $config): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        return $this->_plugin->getTransformSets()->getSet($this->resolveSetName($config));
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
     * Resolve a variant by its slot position rather than its key.
     *
     * The render/transform pipeline iterates breakpoints positionally, so the
     * variant for a given source is the one at the same slot — independent of
     * what key it is stored under. This decouples the render path from any
     * assumption that variant keys match the configured breakpoint names.
     *
     * @param array<string, mixed>|null $set
     * @return array<string, mixed>|null
     */
    private function getVariantByIndex(?array $set, int $index): ?array
    {
        if ($this->_plugin === null) {
            return null;
        }

        return $this->_plugin->getBreakpointPolicy()->getVariantByIndex($set, $index);
    }

    /**
     * @param array<string, mixed>|null $set
     * @return array<string, mixed>
     */
    private function getNamedSetConfig(?array $set): array
    {
        if ($set === null || !isset($set['config']) || !is_array($set['config'])) {
            return [];
        }

        return $set['config'];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed>|null $set
     */
    private function isEscapeWidthIncluded(array $config, ?array $set): bool
    {
        return $this->_plugin?->getBreakpointPolicy()->resolveIncludeEscapeWidth($config, $set) ?? false;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $mergedConfig
     */
    private function resolveEffectiveSecondaryFormat(array $config, array $mergedConfig): string
    {
        $namedSet = $this->getNamedSet($config);
        $namedConfig = $this->getNamedSetConfig($namedSet);

        return $this->normalizeSecondaryFormat(
            (string)($namedConfig['secondaryFormat'] ?? $mergedConfig['secondaryFormat'] ?? 'none')
        );
    }

    /**
     * @param array<string, mixed> $config
     */
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

    /**
     * @param array<string, mixed> $config
     */
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
            'svg' => 'image/svg+xml',
            default => 'image/jpeg',
        };
    }

    private function isSvgAsset(Asset $image): bool
    {
        try {
            $extension = strtolower(trim($image->getExtension()));
            if ($extension === 'svg') {
                return true;
            }
        } catch (\Throwable) {
            // Fall back to MIME type for mocks or assets where extension lookup is unavailable.
        }

        try {
            $mimeType = strtolower(trim((string)$image->getMimeType()));

            return $mimeType === 'image/svg+xml';
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildSizedPlaceholderUrl(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return self::TRANSPARENT_PIXEL_DATA_URI;
        }

        $svg = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d"/>', $width, $height);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
