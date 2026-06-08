<?php

namespace craftyhedge\craftbreakpoints;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\web\UrlManager;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use craftyhedge\craftbreakpoints\helpers\ProcessingRequest;
use craftyhedge\craftbreakpoints\models\Settings;
use craftyhedge\craftbreakpoints\services\BreakpointPolicy;
use craftyhedge\craftbreakpoints\services\BreakpointSlotResolver;
use craftyhedge\craftbreakpoints\services\ConfigService;
use craftyhedge\craftbreakpoints\services\DatabaseService;
use craftyhedge\craftbreakpoints\services\ImageRenderer;
use craftyhedge\craftbreakpoints\services\Images;
use craftyhedge\craftbreakpoints\services\ImageTransforms;
use craftyhedge\craftbreakpoints\services\ProcessingConfig;
use craftyhedge\craftbreakpoints\services\RenderContextBuilder;
use craftyhedge\craftbreakpoints\services\TransformSets;
use craftyhedge\craftbreakpoints\services\TransformStore;
use craftyhedge\craftbreakpoints\services\TransformEditor;
use craftyhedge\craftbreakpoints\services\TelemetryService;
use craftyhedge\craftbreakpoints\web\twig\Extension;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\base\Event;
use yii\base\Application;
use yii\log\Logger;

class Plugin extends BasePlugin
{
    private const LOG_TARGET = 'breakpoints';
    private const LOG_CATEGORY = 'breakpoints';

    public static ?self $plugin = null;

    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'images' => Images::class,
                'configService' => ConfigService::class,
                'breakpointPolicy' => BreakpointPolicy::class,
                'breakpointSlots' => BreakpointSlotResolver::class,
                'transformSets' => TransformSets::class,
                'transformStore' => TransformStore::class,
                'imageRenderer' => ImageRenderer::class,
                'renderContextBuilder' => RenderContextBuilder::class,
                'imageTransforms' => ImageTransforms::class,
                'processingConfig' => ProcessingConfig::class,
                'transformEditor' => TransformEditor::class,
                'telemetry' => TelemetryService::class,
                'database' => DatabaseService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
        $this->name = Craft::t('breakpoints', 'Breakpoints');

        // During a processing run the preview iframe must always see freshly
        // rendered picture markup (with the internal data-bp-* attributes), so
        // suppress Craft's {% cache %} tags for that request only. Full-page
        // caches (Blitz/static) can't be controlled here — see docs.
        if (ProcessingRequest::isActive()) {
            Craft::$app->getConfig()->getGeneral()->enableTemplateCaching = false;
        }

        $this->registerLogTarget();
        $this->registerTwigExtension();
        $this->registerTemplateRoots();
        $this->registerTwigVariable();
        $this->getTransformStore()->initialize();

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['breakpoints'] = 'breakpoints/default/index';
                $event->rules['breakpoints/settings'] = 'breakpoints/default/settings';
                $event->rules['breakpoints/processing'] = 'breakpoints/default/transforms';
                $event->rules['breakpoints/docs'] = 'breakpoints/default/docs';
                $event->rules['breakpoints/docs/<slug:.+>'] = 'breakpoints/default/docs';
                $event->rules['POST breakpoints/database/cleanup-orphaned'] = 'breakpoints/database/cleanup-orphaned';
                $event->rules['POST breakpoints/database/clear-all'] = 'breakpoints/database/clear-all';
            }
        );

        Craft::$app->on(
            Application::EVENT_AFTER_REQUEST,
            function(): void {
                $this->getTelemetry()->flushPendingUsage();
            }
        );

        self::info('Plugin loaded');
    }

    public function getImages(): Images
    {
        return $this->get('images');
    }

    public function getConfigService(): ConfigService
    {
        return $this->get('configService');
    }

    public function getBreakpointPolicy(): BreakpointPolicy
    {
        return $this->get('breakpointPolicy');
    }

    public function getBreakpointSlots(): BreakpointSlotResolver
    {
        return $this->get('breakpointSlots');
    }

    public function getImageRenderer(): ImageRenderer
    {
        return $this->get('imageRenderer');
    }

    public function getRenderContextBuilder(): RenderContextBuilder
    {
        return $this->get('renderContextBuilder');
    }

    public function getTransformSets(): TransformSets
    {
        return $this->get('transformSets');
    }

    public function getTransformStore(): TransformStore
    {
        return $this->get('transformStore');
    }

    public function getImageTransforms(): ImageTransforms
    {
        return $this->get('imageTransforms');
    }

    public function getProcessingConfig(): ProcessingConfig
    {
        return $this->get('processingConfig');
    }

    public function getTransformEditor(): TransformEditor
    {
        return $this->get('transformEditor');
    }

    public function getTelemetry(): TelemetryService
    {
        return $this->get('telemetry');
    }

    public function getDatabase(): DatabaseService
    {
        return $this->get('database');
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

        $item['url'] = 'breakpoints';

        $item['subnav'] = [
            'processing' => [
                'label' => Craft::t('breakpoints', 'Transform Sets'),
                'url' => 'breakpoints/processing',
            ],
            'settings' => [
                'label' => Craft::t('breakpoints', 'Settings'),
                'url' => 'breakpoints/settings',
            ],
            'docs' => [
                'label' => Craft::t('breakpoints', 'Docs'),
                'url' => 'breakpoints/docs',
            ],
        ];

        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect(UrlHelper::cpUrl('breakpoints/settings'));
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

    private function registerTwigExtension(): void
    {
        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    private function registerTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function(RegisterTemplateRootsEvent $event): void {
                $event->roots['breakpoints'] = __DIR__ . '/templates';
            }
        );

        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            static function(RegisterTemplateRootsEvent $event): void {
                $event->roots['breakpoints'] = __DIR__ . '/templates';
            }
        );
    }

    private function registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                $variable = $event->sender;
                $variable->set('images', Images::class);
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'breakpoints/settings',
            ['settings' => $this->getSettings()]
        );
    }
}
