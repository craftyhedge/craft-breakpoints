<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\services\transformeditor\CardStateBuilder;

final class CardStateBuilderTest extends Unit
{
    public function testBuildReturnsEmptyScopeValuesInAllScopeMode(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 320,
                    'height' => 180,
                    'autoDimension' => null,
                    'enabled' => true,
                ],
            ],
            [640],
            ['mode' => 'all'],
            'ratio',
        );

        $this->assertSame(['mode' => 'all', 'breakpoint' => null], $state['scope']);
        $this->assertSame([
            'widthInput' => '',
            'heightInput' => '',
            'widthAuto' => '0',
            'heightAuto' => '0',
            'ratioLocked' => '0',
            'ratioWidthInput' => '',
            'ratioHeightInput' => '',
            'ratioFloatInput' => '',
            'ratioSourceDimension' => 'width',
        ], $state['scopeValues']);
        $this->assertSame('ratio', $state['tab']);
    }

    public function testBuildReturnsAggregateAutoStateInAllScopeMode(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => null,
                    'height' => 180,
                    'autoDimension' => 'width',
                    'enabled' => true,
                ],
                1024 => [
                    'width' => null,
                    'height' => 240,
                    'autoDimension' => 'width',
                    'enabled' => true,
                ],
                1440 => [
                    'width' => null,
                    'height' => 360,
                    'autoDimension' => null,
                    'enabled' => false,
                ],
            ],
            [640, 1024, 1440],
            ['mode' => 'all'],
            'ratio',
        );

        $this->assertSame('1', $state['scopeValues']['widthAuto'] ?? null);
        $this->assertSame('0', $state['scopeValues']['heightAuto'] ?? null);
        $this->assertSame('dimensions', $state['tab']);
    }

    public function testBuildReturnsSharedLockedRatioInAllScopeMode(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 640,
                    'height' => 360,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => true,
                    'ratioWidth' => 16,
                    'ratioHeight' => 9,
                    'ratioSourceDimension' => 'width',
                ],
                1024 => [
                    'width' => 1024,
                    'height' => 576,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => true,
                    'ratioWidth' => 16,
                    'ratioHeight' => 9,
                    'ratioSourceDimension' => 'width',
                ],
            ],
            [640, 1024],
            ['mode' => 'all'],
            'ratio',
        );

        $this->assertSame('1', $state['scopeValues']['ratioLocked']);
        $this->assertSame('16', $state['scopeValues']['ratioWidthInput']);
        $this->assertSame('9', $state['scopeValues']['ratioHeightInput']);
        $this->assertSame('1.7778', $state['scopeValues']['ratioFloatInput']);
        $this->assertSame('width', $state['scopeValues']['ratioSourceDimension']);
        $this->assertSame('ratio', $state['tab']);
    }

    public function testBuildLeavesAllScopeRatioEmptyWhenEnabledRatiosDiffer(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 640,
                    'height' => 360,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => true,
                    'ratioWidth' => 16,
                    'ratioHeight' => 9,
                    'ratioSourceDimension' => 'width',
                ],
                1024 => [
                    'width' => 1024,
                    'height' => 768,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => true,
                    'ratioWidth' => 4,
                    'ratioHeight' => 3,
                    'ratioSourceDimension' => 'width',
                ],
            ],
            [640, 1024],
            ['mode' => 'all'],
            'ratio',
        );

        $this->assertSame('0', $state['scopeValues']['ratioLocked']);
        $this->assertSame('', $state['scopeValues']['ratioWidthInput']);
        $this->assertSame('', $state['scopeValues']['ratioHeightInput']);
        $this->assertSame('', $state['scopeValues']['ratioFloatInput']);
    }

    public function testBuildLeavesAllScopeRatioEmptyWhenAnyEnabledRowIsUnlocked(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 640,
                    'height' => 360,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => true,
                    'ratioWidth' => 16,
                    'ratioHeight' => 9,
                    'ratioSourceDimension' => 'width',
                ],
                1024 => [
                    'width' => 1024,
                    'height' => 576,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => false,
                    'ratioWidth' => 16,
                    'ratioHeight' => 9,
                    'ratioSourceDimension' => 'width',
                ],
            ],
            [640, 1024],
            ['mode' => 'all'],
            'ratio',
        );

        $this->assertSame('0', $state['scopeValues']['ratioLocked']);
        $this->assertSame('', $state['scopeValues']['ratioWidthInput']);
        $this->assertSame('', $state['scopeValues']['ratioHeightInput']);
    }

    public function testBuildIgnoresDisabledRowsForSharedAllScopeRatio(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 640,
                    'height' => 360,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => true,
                    'ratioWidth' => 16,
                    'ratioHeight' => 9,
                    'ratioSourceDimension' => 'width',
                ],
                1024 => [
                    'width' => 1024,
                    'height' => 768,
                    'autoDimension' => null,
                    'enabled' => false,
                    'ratioLocked' => true,
                    'ratioWidth' => 4,
                    'ratioHeight' => 3,
                    'ratioSourceDimension' => 'width',
                ],
            ],
            [640, 1024],
            ['mode' => 'all'],
            'ratio',
        );

        $this->assertSame('1', $state['scopeValues']['ratioLocked']);
        $this->assertSame('16', $state['scopeValues']['ratioWidthInput']);
        $this->assertSame('9', $state['scopeValues']['ratioHeightInput']);
    }

    public function testBuildLeavesAllScopeRatioEmptyWhenSourceDimensionDiffers(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 640,
                    'height' => 360,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => true,
                    'ratioWidth' => 16,
                    'ratioHeight' => 9,
                    'ratioSourceDimension' => 'width',
                ],
                1024 => [
                    'width' => 1024,
                    'height' => 576,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => true,
                    'ratioWidth' => 16,
                    'ratioHeight' => 9,
                    'ratioSourceDimension' => 'height',
                ],
            ],
            [640, 1024],
            ['mode' => 'all'],
            'ratio',
        );

        $this->assertSame('0', $state['scopeValues']['ratioLocked']);
        $this->assertSame('', $state['scopeValues']['ratioWidthInput']);
        $this->assertSame('', $state['scopeValues']['ratioHeightInput']);
    }

    public function testBuildFallsBackToFirstConfiguredBreakpointWhenScopeBreakpointIsInvalid(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 320,
                    'height' => 180,
                    'autoDimension' => null,
                    'enabled' => true,
                ],
            ],
            [640, 1024],
            ['mode' => 'breakpoint', 'breakpoint' => 9999],
            'dimensions',
        );

        $this->assertSame(['mode' => 'breakpoint', 'breakpoint' => 640], $state['scope']);
    }

    public function testBuildCreatesDefaultStateForConfiguredBreakpointWithoutCurrentRow(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 320,
                    'height' => 180,
                    'autoDimension' => null,
                    'enabled' => true,
                ],
            ],
            [640, 1024],
            ['mode' => 'breakpoint', 'breakpoint' => 1024],
            'dimensions',
        );

        $this->assertSame('', $state['scopeValues']['widthInput']);
        $this->assertSame('', $state['scopeValues']['heightInput']);
        $this->assertSame('0', $state['scopeValues']['widthAuto']);
        $this->assertSame('0', $state['scopeValues']['heightAuto']);
        $this->assertTrue(($state['rowsByBreakpoint']['1024']['enabled'] ?? false) === true);
    }

    public function testBuildUsesLockedRatioInputsAndSourceDimensionWhenRatioIsLocked(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 320,
                    'height' => 180,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => true,
                    'ratioWidth' => 16,
                    'ratioHeight' => 9,
                    'ratioSourceDimension' => 'height',
                ],
            ],
            [640],
            ['mode' => 'breakpoint', 'breakpoint' => 640],
            'ratio',
        );

        $this->assertSame('1', $state['scopeValues']['ratioLocked']);
        $this->assertSame('16', $state['scopeValues']['ratioWidthInput']);
        $this->assertSame('9', $state['scopeValues']['ratioHeightInput']);
        $this->assertSame('1.7778', $state['scopeValues']['ratioFloatInput']);
        $this->assertSame('height', $state['scopeValues']['ratioSourceDimension']);
    }

    public function testBuildResolvesRatioInputsFromDimensionsWhenRatioIsUnlocked(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 320,
                    'height' => 180,
                    'autoDimension' => null,
                    'enabled' => true,
                    'ratioLocked' => false,
                    'ratioWidth' => 4,
                    'ratioHeight' => 3,
                    'ratioSourceDimension' => 'height',
                ],
            ],
            [640],
            ['mode' => 'breakpoint', 'breakpoint' => 640],
            'ratio',
        );

        $this->assertSame('0', $state['scopeValues']['ratioLocked']);
        $this->assertSame('320', $state['scopeValues']['ratioWidthInput']);
        $this->assertSame('180', $state['scopeValues']['ratioHeightInput']);
        $this->assertSame('1.7778', $state['scopeValues']['ratioFloatInput']);
        $this->assertSame('width', $state['scopeValues']['ratioSourceDimension']);
    }

    public function testBuildForcesDimensionsTabWhenRatioRequestedAndWidthIsAuto(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => null,
                    'height' => 240,
                    'autoDimension' => 'width',
                    'enabled' => true,
                ],
            ],
            [640],
            ['mode' => 'breakpoint', 'breakpoint' => 640],
            'ratio',
        );

        $this->assertSame('dimensions', $state['tab']);
    }

    public function testBuildForcesDimensionsTabWhenRatioRequestedAndHeightIsAuto(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 320,
                    'height' => null,
                    'autoDimension' => 'height',
                    'enabled' => true,
                ],
            ],
            [640],
            ['mode' => 'breakpoint', 'breakpoint' => 640],
            'ratio',
        );

        $this->assertSame('dimensions', $state['tab']);
    }

    public function testBuildKeepsRatioTabWhenDimensionsAreManual(): void
    {
        $builder = new CardStateBuilder();

        $state = $builder->build(
            [
                640 => [
                    'width' => 320,
                    'height' => 180,
                    'autoDimension' => null,
                    'enabled' => true,
                ],
            ],
            [640],
            ['mode' => 'breakpoint', 'breakpoint' => 640],
            'ratio',
        );

        $this->assertSame('ratio', $state['tab']);
    }
}
