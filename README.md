# Craft Breakpoint Images

Craft CMS 5 plugin scaffold for responsive breakpoint image helpers.

## Requirements

- Craft CMS `^5.3.0`
- PHP `^8.2`

## Install in a Craft Project (Local Development)

1. Add this plugin as a Composer path repository in your Craft project `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../craft-breakpoint-images"
    }
  ]
}
```

2. Require the plugin package in the Craft project:

```bash
composer require craftyhedge/craft-breakpoint-images:@dev
```

3. Install the plugin in Craft:

- Control Panel -> Settings -> Plugins
- or `php craft plugin/install craft-breakpoint-images`

## Plugin Handle

- `craft-breakpoint-images`

## Testing

This plugin uses Craft's standard Codeception-based test harness.

See [TESTING.md](TESTING.md) for full local Docker database setup and troubleshooting.

1. Install development dependencies:

```bash
composer install
```

2. Copy the test environment file:

```bash
cp tests/.env.example tests/.env
```

3. Run unit tests:

```bash
composer test
```

4. Run integration tests:

```bash
composer test:integration
```

5. Run JavaScript unit tests:

```bash
npm run test:js
```

## Static Analysis

PHPStan is configured with Craft's plugin extension config.

Run:

```bash
composer phpstan
```

## Notes

- This scaffold follows Craft CMS 5 docs conventions for plugin metadata, plugin class setup, and plugin settings.
- Add services, migrations, variables, and Twig extensions as features are implemented.
- Transform editor sets include an optional Settings toggle: "Pass if height is less than or equal to saved".
- The setting is stored per set at `config.passHeightWhenRenderedLteSaved` and is strict: only boolean `true` enables it.