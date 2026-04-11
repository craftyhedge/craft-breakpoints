<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpointimages\models\Settings;
use craftyhedge\craftbreakpointimages\Plugin;
use craftyhedge\craftbreakpointimages\utilities\BreakpointImagesUtility;

final class PluginRegistrationTest extends Unit
{
    public function testPluginInstanceIsAvailable(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertSame('Breakpoint Images', $plugin->name);
    }

    public function testPluginReturnsSettingsModel(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertInstanceOf(Settings::class, $plugin->getSettings());
    }

    public function testUtilityTypeIsRegistered(): void
    {
        $utilityTypes = Craft::$app->getUtilities()->getAllUtilityTypes();

        $this->assertTrue(in_array(BreakpointImagesUtility::class, $utilityTypes, true));
    }

    public function testUtilityIdentityMethods(): void
    {
        $this->assertSame('breakpoint-images', BreakpointImagesUtility::id());
        $this->assertNull(BreakpointImagesUtility::icon());
    }
}
