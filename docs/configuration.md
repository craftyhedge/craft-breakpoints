# Configuration

Breakpoints resolves its configuration from several layers and merges them in a
fixed order. This page lists every setting, shows how to use a project config file,
and explains the precedence rules.

## Where settings live

- **`config/breakpoints.php`.** The recommended project config file,
  version-controlled like any Craft config file. Use this for breakpoint widths
  and shared defaults.
- **Control panel — Breakpoints → Settings.** A visual editor for common output,
  transform, template, and editor defaults. Saved values are stored on the
  plugin's Settings model.
- **Per-call `image()` options.** The third argument to the
  [`image()`](reference/twig-image-tag.md) function, scoped to a single render.

## Precedence

Values are merged from lowest to highest priority:

1. **Built-in defaults** (the values shipped with the plugin).
2. **Control-panel settings** — only values you have changed from their default.
3. **`config/breakpoints.php`** — project config file.
4. **Per-call `image()` options** — for a single render.

One further rule applies on top of this:

- A **saved transform set's** own `config` (its `format`, `secondaryFormat`,
  `mode`, `position`) takes precedence over per-call options for those
  fields. A set's saved widths/heights always win for dimensions.

`quality` is render-time config and is not saved to transform sets.

## Settings reference

The table lists each project-level setting, its built-in default, and what it
controls. These settings can be set in `config/breakpoints.php`; the control
panel exposes the common output, transform, template, and editor defaults.

| Setting | Type | Default | Purpose |
| --- | --- | --- | --- |
| `breakpoints` | array | `{ xs: 480, sm: 640, md: 768, lg: 1024, xl: 1280, '2xl': 1536 }` | Viewport widths (name → pixels). Sorted by width; one slot is generated per entry. |
| `escapeWidth` | int | `1920` | Width used by the final slot when escape width is included. `0` disables it. |
| `defaultWidth` | int | `1600` | Fallback source width used when an asset reports no dimensions. |
| `defaultHeight` | int | `900` | Fallback source height used when an asset reports no dimensions. |
| `mode` | string | `'crop'` | Craft transform mode (`crop`, `fit`, `stretch`, …). |
| `position` | string | `'center-center'` | Focal/crop position for `crop` mode. |
| `quality` | int | `80` | Transform quality (1–100). |
| `format` | string | `'jpg'` | Primary image format (`jpg`, `jpeg`, `png`, `webp`, `avif`, `gif`). |
| `secondaryFormat` | string | `'none'` | Optional fallback `<source>` format. `none` emits no secondary source. |
| `interlace` | string | `'none'` | Interlace setting passed to the transform. |
| `allowUpscale` | bool | `false` | Whether transforms may upscale beyond the source size. |
| `pictureTemplatePath` | string | *(plugin default)* | Custom template for raster assets. Project paths are relative to Craft's `templates/` directory; blank uses the built-in template. |
| `svgTemplatePath` | string | *(plugin default)* | Custom template for SVG assets. Project paths are relative to Craft's `templates/` directory; blank uses the built-in template. |
| `nativeLazyLoadingEnabled` | bool | `true` | When on, the `<img>` gets a `loading` attribute (`lazy` by default). |
| `priority` | bool | `false` | Convenience option that enables `preload`, `loading: 'eager'`, and `fetchpriority: 'high'` unless individually overridden. |
| `preload` | bool | `false` | Whether `image()` calls register responsive image preload links by default. Usually set per call for above-the-fold images. |
| `processingLazyLoadingAdapter` | string | `attributes` | Explicit processing adapter: `none`, `attributes`, `lazysizes`, `vanilla-lazyload`, `lozad`, or `custom`. No automatic detection is performed. |
| `processingLazyLoadingSrcAttribute` | string | `data-src` | Attribute copied to `src` by the `attributes` processing adapter. |
| `processingLazyLoadingSrcsetAttribute` | string | `data-srcset` | Attribute copied to `srcset` by the `attributes` processing adapter. |
| `processingLazyLoadingSizesAttribute` | string | `data-sizes` | Attribute copied to `sizes` by the `attributes` processing adapter. |
| `processingLazyLoadingCustomHandler` | string | *(blank)* | Global async function used by the `custom` adapter, for example `window.project.prepareBreakpointImages`. |
| `previewCenter` | bool | `true` | Editor-only: centers the preview width in the Transform Sets UI. |
| `dpr` | array | `[1]` | Device pixel ratios for `srcset`. `1x` is always included; add `2`/`3` for high-density variants. |

Notes:

- `breakpoints` keys are the canonical slot labels used by the editor and the
  saved-set variants, preceded by a `base` slot.
- For template path resolution and custom markup, see
  [Custom Image Templates](custom-templates.md).
- For how `breakpoints`, `escapeWidth`, and `dpr` turn into media queries and
  `srcset`, see [Responsive Images](responsive-images.md).
- For native Craft transforms and external transform service compatibility, see
  [Responsive Images](responsive-images.md#transform-services).

## Sample `config/breakpoints.php`

Create the file at your project root's `config/` directory.

Keep `allowTransformEditing` enabled only in local development. Staging and
production still need Breakpoints available so templates can render saved
transform sets, but processing and saving transform-set changes should happen
locally before committing `config/breakpoints/transform-sets.json`.

```php
<?php

use craft\helpers\App;

return [
    'breakpoints' => [
        'sm' => 640,
        'md' => 768,
        'lg' => 1024,
        'xl' => 1280,
    ],
    'format' => 'webp',
    'secondaryFormat' => 'jpg',
    'quality' => 82,
    'dpr' => [1, 2],

    // Processing lazy loading is explicit; no library auto-detection occurs.
    'processingLazyLoadingAdapter' => 'attributes',
    'processingLazyLoadingSrcAttribute' => 'data-src',
    'processingLazyLoadingSrcsetAttribute' => 'data-srcset',
    'processingLazyLoadingSizesAttribute' => 'data-sizes',

    // Optional project templates (relative to Craft's templates/ directory):
    'pictureTemplatePath' => '_images/breakpoints-picture.twig',
    'svgTemplatePath' => '_images/breakpoints-svg.twig',

    // Local development only: enables processing and transform-set saving.
    'allowTransformEditing' => App::env('ALLOW_TRANSFORM_EDITING') ?? false,
];
```

The `custom` adapter calls the configured global function once per slot inside
the processing iframe. The function receives `{ document, window, breakpoint,
slot, pictures }` and may return a promise. Processing waits for that promise,
then independently waits for each real DOM image to load or decode. Lazy target
URLs are read from the configured processing source attributes before the
handler runs so placeholders cannot satisfy readiness.

```php
'processingLazyLoadingAdapter' => 'custom',
'processingLazyLoadingCustomHandler' => 'window.project.prepareBreakpointImages',
```

## See also

- [Getting Started](getting-started.md)
- [`image()` Twig Function](reference/twig-image-tag.md)
