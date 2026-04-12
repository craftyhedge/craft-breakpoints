<?php

namespace craftyhedge\craftbreakpointimages\services;

use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class ProcessingManifest extends Component
{
    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function getManifest(): array
    {
        if ($this->_plugin === null) {
            return [
                'schemaVersion' => 1,
                'generatedAt' => gmdate('c'),
                'breakpoints' => [],
                'breakpointValues' => [],
                'sets' => [],
            ];
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints();

        return [
            'schemaVersion' => 1,
            'generatedAt' => gmdate('c'),
            'breakpoints' => $breakpoints,
            'breakpointValues' => array_values($breakpoints),
            'sets' => $this->_plugin->getTransformSets()->getSets(),
        ];
    }
}