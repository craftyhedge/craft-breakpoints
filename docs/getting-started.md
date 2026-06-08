# Getting Started

Start in the template, not the control panel. Breakpoints gives you usable
responsive image markup immediately, so you can keep building the component with
a sensible placeholder ratio instead of stopping to configure every transform.

Once the layout has settled, process the real page in the control panel.
Breakpoints measures the rendered image sizes, applies new transform sets
automatically, and lets you review or edit the final dimensions.

## 1. Install

Install with Composer, then enable the plugin:

```sh
composer require craftyhedge/breakpoints
php craft plugin/install breakpoints
```

Or install **Breakpoints** from the control panel Plugin Store. See the
[README](../README.md#install) for both paths.

## 2. Render a usable image

Call `image()` with the asset and a transform set name:

```twig
{{ image(entry.heroImage.one(), 'hero') }}
```
In this example, the image tag will use an existing transform set named 'hero' or create a new set for it when processed.

Until a set exists for 'hero' the defaults will be used unless you specify init values.

```twig
{{ image(entry.heroImage.one(), 'hero', {
  initWidth: 1200,
  initRatio: "16:9",
  pictureClass: 'c-hero__picture',
  imgClass: 'c-hero__img'
}) }}
```

That is enough to keep working on the surrounding component. Raster assets render
as a `<picture>` with one `<source>` per breakpoint and an `<img>` fallback; SVG
assets render as a plain `<img>`. If the asset is `null`, Breakpoints returns an
HTML comment instead of markup.

See the [`image()` reference](reference/twig-image-tag.md) for every option.

## 3. Set project defaults in config

Your projects should put their shared Breakpoints defaults in
`config/breakpoints.php` so they can be version-controlled. This is where you define the viewport breakpoints your design uses:

```php
<?php

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
];
```

See [Configuration](configuration.md) for every setting and how the config layers
combine.

## 4. Process and save the transform set

A **transform set** is a named object (e.g. `hero`) describing the image at each
breakpoint. 

Once your component layout is ready, process the page to measure the
real rendered image sizes.

1. Open **Breakpoints → Transform Sets** and choose the entry with the image(s) to process.

   ![Choose a source entry for processing](images/choose-source.png)

2. Once processing is complete, any new image transform sets will be applied and saved automatically. New sets trigger a reprocessing to verify the applied dimensions haven't drifted.

   ![Choose a source entry for processing](images/results.png)

Image transforms should support the component layout, not define it. Use CSS
constraints such as `width`, `max-width`, `aspect-ratio`, or grid/flex rules to
hold the image in the intended space as its generated dimensions change.

> Processing goes as fast as transforms are generated. Some initial runs can take a while and depends on your set up.

> Breakpoints disables Craft template caching during processing,
> but it cannot bypass full-page caches such as Blitz or static caching. Turn
> those off locally while processing. See
> [Responsive Images → Caching During Processing](responsive-images.md#caching-during-processing).

## Next steps

- [Configuration](configuration.md) — settings and the config file.
- [Custom Image Templates](custom-templates.md) — custom markup, LQIP, and lazy-loading libraries.
- [Media Queries & DPR](picture-media-and-dpr.md) — how the output is assembled.
