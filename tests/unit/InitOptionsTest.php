<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\services\InitOptions;

final class InitOptionsTest extends Unit
{
    public function testParsesRatioFromStringFraction(): void
    {
        $options = InitOptions::fromConfig([
            'initRatio' => '16:9',
        ], false);

        $this->assertNotNull($options->ratio);
        $this->assertEqualsWithDelta(16 / 9, (float)$options->ratio, 0.0001);
    }

    public function testParsesRatioFromStringDecimalFractionPart(): void
    {
        $options = InitOptions::fromConfig([
            'initRatio' => '1.5:1',
        ], false);

        $this->assertNotNull($options->ratio);
        $this->assertEqualsWithDelta(1.5, (float)$options->ratio, 0.0001);
    }

    public function testParsesRatioFromNumericValue(): void
    {
        $options = InitOptions::fromConfig([
            'initRatio' => 0.5625,
        ], false);

        $this->assertSame(0.5625, $options->ratio);
    }

    public function testParsesRatioFromNumericString(): void
    {
        $options = InitOptions::fromConfig([
            'initRatio' => '1.77778',
        ], false);

        $this->assertNotNull($options->ratio);
        $this->assertEqualsWithDelta(1.77778, (float)$options->ratio, 0.00001);
    }

    /**
     * @dataProvider invalidRatioProvider
     */
    public function testInvalidRatioInputsResolveToNull(mixed $value): void
    {
        $options = InitOptions::fromConfig([
            'initRatio' => $value,
        ], false);

        $this->assertNull($options->ratio);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public function invalidRatioProvider(): array
    {
        return [
            'zero numerator' => ['0:5'],
            'zero denominator' => ['5:0'],
            'nonnumeric string' => ['abc'],
            'negative part' => ['-1:2'],
            'empty string' => [''],
            'null' => [null],
        ];
    }

    public function testSavedSetGateNulsInitFieldsAndAutoFlags(): void
    {
        $options = InitOptions::fromConfig([
            'initWidth' => 300,
            'initHeight' => 200,
            'initRatio' => '16:9',
            'initWidthAuto' => true,
            'initHeightAuto' => true,
        ], true);

        $this->assertNull($options->width);
        $this->assertNull($options->height);
        $this->assertNull($options->ratio);
        $this->assertFalse($options->widthAuto);
        $this->assertFalse($options->heightAuto);
    }

    public function testBothDimensionsSetIgnoreRatio(): void
    {
        $options = InitOptions::fromConfig([
            'initWidth' => 320,
            'initHeight' => 200,
            'initRatio' => '16:9',
        ], false);

        $this->assertSame(320, $options->width);
        $this->assertSame(200, $options->height);
        $this->assertNull($options->ratio);
    }

    public function testWidthAutoTakesPrecedenceOverHeightAuto(): void
    {
        $options = InitOptions::fromConfig([
            'initWidthAuto' => true,
            'initHeightAuto' => true,
        ], false);

        $this->assertTrue($options->widthAuto);
        $this->assertFalse($options->heightAuto);
    }
}
