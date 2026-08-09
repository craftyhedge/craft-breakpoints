---
description: Print PHPStan command (user runs it)
---

Print the PHPStan command for the user. Do not execute it unless they explicitly ask you to run analysis.

```sh
composer phpstan
```

If composer is unavailable on the host:

```sh
ddev exec vendor/bin/phpstan --memory-limit=1G
```

Config: `phpstan.neon` (level 7, paths `src` and `tests`). If the user pastes results, report errors with file and line; do not lower the level.
