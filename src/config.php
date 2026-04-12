<?php

return [
    'breakpoints' => [
        'xs' => 480,
        'sm' => 640,
        'md' => 768,
        'lg' => 1024,
        'xl' => 1280,
        '2xl' => 1536,
    ],
    'escapeWidth' => 1920,
    'defaultWidth' => 1600,
    'defaultHeight' => 900,
    'mode' => 'crop',
    'position' => 'center-center',
    'quality' => 80,
    'format' => 'jpg',
    'secondaryFormat' => 'none',
    'interlace' => 'none',
    'allowUpscale' => 0,
    'pictureTemplatePath' => 'craft-breakpoint-images/picture.twig',
    'nativeLazyLoadingEnabled' => true,
    'dpr' => [1, 2],
];
