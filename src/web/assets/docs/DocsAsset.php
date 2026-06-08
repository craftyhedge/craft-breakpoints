<?php

namespace craftyhedge\craftbreakpoints\web\assets\docs;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class DocsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [
            CpAsset::class,
        ];
        $this->css = [
            'css/docs.css',
        ];

        parent::init();
    }
}
