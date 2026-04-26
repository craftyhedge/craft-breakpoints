<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\controllers\TransformsController;
use craftyhedge\craftbreakpoints\services\InitOptions;

final class ParsingSupportDelegationTest extends Unit
{
    public function testTransformsControllerDoesNotContainLegacyInlineParsers(): void
    {
        $this->assertFalse(method_exists(TransformsController::class, 'parseNullableBool'));
        $this->assertFalse(method_exists(TransformsController::class, 'parseNullablePositiveInt'));
        $this->assertFalse(method_exists(TransformsController::class, 'parseNullableNonEmptyString'));
    }

    public function testInitOptionsUsesSupportParsingContract(): void
    {
        $options = InitOptions::fromConfig([
            'initWidth' => '320',
            'initHeight' => '  ',
            'initRatio' => '16:9',
            'initWidthAuto' => 'yes',
            'initHeightAuto' => 'on',
        ], false);

        $this->assertSame(320, $options->width);
        $this->assertNull($options->height);
        $this->assertTrue($options->widthAuto);
        $this->assertFalse($options->heightAuto, 'Width auto must win mutual exclusion.');
        $this->assertNotNull($options->ratio);
    }
}
