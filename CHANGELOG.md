# Changelog

## 0.1.1 - 2026-06-10

- Added `data-set` attributes to picture output so transform usage can be tracked outside processing previews without leaking internal processing markers.
- Wrapped SVG output in `<picture>` markup, preserving picture/image classes while excluding SVGs from transform processing.
- Improved handling for observed transform handles that are no longer found on the processed page, including clearer review warnings and an option to remove stale observations.
- Kept observed-but-unsaved transform handles out of the sidebar while preserving them in the review flow where they can be processed or dismissed.
- Added test coverage for SVG processing exclusions, editable picture attributes, observed-handle review states, and sidebar cleanup behavior.

## 0.1.0 - 2026-06-08

- Initial beta release for Craft CMS 5.
