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
    }
}
