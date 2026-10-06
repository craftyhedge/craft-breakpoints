<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craft\web\Request;
use craftyhedge\craftbreakpoints\helpers\ProcessingRequest;
use craftyhedge\craftbreakpoints\Plugin;
use ReflectionProperty;

final class ProcessingRequestTest extends Unit
{
    public function testIsActiveWithQueryParamWhenTransformEditingAllowed(): void
    {
        $this->withProcessingRequest(true, '1', function(): void {
            $this->assertTrue(ProcessingRequest::isActive());
        });
    }

    public function testIsInactiveWithQueryParamWhenTransformEditingDisabled(): void
    {
        $this->withProcessingRequest(false, '1', function(): void {
            $this->assertFalse(ProcessingRequest::isActive());
        });
    }

    public function testIsInactiveWithoutLiteralOneQueryParam(): void
    {
        $this->withProcessingRequest(true, 'true', function(): void {
            $this->assertFalse(ProcessingRequest::isActive());
        });
    }

    /**
     * @param callable(): void $callback
     */
    private function withProcessingRequest(bool $allowTransformEditing, string $paramValue, callable $callback): void
    {
        $request = Craft::$app->getRequest();
        $this->assertInstanceOf(Request::class, $request);

        $previousIsConsole = $request->getIsConsoleRequest();
        $previousQuery = $request->getQueryParams();

        $configService = Plugin::getInstance()->getConfigService();
        $mergedConfig = new ReflectionProperty($configService, '_mergedConfig');
        $previousConfig = $mergedConfig->getValue($configService);
        $nextConfig = is_array($previousConfig) ? $previousConfig : $configService->getConfig();
        $nextConfig['allowTransformEditing'] = $allowTransformEditing;
        $mergedConfig->setValue($configService, $nextConfig);

        $request->setIsConsoleRequest(false);
        $request->setQueryParams(array_merge($previousQuery, [ProcessingRequest::QUERY_PARAM => $paramValue]));

        try {
            $callback();
        } finally {
            $request->setIsConsoleRequest($previousIsConsole);
            $request->setQueryParams($previousQuery);
            $mergedConfig->setValue($configService, $previousConfig);
        }
    }
}
