<?php

namespace craftyhedge\craftbreakpoints\controllers;

use Craft;
use craft\web\Controller;
use craftyhedge\craftbreakpoints\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class DatabaseController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $telemetry = Plugin::getInstance()->getTelemetry();
        $isUsageTrackingAction = in_array($action->id, ['clear-usage-tracking', 'clear-usage-tracking-row', 'clear-usage-tracking-handle'], true);

        // Usage clears only need an admin account (runtime bpi_* rows), not allowAdminChanges.
        // clear-all stays tied to allowAdminChanges like other admin mutations.
        $this->requireAdmin(!$isUsageTrackingAction);

        if (!$telemetry->canEditTransforms() && (!$isUsageTrackingAction || !$telemetry->canTrackUsage())) {
            throw new ForbiddenHttpException('Transform editing is disabled in this environment.');
        }

        return true;
    }

    public function actionClearAll(): Response
    {
        $result = Plugin::getInstance()->getDatabase()->clearAll();
        $total = array_sum($result);
        $message = sprintf('Cleared all plugin data (%d row%s deleted).', $total, $total === 1 ? '' : 's');
        return $this->respond($message, $result + ['total' => $total]);
    }

    public function actionClearUsageTracking(): Response
    {
        $rows = Plugin::getInstance()->getDatabase()->clearUsageTracking();
        $message = sprintf('Cleared transform usage tracking (%d row%s deleted).', $rows, $rows === 1 ? '' : 's');
        return $this->respond($message, ['usageTracking' => $rows, 'total' => $rows]);
    }

    public function actionClearUsageTrackingRow(): Response
    {
        $id = (int)$this->request->getRequiredBodyParam('id');
        $rows = Plugin::getInstance()->getDatabase()->clearUsageTrackingRow($id);
        $message = sprintf('Cleared transform usage tracking row (%d row%s deleted).', $rows, $rows === 1 ? '' : 's');
        return $this->respond($message, ['usageTracking' => $rows, 'total' => $rows]);
    }

    public function actionClearUsageTrackingHandle(): Response
    {
        $transformHandle = (string)$this->request->getRequiredBodyParam('transformHandle');
        $rows = Plugin::getInstance()->getDatabase()->clearUsageTrackingHandle($transformHandle);
        $message = sprintf('Cleared transform usage tracking (%d row%s deleted).', $rows, $rows === 1 ? '' : 's');
        return $this->respond($message, ['usageTracking' => $rows, 'total' => $rows]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function respond(string $message, array $extra = []): Response
    {
        Plugin::info('Database utility: ' . $message);
        return $this->asJson(array_merge([
            'success' => true,
            'message' => Craft::t('breakpoints', $message),
        ], $extra));
    }
}
