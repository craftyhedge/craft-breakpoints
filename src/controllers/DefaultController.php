<?php

namespace craftyhedge\craftbreakpointimages\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use craftyhedge\craftbreakpointimages\Plugin;
use craftyhedge\craftbreakpointimages\web\assets\transforms\ProcessDetailsAsset;
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

    public function actionProcessingRunDetails(): Response
    {
        $this->requireCpRequest();

        $this->view->registerAssetBundle(ProcessDetailsAsset::class);

        $plugin = Plugin::getInstance();
        $transformHandle = trim((string)$this->request->getQueryParam('transformHandle', ''));
        $telemetry = $plugin->getTelemetry();
        $transformEditor = $plugin->getTransformEditor();
        $snapshot = $telemetry->getLatestRunSnapshot();
        $allRows = is_array($snapshot['rows'] ?? null) ? $snapshot['rows'] : [];
        $rows = $allRows;

        $lastObserved = null;
        $lastObservedEntry = null;
        if ($transformHandle !== '' && $telemetry->isTelemetryEnabled()) {
            $recentlySeen = $telemetry->getRecentlySeen();
            foreach ($recentlySeen as $seenRow) {
                if (!is_array($seenRow)) {
                    continue;
                }

                $seenHandle = trim((string)($seenRow['transformHandle'] ?? ''));
                if ($seenHandle !== $transformHandle) {
                    continue;
                }

                $lastObserved = [
                    'lastSeenAt' => $seenRow['lastSeenAt'] ?? null,
                    'sourceElementId' => isset($seenRow['sourceElementId']) ? (int)$seenRow['sourceElementId'] : null,
                    'sourceUrl' => $seenRow['sourceUrl'] ?? null,
                ];
                break;
            }

            $lastObservedEntryId = isset($lastObserved['sourceElementId']) ? (int)$lastObserved['sourceElementId'] : 0;
            if ($lastObservedEntryId > 0) {
                $lastObservedEntry = Entry::find()
                    ->id($lastObservedEntryId)
                    ->status(null)
                    ->site('*')
                    ->one();
            }
        }

        $processedTransformHandles = [];
        foreach ($allRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowTransformHandle = trim((string)($row['transformHandle'] ?? ''));
            if ($rowTransformHandle === '') {
                continue;
            }

            $processedTransformHandles[$rowTransformHandle] = true;
        }

        $processedTransformHandles = array_keys($processedTransformHandles);
        natcasesort($processedTransformHandles);
        $processedTransformHandles = array_values($processedTransformHandles);

        $otherTransformHandles = $processedTransformHandles;
        if ($transformHandle !== '') {
            $otherTransformHandles = array_values(array_filter(
                $processedTransformHandles,
                static fn(string $name): bool => $name !== $transformHandle,
            ));
        }

        $runEntry = null;
        $canProcessAgain = false;
        $processAgainDisabledReason = Craft::t('craft-breakpoint-images', 'Entry not found.');
        if (is_array($snapshot)) {
            $snapshotEntryId = isset($snapshot['entryId']) ? (int)$snapshot['entryId'] : 0;
            if ($snapshotEntryId > 0) {
                $siteId = Craft::$app->getSites()->getCurrentSite()->id;
                $runEntry = Entry::find()
                    ->id($snapshotEntryId)
                    ->status(null)
                    ->siteId($siteId)
                    ->one();

                if ($runEntry !== null && $runEntry->getStatus() === Entry::STATUS_LIVE) {
                    $canProcessAgain = true;
                    $processAgainDisabledReason = '';
                } elseif ($runEntry !== null) {
                    $processAgainDisabledReason = Craft::t('craft-breakpoint-images', 'Entry is not live.');
                }
            }
        }

        if ($transformHandle !== '') {
            $rows = array_values(array_filter($rows, static function(mixed $row) use ($transformHandle): bool {
                return is_array($row) && (string)($row['transformHandle'] ?? '') === $transformHandle;
            }));
        }

        usort($rows, static function(array $left, array $right): int {
            $leftBp = is_numeric($left['breakpointWidth'] ?? null) ? (int)$left['breakpointWidth'] : 0;
            $rightBp = is_numeric($right['breakpointWidth'] ?? null) ? (int)$right['breakpointWidth'] : 0;
            return $leftBp <=> $rightBp;
        });

        $counts = [
            'loaded' => 0,
            'broken' => 0,
            'unresolved' => 0,
            'disabled' => 0,
            'unprocessed' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower(trim((string)($row['rowStatus'] ?? 'unprocessed')));
            if (!array_key_exists($status, $counts)) {
                $status = 'unprocessed';
            }

            $counts[$status] += 1;
        }

        $healthByTransform = $transformEditor->buildLatestRunHealthByTransform(is_array($snapshot) ? $snapshot : null);
        $breakpointRows = [];
        if ($transformHandle !== '') {
            $health = $healthByTransform[$transformHandle] ?? null;
            if (is_array($health) && isset($health['breakpointRows']) && is_array($health['breakpointRows'])) {
                $breakpointRows = $health['breakpointRows'];
            }
        }

        $mismatchBreakpointCount = 0;
        foreach ($breakpointRows as $breakpointRow) {
            if (!is_array($breakpointRow)) {
                continue;
            }

            $statusLabel = strtolower(trim((string)($breakpointRow['statusLabel'] ?? 'matching')));
            if ($statusLabel === 'mismatches') {
                $mismatchBreakpointCount += 1;
            }
        }

        $healthStatusClass = 'unknown';
        $healthStatusIcon = 'alert';
        $healthStatusLabel = Craft::t('craft-breakpoint-images', 'No Health Data');

        if ($breakpointRows !== []) {
            if ($mismatchBreakpointCount > 0) {
                $healthStatusClass = 'failed';
                $healthStatusIcon = 'alert';
                $healthStatusLabel = Craft::t('craft-breakpoint-images', 'Needs Review');
            } else {
                $healthStatusClass = 'success';
                $healthStatusIcon = 'check';
                $healthStatusLabel = Craft::t('craft-breakpoint-images', 'Transform Sets Valid');
            }
        }

        $response = $this->asCpScreen()
            ->title($transformHandle !== ''
                ? Craft::t('craft-breakpoint-images', 'Process Details: {name}', ['name' => $transformHandle])
                : Craft::t('craft-breakpoint-images', 'Process Details'))
            ->contentTemplate('craft-breakpoint-images/cp/slideouts/process-run-details', [
                'transformHandle' => $transformHandle,
                'snapshot' => is_array($snapshot) ? $snapshot : null,
                'runEntry' => $runEntry,
                'canProcessAgain' => $canProcessAgain,
                'processAgainDisabledReason' => $processAgainDisabledReason,
                'otherTransformHandles' => $otherTransformHandles,
                'lastObserved' => $lastObserved,
                'lastObservedEntry' => $lastObservedEntry,
                'breakpointRows' => $breakpointRows,
                'statusCounts' => $counts,
                'healthStatusClass' => $healthStatusClass,
                'healthStatusIcon' => $healthStatusIcon,
                'healthStatusLabel' => $healthStatusLabel,
            ]);

        return $response;
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

        return $this->renderTemplate('craft-breakpoint-images/cp/transforms', [
            'selectedSubnavItem' => 'processing',
            'processingConfig' => $config,
            'currentBaseVersion' => $plugin->getTransformStore()->getCurrentVersion(),
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
