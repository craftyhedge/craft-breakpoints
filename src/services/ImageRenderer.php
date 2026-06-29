<?php

namespace craftyhedge\craftbreakpoints\services;

use Craft;
use craft\elements\Asset;
use craft\helpers\App;
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
        $breakpointData = is_array($context['breakpointData'] ?? null) ? $context['breakpointData'] : [];
        $mergedConfig = is_array($context['config'] ?? null) ? $context['config'] : [];
        $sourceAssetsBySlot = is_array($context['sourceAssetsBySlot'] ?? null) ? $context['sourceAssetsBySlot'] : [];
        $sourceConfigsBySlot = is_array($context['sourceConfigsBySlot'] ?? null) ? $context['sourceConfigsBySlot'] : [];
        $isSvg = $this->isSvgAsset($image);
        $templatePath = $isSvg ? $svgTemplatePath : $pictureTemplatePath;

        $view = Craft::$app->getView();
        $this->registerPreloadLinks($view, $image, $mergedConfig, $breakpoints, $imgAttributes, $isSvg);
        $oldMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            $markup = $view->renderTemplate($templatePath, [
                'image' => $image,
                'config' => $mergedConfig,
                'pictureAttributes' => $pictureAttributes,
                'imgAttributes' => $imgAttributes,
                'breakpoints' => $breakpoints,
                'breakpointData' => $breakpointData,
                'sourceAssetsBySlot' => $sourceAssetsBySlot,
                'sourceConfigsBySlot' => $sourceConfigsBySlot,
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

    /**
     * @param array<string, mixed> $config
     * @param array<string, int> $breakpoints
     * @param array<string, mixed> $imgAttributes
     */
    private function registerPreloadLinks(View $view, Asset $image, array $config, array $breakpoints, array $imgAttributes, bool $isSvg): void
    {
        if ($this->_plugin === null || ProcessingRequest::isActive()) {
            return;
        }

        if ((App::parseBooleanEnv($config['preload'] ?? false) ?? false) !== true) {
            return;
        }

        if ($isSvg) {
            $href = trim((string)($imgAttributes['src'] ?? ''));
            if ($href === '' || str_starts_with($href, 'data:')) {
                return;
            }

            $view->registerLinkTag([
                'rel' => 'preload',
                'as' => 'image',
                'href' => $href,
                'type' => 'image/svg+xml',
                ...$this->preloadFetchPriorityAttributes($config),
            ], $this->buildPreloadKey($config, 'svg', $href));
            return;
        }

        $index = 0;
        foreach ($breakpoints as $slotKey => $breakpoint) {
            $breakpointData = $this->_plugin->getImages()->getBreakpointData($index, (int)$breakpoint, $config, $image);
            $sourceAttributes = is_array($breakpointData['primarySourceAttributes'] ?? null)
                ? $breakpointData['primarySourceAttributes']
                : [];

            if (($breakpointData['disabled'] ?? false) === true || $sourceAttributes === []) {
                $index++;
                continue;
            }

            $srcset = trim((string)($sourceAttributes['srcset'] ?? ''));
            if ($srcset === '' || str_starts_with($srcset, 'data:')) {
                $index++;
                continue;
            }

            $linkAttributes = [
                'rel' => 'preload',
                'as' => 'image',
                'href' => $this->firstSrcsetCandidate($srcset),
                'imagesrcset' => $srcset,
                'imagesizes' => trim((string)($config['preloadSizes'] ?? '100vw')),
                ...$this->preloadFetchPriorityAttributes($config),
            ];

            $type = trim((string)($sourceAttributes['type'] ?? ''));
            if ($type !== '') {
                $linkAttributes['type'] = $type;
            }

            $media = $this->preloadMediaQuery(array_values($breakpoints), $index);
            if ($media === '') {
                $media = trim((string)($sourceAttributes['media'] ?? ''));
            }
            if ($media !== '') {
                $linkAttributes['media'] = $media;
            }

            $view->registerLinkTag($linkAttributes, $this->buildPreloadKey(
                $config,
                (string)$slotKey,
                $srcset . '|' . $media . '|' . $type,
            ));
            $index++;
        }
    }

    private function firstSrcsetCandidate(string $srcset): string
    {
        $candidate = trim(explode(',', $srcset)[0] ?? '');
        if ($candidate === '') {
            return '';
        }

        return trim(explode(' ', $candidate)[0] ?? '');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private function preloadFetchPriorityAttributes(array $config): array
    {
        $fetchPriority = trim((string)($config['fetchpriority'] ?? $config['fetchPriority'] ?? ''));
        if ($fetchPriority === '') {
            return [];
        }

        return ['fetchpriority' => $fetchPriority];
    }

    /**
     * Link preload media queries need to be mutually exclusive. Unlike
     * `<picture>`, matching `<link>` tags are not resolved by source order.
     *
     * @param int[] $breakpointWidths
     */
    private function preloadMediaQuery(array $breakpointWidths, int $index): string
    {
        $count = count($breakpointWidths);
        if ($count <= 1 || !isset($breakpointWidths[$index])) {
            return '';
        }

        $currentWidth = (int)$breakpointWidths[$index];
        if ($currentWidth <= 0) {
            return '';
        }

        if ($index === 0) {
            return sprintf('(max-width: %srem)', max($currentWidth - 1, 1) / 16);
        }

        $previousWidth = (int)($breakpointWidths[$index - 1] ?? 0);
        if ($previousWidth <= 0) {
            return '';
        }

        $min = sprintf('(min-width: %srem)', $previousWidth / 16);
        if ($index === $count - 1) {
            return $min;
        }

        return sprintf('%s and (max-width: %srem)', $min, max($currentWidth - 1, 1) / 16);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildPreloadKey(array $config, string $slotKey, string $source): string
    {
        return 'craft-breakpoints-preload:' . sha1(implode('|', [
            (string)($config['setName'] ?? $config['transformName'] ?? ''),
            (string)($config['imageId'] ?? ''),
            $slotKey,
            $source,
        ]));
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
