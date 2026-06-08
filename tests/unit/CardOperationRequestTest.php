<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use Craft;
use craftyhedge\craftbreakpoints\services\transformeditor\CardOperationRequest;
use craft\web\Request;

final class CardOperationRequestTest extends Unit
{
    public function testFromRequestNormalizesSupportedOperationAndScalars(): void
    {
        $this->request()->setBodyParams([
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

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

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
        $this->request()->setBodyParams([
            'operation' => 'legacy.field.width',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertFalse($operation->hasValidOperation);
        $this->assertSame('', $operation->operation);
        $this->assertSame('width', $operation->field);
        $this->assertSame('fallback-version', $operation->baseVersion);
    }

    public function testFromRequestDoesNotFallbackToLegacyFieldPayload(): void
    {
        $this->request()->setBodyParams([
            'field' => 'breakpointEnabled',
            'setName' => 'hero',
            'enabled' => true,
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertFalse($operation->hasValidOperation);
        $this->assertSame('', $operation->operation);
    }

    public function testFromRequestNormalizesSelectedAssetKeyAndValueRaw(): void
    {
        $this->request()->setBodyParams([
            'operation' => 'settings.setAllowAnyHeight',
            'value' => 'on',
            'selectedAssetKey' => '  asset:hero:100  ',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('settings.setAllowAnyHeight', $operation->operation);
        $this->assertSame('allowAnyHeight', $operation->field);
        $this->assertSame('on', $operation->valueRaw);
        $this->assertSame('asset:hero:100', $operation->selectedAssetKey);
    }

    public function testFromRequestAcceptsNotesOperation(): void
    {
        $this->request()->setBodyParams([
            'operation' => 'set.notes.update',
            'setName' => 'hero',
            'notes' => "  Line one\r\nLine two  ",
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('set.notes.update', $operation->operation);
        $this->assertSame('notes', $operation->field);
        $this->assertSame("  Line one\r\nLine two  ", $operation->notes);
    }

    public function testFromRequestNormalizesEmptySelectedAssetKeyToNull(): void
    {
        $this->request()->setBodyParams([
            'operation' => 'renderedValues.apply',
            'setName' => 'hero',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertNull($operation->selectedAssetKey);
    }

    public function testFromRequestNormalizesScopeSelectBreakpointOperation(): void
    {
        $this->request()->setBodyParams([
            'setName' => 'hero',
            'operation' => 'scope.selectBreakpoint',
            'scopeBreakpoint' => '768',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('scope.selectBreakpoint', $operation->operation);
        $this->assertSame('scope', $operation->field);
        $this->assertSame('hero', $operation->setName);
        $this->assertSame(768, $operation->scopeBreakpoint);
    }

    public function testFromRequestNormalizesScopeSelectAllOperation(): void
    {
        $this->request()->setBodyParams([
            'setName' => 'hero',
            'operation' => 'scope.selectAll',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('scope.selectAll', $operation->operation);
        $this->assertSame('scope', $operation->field);
        $this->assertSame('hero', $operation->setName);
        $this->assertNull($operation->scopeBreakpoint);
    }

    public function testFromRequestNormalizesDimensionsToggleAutoWidthOperation(): void
    {
        $this->request()->setBodyParams([
            'setName' => 'hero',
            'operation' => 'dimensions.toggleAutoWidth',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('dimensions.toggleAutoWidth', $operation->operation);
        $this->assertSame('dimensions', $operation->field);
    }

    public function testFromRequestNormalizesDimensionsToggleAutoHeightOperation(): void
    {
        $this->request()->setBodyParams([
            'setName' => 'hero',
            'operation' => 'dimensions.toggleAutoHeight',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('dimensions.toggleAutoHeight', $operation->operation);
        $this->assertSame('dimensions', $operation->field);
    }

    public function testFromRequestNormalizesRatioCopyFromRenderedBreakpointOperation(): void
    {
        $this->request()->setBodyParams([
            'setName' => 'hero',
            'operation' => 'ratio.copyFromRenderedBreakpoint',
            'ratioSourceBreakpoint' => '2',
            'ratioSourceBreakpointKey' => 'xs',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('ratio.copyFromRenderedBreakpoint', $operation->operation);
        $this->assertSame('ratio', $operation->field);
        $this->assertSame(2, $operation->ratioSourceBreakpoint);
        $this->assertSame('xs', $operation->ratioSourceBreakpointKey);
    }

    public function testFromRequestNormalizesRatioApplyFloatPayload(): void
    {
        $this->request()->setBodyParams([
            'setName' => 'hero',
            'operation' => 'ratio.apply',
            'ratioFloat' => '1.7777',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertTrue($operation->hasValidOperation);
        $this->assertSame('ratio.apply', $operation->operation);
        $this->assertSame('ratio', $operation->field);
        $this->assertNotNull($operation->ratioFloat);
        $this->assertSame(1.7777, $operation->ratioFloat);
    }

    public function testFromRequestDecodesSelectedAssetKey(): void
    {
        $this->request()->setBodyParams([
            'setName' => 'hero',
            'operation' => 'ratio.copyFromRenderedBreakpoint',
            'selectedAssetKey' => 'asset:hero:100',
        ]);

        $operation = CardOperationRequest::fromRequest($this->request(), 'fallback-version');

        $this->assertSame('asset:hero:100', $operation->selectedAssetKey);
    }

    private function request(): Request
    {
        $request = Craft::$app->getRequest();
        assert($request instanceof Request);

        return $request;
    }
}
