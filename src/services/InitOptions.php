<?php

namespace craftyhedge\craftbreakpoints\services;

use craftyhedge\craftbreakpoints\Plugin;

readonly class InitOptions
{
    public function __construct(
        public ?int $width,
        public ?int $height,
        public ?float $ratio,
        public bool $widthAuto,
        public bool $heightAuto,
    ) {
    }

    public static function fromConfig(array $config, bool $hasSavedSet): self
    {
        if ($hasSavedSet) {
            return new self(null, null, null, false, false);
        }

        $width = self::positiveInt($config['initWidth'] ?? null);
        $height = self::positiveInt($config['initHeight'] ?? null);
        $ratio = self::parseRatio($config['initRatio'] ?? null);

        if ($width !== null && $height !== null) {
            $ratio = null;
        }

        $widthAuto = self::toBool($config['initWidthAuto'] ?? false);
        $heightAuto = self::toBool($config['initHeightAuto'] ?? false) && !$widthAuto;

        return new self($width, $height, $ratio, $widthAuto, $heightAuto);
    }

    public function toSeedArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'ratio' => $this->ratio !== null ? round($this->ratio, 8) : null,
            'widthAuto' => $this->widthAuto,
            'heightAuto' => $this->heightAuto,
        ];
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $parsed = (int)$value;

        return $parsed > 0 ? $parsed : null;
    }

    private static function parseRatio(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            if (strpos($trimmed, ':') !== false) {
                $parts = explode(':', $trimmed, 2);
                if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                    Plugin::debug('Ignored invalid initRatio value. Expected "x:y" with positive numbers or a positive numeric ratio.');
                    return null;
                }

                $left = (float)$parts[0];
                $right = (float)$parts[1];
                if (!is_finite($left) || !is_finite($right) || $left <= 0 || $right <= 0) {
                    Plugin::debug('Ignored invalid initRatio value. Ratio parts must both be positive numbers.');
                    return null;
                }

                return $left / $right;
            }

            if (!is_numeric($trimmed)) {
                Plugin::debug('Ignored invalid initRatio value. Expected "x:y" with positive numbers or a positive numeric ratio.');
                return null;
            }

            $parsed = (float)$trimmed;
            if (!is_finite($parsed) || $parsed <= 0) {
                Plugin::debug('Ignored invalid initRatio value. Numeric ratio must be greater than zero.');
                return null;
            }

            return $parsed;
        }

        if (is_numeric($value)) {
            $parsed = (float)$value;
            if (!is_finite($parsed) || $parsed <= 0) {
                Plugin::debug('Ignored invalid initRatio value. Numeric ratio must be greater than zero.');
                return null;
            }

            return $parsed;
        }

        Plugin::debug('Ignored invalid initRatio value. Expected "x:y" with positive numbers or a positive numeric ratio.');

        return null;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value === 1;
        }

        $normalized = strtolower(trim((string)$value));

        return $normalized === 'true'
            || $normalized === '1'
            || $normalized === 'yes'
            || $normalized === 'on';
    }
}
