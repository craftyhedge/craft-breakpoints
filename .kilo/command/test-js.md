---
description: Print Vitest command (user runs it)
agent: js
---
Print the Breakpoints JS test command for the user. Do not execute it unless they explicitly ask you to run tests.

Local Vitest binary only (not npx):

```sh
node_modules/.bin/vitest run $ARGUMENTS
```

If no args, suggest the full suite. Prefer `tests/js/transforms-runtime.test.mjs` when focusing on processing runtime.

If the user pastes results, report failures; fix only when asked.
