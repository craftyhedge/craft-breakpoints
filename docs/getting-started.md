# Getting Started

Start in the template, not the control panel. Breakpoints gives you usable
responsive image markup immediately, so you can keep building the component with
a sensible placeholder ratio instead of stopping to configure transforms.

During development, when your layout has settled, process the page in the control panel.
Breakpoints measures the rendered image sizes, applies new transform sets
automatically, and lets you review or edit the final dimensions.

> Processing is intended for local development only, with the transform sets being
> committed to git.

## 1. How It Works

This plugin is designed to work with a mobile-first CSS framework such as Tailwind CSS.

Like Tailwind, things start with a base. Tailwind's base classes cover all screen sizes until a breakpoint is defined.

Breakpoints base image covers all screen sizes until a breakpoint is defined.

   ![Breakpoints](images/breakpoints.png)



## 2. Render a usable image

Call `image()` with the asset and a transform set name:

```twig
{{ image(entry.heroImage.one(), 'hero') }}
```
In this example, the image tag will use an existing transform set named `hero`,
or create a new set for it when processed.

Until a set exists for `hero`, Breakpoints uses the defaults unless you specify
init values.

```twig
{{ image(entry.heroImage.one(), 'hero', {
  initWidth: 1200,
  initRatio: "16:9",
  pictureClass: 'c-hero__picture',
  imgClass: 'c-hero__img'
}) }}
```

By using these init values you can leave the task of configuring breakpoint transforms until later. 

See the [`image()` reference](reference/twig-image-tag.md) for every option. For
setting system defaults, see
[Configuration](configuration.md).

## 3. Process and save the transform set

> **Important:** Configure the plugin breakpoints to match your CSS framework's breakpoints before processing. See [Configuration](configuration.md#sample-configbreakpointsphp).

Once your component layout is ready, process the page to measure the real rendered image sizes.

1. Open **Breakpoints → Transform Sets** and choose the entry with the image(s) to process.

   ![Choose a source entry for processing](images/choose-source.png)

2. Once processing is complete, any new image transform sets will be applied and saved automatically. If there are any new sets, processing is run again to verify the applied dimensions haven't drifted.

   ![Processing results](images/results.png)

If front-end image layout is not stable, dimensions can drift between processing runs.

Image transforms should support the component layout, not define it. Use CSS
constraints such as `width`, `max-width`, `aspect-ratio`, or grid/flex rules to
hold the image in the intended space as its generated dimensions change.

> Processing goes as fast as transforms are generated. Some initial runs can take a while and depends on your set up.

> Breakpoints disables Craft template caching during processing,
> but it cannot bypass full-page caches such as Blitz or static caching. Turn
> those off locally while processing. See
> [Responsive Images → Caching During Processing](responsive-images.md#caching-during-processing).

## 4. Review processing warnings

After processing, review any transform cards marked as needing attention before
you treat the saved values as final.

Warnings can appear when a transform set is missing, when enabled breakpoint
values are empty, or when the rendered image sizes no longer match the saved
transform dimensions.

Resolve the warning shown on the card, then process the entry again to verify
the updated values. A clean run should show no warnings detected.

### Missing Transform Sets

Breakpoints can find a transform set handle in your templates before that set
has been saved. Process the entry to capture rendered dimensions and create the
set, or add the transform values manually if editing is enabled.

### Empty Enabled Breakpoints

Enabled breakpoint rows need a saved width or height. If a breakpoint is enabled
but both values are empty, add the missing dimensions or disable that breakpoint
if the component should not generate an image there.

### Mismatched Assets

Transform sets can be reused across templates, so every use of the same set
handle should render at the same dimensions.

When processing finds multiple assets using the same set handle, those assets
are grouped together in the transform set card. If one or more renders at a
different size, review the templates that use that handle and adjust the markup
or CSS so each use produces matching dimensions.

### Rendered Size Mismatches

After processing, Breakpoints compares the latest rendered image sizes with the
saved transform dimensions. If they differ, apply the rendered values or edit as required.

### Hidden or Disabled Breakpoints

Processing can detect breakpoints where an image is hidden by the page layout.
Review those breakpoints before applying rendered values, then disable any
breakpoint that should not generate a transform for that component.

### Image Load or Marker Issues

Processing may warn when an image fails to load, cannot be decoded, uses an
unsupported source, or is still pending when processing is stopped. It can also
warn when Breakpoints processing markers are missing, usually because local
full-page or static caching is serving stale markup.

## 5. Review and edit saved transform sets

The default Transform Sets view shows saved transform sets in a non-editing
state, so you can review the current dimensions and settings without changing
anything by accident.

To make changes, enable the edit toggle, then update the saved set values as
needed. Edits are saved automatically.

   ![Edit toggle](images/edit.png)

Editing is only available when `allowTransformEditing` is enabled in
`config/breakpoints.php`. Keep that enabled for local development only. See
[Configuration](configuration.md#sample-configbreakpointsphp).

### Allow height differences

Open **Set options** on a transform card when the rendered height may
legitimately differ from the saved height.

Useful for background images that are affected by content. Others on your team might process with different content and get warnings unless suppressed with these.

You could use an auto height but then the images would carry their intrinsic ratio instead of setting a fixed transform.

Two settings are available:

- **Allow Shorter heights** accepts a rendered height that is less than or
  equal to the saved height. A taller rendered image is still reported as a
  mismatch.
- **Allow Any Height** accepts every rendered height and prevents height alone
  from causing a mismatch.

Only one height setting can be active at a time.

### Add notes to a transform set

Use the **Notes** tab to record information that should stay with a transform
set, such as its intended component, layout constraints, unusual dimensions,
or decisions made while reviewing processing results.

Enter the note and select **Save notes**. Saved notes are shown on the transform
card so they are visible when the set is reviewed later.

   ![notes](images/notes.png)


## 6. Collapse the breakpoint view

The Transform Sets view shows breakpoints as relatively sized screen and image
representations. That makes the saved sizes easier to understand visually, but
it takes up a fair amount of space and usually requires horizontal scrolling to
view every set breakpoint.

Use the **Hide previews** toggle to collapse the view. Breakpoint columns switch
to fixed widths and asset previews are hidden, which makes it easier to scan an
entire transform set at once.

   ![Collapsed view toggle](images/collapsed-view.png)
   ![Collapsed view](images/collapsed.png)


## Next steps

- [Configuration](configuration.md) — settings and the config file.
- [Custom Image Templates](custom-templates.md) — custom markup, LQIP, and lazy-loading libraries.
- [Responsive Images](responsive-images.md) — how the output is assembled.
