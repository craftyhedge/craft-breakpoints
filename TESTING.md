# Testing

This plugin uses Craft's standard Codeception test harness.

## Prerequisites

- PHP 8.2+
- Composer
- Docker
- PDO MySQL extension enabled in PHP (`pdo_mysql`)

## 1. Install dependencies

```sh
composer install
```

## 2. Start a local test database in Docker

Use your own credentials and keep them in sync with `tests/.env`.

```sh
docker run -d --name craft-breakpoints-test-db \
  -p 3308:3306 \
  -e MYSQL_ROOT_PASSWORD=replace-root-password \
  -e MYSQL_USER=replace-user \
  -e MYSQL_PASSWORD=replace-password \
  -e MYSQL_DATABASE=replace-database \
  mysql:8.0
```

## 3. Configure test environment

Copy and edit the test env file:

```sh
cp tests/.env.example tests/.env
```

Example MySQL settings for `tests/.env`:

```env
APP_ID=craft-test
SECURITY_KEY=test-security-key
DB_DSN=mysql:host=127.0.0.1;port=3308;dbname=replace-database
DB_DRIVER=mysql
DB_SERVER=127.0.0.1
DB_PORT=3308
DB_DATABASE=replace-database
DB_USER=replace-user
DB_PASSWORD=replace-password
DB_SCHEMA=
DB_TABLE_PREFIX=
```

## 4. Run tests

```sh
composer test
```

This runs the unit suite:

```sh
vendor/bin/codecept run unit
```

Run integration tests:

```sh
composer test:integration
```

Run all suites:

```sh
composer test:all
```

Run JavaScript unit tests:

```sh
npm run test:js
```

Run JavaScript tests with coverage:

```sh
npm run test:js -- --coverage --coverage.reporter=text-summary
```

The JS runtime suite in `tests/js/transforms-runtime.test.mjs` covers processing report normalization, lazy-loader activation helpers, wait/cancel readiness behavior, and report event publication across the split runtime modules.

### JS Runtime Test Scope

Prioritize tests for processing correctness and payload contracts:

- run report totals/status/failure metadata
- readiness classification and cancel outcomes
- lazy-loader activation and source normalization
- structured output rows and summary counts

Keep Craft CP presentation wiring at smoke level unless there is a known regression:

- status text/class toggles
- drag-scroll visual behavior
- modal ignore and Datastar patch wiring

Run focused coverage for the split transforms runtime modules:

```sh
npm run test:js -- --coverage --coverage.provider=istanbul --coverage.reporter=text --coverage.include=src/web/assets/transforms/dist/js/transforms*.js --coverage.exclude=**/vendor/** --coverage.exclude=coverage/**
```

## 5. Stop and remove the test database

```sh
docker stop craft-breakpoints-test-db
docker rm craft-breakpoints-test-db
```

## Troubleshooting

- If you see `could not find driver`, enable/install PHP `pdo_mysql`.
- If connection fails, confirm Docker container is running and `tests/.env` credentials match container env vars.
- If port `3308` is busy, map a different local port (for example `-p 3309:3306`) and update `DB_PORT` and `DB_DSN`.
- The test harness uses `dbSetup.clean: true`, so always use a dedicated test database.
- JS coverage attribution depends on loading runtime code through the module pipeline. The runtime hooks test intentionally imports the runtime module directly rather than eval-loading source text.
