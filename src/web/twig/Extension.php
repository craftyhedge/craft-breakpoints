<?php

namespace craftyhedge\craftbreakpoints\web\twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use craftyhedge\craftbreakpoints\Plugin;

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
