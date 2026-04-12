<?php

namespace craftyhedge\craftbreakpointimages\web\twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use craftyhedge\craftbreakpointimages\Plugin;

class Extension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('image', function ($image, string $setName, array $config = []) {
                return Plugin::getInstance()->getImages()->render($image, $setName, $config);
            }),
        ];
    }
}
