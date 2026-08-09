---
description: Print PHP test commands (user runs them)
argument-hint: "[unit|integration|all]"
disable-model-invocation: false
---

Print the Breakpoints PHP test command for the user. Do not execute it unless they explicitly ask you to run tests.

Default: unit suite.
If `$ARGUMENTS` is `integration` or `all`, use that instead.

Commands (bare paths — user runs on SSH / env with PHP + MySQL):
- unit: `vendor/bin/codecept run unit`
- integration: `vendor/bin/codecept run integration`
- all: `vendor/bin/codecept run`

If the user pastes results, report failures with file/test name. Fix only if asked.
