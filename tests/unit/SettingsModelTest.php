<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpointimages\models\Settings;

final class SettingsModelTest extends Unit
{
    public function testDefaultSettingsAreValid(): void
    {
        $settings = new Settings();

        $this->assertTrue($settings->validate());
        $this->assertTrue($settings->previewCenter);
    }
}
