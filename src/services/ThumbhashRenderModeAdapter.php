<?php

namespace craftyhedge\craftbreakpoints\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
use yii\base\Component;

class ThumbhashRenderModeAdapter extends Component
{
    private const MODE_BG = 'bg';
    private const MODE_SRCSET = 'srcset';

    private bool $scriptRegistered = false;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function apply(array $context): array
    {
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        if (!$this->isEnabled($config) || !$this->isAvailable()) {
            return $context;
        }

        $this->registerScript();

        if ((App::parseBooleanEnv($config['nativeLazyLoadingEnabled'] ?? true) ?? true) === true) {
            return $context;
        }

        if ($this->isEagerRender($context)) {
            return $context;
        }

        $image = $context['image'] ?? null;
        if (!$image instanceof Asset || !$this->isSupportedAsset($image)) {
            return $context;
        }

        return $this->normalizeMode($config['thumbhashMode'] ?? self::MODE_BG) === self::MODE_SRCSET
            ? $this->applySrcsetMode($context, $image)
            : $this->applyBgMode($context, $image);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isEnabled(array $config): bool
    {
        if (array_key_exists('thumbhash', $config)) {
            return (App::parseBooleanEnv($config['thumbhash']) ?? false) === true;
        }

        return (App::parseBooleanEnv($config['thumbhashEnabled'] ?? false) ?? false) === true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isEagerRender(array $context): bool
    {
        $config = is_array($context['config'] ?? null) ? $context['config'] : [];
        $imgAttributes = is_array($context['imgAttributes'] ?? null) ? $context['imgAttributes'] : [];
        $loading = strtolower(trim((string)($config['loading'] ?? $imgAttributes['loading'] ?? '')));

        return $loading === 'eager';
    }

    protected function isAvailable(): bool
    {
        return $this->getThumbhashPlugin() !== null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function applyBgMode(array $context, Asset $fallbackAsset): array
    {
        $hash = $this->getThumbhash($fallbackAsset);
        if ($hash === null || $hash === '') {
            return $context;
        }

        $pictureAttributes = is_array($context['pictureAttributes'] ?? null) ? $context['pictureAttributes'] : [];
        $pictureAttributes['data-thumbhash'] = $hash;
        $pictureAttributes['data-thumbhash-render'] = self::MODE_BG;
        $context['pictureAttributes'] = $pictureAttributes;

        return $this->applyPlaceholderAttributes($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function applySrcsetMode(array $context, Asset $fallbackAsset): array
    {
        $pictureAttributes = is_array($context['pictureAttributes'] ?? null) ? $context['pictureAttributes'] : [];
        $pictureAttributes['data-thumbhash-render'] = self::MODE_SRCSET;
        $context['pictureAttributes'] = $pictureAttributes;

        $breakpointData = is_array($context['breakpointData'] ?? null) ? $context['breakpointData'] : [];
        foreach ($breakpointData as $slotKey => $data) {
            if (!is_array($data)) {
                continue;
            }

            if (($data['disabled'] ?? false) === true) {
                continue;
            }

            $asset = $data['asset'] ?? null;
            if (!$asset instanceof Asset || !$this->isSupportedAsset($asset)) {
                continue;
            }

            $hash = $this->getThumbhash($asset);
            if ($hash === null || $hash === '') {
                continue;
            }

            foreach (['primarySourceAttributes', 'secondarySourceAttributes'] as $attributeKey) {
                if (!is_array($data[$attributeKey] ?? null) || $data[$attributeKey] === []) {
                    continue;
                }

                $data[$attributeKey]['data-thumbhash'] = $hash;
            }

            $breakpointData[$slotKey] = $data;
        }
        $context['breakpointData'] = $breakpointData;

        $imgAttributes = is_array($context['imgAttributes'] ?? null) ? $context['imgAttributes'] : [];
        $fallbackHash = $this->getThumbhash($fallbackAsset);
        if ($fallbackHash !== null && $fallbackHash !== '') {
            $imgAttributes['data-thumbhash'] = $fallbackHash;
        }
        $context['imgAttributes'] = $imgAttributes;

        return $this->applyPlaceholderAttributes($context);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function applyPlaceholderAttributes(array $context): array
    {
        $imgAttributes = is_array($context['imgAttributes'] ?? null) ? $context['imgAttributes'] : [];
        if (!array_key_exists('data-src', $imgAttributes)) {
            return $context;
        }

        $placeholderWidth = $this->normalizePositiveInt($imgAttributes['width'] ?? null) ?? 4;
        $placeholderHeight = $this->normalizePositiveInt($imgAttributes['height'] ?? null) ?? 4;
        $imgAttributes['src'] = $this->transparentSvgDataUrl($placeholderWidth, $placeholderHeight);
        $context['imgAttributes'] = $imgAttributes;

        return $context;
    }

    protected function getThumbhash(Asset $asset): ?string
    {
        if (!$asset->id) {
            return null;
        }

        try {
            $plugin = $this->getThumbhashPlugin();
            if ($plugin === null) {
                return null;
            }

            if (!method_exists($plugin, 'get')) {
                return null;
            }

            $service = $plugin->get('thumbhash');
            if (!is_object($service) || !method_exists($service, 'getHash')) {
                return null;
            }

            $hash = $service->getHash((int)$asset->id);

            return is_string($hash) && trim($hash) !== '' ? $hash : null;
        } catch (\Throwable $e) {
            Craft::warning(sprintf(
                'Could not resolve ThumbHash for asset %s: %s',
                (string)$asset->id,
                $e->getMessage(),
            ), 'breakpoints');

            return null;
        }
    }

    protected function registerScript(): void
    {
        if ($this->scriptRegistered) {
            return;
        }

        $extension = $this->createThumbhashTwigExtension();
        if ($extension === null || !method_exists($extension, 'getThumbhashScript')) {
            return;
        }

        try {
            $extension->getThumbhashScript();
            $this->scriptRegistered = true;
        } catch (\Throwable $e) {
            Craft::warning('Could not register ThumbHash script: ' . $e->getMessage(), 'breakpoints');
        }
    }

    protected function transparentSvgDataUrl(int $width, int $height): string
    {
        $extension = $this->createThumbhashTwigExtension();
        if ($extension !== null && method_exists($extension, 'getTransparentSvgDataUrl')) {
            try {
                return $extension->getTransparentSvgDataUrl($width, $height);
            } catch (\Throwable) {
                // Fall through to the local equivalent.
            }
        }

        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode(
            sprintf(
                "<svg xmlns='http://www.w3.org/2000/svg' width='%d' height='%d' style='background:transparent'/>",
                max(1, $width),
                max(1, $height),
            ),
        );
    }

    private function isSupportedAsset(Asset $asset): bool
    {
        try {
            return strtolower((string)$asset->getExtension()) !== 'svg';
        } catch (\Throwable) {
            return true;
        }
    }

    private function normalizeMode(mixed $mode): string
    {
        $mode = strtolower(trim((string)$mode));

        return in_array($mode, [self::MODE_BG, self::MODE_SRCSET], true) ? $mode : self::MODE_BG;
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $normalized = (int)$value;

        return $normalized > 0 ? $normalized : null;
    }

    private function getThumbhashPlugin(): ?object
    {
        try {
            $plugin = Craft::$app->getPlugins()->getPlugin('thumbhash');
        } catch (\Throwable) {
            return null;
        }

        return is_object($plugin) ? $plugin : null;
    }

    private function createThumbhashTwigExtension(): ?object
    {
        $class = 'craftyhedge\\craftthumbhash\\twig\\Extension';
        if (!class_exists($class)) {
            return null;
        }

        try {
            $extension = new $class();
        } catch (\Throwable) {
            return null;
        }

        return is_object($extension) ? $extension : null;
    }
}
