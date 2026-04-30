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

    public function actionCleanupOrphaned(): Response
    {
        $result = Plugin::getInstance()->getDatabase()->cleanupOrphanedRows();
        $message = sprintf(
            'Removed %d orphaned row%s (%d unknown handles, %d missing entries).',
            $result['total'],
            $result['total'] === 1 ? '' : 's',
            $result['orphanedHandles'],
            $result['orphanedEntries'],
        );
        return $this->respond($message, $result);
    }

    public function actionClearAll(): Response
    {
        $result = Plugin::getInstance()->getDatabase()->clearAll();
        $total = array_sum($result);
        $message = sprintf('Cleared all plugin data (%d row%s deleted).', $total, $total === 1 ? '' : 's');
        return $this->respond($message, $result + ['total' => $total]);
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
            'stats' => Plugin::getInstance()->getDatabase()->getTableStats(),
        ], $extra));
    }
}
