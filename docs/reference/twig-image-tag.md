# Twig Image Tag Reference

Reference for the `image()` Twig function.

## Function Signature

```twig
{{ image(asset, transformName, options) }}
```

## Parameters

### `asset` (required)
**Type**: `craft\elements\Asset|null`

Craft asset element to render.

### `transformName` (required)
**Type**: `string`

Transform handle configured in plugin settings.

### `options` (optional)
**Type**: `array`

Configuration options.

## Core Properties

### `includeEscapeWidth`
**Type**: `boolean`  
**Default**: `false`

When `true`, includes an extra image variant at the escape width to ensure sharpness on very large viewports.

```twig
{{ image(asset, 'hero', { includeEscapeWidth: true }) }}
```

## Initialization Properties

### `initWidth` / `initHeight`
**Type**: `integer`

Bootstrap dimensions used only when no saved transform set exists for the handle.


```twig
{{ image(asset, 'content', {
  initWidth: 1200,
  initHeight: 675
}) }}
```

### `initRatio`
**Type**: `string|float|int`

Bootstrap aspect ratio used only when no saved transform set exists for the handle.

Accepted values:

- String `"x:y"` with positive numbers on both sides (e.g. `"16:9"`, `"1.5:1"`)
- Positive numeric ratio (`1.77778`, `"0.5625"`)

Invalid values are ignored and source aspect ratio fallback is used.


```twig
{{ image(asset, 'content', {
  initWidth: 1200,
  initRatio: '16:9'
}) }}
```

### `initWidthAuto` / `initHeightAuto`
**Type**: `boolean`  
**Default**: `false`

Bootstrap auto-dimension flags used only when no saved transform set exists for the handle.

`initWidthAuto: true` sends a transform without `width`.

`initHeightAuto: true` sends a transform without `height`.

No implicit auto inference: providing only `initWidth` does not auto-enable height, and providing only `initHeight` does not auto-enable width.

```twig
{{ image(asset, 'content', {
  initHeightAuto: true
}) }}
```

```twig
{{ image(asset, 'content', {
  initWidthAuto: true
}) }}
```

### Init Option Precedence

`init*` options are bootstrap-only. If a saved transform set exists for the handle, all `init*` options are ignored.

When no saved set exists, precedence is:

1. `initWidth` + `initHeight` -> both explicit, `initRatio` ignored.
2. `initWidth` + `initRatio` -> `height = initWidth / initRatio`.
3. `initHeight` + `initRatio` -> `width = initHeight * initRatio`.
4. `initWidth` only -> derive `height` from intrinsic/source ratio.
5. `initHeight` only -> derive `width` from intrinsic/source ratio.
6. `initRatio` only -> per-breakpoint width, derive `height` from ratio.
7. Neither init dimension nor ratio -> intrinsic/source ratio fallback.
8. `initWidthAuto` / `initHeightAuto` only control whether a transform dimension is omitted; reported dimensions remain computed from the active aspect ratio.

## HTML Attributes

### `loading`
**Type**: `string`  
**Default**: `'lazy'`  
**Values**: `'lazy'` | `'eager'`

### `decoding`
**Type**: `string`  
**Default**: `'async'`  
**Values**: `'sync'` | `'async'` | `'auto'`

### `alt`
**Type**: `string`

Alternative text. Falls back to asset's alt/title.

### `imgClass` / `pictureClass`
**Type**: `string`

CSS classes for `<img>` and `<picture>` elements.

```twig
{{ image(asset, 'hero', {
  loading: 'eager',
  decoding: 'async',
  alt: 'Hero banner',
  imgClass: 'c-hero__img',
  pictureClass: 'c-hero__picture'
}) }}
```

## Rendering Controls

### `disableBreakpoints`
**Type**: `array`

Map of breakpoint keys to `true` to skip rendering those breakpoints.

### `dpr`
**Type**: `array`

Device pixel ratios (e.g., `[1, 2, 3]`). Drives DPR srcset generation.

```twig
{{ image(asset, 'hero', {
  disableBreakpoints: { md: true },
  dpr: [1, 2, 3]
}) }}
```

## Transform Overrides

These options override transform defaults when provided inline. If a saved transform specifies the same fields, the saved transform takes precedence.

- `format` (string) primary format
- `secondaryFormat` (string) fallback/secondary format
- `quality` (int)
- `mode` (string)
- `position` (string)
- `interlace` (string)

```twig
{{ image(asset, 'hero', {
  format: 'avif',
  secondaryFormat: 'webp',
  quality: 82,
  mode: 'crop',
  position: 'center-center',
  interlace: 'none'
}) }}
```

## Template Overrides

- `pictureTemplatePath` — Custom template for raster images.
- `svgTemplatePath` — Custom template for SVG images.

```twig
{{ image(asset, 'hero', {
  pictureTemplatePath: 'custom/picture',
  svgTemplatePath: 'custom/svg'
}) }}
```

## Full Example

```twig
{{ image(asset, 'hero', {
  {# Core #}
  includeEscapeWidth: true,

  {# Initialization #}
  initWidth: 1200,
  initHeight: 675,
  initRatio: '16:9',
  initWidthAuto: false,
  initHeightAuto: false,

  {# HTML attrs #}
  loading: 'eager',
  decoding: 'async',
  alt: 'Hero banner',
  imgClass: 'c-hero__img',
  pictureClass: 'c-hero__picture',

  {# Rendering controls #}
  disableBreakpoints: { md: true },
  dpr: [1, 2],

  {# Transform overrides #}
  format: 'avif',
  secondaryFormat: 'webp',
  quality: 82,
  mode: 'crop',
  position: 'center-center',
  interlace: 'none',

  {# Templates #}
  {# relative to site template root #}
  pictureTemplatePath: 'custom/picture',
  svgTemplatePath: 'custom/svg'
}) }}
```



