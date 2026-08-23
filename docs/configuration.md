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

Later layers replace earlier ones (`array_merge`, later wins):

1. Plugin defaults — `src/config.php`
2. Control panel Settings — only keys that differ from a fresh Settings model
3. Project file — `config/breakpoints.php`
4. Per-call `image()` options

Untouched Control Panel fields therefore do not override plugin defaults. A
project file key always beats Settings. A key on the `image()` call beats
everything.

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
| `nativeLazyLoadingEnabled` | bool | `true` | When on, the `<img>` gets a native `loading` attribute (`lazy` by default), and non-native loading features such as JS lazy-loading adapters and ThumbHash output are disabled. When off, those features can rewrite `src`/`srcset` to data attributes. |
| `priority` | bool | `false` | Per-`image()` shortcut for above-the-fold images: sets `preload`, `loading: 'eager'`, and `fetchpriority: 'high'`. Leave this `false` in the project file; set `{ priority: true }` on the `image()` call. Individual hints on that same call still win. |
| `preload` | bool | `false` | Whether `image()` calls register responsive image preload links by default. Set per call (or via `priority`) for above-the-fold images; do not enable it project-wide. |
| `thumbhashEnabled` | bool | `false` | Enables optional integration with `craftyhedge/craft-thumbhash` when that plugin is installed. |
| `thumbhashMode` | string | `'srcset'` | Default ThumbHash render mode: `srcset` for per-source hashes, or `bg` for one picture-level hash. |
| `processingLazyLoadingAdapter` | string | `none` | When native lazy loading is off: `none` leaves markup unchanged, `lazysizes` rewrites to `data-src` / `data-srcset` / `data-sizes`, adds class `lazyload`, and unveils images during processing. Unknown leftover values fall back to `none`. |
| `previewCenter` | bool | `true` | Editor-only: centers the preview width in the Transform Sets UI. |
| `allowTransformEditing` | bool | `true` | Enables Control Panel nav, processing, and saving transform sets. Keep on for local development only; the sample turns it off outside local via env. |
| `enableUsageTracking` | bool | `false` | Development/staging utility: records transform set usage by page/source for the Transform Tracking utility. Keep disabled in production unless explicitly needed. |
| `frontendProcessButtonPosition` | string | `bottom-right` | Local-admin only: corner for the [front-end unsaved-set process button](getting-started.md#front-end-process-button). One of `bottom-right`, `bottom-left`, `top-right`, `top-left`. Invalid values fall back to `bottom-right`. |
| `dpr` | array | `[1]` | Device pixel ratios for `srcset`. `1x` is always included. The Control Panel offers common `2x`/`3x` presets; project config may provide any positive numeric ratio such as `1.5`. |

Notes:

- `breakpoints` keys are the canonical slot labels used by the editor and the
  saved-set variants, preceded by a `base` slot.
- For template path resolution and custom markup, see
  [Custom Image Templates](custom-templates.md).
- For how `breakpoints`, `escapeWidth`, and `dpr` turn into media queries and
  `srcset`, see [Responsive Images](responsive-images.md).
- For native Craft transforms and external transform service compatibility, see
  [Responsive Images](responsive-images.md#transform-services).

## ThumbHash integration

ThumbHash placeholders are an optional integration with the separate
[`craftyhedge/craft-thumbhash`](https://github.com/craftyhedge/craft-thumbhash)
plugin. Breakpoints doesn't depend on it — if the ThumbHash plugin isn't
installed and enabled, `thumbhashEnabled` has no effect and no ThumbHash
attributes are rendered.

ThumbHash only applies when native lazy loading is off. If
`nativeLazyLoadingEnabled` is on, or the render is eager (`priority: true` or
`loading: 'eager'`), ThumbHash output is skipped regardless of
`thumbhashEnabled`. SVG assets are never given a hash.

> Requires the lazysizes JS library.

Enable it project-wide in `config/breakpoints.php`:

```php
'thumbhashEnabled' => true,
'thumbhashMode' => 'srcset',
```

`thumbhashMode` controls where the hash is applied:

- **`srcset`** — a hash per `<source>`, computed per breakpoint slot from
  whichever asset renders at that slot, plus a fallback hash on the `<img>`.
  Use this when different slots render different source assets and each
  should get its own placeholder.
- **`bg`** — one hash, computed from the fallback asset, applied to the
  `<picture>` element. Allows for nice fades but has the draw back of not working with multiple assets per picture.
  
For the least set up and standard placeholder practice the `srcset` mode is recommended for the default.

`bg` mode requires CSS animations to create nice fades and work with your lazy loading set up.

You can still leaverage both thumbhash modes per `image()` call for creative purposes.


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
    'dpr' => [1, 1.5, 2],

    // Optional ThumbHash integration. Render calls can override this per image.
    'thumbhashEnabled' => false,
    'thumbhashMode' => 'srcset',

    // JavaScript lazy loading is explicit; no library auto-detection occurs.
    // `none` (default) or `lazysizes` when native lazy loading is disabled.
    'processingLazyLoadingAdapter' => 'none',

    // Optional project templates (relative to Craft's templates/ directory):
    'pictureTemplatePath' => '_images/breakpoints-picture.twig',
    'svgTemplatePath' => '_images/breakpoints-svg.twig',

    // Local development only: enables processing and transform-set saving.
    'allowTransformEditing' => App::env('ALLOW_TRANSFORM_EDITING') ?? false,

    // Local-admin cue when a page renders unsaved transform sets.
    // 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left'
    'frontendProcessButtonPosition' => 'bottom-right',

    // Optional development/staging audit table for transform set usage by page.
    'enableUsageTracking' => App::env('ENABLE_BREAKPOINT_USAGE_TRACKING') ?? false,
];
```

When native lazy loading is disabled, `loading` is not `eager`, and the adapter
is `lazysizes`, Breakpoints moves `src`, `srcset`, and `sizes` into `data-src`,
`data-srcset`, and `data-sizes`, and appends class `lazyload` on `<img>`.
Adapter `none` does not rewrite markup. Other libraries need a custom template.

## See also

- [Getting Started](getting-started.md)
- [`image()` Twig Function](reference/twig-image-tag.md)
