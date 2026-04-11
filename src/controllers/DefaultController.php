<?php

namespace craftyhedge\craftbreakpointimages\controllers;

use craft\helpers\UrlHelper;
use craft\web\Controller;
use craftyhedge\craftbreakpointimages\Plugin;
use yii\web\Response;

class DefaultController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('craft-breakpoint-images/settings'));
    }

    public function actionSettings(): Response
    {
        return $this->renderTemplate('craft-breakpoint-images/cp/settings', [
            'settings' => Plugin::getInstance()->getSettings(),
            'selectedSubnavItem' => 'settings',
        ]);
    }

    public function actionTransforms(): Response
    {
        return $this->renderTemplate('craft-breakpoint-images/cp/transforms', [
            'selectedSubnavItem' => 'transforms',
        ]);
    }
}
