<?php

namespace craftyhedge\craftbreakpointimages;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\web\UrlManager;
use craftyhedge\craftbreakpointimages\models\Settings;
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
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
        $this->name = Craft::t('craft-breakpoint-images', 'Breakpoint Images');

        $this->registerLogTarget();

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['craft-breakpoint-images'] = 'craft-breakpoint-images/default/index';
                $event->rules['craft-breakpoint-images/settings'] = 'craft-breakpoint-images/default/settings';
                $event->rules['craft-breakpoint-images/transforms'] = 'craft-breakpoint-images/default/transforms';
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

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item === null) {
            return null;
        }

        $item['subnav'] = [
            'settings' => [
                'label' => Craft::t('craft-breakpoint-images', 'Settings'),
                'url' => 'craft-breakpoint-images/settings',
            ],
            'transforms' => [
                'label' => Craft::t('craft-breakpoint-images', 'Transforms'),
                'url' => 'craft-breakpoint-images/transforms',
            ],
        ];

        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect(UrlHelper::cpUrl('craft-breakpoint-images/settings'));
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