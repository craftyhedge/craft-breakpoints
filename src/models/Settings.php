<?php

namespace craftyhedge\craftbreakpointimages\models;

use craft\base\Model;

class Settings extends Model
{
    public array $breakpoints = [
        'xs' => 480,
        'sm' => 640,
        'md' => 768,
        'lg' => 1024,
        'xl' => 1280,
        '2xl' => 1536,
    ];

    public int $escapeWidth = 0;
    public int $defaultWidth = 1600;
    public int $defaultHeight = 900;

    public string $mode = 'crop';
    public string $position = 'center-center';
    public int $quality = 80;
    public string $format = 'jpg';
    public string $secondaryFormat = 'none';
    public string $interlace = 'none';
    public int $allowUpscale = 0;

    public string $pictureTemplatePath = '';
    public string $svgTemplatePath = '';
    public bool $nativeLazyLoadingEnabled = true;
    public array $dpr = [1];

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['mode', 'position', 'format', 'secondaryFormat', 'interlace', 'pictureTemplatePath', 'svgTemplatePath'], 'string'],
            [['escapeWidth', 'defaultWidth', 'defaultHeight', 'quality', 'allowUpscale'], 'integer', 'min' => 0],
            [['nativeLazyLoadingEnabled'], 'boolean'],
            [['breakpoints', 'dpr'], 'safe'],
            ['quality', 'integer', 'min' => 1, 'max' => 100],
        ]);
    }
}