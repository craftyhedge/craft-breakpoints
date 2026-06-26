# Changelog

## Unreleased

- Added art-directed raster image sources via `image()` options, allowing selected canonical slots to render from alternate Craft assets within one `<picture>`. Custom picture templates that use `craft.images.getBreakpointData()` continue to receive the correct per-slot source asset automatically.

## 0.1.4 - 2026-06-21

- Improved lazy-loading activation during processing, especially for lazysizes, by widening lazy-load thresholds, clearing stale lazysizes state, and recording activation diagnostics.
- Fixed lazy-loaded placeholder handling so rows remain pending or unresolved until the intended lazy target source is actually promoted, preventing placeholder URLs from being treated as successful transform measurements.
- Improved processing readiness tracking for duplicate picture IDs and asset-only picture markers so repeated transform uses are measured as separate images.
- Improved processing completion messaging so existing transform sets skipped during auto-save are treated as already saved, clean runs report when no new sets were created, and rendered mismatches keep the final status in a warning state.
- Improved review cards for breakpoint and asset mismatches with live card warning signals, resolved-mismatch review states, mismatch counts, clearer mismatch copy, and prioritised ordering for newly created transform sets.
- Fixed auto-saving for observed processed transform sets when the saved-set signal is stale, and highlighted newly created transform sets in the rendered review.
- Improved review thumbnails and fallback sizing to prefer the resolved `sourceUsed` URL over lazy-loading placeholder sources.
- Fixed compact breakpoint cards so escape-width badges wrap cleanly.
- Expanded getting-started and responsive-image documentation with workflow screenshots, clearer review-warning guidance, height-warning notes, and transform-service compatibility notes.
- Expanded JavaScript and PHP test coverage for lazy-loading activation, stale lazy targets, duplicate picture IDs, processing completion states, new-set review rendering, source selection, and fallback dimensions.

## 0.1.3 - 2026-06-12

- Fixed final-breakpoint processing so each transform set uses its configured normal or escape-width measurement, with a fallback warning when the preferred measurement is unavailable.
- Improved processing source selection by activating the requested picture sources and accepting lazy-loaded fallback formats from the selected breakpoint slot.
- Reduced the breakpoint measurement margin from 2px to 1px for more accurate viewport-boundary measurements.
- Improved allowed-height controls with clearer **Shorter heights allowed** and **All heights allowed** indicators and a more responsive options layout.
- Added documentation for allowed-height settings and transform-set notes.
- Expanded JavaScript and PHP test coverage for final measurements, source activation, lazy-loaded fallback formats, and allowed-height indicators.

## 0.1.2 - 2026-06-12

- Added explicit lazy-loading adapters for processing previews, with support for attribute-based loading, lazysizes, vanilla-lazyload, lozad, and custom handlers.
- Improved processing reliability for lazy-loaded images, source assets, hidden or skipped breakpoints, and transform dimension changes that require another processing run.
- Added direct ratio copying and ratio removal controls for individual or all breakpoints, with clearer scope handling and editor state updates.
- Improved transform review feedback for edited, stale, matching, and mismatched dimensions, including clearer warnings and a **Process Again** state after edits.
- Improved observed transform usage so only raster images are recorded and source entry information remains available when no processing run exists.
- Simplified database settings around clearing observed usage, processing snapshots, and preview cache data.
- Improved the Transform Sets interface with more consistent tab state, styling fixes, disabled invalid ratio-copy choices, and a heading for the currently displayed sets.
- Expanded configuration and getting-started documentation for processing adapters and review warnings.

## 0.1.1 - 2026-06-10

- Added `data-set` attributes to picture output so transform usage can be tracked outside processing previews without leaking internal processing markers.
- Wrapped SVG output in `<picture>` markup, preserving picture/image classes while excluding SVGs from transform processing.
- Improved handling for observed transform handles that are no longer found on the processed page, including clearer review warnings and an option to remove stale observations.
- Kept observed-but-unsaved transform handles out of the sidebar while preserving them in the review flow where they can be processed or dismissed.
- Added test coverage for SVG processing exclusions, editable picture attributes, observed-handle review states, and sidebar cleanup behavior.

## 0.1.0 - 2026-06-08

- Initial beta release for Craft CMS 5.
