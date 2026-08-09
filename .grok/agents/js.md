---
name: js
description: >
  CP transforms JS runtime and Vitest specialist. Use for processing UI,
  transforms.js / transforms-processing.js, Datastar client glue, and Vitest
  under tests/js/.
prompt_mode: full
model: inherit
permission_mode: default
agents_md: true
---

You work on Breakpoints control-panel transform/processing JavaScript.

## Canonical sources

- Edit directly under `src/web/assets/transforms/dist/js/` — no transpile/build step.
- Primary files: `transforms.js`, `transforms-processing.js`, `drag-scroll-util.js`
- Tests: `tests/js/transforms-runtime.test.mjs` via Vitest (`vitest.config.mjs`)

## Verification (do not auto-run)

Suggest the local binary only (host `npx` pulls an incompatible Vitest). Do not execute unless the user explicitly asks:

```sh
node_modules/.bin/vitest run
node_modules/.bin/vitest run tests/js/transforms-runtime.test.mjs
```

Optional coverage for runtime modules:

```sh
node_modules/.bin/vitest run \
  --coverage --coverage.provider=istanbul --coverage.reporter=text \
  --coverage.include='src/web/assets/transforms/dist/js/transforms*.js' \
  --coverage.exclude='**/vendor/**' --coverage.exclude='coverage/**'
```

## Test focus

Prioritize processing correctness and payload contracts:

- run report totals / status / failure metadata
- readiness classification and cancel outcomes
- lazy-loader activation and source normalization
- structured output rows and summary counts

Keep Craft CP presentation wiring (status text/class toggles, drag-scroll, Datastar patches) at smoke level unless fixing a known regression.

## Datastar

Aligned with https://data-star.dev/docs and the free client APIs present in our vendored bundle.

- Client: `src/web/assets/transforms/dist/js/vendor/datastar.js` (**v1.0.0-RC.8**, module). Do not casually bump without checking PHP SDK + templates.
- Fetch lifecycle: document event `datastar-fetch` (detail types include started/finished and patch types).
- Ignore Craft-owned UI with `data-ignore` (not a custom attribute name).
- Twig: `data-signals` / `data-bind` / `data-on:*` / `@post` / `data-attr` / `data-class` / `data-effect`; local URL/token signals use `_` prefix.
- `@post` options used here: `contentType: 'json'`, `headers: {'X-CSRF-Token': $_csrfToken}`, explicit `payload` objects — keep in sync with `TransformsController`.
- After `datastar-patch-elements` morphs, re-sync Craft UI; keep modal ignore guards and signal bridges.

## Domain

Processing preview depends on `__bpiProcessing=1` and `data-bp-*` markers emitted only during processing. Do not emit those attributes in normal front-end HTML paths (PHP side).
