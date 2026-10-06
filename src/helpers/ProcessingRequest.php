<?php

namespace craftyhedge\craftbreakpoints\helpers;

use Craft;
use craft\web\Request;
use craftyhedge\craftbreakpoints\Plugin;

/**
 * Single source of truth for detecting a Breakpoints processing run.
 *
 * The processing JavaScript loads the preview iframe with `?__bpiProcessing=1`
 * (see web/assets/transforms/dist/js/transforms.js). That query param is the
 * only signal that distinguishes a processing render from a normal one, and it
 * is always set to the literal string "1".
 *
 * The param only counts while `allowTransformEditing` is on.
 */
final class ProcessingRequest
{
    public const QUERY_PARAM = '__bpiProcessing';

    /**
     * Whether the current request is a processing run (the preview iframe).
     *
     * Returns false for console/queue runs, which have no web request and
     * therefore never carry the query param, and whenever transform editing
     * is off.
     */
    public static function isActive(): bool
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof Request || $request->getIsConsoleRequest()) {
            return false;
        }

        if ($request->getQueryParam(self::QUERY_PARAM) !== '1') {
            return false;
        }

        $plugin = Plugin::getInstance();

        return $plugin !== null && $plugin->getConfigService()->allowTransformEditing();
    }
}
