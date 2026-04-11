<?php

namespace craftyhedge\craftbreakpointimages;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\log\MonologTarget;
use craft\services\Utilities;
use craftyhedge\craftbreakpointimages\models\Settings;
use craftyhedge\craftbreakpointimages\utilities\BreakpointImagesUtility;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\base\Event;
use yii\log\Logger;

class Plugin extends BasePlugin
{
    private const LOG_TARGET = 'craft-breakpoint-images';
    private const LOG_CATEGORY = 'craft-breakpoint-images';

    public static ?self $plugin = null;

    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
        $this->name = Craft::t('craft-breakpoint-images', 'Breakpoint Images');

        $this->registerLogTarget();

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = BreakpointImagesUtility::class;
            }
        );

        self::info('Plugin loaded');
    }

    public static function info(string $message): void
    {
        Craft::info($message, self::LOG_CATEGORY);
    }

    public static function warning(string $message): void
    {
        Craft::warning($message, self::LOG_CATEGORY);
    }

    public static function error(string $message): void
    {
        Craft::error($message, self::LOG_CATEGORY);
    }

    public static function debug(string $message): void
    {
        Craft::getLogger()->log($message, Logger::LEVEL_TRACE, self::LOG_CATEGORY);
    }

    private function registerLogTarget(): void
    {
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => self::LOG_TARGET,
            'extractExceptionTrace' => !App::devMode(),
            'allowLineBreaks' => App::devMode(),
            'level' => App::devMode() ? LogLevel::INFO : LogLevel::WARNING,
            'categories' => [self::LOG_CATEGORY],
            'logContext' => false,
            'formatter' => new LineFormatter(
                format: "%datetime% %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);
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