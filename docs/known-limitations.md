# Known Limitations

## Browser zoom

Keep the browser zoom at the default 100% whenever you use Breakpoints. Do not
change it at any point during processing. Measurements are taken from rendered
pixel sizes, so any zoom other than 100% will almost certainly produce wrong
results.

## Browser differences

For best results, you and your team should use the same browser when processing
with Breakpoints. Chromium is recommended.

Browsers can round pixels differently and produce off-by-one measurements.
Breakpoints accepts those values as correct.

