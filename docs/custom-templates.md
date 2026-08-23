# Custom Image Templates

Breakpoints can render image markup through your own Twig templates. Use this when the default `<picture>` output works technically, but your project needs different markup, wrapper hooks, classes, attributes, or integration with a lazy-loading system.

Custom templates are configured with two paths:

- `pictureTemplatePath`: template used for raster assets
- `svgTemplatePath`: template used for SVG assets

Put custom template files in your Craft `templates/` directory and set each path
relative to that directory. Leave either setting blank to use the plugin default.

## Configure Template Paths

Set template paths in the Breakpoints plugin settings, in `config/breakpoints.php`, or per `image()` call.

```php
<?php

return [
    'pictureTemplatePath' => '_images/breakpoints-picture.twig',
    'svgTemplatePath' => '_images/breakpoints-svg.twig',
];
```

```twig
{{ image(asset, 'hero', {
  pictureTemplatePath: '_images/breakpoints-picture.twig',
  svgTemplatePath: '_images/breakpoints-svg.twig'
}) }}
```

Per-call options override global settings for that one image.

## Available Variables

Breakpoints passes these variables to custom templates:

- `image`: the Craft asset element being rendered
- `config`: the merged image configuration for this render
- `pictureAttributes`: attributes for the `<picture>` element
- `imgAttributes`: attributes for the fallback `<img>` element
- `breakpoints`: ordered breakpoint map used by the template
- `breakpointData`: prebuilt source rows keyed by canonical slot key
- `sourceAssetsBySlot`: art-directed asset overrides keyed by canonical slot key
- `sourceConfigsBySlot`: art-directed source config keyed by canonical slot key
- `isProcessing`: `true` only inside the processing preview iframe

Raster templates use `pictureAttributes`, `imgAttributes`, and `breakpointData`. SVG templates use `pictureAttributes`, `imgAttributes`, and `isProcessing`. They do not emit `<source>` elements.

## Source Data

Use `breakpointData` inside raster templates to build each `<source>` element.
These rows are prebuilt before Twig renders, so render adapters such as
ThumbHash can update the source attributes without custom template changes.

```twig
{% for key, row in breakpointData %}
  <source{{ _self.attrs(row.primarySourceAttributes) }}>
{% endfor %}
```

The older `craft.images.getBreakpointData()` helper remains available, but it
returns raw Breakpoints source data for the requested slot. Prefer
`breakpointData` when custom templates should participate in render adapters.

`breakpointData` automatically uses the configured art-directed source asset for
each slot when `config.sources` is present. `sourceAssetsBySlot` is available
when a custom template needs to inspect those overrides directly. Use
`sourceConfigsBySlot` when you need source options such as per-source quality.

The returned array includes:

- `primaryFormat`: primary transform data for the slot (always present; a 1×1 placeholder if a transform is missing)
- `secondaryFormat`: secondary transform data, or `null` when no secondary format is enabled
- `primarySourceAttributes`: attributes for the primary `<source>`
- `secondarySourceAttributes`: attributes for the secondary `<source>`
- `asset`: the effective Craft asset for this slot
- `disabled`: `true` when that slot is turned off in the transform set

The source attribute arrays can include `srcset`, `type`, `width`, `height`, and `media`. When the lazysizes adapter is active, `srcset` / `sizes` are moved to `data-srcset` / `data-sizes`. During processing they also include internal `data-bp-*` and `data-set-*` markers.

## Raster Template Example

This example mirrors the built-in raster template and is a safe starting point for custom markup.

```twig
{% macro attrs(attributes) %}
  {%- for attr, value in attributes -%}
    {%- if value is not null and value is not empty -%}
      {{- (' ' ~ attr ~ '="' ~ value|e ~ '"')|raw -}}
    {%- endif -%}
  {%- endfor -%}
{% endmacro %}

<picture{{ _self.attrs(pictureAttributes) }}>
  {% for key, row in breakpointData %}
    {% if row.primaryFormat %}
      <source{{ _self.attrs(row.primarySourceAttributes) }}>

      {% if row.secondaryFormat %}
        <source{{ _self.attrs(row.secondarySourceAttributes) }}>
      {% endif %}
    {% endif %}
  {% endfor %}

  <img{{ _self.attrs(imgAttributes) }}>
</picture>
```

## SVG Template Example

This example mirrors the built-in SVG template. SVG output is a `<picture>`
wrapping a single `<img>` (no `<source>` elements). If `pictureClass` is empty,
`imgClass` is copied onto `<picture>`. During processing the template sets
`data-bp-processing-ignore` so the editor does not measure SVGs.

```twig
{% macro attrs(attributes) %}
  {%- for attr, value in attributes -%}
    {%- if value is not null and value is not empty -%}
      {{- (' ' ~ attr ~ '="' ~ value|e ~ '"')|raw -}}
    {%- endif -%}
  {%- endfor -%}
{% endmacro %}

{% set svgPictureAttributes = pictureAttributes %}
{% if (svgPictureAttributes.class ?? '') is empty and (imgAttributes.class ?? '') is not empty %}
  {% set svgPictureAttributes = svgPictureAttributes|merge({ class: imgAttributes.class }) %}
{% endif %}
{% if isProcessing ?? false %}
  {% set svgPictureAttributes = svgPictureAttributes|merge({ 'data-bp-processing-ignore': 'svg' }) %}
{% endif %}

<picture{{ _self.attrs(svgPictureAttributes) }}>
  <img{{ _self.attrs(imgAttributes) }}>
</picture>
```

## Keep Breakpoints Attributes Intact

Render all attributes Breakpoints provides unless you have a specific reason to remove one.

`data-set` is set on `<picture>` when transform editing is allowed (the plugin
default; typically local only). Processing finds images with
`picture[data-set]`. Omitting it prevents the editor from measuring that image.

During processing, Breakpoints also adds internal marker attributes to
`<picture>`, `<source>`, and `<img>` (`data-bp-*`, `data-set-width` /
`data-set-height`, `data-picture-id`, `data-asset-id`, `data-uid`). Those exist
only inside the processing preview iframe. Custom templates that drop them can
prevent processing and review from reading the image correctly.

Do not write CSS or JavaScript that depends on these markers. They are editor
implementation details, not a public front-end contract.

## Failure Behavior

If a custom template cannot render, Breakpoints logs an error and rethrows the
exception. There is no fallback `<img>` for developer templates.

If the plugin's own bundled template fails (`breakpoints/picture.twig` or
`breakpoints/svg.twig`), Breakpoints logs the error and falls back to a simple
`<img>` built from `imgAttributes`. That fallback will not include the
responsive `<picture>` source set.

## Low-Quality Placeholders and Non-native Lazy Loading

When native lazy loading is off, set `processingLazyLoadingAdapter` to
`lazysizes` if the plugin should rewrite markup and unveil images during
processing. Use `none` when this template owns `src` / `data-src` itself.

### Imgixer And Lazysizes

This example owns the attribute rewrite, so keep native lazy loading off and
adapter `none`. Processing will not unveil lazysizes in that mode; it measures
layout from the rendered `<img>`.

`imgix()` takes Imgix keys (`w`, `h`, `fm`). Craft `width` / `height` /
`format` keys are passed through unchanged and ignored. Per-slot LQIP must use
`row.asset` (art-directed slots); the fallback `<img>` uses `image`.

```php
'nativeLazyLoadingEnabled' => false,
'processingLazyLoadingAdapter' => 'none',
```

```twig
{# macros #}

{# Moves real image URLs into data-* attrs when lazysizes is active. #}
{% macro attributes(attributes, useLazySizes, isImg) %}
  {%- for attr, value in attributes -%}
    {%- if value is not null and value is not empty -%}
      {%- set renderedAttr = attr -%}
      {%- if useLazySizes -%}
        {%- if attr == 'srcset' -%}
          {%- set renderedAttr = 'data-srcset' -%}
        {%- elseif attr == 'sizes' -%}
          {%- set renderedAttr = 'data-sizes' -%}
        {%- elseif isImg and attr == 'src' -%}
          {%- set renderedAttr = 'data-src' -%}
        {%- endif -%}
      {%- endif -%}
      {{- (' ' ~ renderedAttr ~ '="' ~ value|e ~ '"')|raw -}}
    {%- endif -%}
  {%- endfor -%}
{% endmacro %}

{# Builds the blurred Imgixer placeholder used before lazysizes swaps in the real URL. #}
{% macro lqipUrl(asset, attributes, lqipConfig, fallbackFormat) %}
  {% set fm = attributes.type is defined ? attributes.type|replace({ 'image/': '' }) : fallbackFormat %}
  {% set lqipTransform = lqipConfig %}
  {% if attributes.width %}
    {% set lqipTransform = lqipTransform|merge({ w: attributes.width }) %}
  {% endif %}
  {% if attributes.height %}
    {% set lqipTransform = lqipTransform|merge({ h: attributes.height }) %}
  {% endif %}
  {% if fm %}
    {% set lqipTransform = lqipTransform|merge({ fm: fm }) %}
  {% endif %}
  {{- imgix(asset, lqipTransform) -}}
{% endmacro %}

{% import _self as pictureTemplate %}

{# logic #}
{% set loading = (config.loading ?? imgAttributes.loading ?? '')|lower|trim %}

{# Native-on and loading="eager" both skip the lazysizes rewrite. #}
{% set useLazySizes =
  not (config.nativeLazyLoadingEnabled ?? true)
  and loading != 'eager'
%}
{% set lqipConfig = { blur: 700 } %}

{% if useLazySizes %}
  {% set imgClass = imgAttributes.class ?? '' %}

  {# Add the lazysizes hook class without dropping caller-provided image classes. #}
  {% if 'lazyload' not in imgClass|trim|split(' ') %}
    {% set imgAttributes = imgAttributes|merge({
      class: (imgClass ~ ' lazyload')|trim
    }) %}
  {% endif %}
{% endif %}

{# templating #}
<picture{{ pictureTemplate.attributes(pictureAttributes, false, false) }}>
  {% for key, row in breakpointData %}
    {% if row.primaryFormat %}
      {% set remapRow = useLazySizes and not (row.disabled ?? false) %}
      {% set slotAsset = row.asset ?? image %}
      {# Sources get real srcsets in data-srcset and LQIP placeholders in srcset. #}
      <source{{ pictureTemplate.attributes(row.primarySourceAttributes, remapRow, false) }}{% if remapRow %} srcset="{{ pictureTemplate.lqipUrl(slotAsset, row.primarySourceAttributes, lqipConfig, config.format ?? null)|e }}"{% endif %}>

      {% if row.secondaryFormat %}
        <source{{ pictureTemplate.attributes(row.secondarySourceAttributes, remapRow, false) }}{% if remapRow %} srcset="{{ pictureTemplate.lqipUrl(slotAsset, row.secondarySourceAttributes, lqipConfig, config.secondaryFormat ?? null)|e }}"{% endif %}>
      {% endif %}
    {% endif %}
  {% endfor %}

  {# The fallback img follows the same pattern: real URL in data-src, LQIP in src. #}
  <img{{ pictureTemplate.attributes(imgAttributes, useLazySizes, true) }}{% if useLazySizes %} src="{{ pictureTemplate.lqipUrl(image, imgAttributes, lqipConfig, config.format ?? null)|e }}"{% endif %}>
</picture>
```

## See also

- [`image()` Twig Function](reference/twig-image-tag.md) — including `pictureTemplatePath` / `svgTemplatePath`.
- [Responsive Images](responsive-images.md) — the output model and source behavior.
