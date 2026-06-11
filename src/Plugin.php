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
use yii\web\Controller;
use yii\web\Response;

class Plugin extends BasePlugin
{
    private const LOG_TARGET = 'breakpoints';
    private const LOG_CATEGORY = 'breakpoints';

    public static ?self $plugin = null;

    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    /**
     * @return array{components: array<string, class-string>}
     */
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
                $event->rules['POST breakpoints/database/clear-observations'] = 'breakpoints/database/clear-observations';
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
        /** @var Images $component */
        $component = $this->get('images');
        return $component;
    }

    public function getConfigService(): ConfigService
    {
        /** @var ConfigService $component */
        $component = $this->get('configService');
        return $component;
    }

    public function getBreakpointPolicy(): BreakpointPolicy
    {
        /** @var BreakpointPolicy $component */
        $component = $this->get('breakpointPolicy');
        return $component;
    }

    public function getBreakpointSlots(): BreakpointSlotResolver
    {
        /** @var BreakpointSlotResolver $component */
        $component = $this->get('breakpointSlots');
        return $component;
    }

    public function getImageRenderer(): ImageRenderer
    {
        /** @var ImageRenderer $component */
        $component = $this->get('imageRenderer');
        return $component;
    }

    public function getRenderContextBuilder(): RenderContextBuilder
    {
        /** @var RenderContextBuilder $component */
        $component = $this->get('renderContextBuilder');
        return $component;
    }

    public function getTransformSets(): TransformSets
    {
        /** @var TransformSets $component */
        $component = $this->get('transformSets');
        return $component;
    }

    public function getTransformStore(): TransformStore
    {
        /** @var TransformStore $component */
        $component = $this->get('transformStore');
        return $component;
    }

    public function getImageTransforms(): ImageTransforms
    {
        /** @var ImageTransforms $component */
        $component = $this->get('imageTransforms');
        return $component;
    }

    public function getProcessingConfig(): ProcessingConfig
    {
        /** @var ProcessingConfig $component */
        $component = $this->get('processingConfig');
        return $component;
    }

    public function getTransformEditor(): TransformEditor
    {
        /** @var TransformEditor $component */
        $component = $this->get('transformEditor');
        return $component;
    }

    public function getTelemetry(): TelemetryService
    {
        /** @var TelemetryService $component */
        $component = $this->get('telemetry');
        return $component;
    }

    public function getDatabase(): DatabaseService
    {
        /** @var DatabaseService $component */
        $component = $this->get('database');
        return $component;
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

    /**
     * @return array<string, mixed>|null
     */
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
        ];

        if ($this->getConfigService()->allowTransformEditing()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('breakpoints', 'Settings'),
                'url' => 'breakpoints/settings',
            ];
            $item['subnav']['docs'] = [
                'label' => Craft::t('breakpoints', 'Docs'),
                'url' => 'breakpoints/docs',
            ];
        }

        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        $controller = Craft::$app->controller;
        if ($controller instanceof Controller) {
            return $controller->redirect(UrlHelper::cpUrl('breakpoints/settings'));
        }

        $response = Craft::$app->getResponse();
        if ($response instanceof Response) {
            return $response->redirect(UrlHelper::cpUrl('breakpoints/settings'));
        }

        return null;
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
                if (!$variable instanceof CraftVariable) {
                    return;
                }

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
