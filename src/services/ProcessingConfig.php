<?php

namespace craftyhedge\craftbreakpointimages\services;

use craftyhedge\craftbreakpointimages\Plugin;
use yii\base\Component;

class ProcessingConfig extends Component
{
    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    public function getConfig(): array
    {
        if ($this->_plugin === null) {
            return [
                'schemaVersion' => 2,
                'generatedAt' => gmdate('c'),
                'breakpoints' => [],
                'breakpointValues' => [],
                'sets' => [],
                'processing' => [
                    'authorDiagnosticsEnabled' => false,
                ],
            ];
        }

        $breakpoints = $this->_plugin->getConfigService()->getBreakpoints();

        return [
            'schemaVersion' => 2,
            'generatedAt' => gmdate('c'),
            'breakpoints' => $breakpoints,
            'breakpointValues' => array_values($breakpoints),
            'sets' => $this->_plugin->getTransformSets()->getSets(),
            'processing' => [
                'authorDiagnosticsEnabled' => $this->_plugin->getConfigService()->isProcessingDiagnosticsEnabled(),
            ],
        ];
    }
}