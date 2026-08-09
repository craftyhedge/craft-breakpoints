<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\web\Request;
use craft\web\View;
use craftyhedge\craftbreakpoints\helpers\ProcessingRequest;
use craftyhedge\craftbreakpoints\Plugin;
use ReflectionProperty;

final class FrontendProcessButtonTest extends Unit
{
    protected function _before(): void
    {
        parent::_before();
        Plugin::getInstance()->getFrontendProcessButton()->resetState();
        $this->clearRegisteredViewHtml();
    }

    public function testBuildProcessingUrlRequiresAtLeastOneSource(): void
    {
        $service = Plugin::getInstance()->getFrontendProcessButton();

        $this->assertNull($service->buildProcessingUrl(null, null));
        $this->assertNull($service->buildProcessingUrl(0, ''));
        $this->assertNull($service->buildProcessingUrl(null, '   '));
    }

    public function testBuildProcessingUrlForEntryIncludesEntryIdSourceUrlAndAuto(): void
    {
        $service = Plugin::getInstance()->getFrontendProcessButton();
        $url = $service->buildProcessingUrl(42, 'https://example.test/page');

        $this->assertNotNull($url);
        $this->assertStringContainsString('breakpoints/processing', $url);
        $this->assertStringContainsString('entry_id=42', $url);
        $this->assertStringContainsString('auto=1', $url);

        $query = [];
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('https://example.test/page', $query['source_url'] ?? null);
    }

    public function testBuildProcessingUrlFallsBackToSourceUrlOnly(): void
    {
        $service = Plugin::getInstance()->getFrontendProcessButton();
        $url = $service->buildProcessingUrl(null, 'https://example.test/category/foo');

        $this->assertNotNull($url);
        $this->assertStringNotContainsString('entry_id=', $url);
        $this->assertStringContainsString('auto=1', $url);
        $this->assertStringContainsString('source_url=', $url);
    }

    public function testBuildButtonMarkupIsIconLinkWithLabel(): void
    {
        $service = Plugin::getInstance()->getFrontendProcessButton();
        $html = $service->buildButtonMarkup('https://cp.example/breakpoints/processing?auto=1');

        $this->assertStringContainsString('bpts-frontend-process-btn', $html);
        $this->assertStringContainsString('href="https://cp.example/breakpoints/processing?auto=1"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('title="', $html);
        $this->assertStringContainsString('aria-label="', $html);
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringNotContainsString('unsaved transform set(s) on this page', strtolower($html));
    }

    public function testBuildButtonMarkupHonoursCornerPositions(): void
    {
        $service = Plugin::getInstance()->getFrontendProcessButton();

        $cases = [
            'bottom-right' => ['right:1rem', 'bottom:1rem'],
            'bottom-left' => ['left:1rem', 'bottom:1rem'],
            'top-right' => ['right:1rem', 'top:1rem'],
            'top-left' => ['left:1rem', 'top:1rem'],
        ];

        foreach ($cases as $position => $insets) {
            $html = $service->buildButtonMarkup('https://cp.example/breakpoints/processing?auto=1', $position);
            $this->assertStringContainsString('bpts-frontend-process-btn--' . $position, $html);
            $this->assertStringContainsString('data-bpts-position="' . $position . '"', $html);
            foreach ($insets as $inset) {
                $this->assertStringContainsString($inset, $html, "Expected {$inset} for {$position}");
            }
        }

        $fallback = $service->buildButtonMarkup('https://cp.example/x', 'not-a-corner');
        $this->assertStringContainsString('bpts-frontend-process-btn--bottom-right', $fallback);
        $this->assertStringContainsString('right:1rem', $fallback);
        $this->assertStringContainsString('bottom:1rem', $fallback);
    }

    public function testConfigServiceNormalisesFrontendProcessButtonPosition(): void
    {
        $config = Plugin::getInstance()->getConfigService();

        $this->assertSame('top-left', $config->normalizeFrontendProcessButtonPosition('TOP_LEFT'));
        $this->assertSame('bottom-right', $config->normalizeFrontendProcessButtonPosition('sideways'));
        $this->assertSame('bottom-left', $config->normalizeFrontendProcessButtonPosition('bottom_left'));

        $this->withMergedConfigValue('frontendProcessButtonPosition', 'top-left', function() use ($config): void {
            $this->assertSame('top-left', $config->frontendProcessButtonPosition());
        });

        $this->withMergedConfigValue('frontendProcessButtonPosition', 'TOP_LEFT', function() use ($config): void {
            $this->assertSame('top-left', $config->frontendProcessButtonPosition());
        });

        $this->withMergedConfigValue('frontendProcessButtonPosition', 'sideways', function() use ($config): void {
            $this->assertSame('bottom-right', $config->frontendProcessButtonPosition());
        });
    }

    public function testNoteUnsavedHandleIsNoopWhenEditingDisabled(): void
    {
        $this->withAdminSiteRequest(function(): void {
            $this->withMergedConfigValue('allowTransformEditing', false, function(): void {
                $service = Plugin::getInstance()->getFrontendProcessButton();
                $service->resetState();
                $this->clearRegisteredViewHtml();
                $service->noteUnsavedHandle('missing-local-only-set');

                $this->assertSame([], $service->getMissingHandles());
                $this->assertButtonNotRegistered();
            });
        });
    }

    public function testNoteUnsavedHandleIsNoopForGuest(): void
    {
        $this->withMergedConfigValue('allowTransformEditing', true, function(): void {
            $this->withRequestContext(
                admin: false,
                identity: null,
                isCpRequest: false,
                processing: false,
                callback: function(): void {
                    $service = Plugin::getInstance()->getFrontendProcessButton();
                    $service->resetState();
                    $this->clearRegisteredViewHtml();
                    $service->noteUnsavedHandle('missing-guest-set');

                    $this->assertSame([], $service->getMissingHandles());
                    $this->assertButtonNotRegistered();
                },
            );
        });
    }

    public function testNoteUnsavedHandleIsNoopForNonAdmin(): void
    {
        $this->withMergedConfigValue('allowTransformEditing', true, function(): void {
            $this->withRequestContext(
                admin: false,
                identity: $this->makeUser(admin: false),
                isCpRequest: false,
                processing: false,
                callback: function(): void {
                    $service = Plugin::getInstance()->getFrontendProcessButton();
                    $service->resetState();
                    $this->clearRegisteredViewHtml();
                    $service->noteUnsavedHandle('missing-non-admin-set');

                    $this->assertSame([], $service->getMissingHandles());
                    $this->assertButtonNotRegistered();
                },
            );
        });
    }

    public function testNoteUnsavedHandleIsNoopOnCpRequest(): void
    {
        $this->withMergedConfigValue('allowTransformEditing', true, function(): void {
            $this->withRequestContext(
                admin: true,
                identity: $this->makeUser(admin: true),
                isCpRequest: true,
                processing: false,
                callback: function(): void {
                    $service = Plugin::getInstance()->getFrontendProcessButton();
                    $service->resetState();
                    $this->clearRegisteredViewHtml();
                    $service->noteUnsavedHandle('missing-cp-set');

                    $this->assertSame([], $service->getMissingHandles());
                    $this->assertButtonNotRegistered();
                },
            );
        });
    }

    public function testNoteUnsavedHandleIsNoopDuringProcessingRequest(): void
    {
        $this->withMergedConfigValue('allowTransformEditing', true, function(): void {
            $this->withRequestContext(
                admin: true,
                identity: $this->makeUser(admin: true),
                isCpRequest: false,
                processing: true,
                callback: function(): void {
                    $this->assertTrue(ProcessingRequest::isActive());

                    $service = Plugin::getInstance()->getFrontendProcessButton();
                    $service->resetState();
                    $this->clearRegisteredViewHtml();
                    $service->noteUnsavedHandle('missing-processing-set');

                    $this->assertSame([], $service->getMissingHandles());
                    $this->assertButtonNotRegistered();
                },
            );
        });
    }

    public function testNoteUnsavedHandleIgnoresSavedSets(): void
    {
        $plugin = Plugin::getInstance();
        $sets = $plugin->getTransformSets()->getSets();
        $savedHandle = array_key_first($sets);
        if (!is_string($savedHandle) || $savedHandle === '') {
            $this->markTestSkipped('No saved transform sets available in test config.');
        }

        $this->withMergedConfigValue('allowTransformEditing', true, function() use ($plugin, $savedHandle): void {
            $this->withAdminSiteRequest(function() use ($plugin, $savedHandle): void {
                $service = $plugin->getFrontendProcessButton();
                $service->resetState();
                $this->clearRegisteredViewHtml();

                $service->noteUnsavedHandle($savedHandle);

                $this->assertSame([], $service->getMissingHandles());
                $this->assertButtonNotRegistered();
            });
        });
    }

    public function testNoteUnsavedHandleRegistersOnceForMissingSetWhenEligible(): void
    {
        $plugin = Plugin::getInstance();
        $handle = 'frontend-process-btn-missing-' . uniqid('', true);

        $this->withMergedConfigValue('allowTransformEditing', true, function() use ($plugin, $handle): void {
            $this->withAdminSiteRequest(function() use ($plugin, $handle): void {
                $service = $plugin->getFrontendProcessButton();
                $service->resetState();
                $this->clearRegisteredViewHtml();

                $this->assertNull($plugin->getTransformSets()->getSet($handle));

                $service->noteUnsavedHandle($handle);
                $service->noteUnsavedHandle($handle);
                // After the button is registered, further handles are ignored (one control per page).
                $service->noteUnsavedHandle($handle . '-other');

                $this->assertSame([$handle], $service->getMissingHandles());
                $this->assertButtonRegistered();
                $this->assertStringContainsString('auto=1', $this->registeredButtonHtml());
            });
        });
    }

    public function testRegisterHtmlUsesStableKey(): void
    {
        $plugin = Plugin::getInstance();
        $handle = 'frontend-process-btn-key-' . uniqid('', true);

        $this->withMergedConfigValue('allowTransformEditing', true, function() use ($plugin, $handle): void {
            $this->withAdminSiteRequest(function() use ($plugin, $handle): void {
                $service = $plugin->getFrontendProcessButton();
                $service->resetState();
                $this->clearRegisteredViewHtml();

                $service->noteUnsavedHandle($handle);

                $endBucket = $this->registeredHtmlEndBucket();
                $this->assertArrayHasKey('bpts-frontend-process-button', $endBucket);
                $this->assertStringContainsString('bpts-frontend-process-btn', $endBucket['bpts-frontend-process-button']);
            });
        });
    }

    public function testSvgAssetDoesNotRegisterProcessButton(): void
    {
        $plugin = Plugin::getInstance();
        $handle = 'frontend-process-btn-svg-' . uniqid('', true);

        $this->withMergedConfigValue('allowTransformEditing', true, function() use ($plugin, $handle): void {
            $this->withAdminSiteRequest(function() use ($plugin, $handle): void {
                $service = $plugin->getFrontendProcessButton();
                $service->resetState();
                $this->clearRegisteredViewHtml();

                $this->assertNull($plugin->getTransformSets()->getSet($handle));

                $plugin->getImageTransforms()->getTransformedImages(
                    $this->createMockSvgAsset(),
                    $handle,
                    'primary',
                    [
                        'transformName' => $handle,
                        'breakpoints' => ['xs' => 480],
                        'escapeWidth' => 0,
                        'format' => 'jpg',
                        'secondaryFormat' => 'none',
                    ],
                );

                $this->assertSame([], $service->getMissingHandles());
                $this->assertButtonNotRegistered();
            });
        });
    }

    public function testRasterAssetWithMissingSetRegistersProcessButton(): void
    {
        $plugin = Plugin::getInstance();
        $handle = 'frontend-process-btn-raster-' . uniqid('', true);

        $this->withMergedConfigValue('allowTransformEditing', true, function() use ($plugin, $handle): void {
            $this->withAdminSiteRequest(function() use ($plugin, $handle): void {
                $service = $plugin->getFrontendProcessButton();
                $service->resetState();
                $this->clearRegisteredViewHtml();

                $this->assertNull($plugin->getTransformSets()->getSet($handle));

                $plugin->getImageTransforms()->getTransformedImages(
                    $this->createMockRasterAsset(),
                    $handle,
                    'primary',
                    [
                        'transformName' => $handle,
                        'breakpoints' => ['xs' => 480],
                        'escapeWidth' => 0,
                        'format' => 'jpg',
                        'secondaryFormat' => 'none',
                    ],
                );

                $this->assertSame([$handle], $service->getMissingHandles());
                $this->assertButtonRegistered();
            });
        });
    }

    /**
     * @param callable(): void $callback
     */
    private function withAdminSiteRequest(callable $callback): void
    {
        $this->withRequestContext(
            admin: true,
            identity: $this->makeUser(admin: true),
            isCpRequest: false,
            processing: false,
            callback: $callback,
        );
    }

    /**
     * Simulate a front-end (or CP) web request under Codecept's CLI SAPI.
     *
     * Yii only resolves host/URI from $_SERVER; CLI has neither. Set them
     * explicitly so getAbsoluteUrl() / ProcessingRequest / CP checks behave.
     *
     * @param callable(): void $callback
     * @see \yii\web\Request::getAbsoluteUrl()
     * @see \yii\web\Request::setUrl()
     */
    private function withRequestContext(
        bool $admin,
        ?User $identity,
        bool $isCpRequest,
        bool $processing,
        callable $callback,
    ): void {
        $userComponent = Craft::$app->getUser();
        $request = Craft::$app->getRequest();
        $this->assertInstanceOf(Request::class, $request);

        $previousIdentity = $userComponent->getIdentity();
        $previousIsCp = $request->getIsCpRequest();
        $previousIsConsole = $request->getIsConsoleRequest();
        $previousQuery = $request->getQueryParams();

        // Private caches live on yii\web\Request — restore so later tests are not polluted.
        $yiiRequest = \yii\web\Request::class;
        $hostInfoProp = new ReflectionProperty($yiiRequest, '_hostInfo');
        $scriptUrlProp = new ReflectionProperty($yiiRequest, '_scriptUrl');
        $urlProp = new ReflectionProperty($yiiRequest, '_url');
        $previousHostInfo = $hostInfoProp->getValue($request);
        $previousScriptUrl = $scriptUrlProp->getValue($request);
        $previousUrl = $urlProp->getValue($request);

        if ($identity !== null) {
            $identity->admin = $admin;
            $userComponent->setIdentity($identity);
        } else {
            $userComponent->setIdentity(null);
        }

        $request->setIsConsoleRequest(false);
        $request->setIsCpRequest($isCpRequest);
        $request->setHostInfo('http://test.craftcms.test');
        $request->setScriptUrl('/index.php');
        // Required: without setUrl(), getUrl() tries resolveRequestUri() and throws in CLI.
        $request->setUrl('/index.php?p=pages/test');

        $query = $previousQuery;
        if ($processing) {
            $query[ProcessingRequest::QUERY_PARAM] = '1';
        } else {
            unset($query[ProcessingRequest::QUERY_PARAM]);
        }
        $request->setQueryParams($query);

        Plugin::getInstance()->getFrontendProcessButton()->resetState();

        try {
            $this->assertFalse($request->getIsConsoleRequest());
            $this->assertSame($isCpRequest, $request->getIsCpRequest());
            $this->assertSame($admin && $identity !== null, Craft::$app->getUser()->getIsAdmin());
            if ($processing) {
                $this->assertSame('1', $request->getQueryParam(ProcessingRequest::QUERY_PARAM));
            }
            $this->assertStringStartsWith('http://test.craftcms.test/', $request->getAbsoluteUrl());

            $callback();
        } finally {
            $userComponent->setIdentity($previousIdentity);
            $request->setIsCpRequest($previousIsCp);
            $request->setIsConsoleRequest($previousIsConsole);
            $request->setQueryParams($previousQuery);
            $hostInfoProp->setValue($request, $previousHostInfo);
            $scriptUrlProp->setValue($request, $previousScriptUrl);
            $urlProp->setValue($request, $previousUrl);
            Plugin::getInstance()->getFrontendProcessButton()->resetState();
            $this->clearRegisteredViewHtml();
        }
    }

    private function makeUser(bool $admin): User
    {
        $user = new User();
        $user->id = $admin ? 900001 : 900002;
        $user->admin = $admin;
        $user->username = $admin ? 'bpts-admin' : 'bpts-editor';

        return $user;
    }

    private function createMockRasterAsset(): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUrl', 'getWidth', 'getHeight', 'getExtension', 'getMimeType'])
            ->getMock();

        $asset->id = 123;
        $asset->method('getExtension')->willReturn('jpg');
        $asset->method('getMimeType')->willReturn('image/jpeg');
        $asset->method('getWidth')->willReturn(1600);
        $asset->method('getHeight')->willReturn(900);
        $asset->method('getUrl')->willReturnCallback(static function(...$args): string {
            $transform = $args[0] ?? [];
            if (!is_array($transform)) {
                return 'https://example.test/original.jpg';
            }

            $width = (int)($transform['width'] ?? 0);
            $height = (int)($transform['height'] ?? 0);
            $format = (string)($transform['format'] ?? 'jpg');

            return "https://example.test/{$width}x{$height}.{$format}";
        });

        return $asset;
    }

    private function createMockSvgAsset(): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUrl', 'getWidth', 'getHeight', 'getExtension', 'getMimeType'])
            ->getMock();

        $asset->id = 456;
        $asset->method('getExtension')->willReturn('svg');
        $asset->method('getMimeType')->willReturn('image/svg+xml');
        $asset->method('getWidth')->willReturn(100);
        $asset->method('getHeight')->willReturn(100);
        $asset->method('getUrl')->willReturn('https://example.test/icon.svg');

        return $asset;
    }

    private function clearRegisteredViewHtml(): void
    {
        $view = Craft::$app->getView();
        $htmlProp = new ReflectionProperty($view, '_html');
        $htmlProp->setValue($view, []);
    }

    /**
     * @return array<string, string>
     */
    private function registeredHtmlEndBucket(): array
    {
        $view = Craft::$app->getView();
        $htmlProp = new ReflectionProperty($view, '_html');
        /** @var array<int, array<string, string>> $html */
        $html = $htmlProp->getValue($view);

        return $html[View::POS_END] ?? [];
    }

    private function registeredButtonHtml(): string
    {
        return $this->registeredHtmlEndBucket()['bpts-frontend-process-button'] ?? '';
    }

    private function assertButtonRegistered(): void
    {
        $this->assertArrayHasKey('bpts-frontend-process-button', $this->registeredHtmlEndBucket());
        $this->assertStringContainsString('bpts-frontend-process-btn', $this->registeredButtonHtml());
    }

    private function assertButtonNotRegistered(): void
    {
        $this->assertArrayNotHasKey('bpts-frontend-process-button', $this->registeredHtmlEndBucket());
    }

    private function withMergedConfigValue(string $key, mixed $value, callable $callback): void
    {
        $configService = Plugin::getInstance()->getConfigService();
        $property = new ReflectionProperty($configService, '_mergedConfig');
        $previous = $property->getValue($configService);

        $nextConfig = is_array($previous) ? $previous : $configService->getConfig();
        $nextConfig[$key] = $value;
        $property->setValue($configService, $nextConfig);

        try {
            $callback();
        } finally {
            $property->setValue($configService, $previous);
            Plugin::getInstance()->getFrontendProcessButton()->resetState();
        }
    }
}
