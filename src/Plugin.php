<?php

namespace craftyhedge\craftbreakpointimages;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use craftyhedge\craftbreakpointimages\models\Settings;
use craftyhedge\craftbreakpointimages\utilities\BreakpointImagesUtility;
use yii\base\Event;

class Plugin extends BasePlugin
{
    public static ?self $plugin = null;

    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
        $this->name = Craft::t('craft-breakpoint-images', 'Breakpoint Images');

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = BreakpointImagesUtility::class;
            }
        );

        Craft::info('Craft Breakpoint Images plugin loaded', __METHOD__);
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'craft-breakpoint-images/settings',
            ['settings' => $this->getSettings()]
        );
    }
}