# Custom Image Templates

Breakpoints can render image markup through your own Twig templates. Use this when the default `<picture>` output works technically, but your project needs different markup, wrapper hooks, classes, attributes, or integration with a lazy-loading system.

Custom templates are configured with two paths:

- `pictureTemplatePath`: template used for raster assets
- `svgTemplatePath`: template used for SVG assets

Paths are resolved in Craft's site template mode and are relative to your site's template root. Leave either setting blank to use the plugin default.

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

`pictureAttributes` is useful for raster templates. SVG templates usually only need `image`, `config`, and `imgAttributes`.

## Source Data

Use `craft.images.getBreakpointData()` inside raster templates to build each `<source>` element.

```twig
{% set breakpointData = craft.images.getBreakpointData(loop.index0, breakpoint, config, image) %}
```

The returned array includes:

- `primaryFormat`: primary transform data, or `null` when unavailable
- `secondaryFormat`: secondary transform data, or `null` when no secondary format is enabled
- `primarySourceAttributes`: attributes for the primary `<source>`
- `secondarySourceAttributes`: attributes for the secondary `<source>`
- `disabled`: present and `true` when the breakpoint slot is disabled

The source attribute arrays can include `srcset`, `type`, `width`, `height`, and `media`.

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
  {% for key, breakpoint in breakpoints %}
    {% set breakpointData = craft.images.getBreakpointData(loop.index0, breakpoint, config, image) %}

    {% if breakpointData.primaryFormat %}
      <source{{ _self.attrs(breakpointData.primarySourceAttributes) }}>

      {% if breakpointData.secondaryFormat %}
        <source{{ _self.attrs(breakpointData.secondarySourceAttributes) }}>
      {% endif %}
    {% endif %}
  {% endfor %}

  <img{{ _self.attrs(imgAttributes) }}>
</picture>
```

## SVG Template Example

This example mirrors the built-in SVG template.

```twig
{% macro attrs(attributes) %}
  {%- for attr, value in attributes -%}
    {%- if value is not null and value is not empty -%}
      {{- (' ' ~ attr ~ '="' ~ value|e ~ '"')|raw -}}
    {%- endif -%}
  {%- endfor -%}
{% endmacro %}

<img{{ _self.attrs(imgAttributes) }}>
```

## Keep Breakpoints Attributes Intact

Render all attributes Breakpoints provides unless you have a specific reason to remove one.

During normal front-end requests, the attribute arrays contain clean production attributes. During a processing run, Breakpoints temporarily adds internal marker attributes to `<picture>`, `<source>`, and `<img>` so the editor can inspect rendered image behavior. Custom templates that omit those attributes can prevent processing and review from reading the image correctly.

Do not write CSS or JavaScript that depends on internal marker attributes such as `data-bp-*`, `data-set-*`, or `data-picture-id`. They are processing-only implementation details.

## Failure Behavior

If the configured custom template cannot render, Breakpoints logs a warning and falls back to a simple `<img>` built from `imgAttributes`.

That fallback keeps the page from failing hard, but it will not include the full responsive `<picture>` source set. Check your Craft logs if a custom image template unexpectedly renders as a plain image.

## Low-Quality Placeholders and Non-native Lazy Loading 

### Imgixer And Lazysizes

```twig
{# macros #}

{# Moves real image URLs into data-* attrs when lazysizes is active. #}
{% macro attributes(attributes, useLazySizes, isImg) %}
  {% for attr, value in attributes %}
    {% if value is not null and value is not empty %}
      {% set renderedAttr = attr %}

      {% if useLazySizes %}
        {% if attr == 'srcset' %}
          {% set renderedAttr = 'data-srcset' %}
        {% elseif isImg and attr == 'src' %}
          {% set renderedAttr = 'data-src' %}
        {% endif %}
      {% endif %}

      {{- renderedAttr }}="{{ value }}"
    {% endif %}
  {% endfor %}
{% endmacro %}

{# Builds the blurred Imgixer placeholder used before lazysizes swaps in the real URL. #}
{% macro lqipUrl(image, attributes, lqipConfig, fallbackFormat) %}
  {% set lqipTransform = {
    width: attributes.width ?? null,
    height: attributes.height ?? null,
    format: attributes.type is defined ? attributes.type|replace({ 'image/': '' }) : fallbackFormat,
  }|merge(lqipConfig) %}

  {{- imgix(image, lqipTransform) -}}
{% endmacro %}

{% import _self as pictureTemplate %}

{# logic #}
{% set loading = config.loading ?? imgAttributes.loading ?? null %}

{# loading="eager" is the per-image opt-out from lazysizes. #}
{% set useLazySizes = loading != 'eager' %}
{% set lqipConfig = { blur: 700 } %}

{% if useLazySizes %}
  {% set imgClass = imgAttributes.class ?? '' %}

  {# Add the lazysizes hook class without dropping caller-provided image classes. #}
  {% set imgAttributes = imgAttributes|merge({
    class: ('lazyload' in imgClass ? imgClass : (imgClass ~ ' lazyload')|trim)
  }) %}
{% endif %}

{# templating #}
<picture {{ pictureTemplate.attributes(pictureAttributes, false, false) }}>
  {% for key, breakpoint in breakpoints %}
    {% set breakpointData = craft.images.getBreakpointData(loop.index0, breakpoint, config, image) %}

    {% if breakpointData.primaryFormat %}
      {# Sources get real srcsets in data-srcset and LQIP placeholders in srcset. #}
      <source {{ pictureTemplate.attributes(breakpointData.primarySourceAttributes, useLazySizes, false) }}{% if useLazySizes %} srcset="{{ pictureTemplate.lqipUrl(image, breakpointData.primarySourceAttributes, lqipConfig, config.format ?? null) }}"{% endif %}>

      {% if breakpointData.secondaryFormat %}
        <source {{ pictureTemplate.attributes(breakpointData.secondarySourceAttributes, useLazySizes, false) }}{% if useLazySizes %} srcset="{{ pictureTemplate.lqipUrl(image, breakpointData.secondarySourceAttributes, lqipConfig, config.secondaryFormat ?? null) }}"{% endif %}>
      {% endif %}
    {% endif %}
  {% endfor %}

  {# The fallback img follows the same pattern: real URL in data-src, LQIP in src. #}
  <img {{ pictureTemplate.attributes(imgAttributes, useLazySizes, true) }}{% if useLazySizes %} src="{{ pictureTemplate.lqipUrl(image, imgAttributes, lqipConfig, config.format ?? null) }}"{% endif %}>
</picture>


```
