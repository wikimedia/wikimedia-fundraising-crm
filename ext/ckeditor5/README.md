# CKEditor 5 for CiviCRM

[Source repository and issue tracker](https://lab.civicrm.org/extensions/ckeditor5)

![CKEditor 5 editing interface in CiviCRM](images/ckeditor5-editor.png)

This extension provides CKEditor 5 as a CiviCRM WYSIWYG editor. CKEditor 5 is
actively maintained and handles formatted content pasted from Word and Google
Docs without a separate paste button.

The integration uses one self-hosted editor build and offers elFinder, embedded
Base64 images, or no uploads as per-preset choices. It does not depend on the
KCFinder integration historically used by CKEditor 4.

The extension registers one **CKEditor 5** editor. Administrators can choose an
image-upload mode for each preset:

* **elFinder** stores uploaded files on the filesystem and provides a server
  file manager. This is the recommended mode for publicly reachable sites.
* **Base64** embeds uploaded images in the HTML. This can be useful for a site
  behind a firewall, where recipients could not load an image URL hosted by the
  site. It also increases the stored HTML and database size.
* **None** disables image upload and file browsing for the preset.

Existing installations using the historical `CKEditor5-elfinder` or
`CKEditor5-base64` choices are migrated to the single editor while retaining
their selected upload behavior.

Note - there are discrepancies between email clients and some
might suppress or mis-display embedded images, as some might suppress linked ones.

The extension is licensed under [AGPL-3.0](LICENSE.txt).
Security maintenance and the applicability of reviewed upstream advisories are
documented in [SECURITY.md](SECURITY.md).

## Requirements

* CiviCRM **6.16 or later**, as declared in `info.xml`.
* PHP **8.2 or later**, compatible with the installed CiviCRM and CMS versions.
  The release metadata lists PHP 8.2 through 8.5.
* Smarty **5**.
* For elFinder uploads, a writable CiviCRM image directory and a public image URL
  accessible to recipients when images are used in email.

## Installation (Web UI)

Open **Administer > System Settings > Extensions**. If a compatible release is
listed under **Add New**, install it there. Otherwise, use the manual or Git
installation instructions below.

## Installation (CLI, Zip)

Download an installable ZIP from the
[project releases](https://lab.civicrm.org/extensions/ckeditor5/-/releases), extract its `ckeditor5`
directory into the configured CiviCRM extensions directory, then enable the
extension through the Extensions page or `cv en ckeditor5` from the site root.

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
git clone https://lab.civicrm.org/extensions/ckeditor5.git ckeditor5
cd <civicrm-site-root>
cv en ckeditor5
```

## Usage

After installation it is  available as an option under
Administer->Customize Data and Screens->Display Preferences

![Selecting and configuring CKEditor 5 in CiviCRM](images/ckeditor5-settings.png)

## Extending the editor configuration

### Per-field presets

The adapter reads the source field's `data-preset` attribute. Existing CiviCRM
forms already use `default`, `civimail` and `civievent`. An explicit preset
always wins, including when the form is rendered on the front end. Without an
explicit preset, public forms use the restricted `frontend` preset and
`civi-rich-text-input` fields use the restricted `formbuilder` preset. Both
use the strict HTML ruleset by default. The front-end preset retains permitted
image uploads and file browsing. Form Builder retains permitted direct image
uploads but omits the file manager button. Users without file permissions do
not receive the corresponding upload or browse control. Both presets remain
customizable from the preset administration page.

### Presentation and template styles

The default configuration preserves classes, selected presentation attributes
and common inline CSS on supported text, image and table elements. It accepts
existing font families and sizes even when they are not in the toolbar choices.
Tables use CKEditor's open-source `PlainTableOutput` plugin, keeping dimensions
on the table rather than an email-incompatible `figure` wrapper.

The `civimail` preset inserts images as direct inline `img` elements instead of
block images wrapped in `figure`. Its resize control uses pixel widths and does
not expose block-image styles, preventing newly inserted email images from
returning to the browser-oriented figure markup. Exported images receive
explicit proportional dimensions, `height:auto`, `max-width:100%` and no
legacy border. Images which were not resized are capped at 600 pixels by
default; `civiEmailImageMaxWidth` can override that limit. Other presets retain
the full block and inline image toolbar.

`<style>` blocks and their media queries are retained through General HTML Support.
When loading a complete HTML document (`<!doctype>`, `<html>`, `<head>`, `<body>`),
the adapter preserves the document envelope (`<!doctype>`, `<html>` and `<head>`
metadata, `<style>` blocks, and `<body>` attributes) across visual edits and
Source Editing round-trips, while keeping fragment inputs as fragments.
`<style>` blocks remain inert in the editing canvas so template rules do not
bleed into the CiviCRM back-office UI.

To restrict an individual preset, set its `htmlSupport.allow` to your own list
(or `[]`), or choose the `presentation` or `strict` HTML ruleset in the preset
administration screen. Set `civiPreserveTemplateStyles: false` to disable
`<style>` block and document envelope preservation.

Run `node --test tests/*.test.cjs` for unit tests, or open
`tests/presentation.html` and `tests/shared-build.html` in a browser for
end-to-end CKEditor smoke tests.

### Configuring presets

An extension can register preset settings from its `html-header` resource hook:

```php
$items[] = ['config' => ['CKEditor5Presets' => [
  'default' => [
    'toolbar' => ['items' => ['bold', 'italic', 'link', 'undo', 'redo']],
  ],
  'civimail' => [
    'toolbar' => ['items' => ['bold', 'italic', 'link', 'insertTable', 'undo', 'redo']],
  ],
]]];
```

Configuration order is: built-in defaults, legacy `CKEditor5CustomConfig`,
saved admin defaults, `CKEditor5Presets.default`, saved named preset,
the named `CKEditor5Presets` override, then `crmCkeditor5Config` listeners.
The legacy global override still replaces top-level values. Presets merge nested
objects and replace arrays, including empty arrays; they never concatenate
toolbar items. Each editor receives independent configuration containers.

A missing or unknown preset falls back to the default configuration. Event
context `preset` identifies the field's requested preset (or `default` when
absent); `resolvedPreset` identifies the configured preset used, or `default`.
Listeners can therefore target `civimail` even without a registered preset.

Hiding a toolbar button does not prohibit its HTML in pasted or existing content.
This is configuration, not a permissions system or a CKEditor 4 config importer.
No editor rebuild is required for options supported by the included plugins.

### Preset administration

Administrators with `administer CiviCRM` can open the preset page from the
CKEditor 5 configuration control below the WYSIWYG selector on **Administer >
Customize Data and Screens > Display Preferences**. The page remains available
at `civicrm/admin/ckeditor5`, but it does not add a separate navigation item.
Clear CiviCRM's cache after deploying to discover the route and settings
metadata.

The page discovers presets from `wysiwyg_presets` and adds explicit Front-end
forms and Form Builder tabs. It stores per-domain settings
in `ckeditor5_presets`, published as `CRM.config.CKEditor5AdminPresets`.
Each section has an explicit customization checkbox; unchecked sections are
not stored and continue inheriting. Uncheck everything and save to reset only
the selected preset. Initial input examples are starting values, not a preview
of configuration modified by another extension.

- Toolbar: add, remove and reorder included buttons by dragging or with Up/Down.
  Separators may repeat. The file manager button is omitted in Base64 mode.
- Find and replace: choose a dialog (the default) or a toolbar dropdown.
- CiviCRM tokens: the optional `civiTokens` toolbar button searches the tokens
  available for the current editor preset and inserts one at the current
  selection. It is not enabled in the default toolbar because CiviCRM already
  provides a token selector on the relevant forms. Typing `{` in the visual
  editor uses CKEditor's native Mention autocomplete; Source Editing and HTML
  Embed retain a textarea-compatible autocomplete. Selected tokens are stored
  as plain `{token}` text, without mention markup. CKEditor does not maintain a
  token-name allowlist: the catalogue comes from the current page when CiviCRM
  exposes one, otherwise from CiviCRM's `TokenProcessor`, including tokens
  registered by enabled extensions. Its broad discovery schema can be extended by
  subscribing to the `civi.ckeditor5.tokenSchema` event and appending a context
  field to `$event->schema`.
- Fullscreen: choose whether its menu bar is visible and whether toolbar items
  remain ungrouped. CiviCRM defaults to no menu bar and an ungrouped toolbar.
  The sticky toolbar and contextual controls automatically stay below visible
  Drupal and CiviCRM administration menus. The elFinder dialog remains above
  the editor while fullscreen mode is active.
- Layout tables: choose automatic import detection or force unmarked external
  tables to content/layout type, and optionally strip the content-table figure.
  Existing unmarked tables default to content type for backward compatibility.
  Once edited, they receive the explicit `content-table` class used by CKEditor
  to preserve that distinction on subsequent loads.
  The default toolbar keeps a single table insertion button. Selecting a table
  exposes CKEditor's content/layout switch through the table properties control;
  `insertTableLayout` and `tableType` remain available to custom toolbars.
  The default build's `PlainTableOutput` already strips figures from all tables.
- HTML ruleset: choose `template` (preserve `<style>` blocks, classes and inline
  presentation styles), `presentation` (preserve classes and inline styles
  without `<style>` blocks), or `strict` (standard CKEditor schema only,
  recommended for presets shared with public or FormBuilder application forms).
- Image uploads and file manager: choose `elfinder`, `base64` or `none` (disable
  image uploads and file browsing for the preset). Direct uploads require the
  portable CiviCRM `upload CKEditor 5 images` permission. Browsing existing
  images through elFinder separately requires `access uploaded files`.
  CiviCRM administrators inherit the upload permission; other roles must be
  granted it explicitly. Users without upload permission use `none`, while the
  file-manager button follows its own browse permission.
- HTML QA mode: compare the HTML received when an editor opens with the HTML
  immediately exported by CKEditor. The per-editor report inventories removed
  and generated elements, attributes, classes, inline styles and stylesheet
  blocks. This is an import/export diagnostic only; it does not add a filter,
  alter saved content or inspect later server-side processing.
- Font sizes: comma-separated integer pixel sizes and `default`.
- Palette: one `#RRGGBB | Label` per line, shared by text and table/cell colors.
- Styles: one `Name | element | class-one class-two` per line; add the `style`
  toolbar button to expose them. Supply the CSS separately in the editing and
  rendering contexts. Classes alone do not make styled email output; no CSS
  inlining, CSS injection or arbitrary HTML security-policy editor is included.

Programmatic named presets override saved options for the same context, and
configuration listeners retain final precedence. In particular, an extension
that unconditionally sets font sizes can override the admin selection.
Existing CKEditor 4 files are neither imported nor modified.

### Configuration event

Extensions can alter the CKEditor configuration for each source field before
the editor is created. Listen for the `crmCkeditor5Config` event, which bubbles
from the source textarea. The mutable configuration object and a context object
containing `uploadAdapter`, `preset` and `resolvedPreset` are passed to the listener:

```javascript
CRM.$(document).on('crmCkeditor5Config', function(event, config, context) {
  config.fontFamily = {
    options: [
      'default',
      'Arial, Helvetica, sans-serif'
    ],
    supportAllValues: true
  };

  if (context.uploadAdapter === 'elfinder') {
    // Apply configuration specific to the server-backed file manager.
  }
});
```

The listener may alter any option supported by the plugins already included in
the selected CKEditor build.

Plugins exposed by the shared runtime but intentionally inactive by default can
be enabled for an individual field with `enableBundledPlugin()`. For example,
an extension can opt into CKEditor's full-page data processor:

```javascript
CRM.$(document).on('crmCkeditor5Config', function(event, config, context) {
  if (context.preset !== 'my-full-page-preset') {
    return;
  }

  config.civiPreserveTemplateStyles = false;
  CRM.ckeditor5.enableBundledPlugin(config, 'FullPage');
});
```

`FullPage` replaces the document-envelope behavior for that field, so the
CiviCRM-specific preservation layer must be disabled as above. `ImageInsert`
can be enabled in the same way on fields where `ImageUpload` is permitted:

```javascript
CRM.ckeditor5.enableBundledPlugin(config, 'ImageInsert');
config.toolbar.items.push('insertImage');
```

Neither plugin is active in the default presets. The shared runtime also
exposes its other `ckeditor5` package exports through `window.CKEDITOR` for
extension integrations.

The shared editor activates `AutoLink`, `Mention`, `TextPartLanguage`,
`ShowBlocks`, `TodoList`, `WordCount`, `Emoji`, `IndentBlock`, `TableCaption`
and `Bookmark`. Their toolbar components remain optional and are available in
the preset administration picker; they are not added to the default toolbar.
`IndentBlock` extends the existing indent and outdent controls. Emoji metadata
is served from the extension's local assets instead of CKEditor's CDN.

### Markdown input and output

The shared build includes CKEditor's open-source Markdown data processor and
experimental Markdown paste support. HTML remains the default storage format.
Markdown pasted into a normal editor is converted to rich text and exported as
HTML. Set `civiPasteMarkdown: false` in a preset to disable this behavior.

The built-in `markdown` preset instead uses GitHub Flavored Markdown for both
input and output. It disables file uploads, HTML QA, and email-template
preservation, and limits the toolbar to features with useful Markdown output.
Select it on a CiviCRM WYSIWYG field with `preset => 'markdown'`:

```php
$form->add('wysiwyg', 'message', ts('Message'), [
  'preset' => 'markdown',
  'rows' => 10,
]);
```

The Source button displays Markdown in this preset. Applications using it must
continue treating the saved value as Markdown; it is not HTML and must be
rendered or escaped appropriately outside the editor.

### Loading extension plugins without rebuilding the shared bundle

An extension can load local JavaScript and CSS after the shared CKEditor runtime
and before the first editor is created. Its JavaScript must use
`window.CKEDITOR`; it must not bundle its own copy of CKEditor modules.

Declare the assets from the `html-header` resource hook:

```php
$items[] = ['config' => ['CKEditor5PluginAssets' => [[
  'name' => 'my-extension',
  'scripts' => [CRM_Core_Resources::singleton()->getUrl('my_extension', 'js/ckeditor-plugin.js')],
  'stylesheets' => [CRM_Core_Resources::singleton()->getUrl('my_extension', 'css/ckeditor-plugin.css')],
  'plugins' => ['MyExtensionPlugin'],
]]]];
```

The plugin script registers its constructor against the exact shared runtime
version:

```javascript
(function(CRM, CKEditor) {
  class MyExtensionPlugin extends CKEditor.Plugin {
    static get pluginName() {
      return 'MyExtensionPlugin';
    }
  }

  CRM.ckeditor5.registerPlugin('MyExtensionPlugin', MyExtensionPlugin, {
    ckeditorVersion: CKEditor.version
  });
})(CRM, window.CKEDITOR);
```

Registered constructors are appended to `extraPlugins` before the
`crmCkeditor5Config` event, so a field listener can still remove or replace them.
Scripts load in declaration order; stylesheets load in parallel. Assets must be
same-origin URLs. A declaration can list expected plugin names in `plugins`; an
editor will not initialize if its script fails to register one of them.

This removes the central rebuild requirement for new extension-owned plugins.
A one-time shared build update is still required to expose `window.CKEDITOR`, and
the external plugin must be rebuilt when it needs APIs incompatible with the
shared CKEditor version.

## Limitations and browser support

- The editor language follows the current CiviCRM language in both ElFinder and Base64 modes. CKEditor 5 may not provide every CiviCRM language.
- Source editing is included, but CKEditor may normalize or remove unsupported
  markup when content is edited. Opening a complex email does not establish
  that an edit/save round trip will preserve its layout.
- Use a maintained, up-to-date browser. JavaScript ES6 support alone does not
  establish compatibility with the bundled CKEditor version. See CKEditor's
  [browser support policy](https://ckeditor.com/docs/ckeditor5/latest/updating/technology-upgrades-policy.html).
- Embedded Base64 images are not reliably displayed by every email client.
  Test the generated email in the clients used by your recipients.

## A word about builds

The extension ships one shared, self-hosted CKEditor 5 build under
`js/ckeditor5/ckeditor-classic-build` (`ckeditor5` `49.0.1`). Generated
JavaScript and translations are tracked in this repository.

The canonical editor identifier is `CKEditor5`. The historical
`CKEditor5-elfinder` and `CKEditor5-base64` identifiers remain runtime
compatibility aliases and are migrated to a preset upload mode during upgrade.
The adapter selects the upload mode at initialization; it disables Base64
uploads in elFinder mode and hides the CiviCRM file manager in Base64 mode.
elFinder's own assets are loaded only when the editor may use elFinder.

To rebuild the shared bundle, run:

```bash
cd js/ckeditor5/ckeditor-classic-build
npm ci
npm run build
```

## File management

- **elFinder mode** includes [elFinder](https://github.com/Studio-42/elFinder)
  and a CiviCRM connector. It supports uploading images and selecting existing
  files. Selected image URLs are inserted into the editor; other selected files
  can be linked. Uploads are stored on the server, not embedded in the HTML.
- **Base64 mode** embeds uploaded images in the HTML and does not provide a
  server-side file browser. New images are limited to 10 MiB and must decode
  successfully using the browser image decoder before they are embedded.
  Embedded images increase content and database size, and
  Base64 encoding increases the original binary size by roughly one third.

The shared build includes a `CiviFileManager` toolbar plugin. In elFinder mode,
the CiviCRM adapter handles its event and opens elFinder without overriding a
CKFinder command. Existing preset entries named `ckfinder` are migrated to the
new `civiFileManager` item at runtime and when saved.
