<?php

namespace craftyhedge\craftbreakpoints\services;

use Craft;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\models\Settings;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;
use yii\base\Component;

class ConfigService extends Component
{
    private const PROCESSING_LAZY_LOADING_ADAPTERS = [
        'none',
        'attributes',
        'lazysizes',
        'vanilla-lazyload',
        'lozad',
        'custom',
    ];
    private const DEFAULT_TEMPLATE_PATH = 'breakpoints/picture.twig';
    private const DEFAULT_SVG_TEMPLATE_PATH = 'breakpoints/svg.twig';
    private const PROCESSING_DIAGNOSTICS_ENV = 'CRAFT_BREAKPOINTS_PROCESSING_DIAGNOSTICS';

    private ?Plugin $_plugin = null;
    /**
     * @var array<string, mixed>|null
     */
    private ?array $_mergedConfig = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public function getConfig(array $overrides = []): array
    {
        if ($this->_mergedConfig === null) {
            $this->_mergedConfig = $this->buildMergedConfig();
        }

        if (empty($overrides)) {
            return $this->applyPriorityDefaults($this->_mergedConfig);
        }

        return $this->applyPriorityDefaults(array_merge($this->_mergedConfig, $overrides), $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function get(string $key, mixed $default = null, array $overrides = []): mixed
    {
        $config = $this->getConfig($overrides);

        return $config[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, int>
     */
    public function getBreakpoints(array $config = []): array
    {
        $merged = empty($config) ? $this->getConfig() : $this->getConfig($config);
        $breakpoints = $this->normalizeBreakpoints($merged['breakpoints'] ?? []);

        if (!array_key_exists('escapeWidth', $merged)) {
            return $breakpoints;
        }

        $escapeWidth = (int)$merged['escapeWidth'];
        if ($escapeWidth <= 0) {
            return $breakpoints;
        }

        $maxConfiguredBreakpoint = !empty($breakpoints)
            ? max(array_values($breakpoints))
            : 0;

        if ($escapeWidth <= $maxConfiguredBreakpoint) {
            $escapeWidth = $maxConfiguredBreakpoint + 1;
        }

        if ($escapeWidth > 0) {
            $breakpoints['escape'] = $escapeWidth;
        }

        return $breakpoints;
    }

    /**
     * Configured breakpoint map (`name => media width`) with stable canonical
     * slot keys. The synthetic `escape` key is never returned.
     *
     * @return array<string, int>
     */
    public function getBreakpointMap(bool $includeEscapeWidth): array
    {
        $map = [];
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return $map;
        }

        foreach ($plugin->getBreakpointSlots()->getSlots($includeEscapeWidth) as $slot) {
            $map[$slot['key']] = (int)$slot['mediaWidth'];
        }

        return $map;
    }

    /**
     * Ordered canonical media widths. Stable count; includeEscapeWidth does not
     * change media boundaries.
     *
     * @return int[]
     */
    public function getBreakpointWidths(bool $includeEscapeWidth): array
    {
        return array_map(
            static fn(array $definition): int => (int)$definition['width'],
            $this->getBreakpointSlotDefinitions($includeEscapeWidth),
        );
    }

    /**
     * Canonical saved/editor slots: a synthetic `base` slot followed by every
     * configured breakpoint name. `includeEscapeWidth` only changes the final
     * slot's width; it never changes the slot count or keys.
     *
     * @return array<int, array{key: string, index: int, width: int, mediaWidth: int, measureWidth: int, isBase: bool, isFinal: bool}>
     */
    public function getBreakpointSlotDefinitions(bool $includeEscapeWidth): array
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return [];
        }

        $definitions = [];
        foreach ($plugin->getBreakpointSlots()->getSlots($includeEscapeWidth) as $slot) {
            $definitions[] = [
                'key' => $slot['key'],
                'index' => (int)$slot['index'],
                'width' => (int)$slot['mediaWidth'],
                'mediaWidth' => (int)$slot['mediaWidth'],
                'measureWidth' => (int)$slot['measureWidth'],
                'isBase' => (bool)$slot['isBase'],
                'isFinal' => (bool)$slot['isFinal'],
            ];
        }

        return $definitions;
    }

    /**
     * Canonical ordered list of variant keys for a set: `base` first, then the
     * configured breakpoint names. No `escape` — the escape-width slot (when
     * included) takes the last configured name. Paired to widths by position.
     *
     * Must stay identical to the labels BreakpointCatalog emits, so the
     * sets⇄transforms adapters re-key variants to the same names every other
     * path uses. Width-only and count-only consumers should use
     * getBreakpointWidths()/getBreakpoints().
     *
     * @return string[]
     */
    public function getBreakpointKeys(bool $includeEscapeWidth): array
    {
        return array_keys($this->getBreakpointMap(false));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function getPictureTemplatePath(array $overrides = []): string
    {
        return $this->normalizeTemplatePath(
            $this->get('pictureTemplatePath', self::DEFAULT_TEMPLATE_PATH, $overrides),
            self::DEFAULT_TEMPLATE_PATH
        );
    }

    /**
     * Whether the given resolved template path is one of the plugin's own
     * bundled defaults (rather than a developer-supplied custom template).
     */
    public function isDefaultTemplatePath(string $templatePath): bool
    {
        return $templatePath === self::DEFAULT_TEMPLATE_PATH
            || $templatePath === self::DEFAULT_SVG_TEMPLATE_PATH;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function getSvgTemplatePath(array $overrides = []): string
    {
        return $this->normalizeTemplatePath(
            $this->get('svgTemplatePath', self::DEFAULT_SVG_TEMPLATE_PATH, $overrides),
            self::DEFAULT_SVG_TEMPLATE_PATH
        );
    }

    /**
     * Resolve author diagnostics enablement from config, with env override precedence.
     *
     * @param array<string, mixed> $overrides
     */
    public function isProcessingDiagnosticsEnabled(array $overrides = []): bool
    {
        $configValue = App::parseBooleanEnv(
            $this->get('processingDiagnosticsEnabled', false, $overrides)
        );

        $envValue = App::parseBooleanEnv(App::env(self::PROCESSING_DIAGNOSTICS_ENV));
        if ($envValue !== null) {
            return $envValue;
        }

        return $configValue ?? false;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{adapter: string, attributes: array{src: string, srcset: string, sizes: string}, customHandler: string}
     */
    public function getProcessingLazyLoadingConfig(array $overrides = []): array
    {
        $adapter = trim((string)$this->get('processingLazyLoadingAdapter', 'attributes', $overrides));
        if (!in_array($adapter, self::PROCESSING_LAZY_LOADING_ADAPTERS, true)) {
            $adapter = 'attributes';
        }

        return [
            'adapter' => $adapter,
            'attributes' => [
                'src' => $this->normalizeLazyLoadingAttributeName(
                    $this->get('processingLazyLoadingSrcAttribute', 'data-src', $overrides),
                    'data-src',
                ),
                'srcset' => $this->normalizeLazyLoadingAttributeName(
                    $this->get('processingLazyLoadingSrcsetAttribute', 'data-srcset', $overrides),
                    'data-srcset',
                ),
                'sizes' => $this->normalizeLazyLoadingAttributeName(
                    $this->get('processingLazyLoadingSizesAttribute', 'data-sizes', $overrides),
                    'data-sizes',
                ),
            ],
            'customHandler' => trim((string)$this->get('processingLazyLoadingCustomHandler', '', $overrides)),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function areTransformsDeveloperActionsEnabled(array $overrides = []): bool
    {
        return App::parseBooleanEnv(
            $this->get('transformsDeveloperActionsEnabled', false, $overrides)
        ) ?? false;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function isTelemetryEnabled(array $overrides = []): bool
    {
        return App::parseBooleanEnv(
            $this->get('enableTelemetry', true, $overrides)
        ) ?? true;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function allowTransformEditing(array $overrides = []): bool
    {
        return App::parseBooleanEnv(
            $this->get('allowTransformEditing', false, $overrides)
        ) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMergedConfig(): array
    {
        return array_merge(
            $this->getDefaultConfig(),
            $this->getPluginSettingsArray(),
            $this->getUserConfig(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $path = $this->_plugin->getBasePath() . '/config.php';
        if (!file_exists($path)) {
            return [];
        }

        $config = require $path;
        if (!is_array($config)) {
            return [];
        }

        return $this->resolveEnvironmentConfig($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPluginSettingsArray(): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $settings = $this->_plugin->getSettings();
        if (!$settings instanceof Settings) {
            return [];
        }

        $defaultSettings = new Settings();
        $keys = [
            'breakpoints',
            'escapeWidth',
            'defaultWidth',
            'defaultHeight',
            'mode',
            'position',
            'quality',
            'format',
            'secondaryFormat',
            'interlace',
            'allowUpscale',
            'pictureTemplatePath',
            'svgTemplatePath',
            'nativeLazyLoadingEnabled',
            'priority',
            'preload',
            'processingLazyLoadingAdapter',
            'processingLazyLoadingSrcAttribute',
            'processingLazyLoadingSrcsetAttribute',
            'processingLazyLoadingSizesAttribute',
            'processingLazyLoadingCustomHandler',
            'previewCenter',
            'dpr',
        ];

        // Keep processingDiagnosticsEnabled config/env-only (not persisted in Settings model).

        $overrides = [];
        foreach ($keys as $key) {
            $value = $this->normalizeSettingValue($key, $settings->{$key} ?? null);
            $defaultValue = $this->normalizeSettingValue($key, $defaultSettings->{$key} ?? null);

            if ($this->settingValuesEqual($value, $defaultValue)) {
                continue;
            }

            $overrides[$key] = $value;
        }

        return $overrides;
    }

    /**
     * @return array<string, mixed>
     */
    private function getUserConfig(): array
    {
        try {
            $config = Craft::$app->getConfig()->getConfigFromFile('breakpoints');
            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            Plugin::warning('Could not load project config for breakpoints.');
            return [];
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveEnvironmentConfig(array $config): array
    {
        if (!array_key_exists('*', $config)) {
            return $config;
        }

        $environment = Craft::$app->getConfig()->env;
        if ($environment === null || $environment === '') {
            return is_array($config['*'] ?? null) ? $config['*'] : [];
        }

        $mergedConfig = [];
        foreach ($config as $env => $envConfig) {
            if (!is_array($envConfig)) {
                continue;
            }

            if ($env === '*' || StringHelper::contains($environment, (string)$env)) {
                $mergedConfig = ArrayHelper::merge($mergedConfig, $envConfig);
            }
        }

        return $mergedConfig;
    }

    /**
     * @return array<string, int>
     */
    private function normalizeBreakpoints(mixed $breakpoints): array
    {
        if (!is_array($breakpoints)) {
            return [];
        }

        $normalized = [];
        foreach ($breakpoints as $name => $value) {
            $width = (int)$value;
            if ($width <= 0) {
                continue;
            }

            $key = is_string($name) && trim($name) !== '' ? $name : (string)count($normalized);
            $normalized[$key] = $width;
        }

        asort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function normalizeSettingValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'breakpoints' => $this->normalizeBreakpoints($value),
            'escapeWidth',
            'defaultWidth',
            'defaultHeight',
            'quality',
            'allowUpscale' => (int)$value,
            'nativeLazyLoadingEnabled',
            'priority',
            'preload' => (bool)$value,
            'previewCenter' => (bool)$value,
            'processingDiagnosticsEnabled' => App::parseBooleanEnv($value) ?? false,
            'pictureTemplatePath' => $this->normalizeTemplatePath($value, self::DEFAULT_TEMPLATE_PATH),
            'svgTemplatePath' => $this->normalizeTemplatePath($value, self::DEFAULT_SVG_TEMPLATE_PATH),
            'dpr' => $this->normalizeDpr($value),
            'mode',
            'position',
            'format',
            'secondaryFormat',
            'interlace',
            'processingLazyLoadingAdapter',
            'processingLazyLoadingSrcAttribute',
            'processingLazyLoadingSrcsetAttribute',
            'processingLazyLoadingSizesAttribute',
            'processingLazyLoadingCustomHandler' => trim((string)$value),
            default => $value,
        };
    }

    private function normalizeLazyLoadingAttributeName(mixed $value, string $fallback): string
    {
        $attribute = strtolower(trim((string)$value));
        if ($attribute === '' || preg_match('/^[a-z_:][a-z0-9_.:-]*$/', $attribute) !== 1) {
            return $fallback;
        }

        return $attribute;
    }

    /**
     * `priority` is a convenience flag for above-the-fold images. Per-call
     * options can still override each individual output hint.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function applyPriorityDefaults(array $config, array $overrides = []): array
    {
        if ((App::parseBooleanEnv($config['priority'] ?? false) ?? false) !== true) {
            return $config;
        }

        if (!array_key_exists('preload', $overrides)) {
            $config['preload'] = true;
        }

        if (!array_key_exists('loading', $overrides)) {
            $config['loading'] = 'eager';
        }

        if (!array_key_exists('fetchpriority', $overrides) && !array_key_exists('fetchPriority', $overrides)) {
            $config['fetchpriority'] = 'high';
        }

        return $config;
    }

    private function normalizeTemplatePath(mixed $value, string $fallback): string
    {
        $path = trim((string)$value);
        if ($path === '') {
            return $fallback;
        }

        $normalizedPath = str_replace('\\', '/', $path);

        return ltrim($normalizedPath, '/');
    }

    /**
     * @return array<int, float>
     */
    private function normalizeDpr(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        $normalized = [];
        foreach ($values as $ratio) {
            if (!is_numeric($ratio)) {
                continue;
            }

            $parsed = (float)$ratio;
            if (!is_finite($parsed) || $parsed <= 0) {
                continue;
            }

            $normalized[] = $parsed;
        }

        if (empty($normalized)) {
            return [1.0];
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function settingValuesEqual(mixed $left, mixed $right): bool
    {
        if (is_array($left) && is_array($right)) {
            return $left === $right;
        }

        return $left === $right;
    }
}
