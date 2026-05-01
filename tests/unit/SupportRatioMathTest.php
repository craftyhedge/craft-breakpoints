<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\services\transformeditor\Support;

final class SupportRatioMathTest extends Unit
{
    public function testApproximateRatioPairFromFloatReducesToExpectedFraction(): void
    {
        $pair = Support::approximateRatioPairFromFloat(16 / 9);

        $this->assertNotNull($pair);
        $this->assertSame(16, $pair['width']);
        $this->assertSame(9, $pair['height']);
    }

    public function testApproximateRatioPairFromFloatRejectsInvalidValues(): void
    {
        $this->assertNull(Support::approximateRatioPairFromFloat(null));
        $this->assertNull(Support::approximateRatioPairFromFloat(0.0));
        $this->assertNull(Support::approximateRatioPairFromFloat(-1.0));
    }

    public function testParseNullablePositiveFloat(): void
    {
        $this->assertSame(1.25, Support::parseNullablePositiveFloat('1.25'));
        $this->assertSame(2.0, Support::parseNullablePositiveFloat(2));
        $this->assertNull(Support::parseNullablePositiveFloat(''));
        $this->assertNull(Support::parseNullablePositiveFloat('abc'));
        $this->assertNull(Support::parseNullablePositiveFloat('0'));
    }
}
