<?php

namespace craftyhedge\craftbreakpointimages\utilities;

use Craft;
use craft\base\Utility;

class BreakpointImagesUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('craft-breakpoint-images', 'Breakpoint Images');
    }

    public static function id(): string
    {
        return 'breakpoint-images';
    }

    public static function icon(): ?string
    {
        return null;
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('craft-breakpoint-images/utilities/index');
    }
}