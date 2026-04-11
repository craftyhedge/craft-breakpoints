<?php

namespace craftyhedge\craftbreakpointimages\controllers;

use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use craftyhedge\craftbreakpointimages\Plugin;
use craftyhedge\craftbreakpointimages\web\assets\transforms\TransformsAsset;
use yii\helpers\Json;
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
        $manifest = Plugin::getInstance()->getProcessingManifest()->getManifest();

        $this->view->registerAssetBundle(TransformsAsset::class);
        $this->view->registerJs(
            'window.bpiProcessingManifest = ' . Json::htmlEncode($manifest) . ';',
            View::POS_HEAD
        );

        return $this->renderTemplate('craft-breakpoint-images/cp/transforms', [
            'selectedSubnavItem' => 'transforms',
            'processingManifest' => $manifest,
        ]);
    }
}
