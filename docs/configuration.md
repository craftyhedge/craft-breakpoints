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
  `quality`, `mode`, `position`) takes precedence over per-call options for those
  fields. A set's saved widths/heights always win for dimensions.

## Settings reference

The table lists each setting, its built-in default, and what it controls. Every
setting can be set in `config/breakpoints.php`; the control panel exposes the
common output, transform, template, and editor defaults.

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
| `allowUpscale` | int | `0` | Whether transforms may upscale beyond the source size. |
| `pictureTemplatePath` | string | *(plugin default)* | Custom template for raster assets. Project paths are relative to Craft's `templates/` directory; blank uses the built-in template. |
| `svgTemplatePath` | string | *(plugin default)* | Custom template for SVG assets. Project paths are relative to Craft's `templates/` directory; blank uses the built-in template. |
| `nativeLazyLoadingEnabled` | bool | `true` | When on, the `<img>` gets a `loading` attribute (`lazy` by default). |
| `previewCenter` | bool | `true` | Editor-only: centers the preview width in the Transform Sets UI. |
| `dpr` | array | `[1]` | Device pixel ratios for `srcset`. `1x` is always included; add `2`/`3` for high-density variants. |

Notes:

- `breakpoints` keys are the canonical slot labels used everywhere (the editor,
  `disableBreakpoints`, and the saved-set variants), preceded by a `base` slot.
- For template path resolution and custom markup, see
  [Custom Image Templates](custom-templates.md).
- For how `breakpoints`, `escapeWidth`, and `dpr` turn into media queries and
  `srcset`, see [Media Queries & DPR](picture-media-and-dpr.md).

## Sample `config/breakpoints.php`

Create the file at your project root's `config/` directory. Only include the keys
you want to override — anything omitted uses the built-in default or control-panel
value.

Keep `allowTransformEditing` enabled only in local development. The plugin should
still be installed and enabled in staging and production so templates can render
saved transform sets, but processing and saving transform-set changes should
happen locally before committing `config/breakpoints/transform-sets.json`.

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

    // Optional project templates (relative to Craft's templates/ directory):
    'pictureTemplatePath' => '_images/breakpoints-picture.twig',
    'svgTemplatePath' => '_images/breakpoints-svg.twig',

    // Local development only: enables processing and transform-set saving.
    'allowTransformEditing' => App::env('ALLOW_TRANSFORM_EDITING') ?? false,
];
```

## See also

- [Getting Started](getting-started.md)
- [`image()` Twig Function](reference/twig-image-tag.md)
