<?php

namespace craftyhedge\craftbreakpoints\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\HtmlPurifier;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use craftyhedge\craftbreakpoints\web\assets\docs\DocsAsset;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\web\assets\transforms\TransformsAsset;
use yii\helpers\Json;
use yii\helpers\Markdown;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class DefaultController extends Controller
{
    private const DOCS = [
        'getting-started' => [
            'title' => 'Getting Started',
            'file' => 'getting-started.md',
        ],
        'responsive-images' => [
            'title' => 'Responsive Images',
            'file' => 'responsive-images.md',
        ],
        'configuration' => [
            'title' => 'Configuration',
            'file' => 'configuration.md',
        ],
        'custom-templates' => [
            'title' => 'Custom Image Templates',
            'file' => 'custom-templates.md',
        ],
        'reference/twig-image-tag' => [
            'title' => 'image() Twig Reference',
            'file' => 'reference/twig-image-tag.md',
        ],
    ];

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        if (!Plugin::getInstance()->getTelemetry()->canEditTransforms()) {
            throw new ForbiddenHttpException('Transform editing is disabled in this environment.');
        }

        return true;
    }

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
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDocs(?string $slug = null): Response
    {
        $slug = trim((string)($slug ?: 'getting-started'), '/');
        if (!isset(self::DOCS[$slug])) {
            throw new NotFoundHttpException('Documentation page not found.');
        }

        $doc = self::DOCS[$slug];
        $doc['slug'] = $slug;
        $doc['url'] = UrlHelper::cpUrl($slug === 'getting-started' ? 'breakpoints/docs' : 'breakpoints/docs/' . $slug);

        $path = $this->docsPath($doc['file']);
        if (!is_file($path)) {
            throw new NotFoundHttpException('Documentation page not found.');
        }

        $markdown = (string)file_get_contents($path);
        $markdown = $this->rewriteDocLinks($markdown, dirname($doc['file']));
        $html = HtmlPurifier::process(Markdown::process($markdown, 'gfm'));

        $this->view->registerAssetBundle(DocsAsset::class);

        return $this->renderTemplate('breakpoints/cp/docs', [
            'selectedSubnavItem' => 'docs',
            'docs' => $this->docsNav(),
            'currentDoc' => $doc,
            'contentHtml' => $html,
        ]);
    }

    public function actionTransforms(): Response
    {
        $plugin = Plugin::getInstance();
        $config = $plugin->getProcessingConfig()->getConfig();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $requestedEntryId = (int)($this->request->getQueryParam('entry_id') ?? 0);
        $requestedSourceUrl = trim((string)($this->request->getQueryParam('source_url') ?? ''));

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
            'applyCardOperationUrl' => UrlHelper::actionUrl('breakpoints/transforms/apply-card-operation'),
            'renderTransformCardUrl' => UrlHelper::actionUrl('breakpoints/transforms/render-transform-card'),
            'selectedSourceEntries' => $selectedSourceEntry ? [$selectedSourceEntry] : [],
            'selectedSourceUrl' => $selectedSourceEntry ? '' : $requestedSourceUrl,
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

        if (!$entry instanceof Entry) {
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

    /**
     * @return array<int, array{slug: string, title: string, url: string}>
     */
    private function docsNav(): array
    {
        $nav = [];
        foreach (self::DOCS as $slug => $doc) {
            $nav[] = [
                'slug' => $slug,
                'title' => Craft::t('breakpoints', $doc['title']),
                'url' => UrlHelper::cpUrl($slug === 'getting-started' ? 'breakpoints/docs' : 'breakpoints/docs/' . $slug),
            ];
        }

        return $nav;
    }

    private function rewriteDocLinks(string $markdown, string $currentDir): string
    {
        return (string)preg_replace_callback(
            '/(!?)\[([^\]]*)\]\(([^)]+)\)/',
            function(array $matches) use ($currentDir): string {
                $isImage = $matches[1] === '!';
                $label = $matches[2];
                $target = trim($matches[3]);

                if ($target === '' || str_starts_with($target, '#') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target)) {
                    return $matches[0];
                }

                [$path, $fragment] = array_pad(explode('#', $target, 2), 2, null);
                $normalized = $this->normalizeDocPath($path, $currentDir);

                if ($isImage) {
                    $imageUrl = $this->docImageUrl($normalized);
                    if ($imageUrl === null) {
                        return $matches[0];
                    }

                    return '![' . $label . '](' . $imageUrl . ($fragment !== null ? '#' . $fragment : '') . ')';
                }

                $slug = $this->slugForDocPath($normalized);
                if ($slug === null) {
                    return $matches[0];
                }

                $url = UrlHelper::cpUrl($slug === 'getting-started' ? 'breakpoints/docs' : 'breakpoints/docs/' . $slug);
                if ($fragment !== null && $fragment !== '') {
                    $url .= '#' . rawurlencode($fragment);
                }

                return '[' . $label . '](' . $url . ')';
            },
            $markdown
        );
    }

    private function slugForDocPath(string $path): ?string
    {
        foreach (self::DOCS as $slug => $doc) {
            if ($doc['file'] === $path) {
                return $slug;
            }
        }

        return null;
    }

    private function docImageUrl(string $path): ?string
    {
        $fullPath = $this->docsPath($path);
        if (!is_file($fullPath)) {
            return null;
        }

        [, $url] = Craft::$app->getAssetManager()->publish($fullPath);

        return $url;
    }

    private function normalizeDocPath(string $path, string $currentDir): string
    {
        $path = str_replace('\\', '/', urldecode($path));
        $parts = [];
        $prefix = $currentDir === '.' ? '' : $currentDir . '/';

        foreach (explode('/', $prefix . $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($parts);
                continue;
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    private function docsPath(string $path = ''): string
    {
        return dirname(__DIR__, 2) . '/docs' . ($path !== '' ? '/' . $path : '');
    }
}
