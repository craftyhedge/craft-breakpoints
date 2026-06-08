# Breakpoints

[![CI](https://github.com/craftyhedge/craft-breakpoints/actions/workflows/ci.yml/badge.svg)](https://github.com/craftyhedge/craft-breakpoints/actions/workflows/ci.yml)
[![PHPStan Level 7](https://img.shields.io/badge/PHPStan-level%207-brightgreen?logo=php)](https://github.com/craftyhedge/craft-breakpoints/blob/main/phpstan.neon)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%20|%208.3%20|%208.4-777BB4?logo=php&logoColor=white)](https://github.com/craftyhedge/craft-breakpoints)
[![Craft 5.3+](https://img.shields.io/badge/Craft%20CMS-5.3%2B-e5422b?logo=craft-cms&logoColor=white)](https://github.com/craftyhedge/craft-breakpoints)

> Breakpoints is currently in beta. APIs, configuration, and generated
> transform-set structure may change before the stable `1.0.0` release.

Breakpoints handles the front end markup for your Craft assets and provides a developer processing tool to accurately measure each breakpoint rendering size and create transform sets without any manual work.

Processing is intended for local development environments with the resulting transform sets being commited to git.

## Key concepts

- **Transform set** — a named, reusable recipe (e.g. `hero`, `card`) describing the
  image dimensions, format, and quality at each breakpoint. Stored as JSON in
  `config/breakpoints/transform-sets.json`.
- **Breakpoint slots** — every set has a `base` slot (smallest viewport) followed by
  one slot per configured breakpoint (`xs`, `sm`, `md`, …), in width order.
- **Processing run** — a control-panel pass that renders a chosen entry, measures
  the rendered size of each breakpoint image, and lets you apply those
  measurements back into the set.
- **Escape width** — an optional extra-large variant for the final slot, to keep very
  wide layouts sharp.
- **DPR variants** — high-density `srcset` descriptors (`1x` always; opt in to `2x`/`3x`).

## Features

- One `<source>` per enabled breakpoint, with correct `media` ranges.
- Primary format plus an optional secondary `<source>` fallback (e.g. AVIF + WebP).
- DPR `srcset` density descriptors.
- SVG assets pass through to a plain `<img>` (no `<picture>`).
- Native `loading="lazy"` / `decoding` control.
- Fully overridable Twig templates for custom markup and lazy-loading integrations.
- File-backed transform sets that are commit-friendly and reviewable in version control.

## Requirements

- PHP `^8.2`
- Craft CMS `^5.3.0`

## Install

### Option 1: Composer

Install the current beta with Composer:

```sh
composer require craftyhedge/breakpoints:^0.1.0@beta
```

To pin the exact beta release:

```sh
composer require craftyhedge/breakpoints:0.1.0-beta.1
```

Then install the plugin:

```sh
php craft plugin/install breakpoints
```

### Option 2: Plugin Store (Control Panel)

1. Open the Craft Control Panel.
2. Go to **Plugin Store**.
3. Find **Breakpoints**.
4. Click **Install**.

## Quick start

1. Render the image in a template:

   ```twig
   {{ image(entry.heroImage.one(), 'hero', {
     initWidth: 1200,
     initHeight: 675
   }) }}
   ```
2. Keep building the component with that usable output.
3. When the layout is ready, open **Breakpoints → Transform Sets**, process the
   entry to create the transform set. Review and edit as needed.
4. Put shared defaults, especially breakpoint widths, in `config/breakpoints.php`.
   Use **Breakpoints → Settings** for quick output/default changes.

See [Getting Started](docs/getting-started.md) for the full walkthrough.

## Documentation

- [Getting Started](docs/getting-started.md) — render a usable image, finalize the set.
- [Configuration](docs/configuration.md) — settings, the config file, and precedence.
- [Responsive Images](docs/responsive-images.md) — the output model and standards.
- [Media Queries & DPR](docs/picture-media-and-dpr.md) — how sources, media ranges, and DPR are built.
- [Custom Image Templates](docs/custom-templates.md) — render through your own Twig.
- [`image()` Twig Function](docs/reference/twig-image-tag.md) — full API reference.
- [Release history](CHANGELOG.md)

## Support

- Source: <https://github.com/craftyhedge/breakpoints>
- Issues: <https://github.com/craftyhedge/breakpoints/issues>

## License

Commercial plugin licensing follows the Craft License model.

See [LICENSE.md](LICENSE.md).
