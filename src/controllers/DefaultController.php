<?php

namespace craftyhedge\craftbreakpoints\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\web\assets\transforms\TransformsAsset;
use yii\helpers\Json;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class DefaultController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('breakpoints/processing'));
    }

    public function actionSettings(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = clone $plugin->getSettings();
        $settings->setAttributes($plugin->getConfigService()->getConfig(), false);

        return $this->renderTemplate('breakpoints/cp/settings', [
            'settings' => $settings,
            'selectedSubnavItem' => 'settings',
            'databaseStats' => $plugin->getDatabase()->getTableStats(),
            'databaseLatestRunAt' => $plugin->getDatabase()->getLatestRunTimestamp(),
        ]);
    }

    public function actionTransforms(): Response
    {
        $plugin = Plugin::getInstance();
        $config = $plugin->getProcessingConfig()->getConfig();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $requestedEntryId = (int)($this->request->getQueryParam('entry_id') ?? 0);

        $selectedSourceEntry = null;
        if ($requestedEntryId > 0) {
            $selectedSourceEntry = Entry::find()
                ->id($requestedEntryId)
                ->siteId($siteId)
                ->status(null)
                ->one();
        }

        $this->view->registerAssetBundle(TransformsAsset::class);
        $this->view->registerJs(
            'window.bpiProcessingConfig = ' . Json::htmlEncode($config) . ';',
            View::POS_HEAD
        );

        return $this->renderTemplate('breakpoints/cp/transforms', [
            'selectedSubnavItem' => 'processing',
            'processingConfig' => $config,
            'sidebarTransformRows' => $plugin->getTransformEditor()->buildSidebarTransformRows(),
            'currentBaseVersion' => $plugin->getTransformStore()->getCurrentVersion(),
            'applyCardOperationUrl' => UrlHelper::cpUrl('actions/breakpoints/transforms/apply-card-operation'),
            'selectedSourceEntries' => $selectedSourceEntry ? [$selectedSourceEntry] : [],
            'previewCenter' => (bool)$plugin->getConfigService()->get('previewCenter', true),
            'transformsDeveloperActionsEnabled' => $plugin->getConfigService()->areTransformsDeveloperActionsEnabled(),
            'canEditTransforms' => $plugin->getTelemetry()->canEditTransforms(),
        ]);
    }

    /**
     * Returns an entry URL for the current site.
     *
     * @throws BadRequestHttpException if the request payload is invalid or the entry is not usable.
     */
    public function actionEntryUrl(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $entryId = (int)$this->request->getRequiredBodyParam('entryId');
        if ($entryId < 1) {
            throw new BadRequestHttpException('Invalid entry ID.');
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $entry = Entry::find()
            ->id($entryId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        if (!$entry) {
            throw new BadRequestHttpException('Entry not found for the current site.');
        }

        $entryUrl = $entry->getUrl();
        if (!$entryUrl) {
            throw new BadRequestHttpException('Selected entry does not have a front-end URL.');
        }

        return $this->asJson([
            'url' => $entryUrl,
        ]);
    }
}
