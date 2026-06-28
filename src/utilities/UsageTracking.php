<?php

namespace craftyhedge\craftbreakpoints\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\UrlHelper;
use craftyhedge\craftbreakpoints\Plugin;

class UsageTracking extends Utility
{
    public static function id(): string
    {
        return 'breakpoints-usage-tracking';
    }

    public static function displayName(): string
    {
        return Craft::t('breakpoints', 'Transform Tracking');
    }

    public static function icon(): ?string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'icon-mask.svg';
    }

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->getIsAdmin();
    }

    public static function contentHtml(): string
    {
        $plugin = Plugin::getInstance();
        $rows = $plugin?->getTelemetry()->getUsageObservationRows() ?? [];
        $savedSets = $plugin?->getTransformSets()->getSets() ?? [];

        return Craft::$app->getView()->renderTemplate('breakpoints/cp/utilities/usage-tracking', [
            'usageTrackingEnabled' => $plugin?->getTelemetry()->canTrackUsage() ?? false,
            'canEditTransforms' => $plugin?->getTelemetry()->canEditTransforms() ?? false,
            'usageTrackingGroups' => self::groupRowsByTransform($rows, $savedSets),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<string, mixed>> $savedSets
     * @return array<int, array<string, mixed>>
     */
    private static function groupRowsByTransform(array $rows, array $savedSets): array
    {
        $groups = [];
        $savedHandles = array_fill_keys(array_filter(
            array_keys($savedSets),
            static fn(mixed $handle): bool => is_string($handle) && trim($handle) !== '',
        ), true);

        foreach ($rows as $row) {
            $handle = trim((string)($row['transformHandle'] ?? ''));
            if ($handle === '') {
                continue;
            }
            $row['sourceLabel'] = self::sourceLabel($row);
            $row['processingUrl'] = self::processingUrl($row);

            if (!isset($groups[$handle])) {
                $groups[$handle] = [
                    'transformHandle' => $handle,
                    'isMissingSavedSet' => !isset($savedHandles[$handle]),
                    'recentSource' => $row,
                    'firstSeenAt' => (string)($row['firstSeenAt'] ?? ''),
                    'lastSeenAt' => (string)($row['lastSeenAt'] ?? ''),
                    'seenCount' => 0,
                    'sourceCount' => 0,
                    'sources' => [],
                ];
            }

            $groups[$handle]['seenCount'] += max(0, (int)($row['seenCount'] ?? 0));
            $groups[$handle]['sourceCount']++;
            $groups[$handle]['sources'][] = $row;

            $firstSeenAt = (string)($row['firstSeenAt'] ?? '');
            if ($firstSeenAt !== '' && ($groups[$handle]['firstSeenAt'] === '' || strcmp($firstSeenAt, $groups[$handle]['firstSeenAt']) < 0)) {
                $groups[$handle]['firstSeenAt'] = $firstSeenAt;
            }

            $lastSeenAt = (string)($row['lastSeenAt'] ?? '');
            if ($lastSeenAt !== '' && strcmp($lastSeenAt, (string)$groups[$handle]['lastSeenAt']) > 0) {
                $groups[$handle]['lastSeenAt'] = $lastSeenAt;
                $groups[$handle]['recentSource'] = $row;
            }
        }

        uasort($groups, static function(array $a, array $b): int {
            $missingSort = ((bool)($b['isMissingSavedSet'] ?? false) <=> (bool)($a['isMissingSavedSet'] ?? false));
            if ($missingSort !== 0) {
                return $missingSort;
            }

            $lastSeen = strcmp((string)($b['lastSeenAt'] ?? ''), (string)($a['lastSeenAt'] ?? ''));
            return $lastSeen !== 0
                ? $lastSeen
                : strcmp((string)($a['transformHandle'] ?? ''), (string)($b['transformHandle'] ?? ''));
        });

        return array_values($groups);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function sourceLabel(array $row): ?string
    {
        $sourceUrl = (string)($row['sourceUrl'] ?? '');
        if ($sourceUrl === '') {
            return null;
        }

        $path = parse_url($sourceUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $query = parse_url($sourceUrl, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $path .= '?' . $query;
        }

        $fragment = parse_url($sourceUrl, PHP_URL_FRAGMENT);
        if (is_string($fragment) && $fragment !== '') {
            $path .= '#' . $fragment;
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function processingUrl(array $row): ?string
    {
        $sourceElementId = $row['sourceElementId'] ?? null;
        if (is_numeric($sourceElementId) && (int)$sourceElementId > 0) {
            return UrlHelper::cpUrl('breakpoints/processing', [
                'entry_id' => (int)$sourceElementId,
            ]);
        }

        $sourceUrl = trim((string)($row['sourceUrl'] ?? ''));
        if ($sourceUrl === '') {
            return null;
        }

        return UrlHelper::cpUrl('breakpoints/processing', [
            'source_url' => $sourceUrl,
        ]);
    }
}
