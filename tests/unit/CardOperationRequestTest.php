<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpoints\services\transformeditor\CardOperationRequest;

final class CardOperationRequestTest extends Unit
{
    public function testFromRequestNormalizesSupportedOperationAndScalars(): void
    {
        Craft::$app->getRequest()->setBodyParams([
            'setName' => 'hero',
            'scopeMode' => 'breakpoint',
            'scopeBreakpoint' => '640',
            'operation' => 'dimensions.apply',
            'includeEscapeWidth' => 'true',
            'width' => '320',
            'height' => '180',
            'widthAuto' => '0',
            'heightAuto' => '1',
            'forceAll' => 'yes',
            'clearAuto' => 'no',
            'baseVersion' => 'v123',
        ]);

        $operation = CardOperationRequest::fromRequest(Craft::$app->getRequest(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('dimensions.apply', $operation->operation);
        $this->assertSame('dimensions', $operation->field);
        $this->assertSame('hero', $operation->setName);
        $this->assertSame('breakpoint', $operation->scopeMode);
        $this->assertSame(640, $operation->scopeBreakpoint);
        $this->assertTrue($operation->includeEscapeWidth);
        $this->assertSame(320, $operation->width);
        $this->assertSame(180, $operation->height);
        $this->assertFalse($operation->widthAuto);
        $this->assertTrue($operation->heightAuto);
        $this->assertTrue($operation->forceAll);
        $this->assertFalse($operation->clearAuto);
        $this->assertSame('v123', $operation->baseVersion);
    }

    public function testFromRequestRejectsUnsupportedOperation(): void
    {
        Craft::$app->getRequest()->setBodyParams([
            'operation' => 'legacy.field.width',
        ]);

        $operation = CardOperationRequest::fromRequest(Craft::$app->getRequest(), 'fallback-version');

        $this->assertFalse($operation->hasValidOperation);
        $this->assertSame('', $operation->operation);
        $this->assertSame('width', $operation->field);
        $this->assertSame('fallback-version', $operation->baseVersion);
    }

    public function testFromRequestDoesNotFallbackToLegacyFieldPayload(): void
    {
        Craft::$app->getRequest()->setBodyParams([
            'field' => 'breakpointEnabled',
            'setName' => 'hero',
            'enabled' => true,
        ]);

        $operation = CardOperationRequest::fromRequest(Craft::$app->getRequest(), 'fallback-version');

        $this->assertFalse($operation->hasValidOperation);
        $this->assertSame('', $operation->operation);
    }

    public function testFromRequestNormalizesRenderedRowsAndValueRaw(): void
    {
        Craft::$app->getRequest()->setBodyParams([
            'operation' => 'settings.setAllowAnyHeight',
            'value' => 'on',
            'renderedRows' => 'not-an-array',
        ]);

        $operation = CardOperationRequest::fromRequest(Craft::$app->getRequest(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('settings.setAllowAnyHeight', $operation->operation);
        $this->assertSame('allowAnyHeight', $operation->field);
        $this->assertSame('on', $operation->valueRaw);
        $this->assertSame([], $operation->renderedRows);
    }

    public function testFromRequestNormalizesScopeSelectBreakpointOperation(): void
    {
        Craft::$app->getRequest()->setBodyParams([
            'setName' => 'hero',
            'operation' => 'scope.selectBreakpoint',
            'scopeBreakpoint' => '768',
        ]);

        $operation = CardOperationRequest::fromRequest(Craft::$app->getRequest(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('scope.selectBreakpoint', $operation->operation);
        $this->assertSame('scope', $operation->field);
        $this->assertSame('hero', $operation->setName);
        $this->assertSame(768, $operation->scopeBreakpoint);
    }

    public function testFromRequestNormalizesScopeSelectAllOperation(): void
    {
        Craft::$app->getRequest()->setBodyParams([
            'setName' => 'hero',
            'operation' => 'scope.selectAll',
        ]);

        $operation = CardOperationRequest::fromRequest(Craft::$app->getRequest(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('scope.selectAll', $operation->operation);
        $this->assertSame('scope', $operation->field);
        $this->assertSame('hero', $operation->setName);
        $this->assertNull($operation->scopeBreakpoint);
    }
}
