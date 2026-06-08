<?php

namespace craftyhedge\craftbreakpoints\services;

use craftyhedge\craftbreakpoints\Plugin;
use yii\base\Component;

class ProcessingConfig extends Component
{
    private ?Plugin $_plugin = null;

    public function init(): void
    {
        parent::init();
        $this->_plugin = Plugin::getInstance();
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if ($this->_plugin === null) {
            return [
                'schemaVersion' => 2,
                'generatedAt' => gmdate('c'),
                'breakpoints' => [],
                'breakpointValues' => [],
                'breakpointSlots' => [],
                'sets' => [],
                'processing' => [
                    'authorDiagnosticsEnabled' => false,
                ],
            ];
        }

        $slots = $this->_plugin->getBreakpointSlots()->getSlots(true);
        $breakpoints = [];
        foreach ($slots as $slot) {
            $breakpoints[$slot['key']] = (int)$slot['mediaWidth'];
        }

        return [
            'schemaVersion' => 2,
            'generatedAt' => gmdate('c'),
            'breakpoints' => $breakpoints,
            'breakpointValues' => array_values($breakpoints),
            'breakpointSlots' => $slots,
            'sets' => $this->_plugin->getTransformSets()->getSets(),
            'processing' => [
                'authorDiagnosticsEnabled' => $this->_plugin->getConfigService()->isProcessingDiagnosticsEnabled(),
            ],
        ];
    }
}
