<?php

namespace craftyhedge\craftbreakpoints\services;

use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\transformeditor\Support;

readonly class InitOptions
{
    public function __construct(
        public ?int $width,
        public ?int $height,
        public ?float $ratio,
        public bool $widthAuto,
        public bool $heightAuto,
        public ?string $ratioRaw = null,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(array $config, bool $hasSavedSet): self
    {
        if ($hasSavedSet) {
            return new self(null, null, null, false, false, null);
        }

        $width = self::positiveInt($config['initWidth'] ?? null);
        $height = self::positiveInt($config['initHeight'] ?? null);
        $rawRatio = $config['initRatio'] ?? null;
        $ratio = self::parseRatio($rawRatio);
        $ratioRaw = self::preserveRawRatio($rawRatio, $ratio);

        if ($width !== null && $height !== null) {
            $ratio = null;
            $ratioRaw = null;
        }

        $widthAuto = self::toBool($config['initWidthAuto'] ?? false);
        $heightAuto = self::toBool($config['initHeightAuto'] ?? false) && !$widthAuto;

        return new self($width, $height, $ratio, $widthAuto, $heightAuto, $ratioRaw);
    }

    /**
     * @return array{width: ?int, height: ?int, ratio: ?float, widthAuto: bool, heightAuto: bool}
     */
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
        return Support::parseNullablePositiveInt($value);
    }

    /**
     * Preserve the user-supplied ratio in its original form when it could parse,
     * so consumers can show "1920:1080" instead of a reduced "16:9".
     */
    private static function preserveRawRatio(mixed $value, ?float $parsed): ?string
    {
        if ($parsed === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_numeric($value)) {
            return rtrim(rtrim(number_format((float)$value, 8, '.', ''), '0'), '.');
        }

        return null;
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
        return Support::parseNullableBool($value) === true;
    }
}
