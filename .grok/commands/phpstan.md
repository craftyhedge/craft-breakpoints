---
description: Print PHPStan command (user runs it)
---

Print the PHPStan command for the user. Do not execute it unless they explicitly ask you to run analysis.

Bare paths (user runs on SSH / env with PHP):

```sh
composer phpstan
```

Or:

```sh
vendor/bin/phpstan --memory-limit=1G
```

Config: `phpstan.neon` (level 7, paths `src` and `tests`). If the user pastes results, report errors with file and line; do not lower the level.
