# Testing

This plugin uses Craft's standard Codeception harness for PHP and Vitest for JS.
PHP tests need PHP 8.2 + MySQL, which we run via [ddev](https://ddev.com/).

> This file is for local development only — it is excluded from the released
> package via `.gitattributes` (`export-ignore`).

## PHP tests (ddev)

### One-time setup

```sh
ddev start
ddev composer install
```

The ddev config (`.ddev/config.yaml`) provides PHP 8.2 and MySQL 8.0. The test
harness reads DB credentials from `tests/.env`, which is already pointed at the
ddev database (host `db`, user/password/database all `db`):

```env
APP_ID=craft-test
SECURITY_KEY=test-security-key
DB_DSN=mysql:host=db;port=3306;dbname=db
DB_DRIVER=mysql
DB_SERVER=db
DB_PORT=3306
DB_DATABASE=db
DB_USER=db
DB_PASSWORD=db
DB_SCHEMA=
DB_TABLE_PREFIX=
```

`tests/.env` is gitignored; copy from `tests/.env.example` if it is missing.

### Running

```sh
ddev exec vendor/bin/codecept run unit          # unit suite
ddev exec vendor/bin/codecept run integration   # integration suite
ddev exec vendor/bin/codecept run               # all suites
```

The harness uses `dbSetup.clean: true`, so it always operates on a dedicated
test database — the ddev `db` database is safe to wipe.

## JS tests (Vitest)

Use the locally installed binary, **not** `npx` — the host Node version pulls a
too-new Vitest that fails to start.

```sh
npm install                                          # one-time
node_modules/.bin/vitest run                         # all JS tests
node_modules/.bin/vitest run tests/js/transforms-runtime.test.mjs
```

With coverage for the split transforms runtime modules:

```sh
node_modules/.bin/vitest run \
  --coverage --coverage.provider=istanbul --coverage.reporter=text \
  --coverage.include='src/web/assets/transforms/dist/js/transforms*.js' \
  --coverage.exclude='**/vendor/**' --coverage.exclude='coverage/**'
```

### JS runtime test scope

The runtime suite (`tests/js/transforms-runtime.test.mjs`) prioritizes
processing correctness and payload contracts:

- run report totals / status / failure metadata
- readiness classification and cancel outcomes
- lazy-loader activation and source normalization
- structured output rows and summary counts

Keep Craft CP presentation wiring (status text/class toggles, drag-scroll,
Datastar patch wiring) at smoke level unless there is a known regression.

## Troubleshooting

- `could not find driver`: handled inside ddev (the web container has
  `pdo_mysql`); make sure you run codecept via `ddev exec`, not on the host.
- DB connection failures: confirm `ddev describe` shows the project running and
  that `tests/.env` matches the ddev credentials above.
- JS startup crash under `npx`: use `node_modules/.bin/vitest` instead.
- JS coverage attribution depends on loading runtime code through the module
  pipeline; the runtime hooks test imports the runtime module directly rather
  than eval-loading source text.
