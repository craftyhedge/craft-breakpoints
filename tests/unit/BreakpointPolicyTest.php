<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\Plugin;

final class BreakpointPolicyTest extends Unit
{
    public function testNamedTransformCanEnableEscapeWidthByDefault(): void
    {
        $this->withTemporaryTransforms([
            'escape-enabled' => [
                'name' => 'escape-enabled',
                'includeEscapeWidth' => true,
                'transforms' => [
                    ['width' => 480, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function(Plugin $plugin): void {
            $policy = $plugin->getBreakpointPolicy();
            $config = [
                'transformName' => 'escape-enabled',
                'breakpoints' => ['xs' => 480],
                'escapeWidth' => 1920,
            ];
            $mergedConfig = $plugin->getConfigService()->getConfig($config);

            $breakpoints = $policy->getBreakpointsForSet($config, $mergedConfig);

            $this->assertArrayNotHasKey('escape', $breakpoints);
            $this->assertSame([
                'base' => 480,
                'xs' => 640,
                'sm' => 768,
                'md' => 1024,
                'lg' => 1280,
                'xl' => 1536,
                '2xl' => 1536,
            ], $breakpoints);
        });
    }

    public function testInlineEscapeOverrideTakesPrecedenceOverNamedTransform(): void
    {
        $this->withTemporaryTransforms([
            'escape-enabled' => [
                'name' => 'escape-enabled',
                'includeEscapeWidth' => true,
                'transforms' => [
                    ['width' => 480, 'height' => null, 'enabled' => true, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function(Plugin $plugin): void {
            $policy = $plugin->getBreakpointPolicy();
            $config = [
                'transformName' => 'escape-enabled',
                'includeEscapeWidth' => false,
                'breakpoints' => ['xs' => 480],
                'escapeWidth' => 1920,
            ];
            $mergedConfig = $plugin->getConfigService()->getConfig($config);

            $breakpoints = $policy->getBreakpointsForSet($config, $mergedConfig);

            $this->assertArrayNotHasKey('escape', $breakpoints);
            $this->assertSame([
                'base' => 480,
                'xs' => 640,
                'sm' => 768,
                'md' => 1024,
                'lg' => 1280,
                'xl' => 1536,
                '2xl' => 1536,
            ], $breakpoints);
        });
    }

    public function testDisabledStateUsesNamedTransformEntryEnabledFlag(): void
    {
        $this->withTemporaryTransforms([
            'entry-disabled' => [
                'name' => 'entry-disabled',
                'includeEscapeWidth' => false,
                'transforms' => [
                    ['width' => 480, 'height' => null, 'enabled' => false, 'autoDimension' => null],
                ],
                'config' => [],
            ],
        ], function(Plugin $plugin): void {
            $policy = $plugin->getBreakpointPolicy();

            $isDisabled = $policy->isBreakpointDisabled('xs', 0, [
                'transformName' => 'entry-disabled',
            ]);

            $this->assertTrue($isDisabled);
        });
    }

    private function withTemporaryTransforms(array $transforms, callable $callback): void
    {
        $plugin = Plugin::getInstance();
        $store = $plugin->getTransformStore();
        $previousTransforms = $store->getTransforms();

        $store->replaceTransformsForRuntime($transforms);

        try {
            $callback($plugin);
        } finally {
            $store->replaceTransformsForRuntime($previousTransforms);
        }
    }
}
