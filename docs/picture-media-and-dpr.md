# Media Queries & DPR

This page explains how Breakpoints turns a transform set into the actual
`<source>` elements, media queries, and `srcset` density descriptors in the
rendered `<picture>`. For the conceptual overview see
[Responsive Images](responsive-images.md); for the markup you can customise see
[Custom Image Templates](custom-templates.md).

## Anatomy of the output

For a raster asset, Breakpoints renders one block per **enabled** breakpoint slot:

```html
<picture class="…">
  <!-- one (or two) <source> per enabled slot, smallest first -->
  <source srcset="…" type="image/webp" media="(max-width: 47.9375rem)" width="768" height="432">
  …
  <img src="…" width="640" height="360" alt="…" loading="lazy" decoding="async">
</picture>
```

- A **primary** `<source>` for each enabled slot, in slot order (smallest first).
- An optional **secondary** `<source>` immediately after each primary one, when a
  secondary format is configured.
- A single `<img>` fallback at the end.

SVG assets skip all of this and render as a plain `<img>` — they scale infinitely,
so resolution-specific variants add nothing. See `svg.twig`.

## Media ranges

Slots are ordered smallest to largest and converted into non-overlapping media
ranges so every viewport width has exactly one matching source:

- **Smaller slots** use a `max-width` query.
- **The largest enabled slot** uses a `min-width` query (everything above the
  previous breakpoint).
- **If only one slot is enabled**, no `media` attribute is emitted — that single
  source always applies.

Widths are expressed in `rem` (pixels ÷ 16) and stop one pixel short of the
breakpoint to avoid overlap. For a slot at `768px`:

```
(768 − 1) ÷ 16 = 47.9375rem  →  media="(max-width: 47.9375rem)"
```

> During processing only, the media boundaries are nudged outward by a small
> margin so Breakpoints can reliably measure the intended source. Normal
> front-end output uses the exact boundaries above.

## Escape width

The **escape width** is an extra-large target for the *final* slot, for layouts
that can grow wider than your largest breakpoint (full-bleed heroes on very wide
displays). It does not add a slot — it changes the final slot's target width.

Enable it per render or per set:

```twig
{{ image(asset, 'hero', { includeEscapeWidth: true }) }}
```

The width itself comes from the `escapeWidth` setting (default `1920`; `0`
disables it). See [Configuration](configuration.md).

## DPR (device pixel ratio)

Each `<source>` (and the `<img>`) can offer higher-density variants through
`srcset` density descriptors. The `dpr` setting controls which ratios are
generated:

- `1x` is **always** included.
- Add `2` and/or `3` for high-density variants.
- Invalid, zero, negative, and non-finite values are ignored; ratios are sorted
  and de-duplicated.

With `dpr: [1, 2]`, a source renders:

```html
<source srcset="img-768.webp 1x, img-1536.webp 2x" type="image/webp" media="…">
```

With only `dpr: [1]`, no density descriptor is added — the `srcset` is just the
single URL. Each non-`1x` ratio is a separate Craft transform at the scaled
width and height.

## Formats: primary, secondary, and the fallback

- The **primary** format (`format`) produces the first `<source>` for each slot.
- The **secondary** format (`secondaryFormat`) produces a second `<source>` right
  after it. Leave it `none` to emit only one source per slot.
- Each `<source>` carries a `type` (e.g. `image/webp`, `image/avif`) so the browser
  can skip formats it can't decode.
- The browser uses the **first** source whose `media` matches *and* whose `type` it
  supports; otherwise it falls back to the `<img>`.
- The `<img>` `src` is the **primary**-format transform of the first enabled slot,
  plus `alt`, `class`, `decoding`, and (when native lazy loading is on) `loading`.

Because the browser takes the first supported source, list the **preferred** format
as the primary one. A robust, widely-compatible pairing:

```twig
{{ image(asset, 'hero', {
  format: 'webp',
  secondaryFormat: 'jpg'
}) }}
```

Here modern browsers take the WebP source and older ones fall back to JPEG. For an
aggressive setup use `format: 'avif'` with `secondaryFormat: 'webp'`.

## Disabling slots per render

To skip specific slots for a single placement (without changing the saved set),
pass `disableBreakpoints` keyed by slot label:

```twig
{{ image(asset, 'hero', { disableBreakpoints: { md: true } }) }}
```

A disabled slot emits no `<source>`, and the remaining slots' media ranges adjust
accordingly. See the [`image()` reference](reference/twig-image-tag.md#disablebreakpoints).

## See also

- [Responsive Images](responsive-images.md)
- [Custom Image Templates](custom-templates.md)
- [`image()` Twig Function](reference/twig-image-tag.md)
