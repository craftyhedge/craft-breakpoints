<?php

namespace craftyhedge\craftbreakpointimages\services;

use Craft;
use craftyhedge\craftbreakpointimages\Plugin;
use craftyhedge\craftbreakpointimages\models\Settings;
use yii\base\Component;

class ConfigService extends Component
{
    private const DEFAULT_TEMPLATE_PATH = 'craft-breakpoint-images/picture.twig';

    private ?Plugin $_plugin = null;
    private ?array $_mergedConfig = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function getConfig(array $overrides = []): array
    {
        if ($this->_mergedConfig === null) {
            $this->_mergedConfig = $this->buildMergedConfig();
        }

        if (empty($overrides)) {
            return $this->_mergedConfig;
        }

        return array_merge($this->_mergedConfig, $overrides);
    }

    public function get(string $key, mixed $default = null, array $overrides = []): mixed
    {
        $config = $this->getConfig($overrides);

        return $config[$key] ?? $default;
    }

    public function getBreakpoints(array $config = []): array
    {
        $merged = empty($config) ? $this->getConfig() : $this->getConfig($config);
        $breakpoints = $this->normalizeBreakpoints($merged['breakpoints'] ?? []);

        if (!is_array($breakpoints)) {
            return [];
        }

        $escapeWidth = (int)($merged['escapeWidth'] ?? 1920);
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

    public function getPictureTemplatePath(array $overrides = []): string
    {
        $path = trim((string)$this->get('pictureTemplatePath', self::DEFAULT_TEMPLATE_PATH, $overrides));

        return $path !== '' ? $path : self::DEFAULT_TEMPLATE_PATH;
    }

    private function buildMergedConfig(): array
    {
        return array_merge(
            $this->getDefaultConfig(),
            $this->getPluginSettingsArray(),
            $this->getUserConfig(),
        );
    }

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
        return is_array($config) ? $config : [];
    }

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
            'nativeLazyLoadingEnabled',
            'dpr',
        ];

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

    private function getUserConfig(): array
    {
        try {
            $config = Craft::$app->getConfig()->getConfigFromFile('craft-breakpoint-images');
            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            Plugin::warning('Could not load project config for craft-breakpoint-images.');
            return [];
        }
    }

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
            'nativeLazyLoadingEnabled' => (bool)$value,
            'pictureTemplatePath' => trim((string)$value) !== ''
                ? trim((string)$value)
                : self::DEFAULT_TEMPLATE_PATH,
            'dpr' => $this->normalizeDpr($value),
            'mode',
            'position',
            'format',
            'secondaryFormat',
            'interlace' => trim((string)$value),
            default => $value,
        };
    }

    private function normalizeDpr(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        $normalized = [];
        foreach ($values as $ratio) {
            if (!is_numeric($ratio)) {
                continue;
            }

            $parsed = (float)$ratio;
            if ($parsed <= 0) {
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
            return json_encode($left) === json_encode($right);
        }

        return $left === $right;
    }
}
