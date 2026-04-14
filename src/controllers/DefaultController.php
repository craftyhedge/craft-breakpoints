<?php

namespace craftyhedge\craftbreakpointimages\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use craftyhedge\craftbreakpointimages\Plugin;
use craftyhedge\craftbreakpointimages\web\assets\transforms\TransformsAsset;
use yii\helpers\Json;
use yii\web\BadRequestHttpException;
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

    public function actionConfigTransforms(): Response
    {
        $config = Plugin::getInstance()->getProcessingConfig()->getConfig();
        $configSets = $config['sets'] ?? [];

        if (!is_array($configSets)) {
            $configSets = [];
        }

        $setNames = array_values(array_filter(
            array_map('strval', array_keys($configSets)),
            static fn(string $name): bool => $name !== ''
        ));

        sort($setNames, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->renderTemplate('craft-breakpoint-images/cp/config-transforms', [
            'selectedSubnavItem' => 'transforms',
            'configSets' => $configSets,
            'setNames' => $setNames,
        ]);
    }

    public function actionTransforms(): Response
    {
        $plugin = Plugin::getInstance();
        $config = $plugin->getProcessingConfig()->getConfig();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $requestedEntryId = (int)($this->request->getQueryParam('entry_id')
            ?? $this->request->getQueryParam('entryId')
            ?? $this->request->getQueryParam('id')
            ?? 0);

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

        return $this->renderTemplate('craft-breakpoint-images/cp/transforms', [
            'selectedSubnavItem' => 'processing',
            'processingConfig' => $config,
            'applyCardOperationUrl' => UrlHelper::cpUrl('actions/craft-breakpoint-images/transforms/apply-card-operation'),
            'selectedSourceEntries' => $selectedSourceEntry ? [$selectedSourceEntry] : [],
            'previewCenter' => (bool)$plugin->getConfigService()->get('previewCenter', true),
            'transformsDeveloperActionsEnabled' => $plugin->getConfigService()->areTransformsDeveloperActionsEnabled(),
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
