---
description: Print PHP test commands (user runs them)
agent: php
---
Print the Breakpoints PHP test command for the user. Do not execute it unless they explicitly ask you to run tests.

Default: unit suite.
If $1 is `integration` or `all`, use that instead.

Commands:
- unit: `ddev exec vendor/bin/codecept run unit`
- integration: `ddev exec vendor/bin/codecept run integration`
- all: `ddev exec vendor/bin/codecept run`

If the user pastes results, report failures with file/test name. Fix only if asked.
