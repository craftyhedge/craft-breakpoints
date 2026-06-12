# Changelog

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
