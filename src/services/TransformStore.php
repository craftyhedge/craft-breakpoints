<?php

namespace craftyhedge\craftbreakpointimages\services;

use Craft;
use InvalidArgumentException;
use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class TransformStore extends Component
{
    private const CONFIG_FOLDER_PERMISSIONS = 0755;
    private const TRANSFORMS_CONFIG_PATH = '/craft-breakpoint-images/transforms.json';

    private ?Plugin $_plugin = null;
    private ?array $_transforms = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function initialize(): void
    {
        $this->ensureTransformsConfigFileExists();
        $this->reload();
    }

    public function reload(): array
    {
        $this->_transforms = $this->loadTransformsConfiguration();
        $this->resetImageTransformCaches();

        return $this->_transforms;
    }

    public function getTransforms(): array
    {
        if ($this->_transforms === null) {
            $this->_transforms = $this->loadTransformsConfiguration();
        }

        return $this->_transforms;
    }

    public function replaceTransformsForRuntime(array $transforms): void
    {
        $this->_transforms = $this->validateTransforms($transforms);
        $this->resetImageTransformCaches();
    }

    public function setTransforms(array $transforms): void
    {
        $this->replaceTransformsForRuntime($transforms);
    }

    public function getTransform(string $transformName): ?array
    {
        $transforms = $this->getTransforms();

        if (!isset($transforms[$transformName]) || !is_array($transforms[$transformName])) {
            return null;
        }

        return $transforms[$transformName];
    }

    private function ensureTransformsConfigFileExists(): void
    {
        if ($this->_plugin === null) {
            return;
        }

        $folderPath = dirname($this->getTransformsConfigPath());
        if (!is_dir($folderPath)) {
            $created = mkdir($folderPath, self::CONFIG_FOLDER_PERMISSIONS, true);
            if ($created === false && !is_dir($folderPath)) {
                Plugin::error('Failed to create transforms config directory.');
                return;
            }
        }

        $filePath = $this->getTransformsConfigPath();
        if (is_file($filePath)) {
            return;
        }

        $defaultTransforms = json_encode($this->buildDefaultTransforms(), JSON_PRETTY_PRINT);
        if ($defaultTransforms === false) {
            Plugin::error('Failed to encode default transforms configuration.');
            return;
        }

        $result = file_put_contents($filePath, $defaultTransforms);

        if ($result === false) {
            Plugin::error('Failed to create transforms.json config file.');
        }
    }

    private function loadTransformsConfiguration(): array
    {
        $filePath = $this->getTransformsConfigPath();

        if (!is_file($filePath)) {
            return [];
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            Plugin::warning('Could not read transforms.json config file.');
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            Plugin::warning('Invalid transforms.json content. Expected valid JSON object.');
            return [];
        }

        try {
            return $this->validateTransforms($decoded);
        } catch (InvalidArgumentException $e) {
            Plugin::warning('Invalid transforms.json content structure.');
            return [];
        }
    }

    private function validateTransforms(array $transforms): array
    {
        $normalized = [];
        foreach ($transforms as $transformName => $transformDefinition) {
            if (!is_string($transformName) || trim($transformName) === '') {
                throw new InvalidArgumentException('Transform name must be a non-empty string.');
            }

            if (!is_array($transformDefinition)) {
                throw new InvalidArgumentException('Transform definition must be an array.');
            }

            $entries = $transformDefinition['transforms'] ?? null;
            if (!is_array($entries)) {
                throw new InvalidArgumentException('Transform entries must be an array.');
            }

            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    throw new InvalidArgumentException('Each transform entry must be an array.');
                }
            }

            $config = $transformDefinition['config'] ?? [];
            if (!is_array($config)) {
                throw new InvalidArgumentException('Transform config must be an array when provided.');
            }

            $normalized[$transformName] = array_merge($transformDefinition, [
                'name' => (string)($transformDefinition['name'] ?? $transformName),
                'transforms' => array_values($entries),
                'includeEscapeWidth' => ($transformDefinition['includeEscapeWidth'] ?? false) === true,
                'config' => $config,
            ]);
        }

        return $normalized;
    }

    private function resetImageTransformCaches(): void
    {
        if ($this->_plugin === null) {
            return;
        }

        $this->_plugin->getImageTransforms()->resetCaches();
    }

    private function getTransformsConfigPath(): string
    {
        return Craft::$app->getPath()->getConfigPath() . self::TRANSFORMS_CONFIG_PATH;
    }

    private function buildDefaultTransforms(): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints();
        unset($breakpoints['escape']);

        $transformEntries = [];
        foreach ($breakpoints as $breakpoint) {
            $transformEntries[] = [
                'width' => (int)$breakpoint,
                'height' => null,
                'enabled' => true,
                'autoDimension' => null,
            ];
        }

        return [
            'default' => [
                'name' => 'default',
                'transforms' => $transformEntries,
                'includeEscapeWidth' => false,
                'config' => [],
            ],
        ];
    }
}