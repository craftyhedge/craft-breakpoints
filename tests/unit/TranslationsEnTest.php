<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;

final class TranslationsEnTest extends Unit
{
    public function testEnglishTranslationsContainExpectedKeysAndValues(): void
    {
        $translations = require CRAFT_ROOT_PATH . '/src/translations/en/craft-breakpoint-images.php';

        $this->assertIsArray($translations);
        $this->assertArrayHasKey('Settings', $translations);
        $this->assertArrayHasKey('Transforms', $translations);
        $this->assertArrayHasKey('Go to settings', $translations);

        $this->assertNotSame('', trim((string)($translations['Settings'] ?? '')));
        $this->assertNotSame('', trim((string)($translations['Transforms'] ?? '')));
        $this->assertNotSame('', trim((string)($translations['Go to settings'] ?? '')));

        foreach ($translations as $key => $value) {
            $this->assertIsString($key);
            $this->assertNotSame('', trim($key));
            $this->assertIsString($value);
            $this->assertNotSame('', trim($value));

            // English map currently uses identity translations for shipped keys.
            $this->assertSame($key, $value);
        }
    }
}
