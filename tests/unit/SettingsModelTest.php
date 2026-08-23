<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\models\Settings;

final class SettingsModelTest extends Unit
{
    public function testDefaultSettingsAreValid(): void
    {
        $settings = new Settings();

        $this->assertTrue($settings->validate());
        $this->assertTrue($settings->previewCenter);
        $this->assertSame('none', $settings->processingLazyLoadingAdapter);
        $this->assertFalse($settings->thumbhashEnabled);
        $this->assertSame('bg', $settings->thumbhashMode);
    }

    public function testRejectsUnknownProcessingLazyLoadingAdapter(): void
    {
        $settings = new Settings();
        $settings->processingLazyLoadingAdapter = 'automatic';

        $this->assertFalse($settings->validate());
        $this->assertNotEmpty($settings->getErrors('processingLazyLoadingAdapter'));
    }

    /**
     * @dataProvider removedProcessingLazyLoadingAdapters
     */
    public function testRejectsRemovedProcessingLazyLoadingAdapters(string $adapter): void
    {
        $settings = new Settings();
        $settings->processingLazyLoadingAdapter = $adapter;

        $this->assertFalse($settings->validate());
        $this->assertNotEmpty($settings->getErrors('processingLazyLoadingAdapter'));
    }

    /**
     * @return array<string, array{string}>
     */
    public function removedProcessingLazyLoadingAdapters(): array
    {
        return [
            'attributes' => ['attributes'],
            'custom' => ['custom'],
            'vanilla-lazyload' => ['vanilla-lazyload'],
            'lozad' => ['lozad'],
        ];
    }

    public function testRejectsUnknownThumbhashMode(): void
    {
        $settings = new Settings();
        $settings->thumbhashMode = 'picture';

        $this->assertFalse($settings->validate());
        $this->assertNotEmpty($settings->getErrors('thumbhashMode'));
    }
}
