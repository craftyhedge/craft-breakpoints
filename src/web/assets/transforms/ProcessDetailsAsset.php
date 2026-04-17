<?php

namespace craftyhedge\craftbreakpointimages\web\assets\transforms;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ProcessDetailsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [
            CpAsset::class,
        ];
        $this->css = [
            'css/transforms.css',
        ];
        $this->js = [];

        parent::init();
    }
}
