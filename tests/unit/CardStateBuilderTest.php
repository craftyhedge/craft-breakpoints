<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\services\transformeditor\CardStateBuilder;

final class CardStateBuilderTest extends Unit
{
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
