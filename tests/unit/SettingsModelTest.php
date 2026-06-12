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
        $this->assertSame('attributes', $settings->processingLazyLoadingAdapter);
    }

    public function testRejectsUnknownProcessingLazyLoadingAdapter(): void
    {
        $settings = new Settings();
        $settings->processingLazyLoadingAdapter = 'automatic';

        $this->assertFalse($settings->validate());
        $this->assertNotEmpty($settings->getErrors('processingLazyLoadingAdapter'));
    }

    public function testCustomProcessingAdapterRequiresHandler(): void
    {
        $settings = new Settings();
        $settings->processingLazyLoadingAdapter = 'custom';

        $this->assertFalse($settings->validate());
        $this->assertNotEmpty($settings->getErrors('processingLazyLoadingCustomHandler'));
    }
}
