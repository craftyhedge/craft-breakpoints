<?php

namespace craftyhedge\craftbreakpoints\services;

use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

class TransformSets extends Component
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function getSets(): array
    {
        $plugin = Plugin::getInstance();

        return $plugin->getTransformStore()->getSets();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSet(string $setName): ?array
    {
        $sets = $this->getSets();

        if (!isset($sets[$setName]) || !is_array($sets[$setName])) {
            return null;
        }

        return $sets[$setName];
    }
}
