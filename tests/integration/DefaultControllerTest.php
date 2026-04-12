<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\integration;

use Codeception\Test\Unit;
use Craft;
use craft\helpers\UrlHelper;
use craftyhedge\craftbreakpointimages\controllers\DefaultController;
use craftyhedge\craftbreakpointimages\web\assets\transforms\TransformsAsset;
use yii\web\BadRequestHttpException;
use yii\web\Response;

final class DefaultControllerTest extends Unit
{
    public function testIndexRedirectsToSettings(): void
    {
        $response = $this->controller()->actionIndex();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->getIsRedirection());
        $this->assertSame(
            UrlHelper::cpUrl('craft-breakpoint-images/settings'),
            (string)$response->getHeaders()->get('Location')
        );
    }

    public function testSettingsActionReturnsNonRedirectResponse(): void
    {
        $response = $this->controller()->actionSettings();

        $this->assertSame(200, $response->statusCode);
        $this->assertFalse($response->getIsRedirection());
    }

    public function testTransformsActionRegistersManifestScriptAndAssetBundle(): void
    {
        $view = Craft::$app->getView();

        $response = $this->controller()->actionTransforms();

        $this->assertSame(200, $response->statusCode);
        $this->assertFalse($response->getIsRedirection());

        $registeredJs = implode("\n", array_merge(...array_values($view->js)));
        $this->assertStringContainsString('window.bpiProcessingManifest = ', $registeredJs);
        $this->assertArrayHasKey(TransformsAsset::class, $view->assetBundles);
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
