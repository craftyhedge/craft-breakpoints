# `image()` Twig Function

Use the `image()` function when you want Breakpoints to render image markup from a Craft asset.

```twig
{{ image(asset, 'hero') }}
```

The function returns safe Twig markup for the transform handle you pass in. Raster assets render as a `<picture>` element with responsive sources and a fallback `<img>`. SVG assets render as a plain `<img>`.

## Basic Usage

```twig
{{ image(entry.heroImage.one(), 'hero') }}
```

The first argument is the Craft asset. The second argument is the transform handle configured in Breakpoints. If the asset is `null`, Breakpoints returns an HTML comment instead of image markup.

You can pass a third argument when you need to adjust the output for a specific template:

```twig
{{ image(asset, 'hero', {
  loading: 'eager',
  alt: 'Hero banner',
  imgClass: 'c-hero__img'
}) }}
```

## Function Signature

```twig
{{ image(asset, transformName, options) }}
```

## Parameters

### `asset` (required)
**Type**: `craft\elements\Asset|null`

The Craft asset to render.

### `transformName` (required)
**Type**: `string`

The Breakpoints transform handle to use.

### `options` (optional)
**Type**: `array`

Template-level options for this image.

## Common Options

### `alt`
**Type**: `string`

Alternative text for the image. If you do not pass `alt`, Breakpoints uses Craft's native asset alternative text field. If neither value is available, the rendered `alt` attribute is empty.

```twig
{{ image(asset, 'card', {
  alt: 'Handmade ceramic bowl'
}) }}
```

### `loading`
**Type**: `string`
**Default**: `'lazy'`  
**Values**: `'lazy'` | `'eager'`

Use `eager` for important above-the-fold images, such as a page hero. Leave the default `lazy` for most content images.

The `loading` attribute is only rendered when native lazy loading is enabled in Breakpoints configuration.

### `fetchpriority`
**Type**: `string`
**Values**: `'high'` | `'low'` | `'auto'`

Adds a browser fetch-priority hint to the fallback `<img>`. Use `high` sparingly for important above-the-fold images.

```twig
{{ image(asset, 'hero', {
  loading: 'eager',
  fetchpriority: 'high'
}) }}
```

### `priority`
**Type**: `boolean`
**Default**: `false`

Convenience option for important above-the-fold images. It enables preload links, eager loading, and high fetch priority.

```twig
{{ image(asset, 'hero', {
  priority: true
}) }}
```

This is equivalent to:

```twig
{{ image(asset, 'hero', {
  preload: true,
  loading: 'eager',
  fetchpriority: 'high'
}) }}
```

You can still override any individual hint on the same `image()` call.

### `preload`
**Type**: `boolean`
**Default**: `false`

Registers responsive image preload links for the rendered picture. Use this for important above-the-fold images, usually together with eager loading and high fetch priority.

```twig
{{ image(asset, 'hero', {
  preload: true,
  loading: 'eager',
  fetchpriority: 'high'
}) }}
```

For raster pictures, Breakpoints registers one responsive preload link per canonical slot using `imagesrcset`, rather than one link per DPR candidate. When `sources` is used, each slot preloads the same effective asset that its rendered `<source>` uses.

### `decoding`
**Type**: `string`  
**Default**: `'async'`  
**Values**: `'sync'` | `'async'` | `'auto'`

Controls the browser's image decoding hint.

### `imgClass` / `pictureClass`
**Type**: `string`

CSS classes for the generated `<img>` and `<picture>` elements.

```twig
{{ image(asset, 'hero', {
  loading: 'eager',
  decoding: 'async',
  alt: 'Hero banner',
  imgClass: 'c-hero__img',
  pictureClass: 'c-hero__picture'
}) }}
```

## Responsive Output

### `dpr`
**Type**: `array`

Sets the device pixel ratios for this image's `srcset` output.

```twig
{{ image(asset, 'hero', {
  dpr: [1, 2, 3]
}) }}
```

Breakpoints always includes `1x`. Invalid, zero, negative, and non-finite values are ignored.

### `sources`
**Type**: `array`

Use `sources` when a single responsive picture needs different Craft assets for specific art-directed slots. The first `image()` argument remains the default asset and is still used for the fallback `<img>`.

```twig
{{ image(entry.desktopMasthead.one(), 'masthead', {
  priority: true,
  sources: {
    mobile: {
      asset: entry.mobileMasthead.one(),
      slots: ['base', 'xs']
    }
  }
}) }}
```

`slots` uses canonical Breakpoints slot keys: `base` first, then the configured breakpoint keys by position. Slots without an override use the default asset. If multiple source overrides target the same slot, later overrides win for that slot.

SVG renders stay single-asset; `sources` applies to raster picture output only.

### `includeEscapeWidth`
**Type**: `boolean`  
**Default**: `false`

Adds an extra image variant at the escape width. This can help keep very large layouts sharp.

```twig
{{ image(asset, 'hero', {
  includeEscapeWidth: true
}) }}
```

## First-Time Transform Defaults

The `init*` options are used only when Breakpoints has not yet saved a transform set for the handle. They are useful when you want a new transform to start with sensible initial values directly from your template.

Once a saved transform set exists for the handle, these options are ignored.

When a transform set is first created, Breakpoints applies the initial values
like this:

1. `initWidth` + `initHeight` use both explicit dimensions.
2. `initRatio` seeds the editor ratio metadata, unless both `initWidth` and `initHeight` are provided.
3. `initWidth` only derives the initial height from the source image ratio.
4. `initHeight` only derives the initial width from the source image ratio.
5. `initWidthAuto` and `initHeightAuto` mark which dimension should stay auto when the transform set is saved.

### `initWidth` / `initHeight`
**Type**: `integer`

Initial width and height for a new transform set.

```twig
{{ image(asset, 'content', {
  initWidth: 1200,
  initHeight: 675
}) }}
```

### `initRatio`
**Type**: `string|float|int`

Initial aspect ratio metadata for a new transform set.

Accepted values:

- A string ratio such as `"16:9"` or `"1.5:1"`
- A positive numeric ratio such as `1.77778` or `"0.5625"`

Invalid values are ignored. Rendered image dimensions are still calculated from `initWidth`, `initHeight`, saved transform dimensions, or the source image ratio.

```twig
{{ image(asset, 'content', {
  initWidth: 1200,
  initRatio: '16:9'
}) }}
```

### `initWidthAuto` / `initHeightAuto`
**Type**: `boolean`  
**Default**: `false`

Marks one dimension as auto for the initial transform set. This is useful when a
single transform set will be used by images with different intrinsic ratios.

`initWidthAuto: true` saves width as auto. The saved height is used for every
image using the set, and each image's width is derived from its own ratio.

`initHeightAuto: true` saves height as auto. The saved width is used for every
image using the set, and each image's height is derived from its own ratio.

Choose one auto dimension for a transform set. If both auto flags are passed,
Breakpoints treats width as auto.

```twig
{{ image(asset, 'content', {
  initHeightAuto: true
}) }}
```

## Transform Overrides

These options can adjust transform behavior for a specific image call when the transform set has not already saved its own value. If the saved transform set defines the same field, the saved transform value takes precedence.

- `format` (string): primary format
- `secondaryFormat` (string): fallback format
- `quality` (int): image quality
- `mode` (string): transform mode
- `position` (string): crop position
- `interlace` (string): interlace setting

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

Images will use the default or configured templates but, if you need full control, you can specify different templates in the image options.

- `pictureTemplatePath`: custom template for raster images
- `svgTemplatePath`: custom template for SVG images

Put custom template files in your Craft `templates/` directory and set each path
relative to that directory. Leave either option blank to use the plugin default.

```twig
{{ image(asset, 'hero', {
  pictureTemplatePath: 'custom/picture',
  svgTemplatePath: 'custom/svg'
}) }}
```

See [Custom Image Templates](../custom-templates.md) for the variables passed to custom templates and complete raster/SVG examples.

## See also

- [Getting Started](../getting-started.md)
- [Responsive Images](../responsive-images.md) — how options become markup.
- [Custom Image Templates](../custom-templates.md)
