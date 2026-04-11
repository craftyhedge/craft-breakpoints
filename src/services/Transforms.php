<?php

namespace craftyhedge\craftbreakpointimages\services;

use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class Transforms extends Component
{
    public function getTransforms(): array
    {
        $plugin = Plugin::getInstance();

        return $plugin->getTransformStore()->getTransforms();
    }

    public function getTransform(string $transformName): ?array
    {
        $transforms = $this->getTransforms();

        if (!isset($transforms[$transformName]) || !is_array($transforms[$transformName])) {
            return null;
        }

        return $transforms[$transformName];
    }
}
