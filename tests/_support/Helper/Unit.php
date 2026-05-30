<?php

declare(strict_types=1);

namespace Helper;

use Codeception\Module;
use Codeception\TestInterface;
use Craft;
use craftyhedge\craftbreakpoints\Plugin;

class Unit extends Module
{
    private const SETS_RELATIVE_PATH = '/breakpoints/transform-sets.json';

    private ?string $storeDir = null;

    /**
     * Isolate the file-backed transform store for every unit test.
     *
     * Unit tests that persist a set (operations, editor service) would otherwise
     * write to the tracked transform-sets.json fixture. Pointing the store at an
     * ephemeral copy (under _output/) keeps the committed fixture pristine and
     * makes runs order-independent. Mirrors Helper\Integration.
     */
    public function _before(TestInterface $test): void
    {
        $store = Plugin::getInstance()?->getTransformStore();
        if ($store === null) {
            return;
        }

        $seedPath = Craft::$app->getPath()->getConfigPath() . self::SETS_RELATIVE_PATH;
        $this->storeDir = codecept_output_dir('transform-store-' . uniqid('', true));
        $targetPath = $this->storeDir . self::SETS_RELATIVE_PATH;

        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        if (is_file($seedPath)) {
            copy($seedPath, $targetPath);
        }

        $store->configBasePath = $this->storeDir;
        $store->reload();
    }

    public function _after(TestInterface $test): void
    {
        $store = Plugin::getInstance()?->getTransformStore();
        if ($store !== null) {
            $store->configBasePath = null;
            $store->reload();
        }

        if ($this->storeDir !== null) {
            $this->removeDir($this->storeDir);
            $this->storeDir = null;
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
