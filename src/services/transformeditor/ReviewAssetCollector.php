<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

/**
 * Groups review rows by asset within a single transform, producing stable
 * asset keys / row keys / labels and selecting the rows for a currently
 * selected asset across breakpoints.
 *
 * Pure data collection: all methods are static and operate on passed-in rows.
 */
final class ReviewAssetCollector
{
    public static function buildAssetKey(
        string $transformName,
        string $assetId,
        string $sourceUsed,
        string $src,
        string $title,
    ): string {
        $normalizedTransform = trim($transformName) !== '' ? trim($transformName) : 'unknown';
        $normalizedAssetId = trim($assetId);

        if ($normalizedAssetId !== '') {
            return 'asset:' . $normalizedTransform . ':' . $normalizedAssetId;
        }

        $sourceSignature = self::normalizeSourceSignature($sourceUsed, $src, $title);
        return 'asset:' . $normalizedTransform . ':sig-' . substr(sha1($sourceSignature), 0, 16);
    }

    public static function buildRowKey(
        int $breakpoint,
        string $transformName,
        string $assetId,
        string $sourceUsed,
        string $src,
        string $title,
    ): string {
        return self::buildAssetKey($transformName, $assetId, $sourceUsed, $src, $title)
            . ':bp-' . (string)$breakpoint;
    }

    public static function normalizeSourceSignature(string $sourceUsed, string $src, string $title): string
    {
        $candidates = [
            trim($sourceUsed),
            trim($src),
            trim($title),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $querySplit = explode('?', $candidate, 2);
            $base = $querySplit[0] ?? $candidate;
            $hashSplit = explode('#', $base, 2);
            $normalized = trim((string)($hashSplit[0] ?? $base));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return 'missing-source';
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array{assetKeys: array<int, string>, rowsByAssetByBreakpoint: array<string, array<int, array<int, array<string, mixed>>>>, assetLabelsByKey: array<string, string>}
     */
    public static function buildAssetCollectionForTransform(
        array $rowsByBreakpoint,
        string $transformName,
        array $transformBreakpoints,
    ): array {
        $assetKeys = [];
        $assetSeen = [];
        $rowsByAssetByBreakpoint = [];
        $assetLabelsByKey = [];

        foreach ($transformBreakpoints as $breakpoint) {
            $rows = self::rowsForTransformBreakpoint($rowsByBreakpoint, $transformName, $breakpoint);
            foreach ($rows as $row) {
                $assetKey = trim((string)($row['assetKey'] ?? ''));
                if ($assetKey === '') {
                    $assetKey = self::buildAssetKey(
                        (string)($row['transform'] ?? $transformName),
                        (string)($row['assetId'] ?? ''),
                        (string)($row['sourceUsed'] ?? ''),
                        (string)($row['src'] ?? ''),
                        (string)($row['title'] ?? ''),
                    );
                }

                if (!isset($assetSeen[$assetKey])) {
                    $assetSeen[$assetKey] = true;
                    $assetKeys[] = $assetKey;
                }

                if (!isset($rowsByAssetByBreakpoint[$assetKey])) {
                    $rowsByAssetByBreakpoint[$assetKey] = [];
                }

                if (!isset($rowsByAssetByBreakpoint[$assetKey][$breakpoint])) {
                    $rowsByAssetByBreakpoint[$assetKey][$breakpoint] = [];
                }

                $rowsByAssetByBreakpoint[$assetKey][$breakpoint][] = $row;

                if (!isset($assetLabelsByKey[$assetKey])) {
                    $assetLabelsByKey[$assetKey] = self::buildAssetLabel($row, count($assetKeys));
                }
            }
        }

        return [
            'assetKeys' => $assetKeys,
            'rowsByAssetByBreakpoint' => $rowsByAssetByBreakpoint,
            'assetLabelsByKey' => $assetLabelsByKey,
        ];
    }

    /**
     * @param array<int, string> $assetKeys
     */
    public static function normalizeSelectedAssetKey(mixed $rawSelectedAssetKey, array $assetKeys): string
    {
        if ($assetKeys === []) {
            return '';
        }

        $selectedAssetKey = is_string($rawSelectedAssetKey)
            ? trim($rawSelectedAssetKey)
            : '';

        if ($selectedAssetKey !== '' && in_array($selectedAssetKey, $assetKeys, true)) {
            return $selectedAssetKey;
        }

        return $assetKeys[0];
    }

    /**
     * @param array<string, array<int, array<int, array<string, mixed>>>> $rowsByAssetByBreakpoint
     * @param array<int, int> $transformBreakpoints
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function buildSelectedAssetRowsByBreakpoint(
        array $rowsByAssetByBreakpoint,
        string $selectedAssetKey,
        array $transformBreakpoints,
    ): array {
        $rowsByBreakpoint = [];
        foreach ($transformBreakpoints as $breakpoint) {
            $rows = $rowsByAssetByBreakpoint[$selectedAssetKey][$breakpoint] ?? [];
            $selectedRow = ReviewLayoutCalculator::pickPreviewRow($rows);
            $rowsByBreakpoint[$breakpoint] = $selectedRow !== null ? [$selectedRow] : [];
        }

        return $rowsByBreakpoint;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function buildAssetLabel(array $row, int $fallbackIndex): string
    {
        $assetId = trim((string)($row['assetId'] ?? ''));
        if ($assetId !== '') {
            return $assetId;
        }

        $title = trim((string)($row['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        return 'Asset ' . (string)$fallbackIndex;
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @return array<int, array<string, mixed>>
     */
    private static function rowsForTransformBreakpoint(
        array $rowsByBreakpoint,
        string $transformName,
        int $breakpoint,
    ): array {
        $rows = $rowsByBreakpoint[$breakpoint] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((string)($row['transform'] ?? '') !== $transformName) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }
}
