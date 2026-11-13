# Changelog

## 1.9.2 - 2026-11-03

### Editor lifecycle

- Cancel pending debounced source-element updates when an editor instance is
  destroyed or replaced, preventing collapsed rich-text fields from having
  their underlying textarea overwritten with `undefined` after closing the
  editor.

## 1.9.1 - 2026-10-30

### Permissions and editor lifecycle

- Replace the CMS-specific `upload files` check with the portable CiviCRM
  `upload CKEditor 5 images` permission. CiviCRM administrators inherit the
  permission, while browsing existing files remains independently controlled
  by `access uploaded files`.
- Stop emitting CiviCRM `change` events after every editor data change. The
  source element remains synchronized while editing, and the final `change`
  event is emitted when focus leaves the editor.

### Runtime compatibility

- Keep the shared editor bundle in UMD format while naming its AMD export and
  normalizing it through the CiviCRM adapter. CKEditor now loads correctly on
  CiviCRM 6.20 message template screens where Monaco already provides an AMD
  loader.
- Allow elFinder uploads to initialize when the optional CKEditor Notification
  plugin is not loaded.
- Mark the image manager and token endpoints as AJAX routes.

### File manager

- Request and submit an elFinder CSRF token for direct CKEditor image uploads.
- Add a light CiviCRM integration theme for elFinder without modifying the
  bundled upstream styles.

## 1.9 - 2026-10-28

This release requires CiviCRM 6.16 or later, PHP 8.2 or later, and Smarty 5.

### Security and dependencies

- Update the bundled CKEditor runtime from 41.0.0 to 49.0.1.
- Update elFinder from 2.1.53 to 2.1.70 with restricted image MIME types, URL uploads,
  archive operations, file editing, file creation, and network mounts disabled.
- Require `upload files` for direct server-side uploads and independently
  require `access uploaded files` to browse existing images.
- Validate embedded Base64 images in the browser and enforce a 10 MiB limit.
- Document the reviewed upstream advisories and their applicability in
  `SECURITY.md`. The three CKEditor advisories fixed by 49.0.1 affect premium
  Track Changes or CKEditor AI plugins which are not included here.

### Editor integration

- Register one canonical `CKEditor5` editor and migrate the historical
  `CKEditor5-elfinder` and `CKEditor5-base64` choices without changing their
  upload behavior.
- Use one shared build for Base64, elFinder, and disabled-upload presets.
- Replace the CKFinder command override with a first-class CiviCRM file manager
  toolbar plugin. Existing `ckfinder` toolbar entries remain compatible.
- Load CKEditor and elFinder styles reliably for editors initialized after the
  page header, including WordPress front-end forms.
- Add restricted and customizable Front-end forms and Form Builder presets.
  Front-end forms retain permitted image uploads and file browsing. Form
  Builder retains permitted direct image uploads without exposing the file
  manager.
- Add a CKEditor 5 configuration button beside the standard CiviCRM WYSIWYG
  setting and move the live preset preview above advanced controls.

### Editing and QA

- Add configurable Find and Replace, Fullscreen, and Table Layout support.
- Preserve complete email template envelopes, style blocks, legacy table
  layout, inline email images, and representative CKEditor 4 content more
  faithfully.
- Add optional HTML transformation reports for migration testing.
- Add CiviCRM token selection and native Mention-based autocomplete without
  maintaining an extension-local token allow-list.
- Add Markdown paste support and an opt-in Markdown storage preset for fields
  which explicitly store Markdown instead of HTML.
- Bundle the open-source AutoLink, Bookmark, Emoji, IndentBlock, Mention,
  ShowBlocks, TableCaption, TextPartLanguage, TodoList, and WordCount plugins.
  Their toolbar controls remain optional and Emoji data is served locally.
- Expose bundled plugins such as FullPage and ImageInsert to extensions without
  enabling them in the default presets.
- Keep collapsed editors open while link and other contextual editor controls
  have focus, and allow elFinder to receive keyboard focus inside CiviCRM
  popups.

### Distribution

- Produce a versioned installable ZIP with a single `ckeditor5` root directory
  and a SHA-256 checksum for every Git tag.
