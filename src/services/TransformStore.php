<?php

namespace craftyhedge\craftbreakpoints\services;

use Craft;
use InvalidArgumentException;
use RuntimeException;
use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

class TransformStore extends Component
{
    private const CONFIG_FOLDER_PERMISSIONS = 0755;
    private const SETS_CONFIG_PATH = '/craft-breakpoints/transform-sets.json';

    private ?Plugin $_plugin = null;
    private ?array $_sets = null;
    private string $_version = '';

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function initialize(): void
    {
        $this->ensureSetsConfigFileExists();
        $this->reload();
    }

    public function reload(): array
    {
        $this->_sets = $this->loadSetsConfiguration();
        $this->resetImageTransformCaches();

        return $this->_sets;
    }

    public function getSets(): array
    {
        if ($this->_sets === null) {
            $this->_sets = $this->loadSetsConfiguration();
        }

        return $this->_sets;
    }

    public function getCurrentVersion(): string
    {
        if ($this->_sets === null) {
            $this->_sets = $this->loadSetsConfiguration();
        }

        return $this->_version;
    }

    public function getTransforms(): array
    {
        return $this->convertSetsToLegacyTransforms($this->getSets());
    }

    public function replaceSetsForRuntime(array $sets): void
    {
        $this->_sets = $this->validateSets($sets);
        $this->resetImageTransformCaches();
    }

    public function replaceTransformsForRuntime(array $transforms): void
    {
        $this->replaceSetsForRuntime($this->convertLegacyTransformsToSets($transforms));
    }

    public function setSets(array $sets): void
    {
        $this->replaceSetsForRuntime($sets);
    }

    public function setTransforms(array $transforms): void
    {
        $this->replaceTransformsForRuntime($transforms);
    }

    public function persistSets(array $sets, string $expectedVersion): array
    {
        $currentVersion = $this->getCurrentVersion();
        $existingSets = $this->getSets();

        if ($expectedVersion !== $currentVersion) {
            return [
                'persisted' => false,
                'conflict' => true,
                'currentVersion' => $currentVersion,
                'sets' => $existingSets,
            ];
        }

        $normalized = $this->validateSets($sets);
        $normalized = $this->stampProcessedTimestamps($normalized, $existingSets);
        $nextVersion = $this->nextRevisionToken($currentVersion);
        $payload = [
            'version' => $nextVersion,
            'sets' => $normalized,
        ];
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('Failed to encode transform sets configuration.');
        }

        $this->ensureSetsConfigFileExists();
        $bytesWritten = file_put_contents($this->getSetsConfigPath(), $encoded . PHP_EOL, LOCK_EX);
        if ($bytesWritten === false) {
            throw new RuntimeException('Failed to persist transform sets configuration.');
        }

        $this->_sets = $normalized;
        $this->_version = $nextVersion;
        $this->resetImageTransformCaches();

        return [
            'persisted' => true,
            'conflict' => false,
            'currentVersion' => $this->_version,
            'sets' => $normalized,
        ];
    }

    public function persistTransforms(array $transforms, string $expectedVersion): array
    {
        $persistResult = $this->persistSets(
            $this->convertLegacyTransformsToSets($transforms),
            $expectedVersion,
        );

        $persistedSets = is_array($persistResult['sets'] ?? null) ? $persistResult['sets'] : [];

        return [
            'persisted' => ($persistResult['persisted'] ?? false) === true,
            'conflict' => ($persistResult['conflict'] ?? false) === true,
            'currentVersion' => (string)($persistResult['currentVersion'] ?? $this->getCurrentVersion()),
            'transforms' => $this->convertSetsToLegacyTransforms($persistedSets),
        ];
    }

    public function getSet(string $setName): ?array
    {
        $sets = $this->getSets();

        if (!isset($sets[$setName]) || !is_array($sets[$setName])) {
            return null;
        }

        return $sets[$setName];
    }

    public function getTransform(string $transformName): ?array
    {
        $transforms = $this->getTransforms();

        if (!isset($transforms[$transformName]) || !is_array($transforms[$transformName])) {
            return null;
        }

        return $transforms[$transformName];
    }

    private function ensureSetsConfigFileExists(): void
    {
        if ($this->_plugin === null) {
            return;
        }

        $folderPath = dirname($this->getSetsConfigPath());
        if (!is_dir($folderPath)) {
            $created = mkdir($folderPath, self::CONFIG_FOLDER_PERMISSIONS, true);
            if ($created === false && !is_dir($folderPath)) {
                Plugin::error('Failed to create transform sets config directory.');
                return;
            }
        }

        $filePath = $this->getSetsConfigPath();
        if (is_file($filePath)) {
            return;
        }

        $defaultSets = json_encode($this->buildDefaultSets(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($defaultSets === false) {
            Plugin::error('Failed to encode default transform sets configuration.');
            return;
        }

        $result = file_put_contents($filePath, $defaultSets . PHP_EOL);

        if ($result === false) {
            Plugin::error('Failed to create transform-sets.json config file.');
        }
    }

    private function loadSetsConfiguration(): array
    {
        $filePath = $this->getSetsConfigPath();

        if (!is_file($filePath)) {
            $this->_version = $this->buildRevisionToken();
            return [];
        }

        $json = file_get_contents($filePath);
        if ($json === false) {
            Plugin::warning('Could not read transform-sets.json config file.');
            $this->_version = $this->buildRevisionToken();
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            Plugin::warning('Invalid transform-sets.json content. Expected valid JSON object.');
            $this->_version = $this->buildRevisionToken();
            return [];
        }

        $version = $decoded['version'] ?? null;
        $rawSets = $decoded['sets'] ?? null;
        if (!is_string($version) || trim($version) === '' || !is_array($rawSets)) {
            Plugin::warning('Invalid transform-sets.json content. Expected top-level version timestamp and sets object.');
            $this->_version = $this->buildRevisionToken();
            return [];
        }

        try {
            $this->_version = trim($version);
            return $this->validateSets($rawSets);
        } catch (InvalidArgumentException $e) {
            Plugin::warning('Invalid transform-sets.json content structure.');
            $this->_version = $this->buildRevisionToken();
            return [];
        }
    }

    private function validateSets(array $sets): array
    {
        $normalized = [];
        foreach ($sets as $setName => $setDefinition) {
            if (!is_string($setName) || trim($setName) === '') {
                throw new InvalidArgumentException('Set name must be a non-empty string.');
            }

            if (!is_array($setDefinition)) {
                throw new InvalidArgumentException('Set definition must be an array.');
            }

            $variants = $setDefinition['variants'] ?? null;
            if (!is_array($variants)) {
                throw new InvalidArgumentException('Set variants must be an array.');
            }

            $normalizedVariants = [];
            foreach ($variants as $breakpointName => $variant) {
                if (!is_string($breakpointName) || trim($breakpointName) === '') {
                    throw new InvalidArgumentException('Variant breakpoint name must be a non-empty string.');
                }

                if (!is_array($variant)) {
                    throw new InvalidArgumentException('Each variant must be an array.');
                }

                $normalizedVariants[$breakpointName] = $variant;
            }

            $config = $setDefinition['config'] ?? [];
            if (!is_array($config)) {
                throw new InvalidArgumentException('Set config must be an array when provided.');
            }

            $lastUpdatedAt = $this->normalizeIsoDateTime($setDefinition['lastUpdatedAt'] ?? null);

            $normalized[$setName] = array_merge($setDefinition, [
                'name' => (string)($setDefinition['name'] ?? $setName),
                'variants' => $normalizedVariants,
                'includeEscapeWidth' => ($setDefinition['includeEscapeWidth'] ?? false) === true,
                'config' => $config,
                'lastUpdatedAt' => $lastUpdatedAt,
            ]);
        }

        return $normalized;
    }

    private function stampProcessedTimestamps(array $sets, array $existingSets): array
    {
        $now = gmdate('c');

        foreach ($sets as $setName => $setDefinition) {
            $existingDefinition = isset($existingSets[$setName]) && is_array($existingSets[$setName])
                ? $existingSets[$setName]
                : null;

            if ($existingDefinition === null) {
                $sets[$setName]['lastUpdatedAt'] = $now;
                continue;
            }

            $existingComparable = $existingDefinition;
            $currentComparable = $setDefinition;
            unset($existingComparable['lastUpdatedAt'], $currentComparable['lastUpdatedAt']);

            if ($existingComparable === $currentComparable) {
                $sets[$setName]['lastUpdatedAt'] = $this->normalizeIsoDateTime($existingDefinition['lastUpdatedAt'] ?? null);
                continue;
            }

            $sets[$setName]['lastUpdatedAt'] = $now;
        }

        return $sets;
    }

    private function normalizeIsoDateTime(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resetImageTransformCaches(): void
    {
        if ($this->_plugin === null) {
            return;
        }

        $this->_plugin->getImageTransforms()->resetCaches();
    }

    private function getSetsConfigPath(): string
    {
        return Craft::$app->getPath()->getConfigPath() . self::SETS_CONFIG_PATH;
    }

    private function buildDefaultSets(): array
    {
        return [
            'version' => $this->buildRevisionToken(),
            'sets' => [],
        ];
    }

    private function buildRevisionToken(): string
    {
        $microtime = microtime(true);
        $seconds = (int)floor($microtime);
        $microseconds = (int)(($microtime - $seconds) * 1000000);

        return gmdate('Y-m-d\\TH:i:s', $seconds) . sprintf('.%06dZ', $microseconds);
    }

    private function nextRevisionToken(string $currentVersion): string
    {
        $candidate = $this->buildRevisionToken();
        if ($candidate === $currentVersion) {
            return $candidate . '.1';
        }

        return $candidate;
    }

    private function convertSetsToLegacyTransforms(array $sets): array
    {
        $legacy = [];

        foreach ($sets as $setName => $setDefinition) {
            if (!is_string($setName) || $setName === '' || !is_array($setDefinition)) {
                continue;
            }

            $breakpointNames = $this->getBreakpointNamesForSetDefinition($setDefinition);
            $variants = isset($setDefinition['variants']) && is_array($setDefinition['variants'])
                ? $setDefinition['variants']
                : [];

            $entries = [];
            foreach ($breakpointNames as $breakpointName) {
                $entry = $variants[$breakpointName] ?? [];
                $entries[] = is_array($entry) ? $entry : [];
            }

            $legacy[$setName] = [
                'name' => (string)($setDefinition['name'] ?? $setName),
                'includeEscapeWidth' => ($setDefinition['includeEscapeWidth'] ?? false) === true,
                'transforms' => $entries,
                'config' => isset($setDefinition['config']) && is_array($setDefinition['config'])
                    ? $setDefinition['config']
                    : [],
            ];
        }

        return $legacy;
    }

    private function convertLegacyTransformsToSets(array $transforms): array
    {
        $sets = [];

        foreach ($transforms as $setName => $legacyDefinition) {
            if (!is_string($setName) || trim($setName) === '' || !is_array($legacyDefinition)) {
                continue;
            }

            $includeEscapeWidth = ($legacyDefinition['includeEscapeWidth'] ?? false) === true;
            $entries = isset($legacyDefinition['transforms']) && is_array($legacyDefinition['transforms'])
                ? array_values($legacyDefinition['transforms'])
                : [];

            $breakpointNames = $this->getBreakpointNamesForIncludeEscapeWidth($includeEscapeWidth);
            $variants = [];
            foreach ($breakpointNames as $index => $breakpointName) {
                $entry = $entries[$index] ?? [];
                $variants[$breakpointName] = is_array($entry) ? $entry : [];
            }

            $sets[$setName] = [
                'name' => (string)($legacyDefinition['name'] ?? $setName),
                'includeEscapeWidth' => $includeEscapeWidth,
                'variants' => $variants,
                'config' => isset($legacyDefinition['config']) && is_array($legacyDefinition['config'])
                    ? $legacyDefinition['config']
                    : [],
            ];
        }

        return $sets;
    }

    private function getBreakpointNamesForSetDefinition(array $setDefinition): array
    {
        $includeEscapeWidth = ($setDefinition['includeEscapeWidth'] ?? false) === true;

        return $this->getBreakpointNamesForIncludeEscapeWidth($includeEscapeWidth);
    }

    private function getBreakpointNamesForIncludeEscapeWidth(bool $includeEscapeWidth): array
    {
        if ($this->_plugin === null) {
            return [];
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints();
        if (!$includeEscapeWidth) {
            unset($breakpoints['escape']);
        }

        return array_values(array_map(static fn(mixed $value): string => (string)$value, array_keys($breakpoints)));
    }
}