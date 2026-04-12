<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpointimages\Plugin;

final class ProcessingManifestServiceTest extends Unit
{
    public function testManifestUsesCurrentRuntimeSets(): void
    {
        $plugin = Plugin::getInstance();
        $store = $plugin->getTransformStore();
        $manifestService = $plugin->getProcessingManifest();
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
            $manifest = $manifestService->getManifest();

            $this->assertArrayHasKey('sets', $manifest);
            $this->assertArrayHasKey('runtime-transform', $manifest['sets']);
        } finally {
            $store->replaceSetsForRuntime($previousSets);
        }
    }

    public function testManifestBreakpointValuesMirrorBreakpointMapOrder(): void
    {
        $manifest = Plugin::getInstance()->getProcessingManifest()->getManifest();

        $this->assertArrayHasKey('breakpoints', $manifest);
        $this->assertArrayHasKey('breakpointValues', $manifest);
        $this->assertSame(array_values($manifest['breakpoints']), $manifest['breakpointValues']);
    }
}
