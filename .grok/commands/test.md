---
description: Print PHP test commands (user runs them)
argument-hint: "[unit|integration|all]"
disable-model-invocation: false
---

Print the Breakpoints PHP test command for the user. Do not execute it unless they explicitly ask you to run tests.

Default: unit suite.
If `$ARGUMENTS` is `integration` or `all`, use that instead.

Commands:
- unit: `ddev exec vendor/bin/codecept run unit`
- integration: `ddev exec vendor/bin/codecept run integration`
- all: `ddev exec vendor/bin/codecept run`

If the user pastes results, report failures with file/test name. Fix only if asked.
