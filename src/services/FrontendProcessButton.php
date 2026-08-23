<?php

namespace craftyhedge\craftbreakpoints\services;

use Craft;
use craft\elements\Entry;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\web\Application as WebApplication;
use craft\web\Request as WebRequest;
use craft\web\View;
use craftyhedge\craftbreakpoints\helpers\ProcessingRequest;
use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

/**
 * Local-admin front-end cue: fixed icon link into Transform Sets when the
 * page rendered at least one unsaved (non-SVG) transform handle.
 */
class FrontendProcessButton extends Component
{
    private const REGISTER_HTML_KEY = 'bpts-frontend-process-button';

    /** @var array<string, true> */
    private array $_missingHandles = [];

    private bool $_registered = false;

    private ?bool $_eligible = null;

    private ?string $_iconSvg = null;

    /**
     * Record a transform handle rendered on this request if it has no saved set.
     * Registers the icon button once when the first missing handle is noted.
     */
    public function noteUnsavedHandle(string $transformHandle): void
    {
        if ($this->_registered) {
            return;
        }

        if (!$this->isEligibleRequest()) {
            return;
        }

        $handle = trim($transformHandle);
        if ($handle === '' || isset($this->_missingHandles[$handle])) {
            return;
        }

        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return;
        }

        if ($plugin->getTransformSets()->getSet($handle) !== null) {
            return;
        }

        $this->_missingHandles[$handle] = true;

        $url = $this->buildProcessingUrlFromRequest();
        if ($url === null) {
            return;
        }

        $this->registerButton($url);
    }

    /**
     * @return array<int, string>
     */
    public function getMissingHandles(): array
    {
        return array_keys($this->_missingHandles);
    }

    public function resetState(): void
    {
        $this->_missingHandles = [];
        $this->_registered = false;
        $this->_eligible = null;
    }

    /**
     * Build a CP processing deep link. Prefer Entry id only for real Entry matches.
     */
    public function buildProcessingUrl(?int $entryId, ?string $sourceUrl): ?string
    {
        $params = [];

        if ($entryId !== null && $entryId > 0) {
            $params['entry_id'] = $entryId;
        }

        $sourceUrl = is_string($sourceUrl) ? trim($sourceUrl) : '';
        if ($sourceUrl !== '') {
            $params['source_url'] = $sourceUrl;
        }

        if ($params === []) {
            return null;
        }

        $params['auto'] = 1;

        return UrlHelper::cpUrl('breakpoints/processing', $params);
    }

    public function buildButtonMarkup(string $href, ?string $position = null): string
    {
        $label = Craft::t('breakpoints', 'Process unsaved transform sets');
        $icon = $this->getIconSvg();
        $position = $this->resolvePosition($position);
        $inset = $this->positionInsetCss($position);

        // Red rounded square: only shown when a transform set is missing.
        $style = '.bpts-frontend-process-btn{position:fixed;' . $inset
            . 'z-index:2147483000;display:inline-flex;align-items:center;justify-content:center;'
            . 'width:2.75rem;height:2.75rem;border-radius:0.75rem;background:#dc2626;color:#fff;'
            . 'box-shadow:0 4px 14px rgba(0,0,0,.28);text-decoration:none}'
            . '.bpts-frontend-process-btn:hover,.bpts-frontend-process-btn:focus{background:#b91c1c;color:#fff;outline:2px solid #fecaca;outline-offset:2px}'
            . '.bpts-frontend-process-btn svg{width:1.35rem;height:1.35rem;display:block}';

        return '<style>' . $style . '</style>'
            . Html::a($icon, $href, [
                'class' => 'bpts-frontend-process-btn bpts-frontend-process-btn--' . $position,
                'title' => $label,
                'aria-label' => $label,
                'data-bpts-position' => $position,
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
            ]);
    }

    /**
     * @return 'bottom-right'|'bottom-left'|'top-right'|'top-left'
     */
    public function resolvePosition(?string $position = null): string
    {
        $plugin = Plugin::getInstance();
        if ($plugin === null) {
            return 'bottom-right';
        }

        $config = $plugin->getConfigService();
        if ($position !== null && trim($position) !== '') {
            return $config->normalizeFrontendProcessButtonPosition($position);
        }

        return $config->frontendProcessButtonPosition();
    }

    private function positionInsetCss(string $position): string
    {
        return match ($position) {
            'bottom-left' => 'left:1rem;bottom:1rem;',
            'top-right' => 'right:1rem;top:1rem;',
            'top-left' => 'left:1rem;top:1rem;',
            default => 'right:1rem;bottom:1rem;',
        };
    }

    private function isEligibleRequest(): bool
    {
        if ($this->_eligible !== null) {
            return $this->_eligible;
        }

        $this->_eligible = $this->evaluateEligibility();

        return $this->_eligible;
    }

    private function evaluateEligibility(): bool
    {
        if (!Craft::$app instanceof WebApplication) {
            return false;
        }

        $plugin = Plugin::getInstance();
        if ($plugin === null || !$plugin->getConfigService()->allowTransformEditing()) {
            return false;
        }

        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest() || $request->getIsCpRequest()) {
            return false;
        }

        if (ProcessingRequest::isActive()) {
            return false;
        }

        if (!Craft::$app->getUser()->getIsAdmin()) {
            return false;
        }

        return true;
    }

    private function buildProcessingUrlFromRequest(): ?string
    {
        $entryId = null;
        $matched = Craft::$app->urlManager->getMatchedElement();
        if ($matched instanceof Entry && (int)$matched->id > 0) {
            $entryId = (int)$matched->id;
        }

        $sourceUrl = null;
        $request = Craft::$app->getRequest();
        if ($request instanceof WebRequest) {
            try {
                $sourceUrl = $request->getAbsoluteUrl();
            } catch (\Throwable) {
                $sourceUrl = null;
            }
        }

        return $this->buildProcessingUrl($entryId, $sourceUrl);
    }

    private function registerButton(string $url): void
    {
        if ($this->_registered) {
            return;
        }

        if (!Craft::$app instanceof WebApplication) {
            return;
        }

        Craft::$app->getView()->registerHtml(
            $this->buildButtonMarkup($url),
            View::POS_END,
            self::REGISTER_HTML_KEY
        );

        $this->_registered = true;
    }

    private function getIconSvg(): string
    {
        if ($this->_iconSvg !== null) {
            return $this->_iconSvg;
        }

        $plugin = Plugin::getInstance();
        $path = $plugin !== null
            ? $plugin->getBasePath() . DIRECTORY_SEPARATOR . 'icon-mask.svg'
            : '';

        if ($path !== '' && is_file($path)) {
            $svg = (string)file_get_contents($path);
            // Avoid breaking attribute context if the file ever changes.
            $this->_iconSvg = trim($svg) !== '' ? $svg : $this->fallbackIconSvg();
        } else {
            $this->_iconSvg = $this->fallbackIconSvg();
        }

        return $this->_iconSvg;
    }

    private function fallbackIconSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4 4h16v4H4V4zm0 6h10v4H4v-4zm0 6h16v4H4v-4z"/></svg>';
    }
}
