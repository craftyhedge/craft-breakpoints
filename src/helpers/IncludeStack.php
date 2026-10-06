<?php

namespace craftyhedge\craftbreakpoints\helpers;

use Twig\Template;
use Twig\TemplateWrapper;

/**
 * Recovers the Twig include stack that rendered the current `image()` call.
 *
 * Craft exposes no include stack, and its render-template events only fire for
 * the page template. Every rendering `Twig\Template` is on the PHP call stack
 * while `image()` runs, so the stack is read from `debug_backtrace()`.
 *
 * The stack is a root → leaf list of include levels. Template inheritance does
 * not add levels: a template's `{% extends %}` parents and `{% embed %}` parents
 * are listed on its level, and `in` names the template in that chain whose code
 * was running when the next level (or `image()`) was reached, when it is not
 * the level's own template.
 *
 * Only used during processing runs; callers gate on `ProcessingRequest::isActive()`.
 *
 * @phpstan-type IncludeLevel array{template: string, extends?: string[], embeds?: string[], in?: string}
 */
final class IncludeStack
{
    public const MAX_DEPTH = 32;
    public const MAX_NAME_LENGTH = 160;

    private const STRING_TEMPLATE_PREFIX = '__string_template__';
    private const EMBED_CLASS_PATTERN = '/___\d+$/';

    /**
     * Include levels from root to leaf for the current call.
     *
     * @return array<int, IncludeLevel>
     */
    public static function capture(): array
    {
        $frames = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $object = $frame['object'] ?? null;
            $function = $frame['function'];
            if (
                $object instanceof Template
                && ($function === 'doDisplay' || str_starts_with($function, 'block_') || str_starts_with($function, 'macro_'))
            ) {
                $frames[] = [$object, $function];
            }
        }

        return self::normalize(self::buildLevels(array_reverse($frames)));
    }

    /**
     * Normalises a stored or posted stack: a list of levels, the JSON of one
     * (as the CP apply buttons post it), or a single template name.
     *
     * @return array<int, IncludeLevel>
     */
    public static function fromValue(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            $value = str_starts_with($value, '[') ? json_decode($value, true) : [$value];
        }

        return self::normalize($value);
    }

    /**
     * Normalises a root → leaf list of levels. A level may be given as a bare
     * template name. Strips `.twig`, drops string templates, and caps name
     * length and depth (keeping the leaf end).
     *
     * Plugin templates never appear: markers are built before the plugin's
     * picture/SVG template renders.
     *
     * @return array<int, IncludeLevel>
     */
    public static function normalize(mixed $levels): array
    {
        if (!is_array($levels)) {
            return [];
        }

        $stack = [];
        foreach ($levels as $level) {
            if (is_string($level)) {
                $level = ['template' => $level];
            }
            if (!is_array($level)) {
                continue;
            }

            $template = self::normalizeName($level['template'] ?? null);
            if ($template === null) {
                continue;
            }

            $normalized = ['template' => $template];
            foreach (['extends', 'embeds'] as $key) {
                $names = self::normalizeNames($level[$key] ?? []);
                if ($names !== []) {
                    $normalized[$key] = $names;
                }
            }

            $in = self::normalizeName($level['in'] ?? null);
            if ($in !== null && $in !== $template) {
                $normalized['in'] = $in;
            }

            /** @var IncludeLevel $normalized */
            $stack[] = $normalized;
        }

        if (count($stack) > self::MAX_DEPTH) {
            $stack = array_slice($stack, -self::MAX_DEPTH);
        }

        return $stack;
    }

    /**
     * The template whose code called `image()`.
     *
     * @param array<int, array<string, mixed>> $stack Normalized levels.
     */
    public static function leaf(array $stack): ?string
    {
        if ($stack === []) {
            return null;
        }

        $level = $stack[count($stack) - 1];
        $name = (string)($level['in'] ?? $level['template'] ?? '');

        return $name !== '' ? $name : null;
    }

    /**
     * Number of templates the stack lists, counting extends and embeds parents.
     *
     * @param array<int, array<string, mixed>> $stack Normalized levels.
     */
    public static function templateCount(array $stack): int
    {
        $count = 0;
        foreach ($stack as $level) {
            $count += 1 + count((array)($level['extends'] ?? [])) + count((array)($level['embeds'] ?? []));
        }

        return $count;
    }

    /**
     * A level as one line of text, e.g. `page (extends _layout › _base, in _layout)`.
     *
     * @param array<string, mixed> $level A normalized level.
     */
    public static function describeLevel(array $level): string
    {
        $template = (string)($level['template'] ?? '');
        $parts = [];
        foreach (['extends', 'embeds'] as $key) {
            $names = self::normalizeNames($level[$key] ?? []);
            if ($names !== []) {
                $parts[] = $key . ' ' . implode(' › ', $names);
            }
        }
        if (isset($level['in']) && is_string($level['in'])) {
            $parts[] = 'in ' . $level['in'];
        }

        return $parts !== []
            ? $template . ' (' . implode(', ', $parts) . ')'
            : $template;
    }

    /**
     * JSON for markup and signals; empty string for an empty stack.
     *
     * @param array<int, array<string, mixed>> $stack Normalized levels.
     */
    public static function encode(array $stack): string
    {
        if ($stack === []) {
            return '';
        }

        $encoded = json_encode(array_values($stack), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * Groups root → leaf template frames into include levels.
     *
     * A `doDisplay` frame for a template that the current level's chain extends
     * (its `parent` is that template object) joins the level as a parent; an
     * embed class (`___N`) named like the level's running template marks an
     * embed. A block
     * or macro frame on an object already in a level returns to that level;
     * levels opened after it were its parents, which covers parents the
     * `parent` check could not see. A macro frame on an earlier level's object
     * opens a new level instead: Twig shares one object per template, so an
     * include can import and call into a template already on the stack.
     *
     * @param array<int, array{0: Template, 1: string}> $frames
     * @return array<int, array<string, mixed>>
     */
    private static function buildLevels(array $frames): array
    {
        $levels = [];
        foreach ($frames as [$template, $function]) {
            $name = $template->getTemplateName();
            $objectId = spl_object_id($template);
            $lastKey = array_key_last($levels);

            if ($function === 'doDisplay') {
                if ($lastKey !== null) {
                    $last = $levels[$lastKey];
                    if (self::isParentOf($template, $last['tail'])) {
                        $levels[$lastKey][$last['embedding'] ? 'embeds' : 'extends'][] = $name;
                        $levels[$lastKey]['objects'][$objectId] = true;
                        $levels[$lastKey]['tail'] = $template;
                        $levels[$lastKey]['in'] = $name;
                        continue;
                    }

                    if (preg_match(self::EMBED_CLASS_PATTERN, get_class($template)) === 1 && $name === $last['in']) {
                        $levels[$lastKey]['embedding'] = true;
                        $levels[$lastKey]['objects'][$objectId] = true;
                        $levels[$lastKey]['tail'] = $template;
                        $levels[$lastKey]['in'] = $name;
                        continue;
                    }
                }

                $levels[] = self::newLevel($template, $name, $objectId);
                continue;
            }

            $ownerKey = null;
            for ($key = $lastKey; $key !== null && $key >= 0; $key--) {
                if (isset($levels[$key]['objects'][$objectId])) {
                    $ownerKey = $key;
                    break;
                }
            }

            if ($ownerKey === null || ($ownerKey !== $lastKey && str_starts_with($function, 'macro_'))) {
                $levels[] = self::newLevel($template, $name, $objectId);
                continue;
            }

            $parentKey = $levels[$ownerKey]['embedding'] ? 'embeds' : 'extends';
            foreach (array_splice($levels, $ownerKey + 1) as $folded) {
                $levels[$ownerKey][$parentKey] = array_merge(
                    $levels[$ownerKey][$parentKey],
                    [$folded['template']],
                    $folded['extends'],
                    $folded['embeds'],
                );
                $levels[$ownerKey]['objects'] += $folded['objects'];
            }
            $levels[$ownerKey]['in'] = $name;
        }

        return $levels;
    }

    /**
     * @return array<string, mixed>
     */
    private static function newLevel(Template $template, string $name, int $objectId): array
    {
        return [
            'template' => $name,
            'extends' => [],
            'embeds' => [],
            'in' => $name,
            'objects' => [$objectId => true],
            'tail' => $template,
            'embedding' => false,
        ];
    }

    /**
     * Whether `$candidate` is a parent `$child` has loaded: `$parent` for a
     * static `{% extends %}`, the `$parents` cache for a dynamic one. These are
     * Twig internals; if a Twig release drops them this returns false and
     * `buildLevels()` falls back to block re-entry.
     */
    private static function isParentOf(Template $candidate, Template $child): bool
    {
        $readParents = \Closure::bind(static function(Template $template): array {
            return [
                property_exists($template, 'parent') ? $template->parent : null,
                ...(property_exists($template, 'parents') ? array_values((array)$template->parents) : []),
            ];
        }, null, Template::class);
        if ($readParents === null) {
            return false;
        }

        foreach ($readParents($child) as $parent) {
            if ($parent instanceof TemplateWrapper) {
                $parent = $parent->unwrap();
            }
            if ($parent === $candidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private static function normalizeNames(mixed $names): array
    {
        if (!is_array($names)) {
            return [];
        }

        $normalized = [];
        foreach ($names as $name) {
            $name = self::normalizeName($name);
            if ($name !== null) {
                $normalized[] = $name;
            }
        }

        return array_slice($normalized, 0, self::MAX_DEPTH);
    }

    private static function normalizeName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }

        $name = trim($name);
        if (str_ends_with($name, '.twig')) {
            $name = substr($name, 0, -5);
        }

        if ($name === '' || str_starts_with($name, self::STRING_TEMPLATE_PREFIX)) {
            return null;
        }

        return mb_strlen($name) > self::MAX_NAME_LENGTH
            ? mb_substr($name, 0, self::MAX_NAME_LENGTH)
            : $name;
    }
}
