<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpointimages\Plugin;

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

            $this->assertArrayHasKey('escape', $breakpoints);
            $this->assertSame(1920, $breakpoints['escape']);
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

            $isDisabled = $policy->isBreakpointDisabled('xs', [
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
