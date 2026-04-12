<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;

final class ConfigFileTest extends Unit
{
    public function testConfigFileReturnsExpectedShapeAndDefaults(): void
    {
        $config = require CRAFT_ROOT_PATH . '/src/config.php';

        $this->assertIsArray($config);

        $this->assertArrayHasKey('breakpoints', $config);
        $this->assertArrayHasKey('escapeWidth', $config);
        $this->assertArrayHasKey('defaultWidth', $config);
        $this->assertArrayHasKey('defaultHeight', $config);
        $this->assertArrayHasKey('pictureTemplatePath', $config);
        $this->assertArrayHasKey('dpr', $config);

        $this->assertSame('craft-breakpoint-images/picture.twig', $config['pictureTemplatePath']);
        $this->assertSame('jpg', $config['format']);
        $this->assertSame('webp', $config['secondaryFormat']);
        $this->assertSame([1, 2], $config['dpr']);

        $this->assertIsArray($config['breakpoints']);
        $this->assertSame(480, $config['breakpoints']['xs'] ?? null);
        $this->assertSame(1536, $config['breakpoints']['2xl'] ?? null);

        $breakpointValues = array_values($config['breakpoints']);
        $this->assertSame($breakpointValues, array_values(array_unique($breakpointValues)));

        $sortedBreakpointValues = $breakpointValues;
        sort($sortedBreakpointValues);
        $this->assertSame($sortedBreakpointValues, $breakpointValues);

        $this->assertGreaterThan(0, (int)$config['defaultWidth']);
        $this->assertGreaterThan(0, (int)$config['defaultHeight']);
        $this->assertGreaterThan(0, (int)$config['escapeWidth']);
        $this->assertGreaterThanOrEqual(1, (int)$config['quality']);
        $this->assertLessThanOrEqual(100, (int)$config['quality']);
        $this->assertIsBool($config['nativeLazyLoadingEnabled']);
    }
}
