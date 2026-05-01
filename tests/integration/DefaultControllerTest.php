<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\helpers\UrlHelper;
use craftyhedge\craftbreakpoints\controllers\DefaultController;
use craftyhedge\craftbreakpoints\web\assets\transforms\TransformsAsset;
use yii\web\BadRequestHttpException;
use yii\web\Response;

final class DefaultControllerTest extends Unit
{
    public function testIndexRedirectsToProcessing(): void
    {
        $response = $this->controller()->actionIndex();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->getIsRedirection());
        $this->assertSame(
            UrlHelper::cpUrl('breakpoints/processing'),
            (string)$response->getHeaders()->get('Location')
        );
    }

    public function testSettingsActionReturnsNonRedirectResponse(): void
    {
        $response = $this->controller()->actionSettings();

        $this->assertSame(200, $response->statusCode);
        $this->assertFalse($response->getIsRedirection());
    }

    public function testSettingsActionUsesEffectiveConfigOverridesForDisplay(): void
    {
        $controller = new class('default', Craft::$app) extends DefaultController {
            public ?array $capturedTemplatePayload = null;

            public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
            {
                $this->capturedTemplatePayload = [
                    'template' => $template,
                    'variables' => $variables,
                    'templateMode' => $templateMode,
                ];

                return parent::renderTemplate($template, $variables, $templateMode);
            }
        };

        $configService = \craftyhedge\craftbreakpoints\Plugin::getInstance()->getConfigService();
        $property = new \ReflectionProperty($configService, '_mergedConfig');
        $previous = $property->getValue($configService);

        $nextConfig = is_array($previous) ? $previous : [];
        $nextConfig['format'] = 'webp';
        $nextConfig['quality'] = 60;
        $property->setValue($configService, $nextConfig);

        try {
            $response = $controller->actionSettings();

            $this->assertSame(200, $response->statusCode);
            $this->assertSame('breakpoints/cp/settings', $controller->capturedTemplatePayload['template'] ?? null);

            $settings = $controller->capturedTemplatePayload['variables']['settings'] ?? null;
            $this->assertNotNull($settings);
            $this->assertSame('webp', $settings->format);
            $this->assertSame(60, $settings->quality);
        } finally {
            $property->setValue($configService, $previous);
        }
    }

    public function testTransformsActionRegistersConfigScriptAndAssetBundle(): void
    {
        $view = Craft::$app->getView();

        $response = $this->controller()->actionTransforms();

        $this->assertSame(200, $response->statusCode);
        $this->assertFalse($response->getIsRedirection());

        $registeredJs = implode("\n", array_merge(...array_values($view->js)));
        $this->assertStringContainsString('window.bpiProcessingConfig = ', $registeredJs);
        $this->assertArrayHasKey(TransformsAsset::class, $view->assetBundles);
        $this->assertStringContainsString('data-persist:breakpoints-editor__session="{include: /^(showCardSettings|editor\\.cards\\.[^.]+\\.selectedAssetKey)$/}"', (string)$response->content);
        $this->assertStringNotContainsString('data-persist:breakpoints-editor__session="{include: /^editor\\.cards\\./}"', (string)$response->content);
        $this->assertStringNotContainsString('bpts-frame-toolbar-actions', (string)$response->content);
        $this->assertStringNotContainsString('bpts-open-preview', (string)$response->content);
    }

    public function testTransformsActionCanRenderDeveloperToolbarActionsWhenEnabledByConfig(): void
    {
        $controller = new class('default', Craft::$app) extends DefaultController {
            public ?array $capturedTemplatePayload = null;

            public function renderTemplate(string $template, array $variables = [], ?string $templateMode = null): Response
            {
                $this->capturedTemplatePayload = [
                    'template' => $template,
                    'variables' => $variables,
                    'templateMode' => $templateMode,
                ];

                return parent::renderTemplate($template, $variables, $templateMode);
            }
        };

        $configService = \craftyhedge\craftbreakpoints\Plugin::getInstance()->getConfigService();
        $property = new \ReflectionProperty($configService, '_mergedConfig');
        $previous = $property->getValue($configService);

        $nextConfig = is_array($previous) ? $previous : [];
        $nextConfig['transformsDeveloperActionsEnabled'] = true;
        $property->setValue($configService, $nextConfig);

        try {
            $response = $controller->actionTransforms();

            $this->assertSame(200, $response->statusCode);
            $this->assertSame('breakpoints/cp/transforms', $controller->capturedTemplatePayload['template'] ?? null);
            $this->assertTrue(($controller->capturedTemplatePayload['variables']['transformsDeveloperActionsEnabled'] ?? false) === true);
        } finally {
            $property->setValue($configService, $previous);
        }
    }

    public function testEntryUrlActionInvokesRequestGuardsAndRejectsInvalidEntryId(): void
    {
        $controller = new class('default', Craft::$app) extends DefaultController {
            public bool $cpRequestChecked = false;
            public bool $acceptsJsonChecked = false;
            public bool $postRequestChecked = false;

            public function requireCpRequest(): void
            {
                $this->cpRequestChecked = true;
            }

            public function requireAcceptsJson(): void
            {
                $this->acceptsJsonChecked = true;
            }

            public function requirePostRequest(): void
            {
                $this->postRequestChecked = true;
            }
        };

        Craft::$app->getRequest()->setBodyParams([
            'entryId' => 0,
        ]);

        try {
            $controller->actionEntryUrl();
            $this->fail('Expected BadRequestHttpException for invalid entry ID.');
        } catch (BadRequestHttpException $e) {
            $this->assertSame('Invalid entry ID.', $e->getMessage());
        }

        $this->assertTrue($controller->cpRequestChecked);
        $this->assertTrue($controller->acceptsJsonChecked);
        $this->assertTrue($controller->postRequestChecked);
    }

    private function controller(): DefaultController
    {
        return new DefaultController('default', Craft::$app);
    }
}
