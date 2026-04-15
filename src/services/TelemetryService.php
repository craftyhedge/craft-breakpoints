<?php

namespace craftyhedge\craftbreakpointimages\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Application as WebApplication;
use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class TelemetryService extends Component
{
    private const PROCESSING_QUERY_PARAM = '__bpiProcessing';

    /** @var array<string, bool> */
    private array $_seenHandles = [];

    public function isTelemetryEnabled(): bool
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return false;
        }

        return $plugin->getConfigService()->isTelemetryEnabled();
    }

    public function isInsightsCpEnabled(): bool
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return false;
        }

        return $plugin->getConfigService()->isInsightsCpEnabled();
    }

    public function canWriteTelemetry(): bool
    {
        return $this->isTelemetryEnabled();
    }

    public function canEditTransforms(): bool
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return false;
        }

        return $plugin->getConfigService()->allowTransformEditing();
    }

    public function recordUsage(string $transformHandle): void
    {
        if (!$this->canWriteTelemetry()) {
            return;
        }

        $handle = trim($transformHandle);
        if ($handle === '') {
            return;
        }

        $sourceElementId = null;
        $sourceUrl = null;

        if (Craft::$app instanceof WebApplication) {
            if ($this->isProcessingIframeRequest()) {
                return;
            }

            if (isset($this->_seenHandles[$handle])) {
                return;
            }

            $matched = Craft::$app->urlManager->getMatchedElement();
            $sourceElementId = ($matched !== false) ? $matched->id : null;

            try {
                $sourceUrl = Craft::$app->request->getAbsoluteUrl();
            } catch (\Throwable) {
                $sourceUrl = null;
            }

            $this->_seenHandles[$handle] = true;
            $this->upsertUsage($handle, $sourceElementId, $sourceUrl);

            return;
        }

        // Queue/console runtimes have no web request lifecycle; write immediately.
        $this->upsertUsage($handle, $sourceElementId, $sourceUrl);
    }

    private function isProcessingIframeRequest(): bool
    {
        if (!(Craft::$app instanceof WebApplication)) {
            return false;
        }

        $rawFlag = Craft::$app->request->getQueryParam(self::PROCESSING_QUERY_PARAM);
        if ($rawFlag === null) {
            return false;
        }

        if (is_bool($rawFlag)) {
            return $rawFlag;
        }

        if (is_numeric($rawFlag)) {
            return ((int)$rawFlag) !== 0;
        }

        $normalized = strtolower(trim((string)$rawFlag));
        if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'no' || $normalized === 'off') {
            return false;
        }

        return true;
    }

    public function flushPendingUsage(): void
    {
        // Writes happen immediately on first observation per request.
        $this->_seenHandles = [];
    }

    private function upsertUsage(string $handle, ?int $sourceElementId, ?string $sourceUrl): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $normalizedSourceUrl = $sourceUrl;
        if (is_string($normalizedSourceUrl) && $normalizedSourceUrl !== '') {
            $normalizedSourceUrl = mb_substr($normalizedSourceUrl, 0, 255);
        }

        try {
            Db::upsert('{{%bpi_transform_last_processed}}', [
                'transformHandle' => $handle,
                'sourceElementId' => $sourceElementId,
                'sourceUrl' => $normalizedSourceUrl,
                'lastSeenAt' => $now,
            ]);
        } catch (\Throwable $e) {
            Plugin::warning('Telemetry write failed for handle "' . $handle . '": ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentlySeen(int $limit = 50): array
    {
        return (new Query())
            ->select(['transformHandle', 'sourceElementId', 'sourceUrl', 'lastSeenAt'])
            ->from('{{%bpi_transform_last_processed}}')
            ->orderBy(['lastSeenAt' => SORT_DESC])
            ->limit($limit)
            ->all();
    }
}
