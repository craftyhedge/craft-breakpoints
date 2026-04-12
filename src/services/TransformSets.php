<?php

namespace craftyhedge\craftbreakpointimages\services;

use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class TransformSets extends Component
{
    public function getSets(): array
    {
        $plugin = Plugin::getInstance();

        return $plugin->getTransformStore()->getSets();
    }

    public function getSet(string $setName): ?array
    {
        $sets = $this->getSets();

        if (!isset($sets[$setName]) || !is_array($sets[$setName])) {
            return null;
        }

        return $sets[$setName];
    }
}
