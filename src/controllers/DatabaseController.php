<?php

namespace craftyhedge\craftbreakpoints\controllers;

use Craft;
use craft\web\Controller;
use craftyhedge\craftbreakpoints\Plugin;
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
        $this->requireAdmin();

        return true;
    }

    public function actionClearAll(): Response
    {
        $result = Plugin::getInstance()->getDatabase()->clearAll();
        $total = array_sum($result);
        $message = sprintf('Cleared all plugin data (%d row%s deleted).', $total, $total === 1 ? '' : 's');
        return $this->respond($message, $result + ['total' => $total]);
    }

    public function actionClearObservations(): Response
    {
        $rows = Plugin::getInstance()->getDatabase()->clearObservedUsage();
        $message = sprintf('Cleared observed transform usage (%d row%s deleted).', $rows, $rows === 1 ? '' : 's');
        return $this->respond($message, ['usage' => $rows, 'total' => $rows]);
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
