<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\Plugin;

final class ProcessingConfigServiceTest extends Unit
{
    public function testConfigUsesCurrentRuntimeSets(): void
    {
        $plugin = Plugin::getInstance();
        $store = $plugin->getTransformStore();
        $configService = $plugin->getProcessingConfig();
        $previousSets = $store->getSets();

        $store->replaceSetsForRuntime([
            'runtime-transform' => [
                'name' => 'runtime-transform',
                'includeEscapeWidth' => false,
                'variants' => [
                    'sm' => ['width' => 640, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ]);

        try {
            $config = $configService->getConfig();

            $this->assertArrayHasKey('sets', $config);
            $this->assertArrayHasKey('runtime-transform', $config['sets']);
        } finally {
            $store->replaceSetsForRuntime($previousSets);
        }
    }

    public function testConfigBreakpointValuesMirrorBreakpointMapOrder(): void
    {
        $config = Plugin::getInstance()->getProcessingConfig()->getConfig();

        $this->assertArrayHasKey('breakpoints', $config);
        $this->assertArrayHasKey('breakpointValues', $config);
        $this->assertArrayHasKey('processing', $config);
        $this->assertIsArray($config['processing']);
        $this->assertArrayHasKey('authorDiagnosticsEnabled', $config['processing']);
        $this->assertIsBool($config['processing']['authorDiagnosticsEnabled']);
        $this->assertSame('attributes', $config['processing']['lazyLoading']['adapter'] ?? null);
        $this->assertSame('data-srcset', $config['processing']['lazyLoading']['attributes']['srcset'] ?? null);
        $this->assertSame(array_values($config['breakpoints']), $config['breakpointValues']);
    }
}
