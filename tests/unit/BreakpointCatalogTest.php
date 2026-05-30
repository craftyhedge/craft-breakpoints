<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\services\ConfigService;
use craftyhedge\craftbreakpoints\services\transformeditor\BreakpointCatalog;

final class BreakpointCatalogTest extends Unit
{
    public function testDefinitionsUseBaseFirstLabelsAndDropEscapeKey(): void
    {
        $catalog = new BreakpointCatalog($this->buildConfigService([
            'xs' => 480,
            'sm' => 640,
            'escape' => 1281,
        ]));

        $withoutEscape = $catalog->getDefinitionsForIncludeEscapeWidth(false);
        $withEscape = $catalog->getDefinitionsForIncludeEscapeWidth(true);

        // Labels are `base`-first; configured names shift down; no `escape` key.
        // Widths stay paired to slots by position.
        $this->assertSame(['base', 'xs'], array_column($withoutEscape, 'key'));
        $this->assertSame([480, 640], array_column($withoutEscape, 'width'));
        $this->assertSame(['base', 'xs', 'sm'], array_column($withEscape, 'key'));
        $this->assertSame([480, 640, 1281], array_column($withEscape, 'width'));
        $this->assertFalse($withEscape[2]['isEscape']);
    }

    public function testNumericWidthResolutionRejectsDuplicateActiveWidths(): void
    {
        $catalog = new BreakpointCatalog($this->buildConfigService([
            'sm' => 640,
            'md' => 640,
        ]));

        $result = $catalog->resolveOperationTargetOrReject(null, 640, false);

        $this->assertSame([
            'error' => 'Ambiguous breakpoint: multiple breakpoints have the same width.',
        ], $result);
        $this->assertNull($catalog->findDefinitionByWidth(640, false));
    }

    private function buildConfigService(array $breakpoints): ConfigService
    {
        return new class($breakpoints) extends ConfigService {
            public function __construct(private readonly array $testBreakpoints)
            {
            }

            public function getBreakpoints(array $config = []): array
            {
                return $this->testBreakpoints;
            }
        };
    }
}
