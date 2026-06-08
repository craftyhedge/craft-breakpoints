<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\Plugin;
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
        ]), Plugin::getInstance()->getBreakpointPolicy());

        $withoutEscape = $catalog->getDefinitionsForIncludeEscapeWidth(false);
        $withEscape = $catalog->getDefinitionsForIncludeEscapeWidth(true);

        // Labels are `base`-first; configured names follow; no `escape` key.
        // includeEscapeWidth changes only the final slot's measurement width.
        $this->assertSame(['base', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'], array_column($withoutEscape, 'key'));
        $this->assertSame([480, 640, 768, 1024, 1280, 1536, 1536], array_column($withoutEscape, 'width'));
        $this->assertSame([480, 640, 768, 1024, 1280, 1536, 1536], array_column($withoutEscape, 'measureWidth'));
        $this->assertSame(['base', 'xs', 'sm', 'md', 'lg', 'xl', '2xl'], array_column($withEscape, 'key'));
        $this->assertSame([480, 640, 768, 1024, 1280, 1536, 1536], array_column($withEscape, 'width'));
        $this->assertSame([480, 640, 768, 1024, 1280, 1536, 1920], array_column($withEscape, 'measureWidth'));
        $this->assertFalse($withEscape[6]['isEscape']);
    }

    public function testOperationResolutionRequiresCanonicalKey(): void
    {
        $catalog = new BreakpointCatalog($this->buildConfigService([
            'sm' => 640,
            'md' => 640,
        ]), Plugin::getInstance()->getBreakpointPolicy());

        $result = $catalog->resolveOperationTargetOrReject(null, null, false);

        $this->assertSame([
            'error' => 'breakpoint key is required.',
        ], $result);
    }

    /**
     * @param array<string, int> $breakpoints
     */
    private function buildConfigService(array $breakpoints): ConfigService
    {
        return new class($breakpoints) extends ConfigService {
            /**
             * @param array<string, int> $testBreakpoints
             */
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
