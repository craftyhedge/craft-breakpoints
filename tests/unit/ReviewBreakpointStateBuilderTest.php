<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\services\BreakpointPolicy;
use craftyhedge\craftbreakpoints\services\ConfigService;
use craftyhedge\craftbreakpoints\services\TelemetryService;
use craftyhedge\craftbreakpoints\services\TransformStore;
use craftyhedge\craftbreakpoints\services\transformeditor\HealthAnalyzer;
use craftyhedge\craftbreakpoints\services\transformeditor\ReviewBreakpointStateBuilder;
use craftyhedge\craftbreakpoints\services\transformeditor\SnapshotReader;

final class ReviewBreakpointStateBuilderTest extends Unit
{
    private function createBuilder(): ReviewBreakpointStateBuilder
    {
        $snapshotReader = new SnapshotReader(
            $this->createMock(TransformStore::class),
            $this->createMock(TelemetryService::class),
        );
        $healthAnalyzer = new HealthAnalyzer(
            $snapshotReader,
            $this->createMock(ConfigService::class),
            $this->createMock(BreakpointPolicy::class),
        );

        return new ReviewBreakpointStateBuilder($healthAnalyzer);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function renderedRows(int $width, int $height): array
    {
        return [
            [
                'enabled' => true,
                'isVisible' => true,
                'loaded' => true,
                'rendered' => ['width' => $width, 'height' => $height],
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $currentRow
     * @param array{w: int|null, h: int|null}|null $processSavedDimensions
     * @return array<string, mixed>
     */
    private function build(
        array $rows,
        array $currentRow,
        ?int $savedWidth,
        ?int $savedHeight,
        ?array $processSavedDimensions,
        string $reviewMode = 'processed',
        bool $allowHiddenDuringProcessing = false,
    ): array {
        return $this->createBuilder()->build(
            'hero',
            800,
            $rows,
            $currentRow,
            false,
            $savedWidth,
            $savedHeight,
            false,
            $allowHiddenDuringProcessing,
            false,
            $reviewMode,
            null,
            $processSavedDimensions,
        );
    }

    public function testUneditedMatchStaysGreen(): void
    {
        $state = $this->build(
            $this->renderedRows(800, 600),
            ['width' => 800, 'height' => 600, 'enabled' => true],
            800,
            600,
            ['w' => 800, 'h' => 600],
        );

        $this->assertSame('bpi_dimension-match', $state['widthClass']);
        $this->assertSame('bpi_dimension-match', $state['heightClass']);
        $this->assertSame('', $state['currentWidthEditedClass']);
        $this->assertSame('', $state['currentHeightEditedClass']);
        $this->assertFalse($state['hasBreakpointMismatch']);
    }

    public function testPreviewSrcPrefersResolvedSourceUsedOverPlaceholderSrc(): void
    {
        $state = $this->build(
            [[
                'enabled' => true,
                'isVisible' => true,
                'loaded' => true,
                'src' => 'https://example.test/placeholder.gif',
                'sourceUsed' => 'https://example.test/real-transform.webp',
                'rendered' => ['width' => 800, 'height' => 600],
            ]],
            ['width' => 800, 'height' => 600, 'enabled' => true],
            800,
            600,
            ['w' => 800, 'h' => 600],
        );

        $this->assertSame('https://example.test/real-transform.webp', $state['previewSrc']);
    }

    public function testUneditedMismatchStaysRedOnRenderedValue(): void
    {
        $state = $this->build(
            $this->renderedRows(640, 600),
            ['width' => 800, 'height' => 600, 'enabled' => true],
            800,
            600,
            ['w' => 800, 'h' => 600],
        );

        $this->assertSame('bpi_dimension-mismatch', $state['widthClass']);
        $this->assertSame('bpi_dimension-match', $state['heightClass']);
        $this->assertSame('', $state['currentWidthEditedClass']);
        $this->assertSame('', $state['currentHeightEditedClass']);
        $this->assertTrue($state['hasBreakpointMismatch']);
    }

    public function testHiddenOnlyRowsAreNeutralWhenHiddenProcessingIsAllowed(): void
    {
        $state = $this->build(
            [[
                'enabled' => true,
                'isVisible' => false,
                'loaded' => true,
                'rendered' => ['width' => 0, 'height' => 0],
            ]],
            ['width' => 800, 'height' => 600, 'enabled' => true],
            800,
            600,
            ['w' => 800, 'h' => 600],
            'processed',
            true,
        );

        $this->assertFalse($state['hasBreakpointMismatch']);
        $this->assertSame('bpi_dimension-allowed', $state['widthClass']);
        $this->assertSame('bpi_dimension-allowed', $state['heightClass']);
    }

    public function testEditedDimensionGoesStaleAndFlagsCurrentValue(): void
    {
        // Processed at w=800, h=600; user has since saved w=500. The measurement is
        // stale for width: rendered goes neutral, the edited current value is flagged.
        $state = $this->build(
            $this->renderedRows(800, 600),
            ['width' => 500, 'height' => 600, 'enabled' => true],
            500,
            600,
            ['w' => 800, 'h' => 600],
        );

        $this->assertSame('bpi_dimension-stale', $state['widthClass']);
        $this->assertSame('bpi_dimension-match', $state['heightClass']);
        $this->assertSame('bpi_current-dimension-edited', $state['currentWidthEditedClass']);
        $this->assertSame('', $state['currentHeightEditedClass']);
        $this->assertFalse($state['hasBreakpointMismatch']);
    }

    public function testEditedWidthDoesNotMaskGenuineHeightMismatch(): void
    {
        $state = $this->build(
            $this->renderedRows(800, 650),
            ['width' => 500, 'height' => 600, 'enabled' => true],
            500,
            600,
            ['w' => 800, 'h' => 600],
        );

        $this->assertSame('bpi_dimension-stale', $state['widthClass']);
        $this->assertSame('bpi_dimension-mismatch', $state['heightClass']);
        $this->assertSame('bpi_current-dimension-edited', $state['currentWidthEditedClass']);
        $this->assertSame('', $state['currentHeightEditedClass']);
        $this->assertTrue($state['hasBreakpointMismatch']);
    }

    public function testEditedValueMatchingRenderedStaysGreen(): void
    {
        // "Set to rendered" adopts the measured values: edited, but in agreement
        // with the measurement, so the match coloring is kept.
        $state = $this->build(
            $this->renderedRows(640, 480),
            ['width' => 640, 'height' => 480, 'enabled' => true],
            640,
            480,
            ['w' => 800, 'h' => 600],
        );

        $this->assertSame('bpi_dimension-match', $state['widthClass']);
        $this->assertSame('bpi_dimension-match', $state['heightClass']);
        $this->assertSame('', $state['currentWidthEditedClass']);
        $this->assertSame('', $state['currentHeightEditedClass']);
        $this->assertFalse($state['hasBreakpointMismatch']);
    }

    public function testMismatchStaysRedWithoutProcessBaseline(): void
    {
        $state = $this->build(
            $this->renderedRows(800, 600),
            ['width' => 500, 'height' => 600, 'enabled' => true],
            500,
            600,
            null,
        );

        $this->assertSame('bpi_dimension-mismatch', $state['widthClass']);
        $this->assertSame('', $state['currentWidthEditedClass']);
        $this->assertTrue($state['hasBreakpointMismatch']);
    }

    public function testStaleDetectionOnlyAppliesInProcessedReviewMode(): void
    {
        $state = $this->build(
            $this->renderedRows(800, 600),
            ['width' => 500, 'height' => 600, 'enabled' => true],
            500,
            600,
            ['w' => 800, 'h' => 600],
            'saved',
        );

        $this->assertSame('bpi_dimension-mismatch', $state['widthClass']);
        $this->assertSame('', $state['currentWidthEditedClass']);
    }

    public function testAutoDimensionIsNotTreatedAsEdited(): void
    {
        // Width was auto at process time (baseline w=null) and is still auto now.
        $state = $this->build(
            $this->renderedRows(800, 600),
            ['width' => null, 'height' => 600, 'autoDimension' => 'width', 'enabled' => true],
            null,
            600,
            ['w' => null, 'h' => 600],
        );

        $this->assertSame('bpi_dimension-auto', $state['widthClass']);
        $this->assertSame('', $state['currentWidthEditedClass']);
        $this->assertFalse($state['hasBreakpointMismatch']);
    }
}
