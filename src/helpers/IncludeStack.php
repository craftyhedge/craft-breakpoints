<?php

namespace craftyhedge\craftbreakpoints\helpers;

use Twig\Template;

/**
 * Recovers the Twig include stack that rendered the current `image()` call.
 *
 * Craft exposes no include stack, and its render-template events only fire for
 * the page template. Every rendering `Twig\Template` is on the PHP call stack
 * while `image()` runs, so the stack is read from `debug_backtrace()`.
 *
 * Only used during processing runs; callers gate on `ProcessingRequest::isActive()`.
 */
final class IncludeStack
{
    public const MAX_DEPTH = 32;
    public const MAX_NAME_LENGTH = 160;

    private const STRING_TEMPLATE_PREFIX = '__string_template__';

    /**
     * Template names from root to leaf for the current call.
     *
     * @return string[]
     */
    public static function capture(): array
    {
        $names = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT | DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $object = $frame['object'] ?? null;
            if ($object instanceof Template) {
                $names[] = $object->getTemplateName();
            }
        }

        return self::normalize(array_reverse($names));
    }

    /**
     * Normalises a root → leaf list of template names: drops non-strings and
     * string templates, strips `.twig`, collapses consecutive duplicates, and
     * caps name length and depth (keeping the leaf end).
     *
     * Plugin templates never appear: markers are built before the plugin's
     * picture/SVG template renders.
     *
     * @return string[]
     */
    public static function normalize(mixed $names): array
    {
        if (!is_array($names)) {
            return [];
        }

        $stack = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }

            $name = trim($name);
            if (str_ends_with($name, '.twig')) {
                $name = substr($name, 0, -5);
            }

            if ($name === '' || str_starts_with($name, self::STRING_TEMPLATE_PREFIX)) {
                continue;
            }

            if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);
            }

            if ($stack !== [] && $stack[count($stack) - 1] === $name) {
                continue;
            }

            $stack[] = $name;
        }

        if (count($stack) > self::MAX_DEPTH) {
            $stack = array_slice($stack, -self::MAX_DEPTH);
        }

        return $stack;
    }
}
