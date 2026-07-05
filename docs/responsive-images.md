# Responsive Images

## Overview

Breakpoints outputs responsive images using standard picture markup. You choose the breakpoints, image formats, and DPR ratios in the plugin settings; Breakpoints turns those choices into standard HTML:

- `<picture>`
- `<source>`
- `<img>`

From there, the browser chooses the best available image for the visitor's screen size and device pixel density.

## What Gets Rendered

For each configured breakpoint, Breakpoints can render:

- A primary `<source>` for your main image format
- A secondary `<source>` when you have configured a fallback format
- A final `<img>` element as the browser fallback

This keeps the markup compatible with normal browser behavior while still giving you control over image size, format, and sharpness.

## Transform Services

Breakpoints uses native Craft transforms. It will work with external transform services but this requires a plugin or custom code that will convert Craft's native transform methods to the external service.

External transforms services are recommended in general but do improve processing speed when compared to native transforms. Only when new transforms are generated.

Known working options:
- [Imgixer](https://plugins.craftcms.com/imgixer) - as long as you set the transformSource to the external service you configure
- [Imgix Asset Transformer](https://plugins.craftcms.com/newism-imgix) - a Newism plugin that replaces native image transforms with Imgix
- [Cloudinary](https://plugins.craftcms.com/cloudinary) - uses Cloudinary for Craft's native asset transforms and named image transforms
- [Transform Mapper](https://plugins.craftcms.com/transform-mapper) - a free Crafty Hedge plugin that maps Crafts transform methods to external services
- [Servd](https://plugins.craftcms.com/servd-asset-storage) - Servd Assets and Helpers plugin

## Breakpoints

Your configured breakpoints are ordered and turned into media ranges. Smaller breakpoints use `max-width` rules, and the largest breakpoint uses `min-width`.

That means each viewport width has a clear matching source, from the smallest screens through to the largest layouts.

This works well with frameworks like Tailwind.

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

The width comes from the `escapeWidth` setting. The default is `1920`; set it to
`0` to disable escape width project-wide.

## High-Density Displays

Breakpoints also supports DPR variants through `srcset` density descriptors.

The `1x` image is always included. The Control Panel offers common `2x` and
`3x` presets, and project or Twig config may provide any positive numeric ratio
such as `1.5`. The browser can choose a sharper asset for higher-density
displays when it needs one.

## Settings That Matter

The main settings that affect responsive image output are:

- Breakpoints
- Escape width
- Format
- Secondary Format
- DPR ratios

The default breakpoint set includes familiar labels such as `xs`, `sm`, `md`, `lg`, `xl`, and `2xl`, but you can adjust these to match your project.

## Processing Markers

When Breakpoints processes images, it temporarily adds internal `data-bp-*`
attributes to each generated `<source>`. These attributes help the plugin detect
the configured sources during processing.

Those markers are only added during image processing. Normal front-end output is
clean and does not include them.

Do not rely on `data-bp-*` attributes in your templates, CSS, or JavaScript. They
are internal to Breakpoints and are not part of the public output. Using the those attributes in your project may break processing.

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
