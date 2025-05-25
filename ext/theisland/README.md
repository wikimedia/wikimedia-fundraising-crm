# The Island (CiviCRM theme)

"The Island" is a theme for CiviCRM based on a [flat design](https://en.wikipedia.org/wiki/Flat_design) and
the [Bootstrap v3](https://getbootstrap.com/docs/3.3/) framework.

It is a fork of the [Shoreditch](https://civicrm.org/extensions/shoreditch) extension/theme, with the aim of:

- improving support for Form Builder and SearchKit
- improving support for various content management systems (CMS), such as WordPress, Drupal 9/10 and CiviCRM Standalone (Joomla and Backdrop should work, but not tested, and patches welcome)
- doing iterative, minor visual improvements (more contrast, slowly modernize a wee bit, but no drastic changes)
- improving mobile support
- tidy up the CSS a bit, maybe improve core, to have a smaller CSS footprint (but don't have too many high expectations because this is a mostly volunteer effort)

The name of this extension continues the tradition of naming themes after places. It was forked on the island Montreal, but it also a nod to all sorts of islands out there, such as Snake Island ("Russian warship, go fuck yourself"), islands going under water due to global warming, islands exploited by colonization, desert islands, etc.

## Requirements

* CiviCRM 5.60 or later
* We aim to support all CMS officially supported by CiviCRM, including CiviCRM Standalone. In the short term, the [Shoreditch on WordPress](https://civicrm.org/extensions/shoreditch-wordpress) extension is required for WordPress.

### For development

* NodeJS v14.16 (or maybe later)

## Installation

Install as a regular CiviCRM extension.

### Development

If you are [developing](CONTRIBUTING.md#code-contributions) for the theme or if want the very latest (but untested) version of the theme on your site, run

```bash
cd theisland
npm i
```

To compile the scss:

```
npx gulp copy && npx gulp sass
```

To explore the generated CSS and why it's huge, edit the `gulpfile.js` and change `outputStyle: 'compressed'` to `outputStyle: 'expanded'` for the `sass:civicrm` task.

## Components
The theme includes two major components:

 * "`bootstrap.css`" is a build of Bootstrap based on the standard Bootstrap style-guide. It can be used with other CiviCRM extensions which satisfy the Bootstrap style-guide.
 * "`custom-civicrm.css`" is a supplement to "civicrm.css". It uses the same visual conventions and SCSS metadata, but it applies to existing core screens.

### Using `bootstrap.css`

This extension provides the CSS for Bootstrap.  Other extensions should output compliant HTML, e.g.

```html
<div id="bootstrap-theme">
  ...
  <div class="panel panel-default">
    <div class="panel-heading">
      <h3 class="panel-title">Hello World</h3>
    </div>
    <div class="panel-body">
      This is the Hello World example.
    </div>
  </div>
  ...
</div>
```

Note the use of `id="bootstrap-theme"`.  To avoid conflicts with CMS UIs, the CSS rules are
restricted to `#bootstrap-theme`.

## Contributing

Want to report a bug, suggest enhancements, or contribute to the project? Please read [here](CONTRIBUTING.md)!
