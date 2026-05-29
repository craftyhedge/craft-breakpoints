<?php

namespace craftyhedge\craftbreakpoints\helpers;

use Craft;

/**
 * Single source of truth for detecting a Breakpoints processing run.
 *
 * The processing JavaScript loads the preview iframe with `?__bpiProcessing=1`
 * (see web/assets/transforms/dist/js/transforms.js). That query param is the
 * only signal that distinguishes a processing render from a normal one, and it
 * is always set to the literal string "1".
 */
final class ProcessingRequest
{
    public const QUERY_PARAM = '__bpiProcessing';

    /**
     * Whether the current request is a processing run (the preview iframe).
     *
     * Returns false for console/queue runs, which have no web request and
     * therefore never carry the query param.
     */
    public static function isActive(): bool
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return false;
        }

        return $request->getQueryParam(self::QUERY_PARAM) === '1';
    }
}
