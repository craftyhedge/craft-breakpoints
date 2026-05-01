<?php

namespace craftyhedge\craftbreakpoints\services\transformeditor;

use yii\web\Request;

final class CardOperationRequest
{
    private const FIELD_WIDTH = 'width';
    private const FIELD_HEIGHT = 'height';
    private const FIELD_DIMENSIONS = 'dimensions';
    private const FIELD_RATIO = 'ratio';
    private const FIELD_BREAKPOINT_ENABLED = 'breakpointEnabled';
    private const FIELD_PASS_HEIGHT_WHEN_RENDERED_LTE_SAVED = 'passHeightWhenRenderedLteSaved';
    private const FIELD_ALLOW_ANY_HEIGHT = 'allowAnyHeight';
    private const FIELD_RENDERED_VALUES = 'renderedValues';
    private const FIELD_DELETE_SET = 'deleteSet';
    private const FIELD_SCOPE = 'scope';

    private const OPERATION_DIMENSION_WIDTH = 'dimension.width';
    private const OPERATION_DIMENSION_HEIGHT = 'dimension.height';
    private const OPERATION_DIMENSIONS_APPLY = 'dimensions.apply';
    private const OPERATION_DIMENSIONS_TOGGLE_AUTO_WIDTH = 'dimensions.toggleAutoWidth';
    private const OPERATION_DIMENSIONS_TOGGLE_AUTO_HEIGHT = 'dimensions.toggleAutoHeight';
    private const OPERATION_RATIO_APPLY = 'ratio.apply';
    private const OPERATION_RATIO_COPY_FROM_RENDERED_BREAKPOINT = 'ratio.copyFromRenderedBreakpoint';
    private const OPERATION_BREAKPOINT_TOGGLE_ENABLED = 'breakpoint.toggleEnabled';
    private const OPERATION_SETTINGS_SET_PASS_HEIGHT_WHEN_RENDERED_LTE_SAVED = 'settings.setPassHeightWhenRenderedLteSaved';
    private const OPERATION_SETTINGS_SET_ALLOW_ANY_HEIGHT = 'settings.setAllowAnyHeight';
    private const OPERATION_RENDERED_VALUES_APPLY = 'renderedValues.apply';
    private const OPERATION_SET_DELETE = 'set.delete';
    private const OPERATION_SCOPE_SELECT_BREAKPOINT = 'scope.selectBreakpoint';
    private const OPERATION_SCOPE_SELECT_ALL = 'scope.selectAll';

    /**
     * @var array<string, string>
     */
    private const VALID_OPERATION_TO_FIELD = [
        self::OPERATION_DIMENSION_WIDTH => self::FIELD_WIDTH,
        self::OPERATION_DIMENSION_HEIGHT => self::FIELD_HEIGHT,
        self::OPERATION_DIMENSIONS_APPLY => self::FIELD_DIMENSIONS,
        self::OPERATION_DIMENSIONS_TOGGLE_AUTO_WIDTH => self::FIELD_DIMENSIONS,
        self::OPERATION_DIMENSIONS_TOGGLE_AUTO_HEIGHT => self::FIELD_DIMENSIONS,
        self::OPERATION_RATIO_APPLY => self::FIELD_RATIO,
        self::OPERATION_RATIO_COPY_FROM_RENDERED_BREAKPOINT => self::FIELD_RATIO,
        self::OPERATION_BREAKPOINT_TOGGLE_ENABLED => self::FIELD_BREAKPOINT_ENABLED,
        self::OPERATION_SETTINGS_SET_PASS_HEIGHT_WHEN_RENDERED_LTE_SAVED => self::FIELD_PASS_HEIGHT_WHEN_RENDERED_LTE_SAVED,
        self::OPERATION_SETTINGS_SET_ALLOW_ANY_HEIGHT => self::FIELD_ALLOW_ANY_HEIGHT,
        self::OPERATION_RENDERED_VALUES_APPLY => self::FIELD_RENDERED_VALUES,
        self::OPERATION_SET_DELETE => self::FIELD_DELETE_SET,
        self::OPERATION_SCOPE_SELECT_BREAKPOINT => self::FIELD_SCOPE,
        self::OPERATION_SCOPE_SELECT_ALL => self::FIELD_SCOPE,
    ];

    public function __construct(
        public readonly string $setName,
        public readonly string $scopeMode,
        public readonly ?int $scopeBreakpoint,
        public readonly string $operation,
        public readonly string $field,
        public readonly ?bool $includeEscapeWidth,
        public readonly ?int $value,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?bool $widthAuto,
        public readonly ?bool $heightAuto,
        public readonly bool $forceAll,
        public readonly bool $clearAuto,
        public readonly ?int $ratioWidth,
        public readonly ?int $ratioHeight,
        public readonly ?float $ratioFloat,
        public readonly ?string $ratioSourceDimension,
        public readonly ?int $ratioSourceBreakpoint,
        public readonly ?bool $enabled,
        public readonly array $renderedRows,
        public readonly bool $hasMalformedRenderedRows,
        public readonly string $baseVersion,
        public readonly mixed $valueRaw,
        public readonly bool $hasValidOperation,
    ) {
    }

    public static function fromRequest(Request $request, string $fallbackBaseVersion): self
    {
        $rawOperation = trim((string)$request->getBodyParam('operation', ''));
        $operation = self::normalizeOperation($rawOperation);
        $field = self::normalizeFieldFromOperation($operation);

        $rawBaseVersion = trim((string)$request->getBodyParam('baseVersion', ''));
        $resolvedBaseVersion = $rawBaseVersion !== '' ? $rawBaseVersion : $fallbackBaseVersion;

        [$renderedRows, $hasMalformedRenderedRows] = self::normalizeRenderedRows(
            $request->getBodyParam('renderedRows', []),
        );

        return new self(
            setName: trim((string)$request->getBodyParam('setName', '')),
            scopeMode: trim((string)$request->getBodyParam('scopeMode', 'all')),
            scopeBreakpoint: Support::parseNullablePositiveInt($request->getBodyParam('scopeBreakpoint')),
            operation: $operation,
            field: $field,
            includeEscapeWidth: Support::parseNullableBool($request->getBodyParam('includeEscapeWidth')),
            value: Support::parseNullablePositiveInt($request->getBodyParam('value')),
            width: Support::parseNullablePositiveInt($request->getBodyParam('width')),
            height: Support::parseNullablePositiveInt($request->getBodyParam('height')),
            widthAuto: Support::parseNullableBool($request->getBodyParam('widthAuto')),
            heightAuto: Support::parseNullableBool($request->getBodyParam('heightAuto')),
            forceAll: Support::parseNullableBool($request->getBodyParam('forceAll')) === true,
            clearAuto: Support::parseNullableBool($request->getBodyParam('clearAuto')) === true,
            ratioWidth: Support::parseNullablePositiveInt($request->getBodyParam('ratioWidth')),
            ratioHeight: Support::parseNullablePositiveInt($request->getBodyParam('ratioHeight')),
            ratioFloat: Support::parseNullablePositiveFloat($request->getBodyParam('ratioFloat')),
            ratioSourceDimension: Support::parseNullableNonEmptyString($request->getBodyParam('ratioSourceDimension')),
            ratioSourceBreakpoint: Support::parseNullablePositiveInt($request->getBodyParam('ratioSourceBreakpoint')),
            enabled: Support::parseNullableBool($request->getBodyParam('enabled')),
            renderedRows: $renderedRows,
            hasMalformedRenderedRows: $hasMalformedRenderedRows,
            baseVersion: $resolvedBaseVersion,
            valueRaw: $request->getBodyParam('value'),
            hasValidOperation: $operation !== '',
        );
    }

    /**
     * @return array{0: array<int, array{breakpoint: int, width: ?int, height: ?int}>, 1: bool}
     */
    private static function normalizeRenderedRows(mixed $rawRenderedRows): array
    {
        if (is_string($rawRenderedRows)) {
            $decodedRows = json_decode($rawRenderedRows, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [[], true];
            }

            $rawRenderedRows = $decodedRows;
        }

        if (!is_array($rawRenderedRows)) {
            return [[], true];
        }

        $normalizedRows = [];
        $hasMalformedRows = false;

        foreach ($rawRenderedRows as $row) {
            if (!is_array($row)) {
                $hasMalformedRows = true;
                continue;
            }

            $breakpoint = Support::parseNullablePositiveInt($row['breakpoint'] ?? null);
            $width = Support::parseNullablePositiveInt($row['width'] ?? null);
            $height = Support::parseNullablePositiveInt($row['height'] ?? null);

            if ($breakpoint === null || ($width === null && $height === null)) {
                $hasMalformedRows = true;
                continue;
            }

            $normalizedRows[] = [
                'breakpoint' => $breakpoint,
                'width' => $width,
                'height' => $height,
            ];
        }

        return [$normalizedRows, $hasMalformedRows];
    }

    private static function normalizeOperation(string $operation): string
    {
        if ($operation === '') {
            return '';
        }

        return isset(self::VALID_OPERATION_TO_FIELD[$operation]) ? $operation : '';
    }

    private static function normalizeFieldFromOperation(string $operation): string
    {
        $normalized = self::VALID_OPERATION_TO_FIELD[$operation] ?? null;
        if (is_string($normalized)) {
            return $normalized;
        }

        return self::FIELD_WIDTH;
    }
}
