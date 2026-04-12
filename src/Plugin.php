<?php

namespace craftyhedge\craftbreakpointimages;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\PluginEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Plugins;
use craft\web\UrlManager;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use craftyhedge\craftbreakpointimages\models\Settings;
use craftyhedge\craftbreakpointimages\services\BreakpointPolicy;
use craftyhedge\craftbreakpointimages\services\ConfigService;
use craftyhedge\craftbreakpointimages\services\ImageRenderer;
use craftyhedge\craftbreakpointimages\services\Images;
use craftyhedge\craftbreakpointimages\services\ImageTransforms;
use craftyhedge\craftbreakpointimages\services\ProcessingManifest;
use craftyhedge\craftbreakpointimages\services\RenderContextBuilder;
use craftyhedge\craftbreakpointimages\services\TransformStore;
use craftyhedge\craftbreakpointimages\services\TransformEditor;
use craftyhedge\craftbreakpointimages\services\Transforms;
use craftyhedge\craftbreakpointimages\web\twig\Extension;
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

    public static function config(): array
    {
        return [
            'components' => [
                'images' => Images::class,
                'configService' => ConfigService::class,
                'breakpointPolicy' => BreakpointPolicy::class,
                'transforms' => Transforms::class,
                'transformStore' => TransformStore::class,
                'imageRenderer' => ImageRenderer::class,
                'renderContextBuilder' => RenderContextBuilder::class,
                'imageTransforms' => ImageTransforms::class,
                'processingManifest' => ProcessingManifest::class,
                'transformEditor' => TransformEditor::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;
        $this->name = Craft::t('craft-breakpoint-images', 'Breakpoint Images');

        $this->registerLogTarget();
        $this->registerTwigExtension();
        $this->registerTemplateRoots();
        $this->registerTwigVariable();
        $this->registerInstallEventHandlers();

        $this->getTransformStore()->initialize();

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event): void {
                $event->rules['craft-breakpoint-images'] = 'craft-breakpoint-images/default/index';
                $event->rules['craft-breakpoint-images/settings'] = 'craft-breakpoint-images/default/settings';
                $event->rules['craft-breakpoint-images/transforms'] = 'craft-breakpoint-images/default/manifest-transforms';
                $event->rules['craft-breakpoint-images/processing'] = 'craft-breakpoint-images/default/transforms';
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

    public function getImageRenderer(): ImageRenderer
    {
        return $this->get('imageRenderer');
    }

    public function getRenderContextBuilder(): RenderContextBuilder
    {
        return $this->get('renderContextBuilder');
    }

    public function getTransforms(): Transforms
    {
        return $this->get('transforms');
    }

    public function getTransformStore(): TransformStore
    {
        return $this->get('transformStore');
    }

    public function getImageTransforms(): ImageTransforms
    {
        return $this->get('imageTransforms');
    }

    public function getProcessingManifest(): ProcessingManifest
    {
        return $this->get('processingManifest');
    }

    public function getTransformEditor(): TransformEditor
    {
        return $this->get('transformEditor');
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
            'processing' => [
                'label' => Craft::t('craft-breakpoint-images', 'Processing'),
                'url' => 'craft-breakpoint-images/processing',
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
                $event->roots['craft-breakpoint-images'] = __DIR__ . '/templates';
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

    private function registerInstallEventHandlers(): void
    {
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function(PluginEvent $event): void {
                if ($event->plugin !== $this) {
                    return;
                }

                $this->getTransformStore()->initialize();
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
            'craft-breakpoint-images/settings',
            ['settings' => $this->getSettings()]
        );
    }
}