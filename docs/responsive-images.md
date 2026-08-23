# Responsive Images

## Overview

Breakpoints outputs responsive images using standard picture markup. You choose
the breakpoints (in `config/breakpoints.php`), image formats, and DPR ratios;
Breakpoints turns those choices into standard HTML:

- `<picture>`
- `<source>`
- `<img>`

Raster assets get a `<picture>` with one `<source>` per slot and a fallback
`<img>`. SVG assets get a `<picture>` wrapping a single `<img>` (no
`<source>` elements) and are skipped during processing.

From there, the browser chooses the best available image for the visitor's
screen size and device pixel density.

## What Gets Rendered

Slots are synthetic `base` plus one slot per configured breakpoint name,
ordered by ascending width. `base` uses the smallest breakpoint width as its
media.

For each slot, Breakpoints can render:

- A primary `<source>` for your main image format
- A secondary `<source>` when you have configured a fallback format
- A final `<img>` element as the browser fallback

This keeps the markup compatible with normal browser behavior while still giving you control over image size, format, and sharpness.

## Transform Services

Breakpoints uses native Craft transforms. It will work with external transform services but this requires a plugin or custom code that will convert Craft's native transform methods to the external service.

External transform services are recommended. They mainly speed things up when
new transforms are generated, compared with native Craft transforms.

Known working options:
- [Imgixer](https://plugins.craftcms.com/imgixer) - as long as you set the transformSource to the external service you configure
- [Imgix Asset Transformer](https://plugins.craftcms.com/newism-imgix) - a Newism plugin that replaces native image transforms with Imgix
- [Cloudinary](https://plugins.craftcms.com/cloudinary) - uses Cloudinary for Craft's native asset transforms and named image transforms
- [Transform Mapper](https://plugins.craftcms.com/transform-mapper) - a free Crafty Hedge plugin that maps Crafts transform methods to external services
- [Servd](https://plugins.craftcms.com/servd-asset-storage) - Servd Assets and Helpers plugin

## Breakpoints

Configured breakpoint names are sorted by width and turned into slots (`base`
first). Smaller slots use `max-width` rules; the last enabled slot uses
`min-width`.

That means each viewport width has a clear matching source, from the smallest
screens through to the largest layouts.

This works well with frameworks like Tailwind. Set breakpoint widths in
`config/breakpoints.php` so they match your CSS.

## Escape Width

Escape width is an optional extra-large target for the final slot. Use it when a
layout can grow wider than your largest breakpoint, such as a full-bleed hero on
very wide displays.

It does not add another breakpoint slot. It only changes the final slot's target
image width when enabled.

```twig
{{ image(asset, 'hero', {
  escape: true
}) }}
```

After processing, the saved transform set keeps escape on, so later renders do
not need the Twig flag.

The width comes from the `escapeWidth` setting. The default is `1920`; set it to
`0` to disable escape width project-wide.

## High-Density Displays

Breakpoints also supports DPR variants through `srcset` density descriptors.

The `1x` image is always included. Density descriptors (`1x`, `2x`, …) are only
written when `dpr` has more than `1`; a `[1]` config stays a plain URL. The
Control Panel offers common `2x` and `3x` presets, and project or Twig config
may provide any positive numeric ratio such as `1.5`. The browser can choose a
sharper asset for higher-density displays when it needs one.

## Settings That Matter

The main settings that affect responsive image output are:

- Breakpoints
- Escape width
- Format
- Secondary Format
- DPR ratios

The default breakpoint set includes familiar labels such as `xs`, `sm`, `md`, `lg`, `xl`, and `2xl`, but you can adjust these to match your project. Those names are preceded by a synthetic `base` slot.

## Processing Markers

When transform editing is allowed (local default), `<picture>` includes
`data-set` so processing can find the image.

During a processing request, Breakpoints also adds internal markers:
`data-bp-*` and `data-set-width` / `data-set-height` on `<source>`, and
`data-picture-id` / `data-asset-id` / `data-uid` on `<picture>` / `<img>`.
Those extra markers are not on normal front-end output.

Do not rely on these attributes in your templates, CSS, or JavaScript. They are
internal to Breakpoints and are not part of the public output. Using them in
your project may break processing.

## Caching During Processing

When processing locally, Breakpoints needs fresh, uncached page markup so it can
read its processing markers. Breakpoints disables Craft template caching for the
processing request.

Do **not** serve full-page or static cached pages while processing locally.
Cached pages may not include the markers Breakpoints needs, which can prevent
processing from seeing your configured breakpoints.

## References

- HTML Living Standard
  - https://html.spec.whatwg.org/multipage/embedded-content.html#the-picture-element
  - https://html.spec.whatwg.org/multipage/images.html#attr-img-srcset
- MDN
  - https://developer.mozilla.org/en-US/docs/Web/HTML/Element/picture
  - https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/source
- Craft CMS Image Transforms
  - https://craftcms.com/docs/5.x/development/image-transforms.html

## See also

- [Configuration](configuration.md) — settings, defaults, and precedence.
- [`image()` Twig Function](reference/twig-image-tag.md) — the rendering API.
