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

## Notes

- This scaffold follows Craft CMS 5 docs conventions for plugin metadata, plugin class setup, and plugin settings.
- Add services, migrations, variables, and Twig extensions as features are implemented.