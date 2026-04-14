<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpointimages\tests\unit;

use Codeception\Test\Unit;
use craft\web\assets\cp\CpAsset;
use craftyhedge\craftbreakpointimages\web\assets\transforms\TransformsAsset;

final class TransformsAssetTest extends Unit
{
    public function testAssetBundleRegistersExpectedSourceDependenciesAndFiles(): void
    {
        $assetBundle = new TransformsAsset();

        $this->assertStringEndsWith('/src/web/assets/transforms/dist', (string)$assetBundle->sourcePath);
        $this->assertDirectoryExists((string)$assetBundle->sourcePath);
        $this->assertContains(CpAsset::class, $assetBundle->depends);

        $this->assertSame(['css/transforms.css'], $assetBundle->css);
        $this->assertSame('js/vendor/datastar.js', $assetBundle->js[0][0] ?? null);
        $this->assertSame('module', $assetBundle->js[0]['type'] ?? null);
        $this->assertSame('js/transforms.js', $assetBundle->js[1][0] ?? null);
        $this->assertSame('module', $assetBundle->js[1]['type'] ?? null);

        $this->assertFileExists((string)$assetBundle->sourcePath . '/css/transforms.css');
        $this->assertFileExists((string)$assetBundle->sourcePath . '/js/vendor/datastar.js');
        $this->assertFileExists((string)$assetBundle->sourcePath . '/js/transforms.js');
    }
}
