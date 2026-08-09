# Breakpoints (craftyhedge/breakpoints)

Craft CMS 5 plugin for responsive image markup and local transform-set processing.

- Package: `craftyhedge/breakpoints`
- Namespace: `craftyhedge\craftbreakpoints\`
- Source: `src/`
- PHP `^8.2`, Craft CMS `^5.3.0`
- Handle: `breakpoints`
- Internal prefixes: DB tables `bpi_*`, processing query param `__bpiProcessing`; CP UI classes/IDs mostly `bpts-`
- Beta: APIs and transform-set structure may change before `1.0.0`

## Product scope

- Front-end: `image()` Twig function / `craft.images` renders `<picture>` (or plain `<img>` for SVG). Rendering is not gated by transform editing.
- Local processing: CP **Breakpoints → Transform Sets** measures rendered breakpoint sizes and writes transform sets to file-backed JSON for git.
- Processing/editing is intended for local development (README + docs). CP nav and transform-edit actions are gated by `allowTransformEditing` (`ConfigService::allowTransformEditing()` / `TelemetryService::canEditTransforms()`). Plugin default in `src/config.php` is `true`; project config typically turns it off outside local.
- Transform sets: named recipes (e.g. `hero`, `card`) at Craft config path `config/breakpoints/transform-sets.json`.
- Slots: synthetic `base` plus one slot key per configured breakpoint name, ordered by ascending width (`BreakpointSlotResolver` / `ConfigService::normalizeBreakpoints`).

## Architecture

- Plugin services are Craft components from `Plugin::config()`, accessed via `Plugin::getInstance()->getX()`. Do not `new` those components. Domain helpers under `src/services/transformeditor/` are constructed by `TransformEditor` (not registered components). `InitOptions` is a value object (`InitOptions::fromConfig`), not a component.
- `Images` (`craft.images`) is a thin public facade (`render`, `getBreakpointData`); keep real logic in other services.
- Public API boundary (breaking-change sensitive): `image()` Twig function and `craft.images` method signatures.
- `pictureClass` / `imgClass` apply only to `<picture>` / `<img>` attributes (`RenderContextBuilder`), never to `<source>`.
- `TransformStore` is file-backed: path is Craft config path + `/breakpoints/transform-sets.json` (private `SETS_CONFIG_PATH` in that class).
- Default templates: SVG → `breakpoints/svg.twig`, raster → `breakpoints/picture.twig` (plugin files under `src/templates/`; overridable via config).
- DB schema: `src/migrations/Install.php` establishes the full schema on install (tables `bpi_*`). After a release, ship a new incremental migration for existing installs, and keep `Install.php` complete for fresh installs (Craft: Install is the only migration run on install). Never rename or rewrite already-shipped incremental migrations; bump `Plugin::$schemaVersion` when adding migrations.
- Transform editor domain code lives under `src/services/transformeditor/`; CP orchestration in `TransformsController` and `TransformEditor`.
- CP JS under `src/web/assets/transforms/dist/js/` is **canonical** — no separate build step; edit `dist/` files directly.

## Datastar (CP transform editor only)

Datastar powers the **Breakpoints → Transform Sets** CP UI, not front-end `image()` markup.
Official guide: https://data-star.dev/docs (also `/docs.md`). PHP SDK: https://github.com/starfederation/datastar-php

### Stack in this repo

- PHP: `starfederation/datastar-php` `1.0.0` (`PatchElements`, `PatchSignals`, `ServerSentEventGenerator`).
- Client: vendored free bundle `src/web/assets/transforms/dist/js/vendor/datastar.js` (**v1.0.0-RC.8**), loaded as `type="module"` by `TransformsAsset`. Hosting the file yourself matches Datastar’s install guidance.
- App glue: `transforms.js` listens for the client’s `datastar-fetch` custom event and re-syncs Craft UI after patches.

### Protocol (matches Datastar + SDK)

- Datastar backend actions include `@get` / `@post` / `@put` / `@patch` / `@delete`. This editor’s mutations use `@post` with `{contentType: 'json', headers: {'X-CSRF-Token': …}, payload: {…}}` so the body is an explicit JSON payload (not the default “all signals” body).
- Signals whose keys begin with `_` are **local** and are not sent with default backend requests — used for `$_applyCardOperationUrl`, `$_csrfToken`, `$_renderTransformCardUrl`.
- Responses are SSE; controller sets `Content-Type: text/event-stream; charset=UTF-8`. Event types:
  - `datastar-patch-elements` (`PatchElements`)
  - `datastar-patch-signals` (`PatchSignals`)
- Craft-compatible path: apply `ServerSentEventGenerator::headers()`, then concatenate each event’s `getOutput()` into a raw Yii response (`asDatastarEventStream` / `asDatastarSignalsPatch`). Do not call `$sse->sendHeaders()` when Craft owns the response.
- Integration coverage: `tests/integration/TransformsControllerTest.php` asserts those event names in the stream body.

### Frontend attributes used here

Attributes present in CP templates/JS and supported by the vendored free client:

- `data-signals`, `data-bind`, `data-on:*` (e.g. `data-on:click`, `data-on-signal-patch`), `data-attr`, `data-class`, `data-effect`
- Modifiers such as `__throttle.300ms` on `data-on`
- `data-ignore` on Craft modals / shade nodes so Datastar does not process foreign CP DOM (`transforms.js` ignore guards)

Prefer extending existing card-operation / signal paths (`editor.cards.*`, `baseVersion`, CSRF header) over a second UI stack. After element morphs, keep signal bridges and Craft-owned modal ignore guards intact.

## Processing model

- A processing run loads a preview iframe with query param `__bpiProcessing=1` (JS sets the literal string `"1"`).
- PHP: use `helpers/ProcessingRequest::isActive()` only — do not re-parse the query param in PHP elsewhere. (JS may set/read the param when building the iframe URL.)
- Processing-only source markers are gated in `ImageTransforms::buildSourceDataAttributes` (returns `[]` unless processing). When active they include `data-bp-source`, `data-bp-size`, `data-bp-key`, `data-bp-index`, `data-bp-enabled`, `data-bp-asset-id`, `data-bp-asset-title`, `data-bp-measure-width`, `data-set-width`, `data-set-height`, and optional `data-auto-dimension`.
- Processing-only picture/img markers are gated in `RenderContextBuilder` (`composePictureMarkers` / img `data-asset-id` + `data-uid`). Exception: `data-set` on `<picture>` is also set when `allowTransformEditing` is true, even outside processing.
- Sources use `data-bp-source="primary"|"secondary"` (attribute, not class) to avoid class collisions.
- During processing, Craft `{% cache %}` is disabled for that request (`enableTemplateCaching = false` in `Plugin::init`). Full-page caches (Blitz/static) are outside plugin control and must be off locally while processing.

## Config layers

Merged in `ConfigService::buildMergedConfig()` via `array_merge` (later wins), then per-call options in `getConfig($overrides)`:

1. Plugin defaults — `src/config.php` (`getDefaultConfig()`)
2. CP Settings model — only keys that differ from a fresh `Settings` defaults instance (`getPluginSettingsArray()`; not every `config.php` key is on the Settings model)
3. Project file — `config/breakpoints.php` via `Craft::$app->getConfig()->getConfigFromFile('breakpoints')` (`getUserConfig()`)
4. Per-call `image()` third-argument options — highest, `array_merge($mergedConfig, $overrides)` inside `getConfig()`

CP settings Twig disables fields already defined in the project file (`projectConfig.<key> is defined`). Defaults and setting reference: `src/config.php`, `docs/configuration.md` (docs describe layers; **merge order above is the code truth**).

## Docs map

- `docs/getting-started.md` — render then process walkthrough
- `docs/configuration.md` — settings reference and project file examples
- `docs/responsive-images.md` — media ranges, DPR, escape width
- `docs/custom-templates.md` — override Twig markup
- `docs/reference/twig-image-tag.md` — `image()` API
- `TESTING.md` — local test harness (export-ignored from package)

## Commands

**Do not run tests or PHPStan automatically.** After changes, print the exact command(s) and wait for the user to run them and paste results if needed.

PHP (needs PHP 8.2 + MySQL; use ddev):

```sh
ddev start
ddev composer install   # first time
ddev exec vendor/bin/codecept run unit
ddev exec vendor/bin/codecept run integration
ddev exec vendor/bin/codecept run
composer phpstan        # or: ddev exec vendor/bin/phpstan --memory-limit=1G
```

Composer scripts: `composer test`, `composer test:integration`, `composer test:all`, `composer phpstan`.

JS (Vitest; use local binary, not `npx`):

```sh
npm install
node_modules/.bin/vitest run
node_modules/.bin/vitest run tests/js/transforms-runtime.test.mjs
```

JS runtime tests prioritize processing correctness and payload contracts; keep Craft CP presentation wiring at smoke level unless fixing a known regression.

## Coding rules

- Match existing style in the file you edit; no drive-by refactors.
- Do not add comments unless asked or the surrounding code already documents non-obvious behavior.
- Prefer extending existing services/helpers over new top-level abstractions.
- Preserve public Twig/`craft.images` contracts unless the task explicitly changes them.
- Keep processing-only markup and attributes out of normal render paths.
- Solo commercial plugin: do not treat `.nocommit/`, local notes, or ddev harness as shipped product surface. Package export-ignore is defined in `.gitattributes`.

## Local-only / gitignored paths

- `CLAUDE.local.md`, `.nocommit/`, `tests/.env`, `vendor/`, `node_modules/`
- Empty legacy dirs `.agents/`, `.codex/`, `.kilo/` may exist; Grok project agents/commands live under `.grok/`
