<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

/**
 * Pure static helpers extracted from TransformEditor.
 *
 * No service dependencies. No state. Anything here must be trivially
 * callable without constructing an instance.
 */
final class Support
{
    public static function normalizeNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $parsed = (int)$value;

        return $parsed > 0 ? $parsed : null;
    }

    public static function normalizeAutoDimension(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $candidate = strtolower(trim((string)$value));
        if ($candidate === 'width' || $candidate === 'height') {
            return $candidate;
        }

        return null;
    }

    public static function normalizeRatioSourceDimension(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $candidate = strtolower(trim($value));
        if ($candidate === 'width' || $candidate === 'height') {
            return $candidate;
        }

        return null;
    }

    public static function normalizeTransformEntry(mixed $entry): array
    {
        $entry = is_array($entry) ? $entry : [];

        $normalizedEntry = [
            'width' => self::normalizeNullablePositiveInt($entry['width'] ?? null),
            'height' => self::normalizeNullablePositiveInt($entry['height'] ?? null),
            'enabled' => ($entry['enabled'] ?? true) !== false,
            'autoDimension' => self::normalizeAutoDimension($entry['autoDimension'] ?? null),
            'ratioWidth' => self::normalizeNullablePositiveInt($entry['ratioWidth'] ?? null),
            'ratioHeight' => self::normalizeNullablePositiveInt($entry['ratioHeight'] ?? null),
            'ratioSourceDimension' => self::normalizeRatioSourceDimension($entry['ratioSourceDimension'] ?? null) ?? 'width',
            'ratioLocked' => ($entry['ratioLocked'] ?? false) === true,
        ];

        if ($normalizedEntry['autoDimension'] === 'width') {
            $normalizedEntry['width'] = null;
            $normalizedEntry['ratioLocked'] = false;
        }

        if ($normalizedEntry['autoDimension'] === 'height') {
            $normalizedEntry['height'] = null;
            $normalizedEntry['ratioLocked'] = false;
        }

        if ($normalizedEntry['ratioWidth'] === null || $normalizedEntry['ratioHeight'] === null) {
            $normalizedEntry['ratioLocked'] = false;
        }

        return $normalizedEntry;
    }

    public static function normalizeTransformEntriesForBreakpoints(array $breakpoints, array $rawEntries): array
    {
        $entries = [];

        foreach ($breakpoints as $index => $_breakpoint) {
            $entry = isset($rawEntries[$index]) && is_array($rawEntries[$index])
                ? $rawEntries[$index]
                : [];

            $entries[$index] = self::normalizeTransformEntry($entry);
        }

        return $entries;
    }

    public static function buildDefaultTransformEntry(): array
    {
        return [
            'width' => null,
            'height' => null,
            'enabled' => true,
            'autoDimension' => null,
            'ratioWidth' => null,
            'ratioHeight' => null,
            'ratioSourceDimension' => 'width',
            'ratioLocked' => false,
        ];
    }

    public static function toNonNegativeInt(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        $parsed = (int)$value;
        return $parsed >= 0 ? $parsed : 0;
    }

    public static function slugifyTransformName(string $transformName): string
    {
        $slug = strtolower($transformName);
        $slug = (string)preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'transform';
    }

    public static function formatRatioFloatInput(?int $ratioWidth, ?int $ratioHeight): string
    {
        if ($ratioWidth === null || $ratioHeight === null || $ratioWidth <= 0 || $ratioHeight <= 0) {
            return '';
        }

        $value = $ratioWidth / $ratioHeight;
        $formatted = number_format($value, 4, '.', '');
        $trimmed = rtrim(rtrim($formatted, '0'), '.');

        return $trimmed !== '' ? $trimmed : '0';
    }

    public static function truncateUrl(string $url, int $maxLength): string
    {
        $display = preg_replace('#^https?://#', '', $url) ?? $url;
        if (mb_strlen($display) <= $maxLength) {
            return $display;
        }

        return mb_substr($display, 0, $maxLength - 1) . '…';
    }

    public static function addGlobalError(array &$validation, string $message): void
    {
        $validation['hasErrors'] = true;
        $validation['global'][] = $message;
    }

    public static function addFieldError(array &$validation, string $fieldPath, string $message): void
    {
        $validation['hasErrors'] = true;

        if (!isset($validation['fields'][$fieldPath]) || !is_array($validation['fields'][$fieldPath])) {
            $validation['fields'][$fieldPath] = [];
        }

        $validation['fields'][$fieldPath][] = $message;
    }

    public static function defaultValidation(): array
    {
        return [
            'hasErrors' => false,
            'global' => [],
            'fields' => [],
        ];
    }

}
