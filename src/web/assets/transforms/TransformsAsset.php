<?php

namespace craftyhedge\craftbreakpointimages\web\assets\transforms;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class TransformsAsset extends AssetBundle
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
        $this->js = [
            [
                'js/vendor/datastar.js',
                'type' => 'module',
            ],
            [
                'js/transforms.js',
                'type' => 'module',
            ],
        ];

        parent::init();
    }
}
