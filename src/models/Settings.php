<?php

namespace craftyhedge\craftbreakpoints\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * @var array<string, int>
     */
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
    public bool $priority = false;
    public bool $preload = false;
    public bool $thumbhashEnabled = false;
    public string $thumbhashMode = 'srcset';
    public string $processingLazyLoadingAdapter = 'none';
    public bool $previewCenter = true;
    /**
     * @var array<int, int|float>
     */
    public array $dpr = [1];

    /**
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [[
                'mode',
                'position',
                'format',
                'secondaryFormat',
                'interlace',
                'pictureTemplatePath',
                'svgTemplatePath',
                'thumbhashMode',
                'processingLazyLoadingAdapter',
            ], 'string'],
            ['thumbhashMode', 'in', 'range' => ['bg', 'srcset']],
            ['processingLazyLoadingAdapter', 'in', 'range' => ['none', 'lazysizes']],
            [['escapeWidth', 'defaultWidth', 'defaultHeight', 'quality', 'allowUpscale'], 'integer', 'min' => 0],
            [['nativeLazyLoadingEnabled', 'priority', 'preload', 'thumbhashEnabled', 'previewCenter'], 'boolean'],
            [['breakpoints', 'dpr'], 'safe'],
            ['quality', 'integer', 'min' => 1, 'max' => 100],
        ]);
    }
}
