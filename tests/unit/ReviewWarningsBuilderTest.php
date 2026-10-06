<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\Plugin;
use craftyhedge\craftbreakpoints\services\transformeditor\ReviewWarningsBuilder;
use craftyhedge\craftbreakpoints\services\transformeditor\SnapshotReader;

final class ReviewWarningsBuilderTest extends Unit
{
    public function testWarnsOnceNamingDivergentTemplatesForDifferentStacks(): void
    {
        $warnings = $this->buildOriginWarnings([
            1 => [
                $this->row('cta-image', '1', ['_layout', 'mega-menus', 'mega-menu-cta']),
                $this->row('cta-image', '2', ['_layout', 'nav-mobile', 'mega-menu-cta']),
            ],
            2 => [
                $this->row('cta-image', '1', ['_layout', 'mega-menus', 'mega-menu-cta']),
                $this->row('cta-image', '2', ['_layout', 'nav-mobile', 'mega-menu-cta']),
            ],
        ]);

        $this->assertCount(1, $warnings['cta-image'] ?? []);
        $warning = $warnings['cta-image'][0];
        $this->assertSame(ReviewWarningsBuilder::MULTIPLE_INCLUDE_ORIGINS_CODE, $warning['code']);
        $this->assertSame(['mega-menus', 'nav-mobile'], $warning['origins']);
        $this->assertSame('', $warning['message']);
        $this->assertNotSame('', $warning['hint']);
    }

    public function testNoWarningForCopiesSharingOneStack(): void
    {
        $warnings = $this->buildOriginWarnings([
            1 => [
                $this->row('cta-image', '1', ['_layout', 'mega-menus', 'mega-menu-cta']),
                $this->row('cta-image', '2', ['_layout', 'mega-menus', 'mega-menu-cta']),
            ],
        ]);

        $this->assertSame([], $warnings);
    }

    public function testEmptyStackNeverCountsAsAnOrigin(): void
    {
        $warnings = $this->buildOriginWarnings([
            1 => [
                $this->row('cta-image', '1', ['_layout', 'mega-menus', 'mega-menu-cta']),
                $this->row('cta-image', '2', []),
                $this->row('cta-image', '3', null),
            ],
        ]);

        $this->assertSame([], $warnings);
    }

    public function testResolveDivergentOriginsUsesFirstIndexWhereNotAllAgree(): void
    {
        $this->assertSame(
            ['header', 'footer'],
            ReviewWarningsBuilder::resolveDivergentOrigins([
                ['_layout', 'header', 'cta'],
                ['_layout', 'footer', 'cta'],
                ['_layout', 'header', 'cta'],
            ]),
        );

        $this->assertSame(
            ['card', 'hero'],
            ReviewWarningsBuilder::resolveDivergentOrigins([
                ['page', 'grid', 'card'],
                ['page', 'grid', 'hero'],
            ]),
        );
    }

    public function testResolveDivergentOriginsFallsBackToLeafForShorterStack(): void
    {
        $this->assertSame(
            ['grid', 'card'],
            ReviewWarningsBuilder::resolveDivergentOrigins([
                ['page', 'grid'],
                ['page', 'grid', 'card'],
            ]),
        );
    }

    public function testResolveDivergentOriginsDescribesLevelsWhenOnlyLayoutsDiffer(): void
    {
        $this->assertSame(
            ['page (extends _layout)', 'page (extends _wide)'],
            ReviewWarningsBuilder::resolveDivergentOrigins([
                [['template' => 'page', 'extends' => ['_layout']], ['template' => 'card']],
                [['template' => 'page', 'extends' => ['_wide']], ['template' => 'card']],
            ]),
        );
    }

    public function testAdvisoryWarningsDoNotCountOrMarkDanger(): void
    {
        $advisory = ReviewWarningsBuilder::buildMultipleIncludeOriginsWarning(['a', 'b']);
        $other = ['code' => 'empty-enabled-breakpoints', 'message' => 'x'];

        $this->assertSame(1, ReviewWarningsBuilder::countWarningsByTransform([
            'cta-image' => [$advisory],
            'hero' => [$other],
        ]));
        $this->assertFalse(ReviewWarningsBuilder::hasNonAdvisoryWarning([$advisory]));
        $this->assertTrue(ReviewWarningsBuilder::hasNonAdvisoryWarning([$advisory, $other]));
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $rowsByBreakpoint
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function buildOriginWarnings(array $rowsByBreakpoint): array
    {
        $plugin = Plugin::getInstance();
        $builder = new ReviewWarningsBuilder(
            new SnapshotReader($plugin->getTransformStore(), $plugin->getTelemetry()),
            $plugin->getConfigService(),
            $plugin->getTelemetry(),
            $plugin->getBreakpointPolicy(),
        );

        // Stored so the missing-set path (which reads the latest DB snapshot) is skipped.
        $warnings = $builder->buildWarningsByTransform($rowsByBreakpoint, ['cta-image' => ['transforms' => []]]);

        $originWarnings = [];
        foreach ($warnings as $transformName => $transformWarnings) {
            foreach ($transformWarnings as $warning) {
                if (($warning['code'] ?? '') === ReviewWarningsBuilder::MULTIPLE_INCLUDE_ORIGINS_CODE) {
                    $originWarnings[$transformName][] = $warning;
                }
            }
        }

        return $originWarnings;
    }

    /**
     * @param string[]|null $includeStack
     * @return array<string, mixed>
     */
    private function row(string $transform, string $instance, ?array $includeStack): array
    {
        return [
            'transform' => $transform,
            'instance' => $instance,
            'assetId' => '893',
            'includeStack' => $includeStack,
        ];
    }
}
