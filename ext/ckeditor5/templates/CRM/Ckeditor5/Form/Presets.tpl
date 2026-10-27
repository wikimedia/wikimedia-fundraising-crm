<div class="crm-block crm-form-block ck5-presets">
  <nav aria-label="{ts}Editor presets{/ts}" class="ck5-preset-tabs">
    {foreach from=$presets key=key item=label}
      <a href="{crmURL p='civicrm/admin/ckeditor5' q="reset=1&preset=$key"}" {if $key eq $preset}aria-current="page"{/if}>{$label|escape}</a>
    {/foreach}
  </nav>
  <p>{ts}Unchecked sections inherit the default preset. In the default preset, they retain the editor defaults. Check a section to replace its values. Uncheck all sections and save to reset this preset.{/ts}</p>
  <p class="description">{ts}Extensions may override these settings. Hiding a button does not filter pasted HTML or change permissions.{/ts}</p>
  <fieldset>
    <legend>{ts}Toolbar{/ts}</legend>
    {$form.custom_toolbar.html} {$form.custom_toolbar.label}
    <div class="ck5-section" data-control="custom_toolbar">
      <p>{ts}Add buttons, then drag them to reorder, or use Up and Down. Separators may be repeated. The file manager button is ignored in Base64 mode.{/ts}</p>
      <label for="ck5-button">{ts}Available button{/ts}</label>
      <select id="ck5-button"></select>
      <button type="button" id="ck5-add">{ts}Add{/ts}</button>
      <ol id="ck5-toolbar" aria-label="{ts}Toolbar order{/ts}"></ol>
      <div id="ck5-toolbar-source">{$form.toolbar_items.label} {$form.toolbar_items.html}</div>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}Find and replace{/ts}</legend>
    {$form.custom_find_replace.html} {$form.custom_find_replace.label}
    <div class="ck5-section" data-control="custom_find_replace">
      {$form.find_replace_ui.label} {$form.find_replace_ui.html}
      <p class="description">{ts}The dialog provides more room. The dropdown keeps the search controls attached to the toolbar.{/ts}</p>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}Fullscreen mode{/ts}</legend>
    {$form.custom_fullscreen.html} {$form.custom_fullscreen.label}
    <div class="ck5-section" data-control="custom_fullscreen">
      {$form.fullscreen_menu_bar.html} {$form.fullscreen_menu_bar.label}<br>
      {$form.fullscreen_ungrouped_toolbar.html} {$form.fullscreen_ungrouped_toolbar.label}
      <p class="description">{ts}The CiviCRM default hides the menu bar and keeps all toolbar items visible when the editor fills the viewport.{/ts}</p>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}Layout tables{/ts}</legend>
    {$form.custom_table_layout.html} {$form.custom_table_layout.label}
    <div class="ck5-section" data-control="custom_table_layout">
      {$form.external_table_type.label} {$form.external_table_type.html}<br>
      {$form.strip_content_table_figure.html} {$form.strip_content_table_figure.label}
      <p class="description">{ts}The CiviCRM default treats unmarked external tables as content tables for backward compatibility. Automatic detection treats explicitly marked tables and tables with captions as content tables; other imported tables become layout tables. Layout tables are emitted without a figure wrapper and use role="presentation". The existing PlainTableOutput plugin already removes figure wrappers from every table in the default build.{/ts}</p>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}HTML ruleset{/ts}</legend>
    {$form.custom_html_ruleset.html} {$form.custom_html_ruleset.label}
    <div class="ck5-section" data-control="custom_html_ruleset">
      {$form.html_ruleset.label} {$form.html_ruleset.html}
      <p class="description">{ts}Choose whether &lt;style&gt; blocks and inline presentation attributes are preserved. Use Strict on presets shared with public or FormBuilder fields.{/ts}</p>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}Image uploads and file manager{/ts}</legend>
    {$form.custom_upload_mode.html} {$form.custom_upload_mode.label}
    <div class="ck5-section" data-control="custom_upload_mode">
      {$form.upload_mode.label} {$form.upload_mode.html}
      <p class="description">{ts}Restrict or disable image uploads and server file browsing for this preset. Users without CiviCRM file permissions never receive elFinder access.{/ts}</p>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}HTML QA mode{/ts}</legend>
    {$form.custom_qa.html} {$form.custom_qa.label}
    <div class="ck5-section" data-control="custom_qa">
      {$form.qa_enabled.html} {$form.qa_enabled.label}
      <p class="description">{ts}When enabled, each editor compares the HTML received at initialization with the HTML immediately exported by CKEditor. The report lists removed and generated elements, attributes, classes, inline styles and stylesheet blocks. It is diagnostic only: it does not change filtering, saving or permissions.{/ts}</p>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}Font families and headings{/ts}</legend>
    {$form.custom_fonts.html} {$form.custom_fonts.label}
    <div class="ck5-section" data-control="custom_fonts">
      {$form.fonts.label} {$form.fonts.html}
      <p>{ts}One font stack per line, or default. Example: Roboto, Arial, sans-serif. Fonts must be loaded separately by your theme or extension. This list does not download fonts.{/ts}</p>
    </div>
    {$form.custom_headings.html} {$form.custom_headings.label}
    <div class="ck5-section" data-control="custom_headings">
      {$form.headings.label} {$form.headings.html}
      <p>{ts}Comma-separated headings: h2, h3. Paragraph is always available; leave empty for paragraphs only. This configures the heading menu, not a server-side HTML filter.{/ts}</p>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}Font sizes{/ts}</legend>
    {$form.custom_sizes.html} {$form.custom_sizes.label}
    <div class="ck5-section" data-control="custom_sizes">
      {$form.sizes.label} {$form.sizes.html}
      <p class="description">{ts}Comma-separated pixel sizes (1–200), with default for the inherited text size. Example: default, 12, 14, 16, 20, 24.{/ts}</p>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}Color palette{/ts}</legend>
    {$form.custom_palette.html} {$form.custom_palette.label}
    <div class="ck5-section" data-control="custom_palette">
      {$form.palette.label} {$form.palette.html}
      <p class="description">{ts}One color per line: #RRGGBB | Label. Used for text, text backgrounds, table and cell backgrounds and borders. Custom color selection remains available in hexadecimal format.{/ts}</p>
      <div id="ck5-swatches" aria-label="{ts}Palette preview{/ts}"></div>
    </div>
  </fieldset>
  <fieldset>
    <legend>{ts}Named styles{/ts}</legend>
    {$form.custom_styles.html} {$form.custom_styles.label}
    <div class="ck5-section" data-control="custom_styles">
      {$form.styles.label} {$form.styles.html}
      <p class="description">{ts}One style per line: Name | element | class-one class-two. Supported elements: p, h2, h3, h4, span, div, blockquote, table, td, th. Example: Lead paragraph | p | lead.{/ts}</p>
      <p class="description">{ts}Add the style button to the toolbar to select these styles. CSS must be supplied separately by your theme or extension, both in the editor and in the output. This page does not inline CSS for email or guarantee newsletter HTML preservation. An empty list removes inherited named styles.{/ts}</p>
    </div>
  </fieldset>
  <fieldset id="ck5-preview" data-preset="{$preset|escape}">
    <legend>{ts}Live editor preview{/ts}</legend>
    <p>{ts}Unsaved settings are previewed here. This sample is never saved. Configuration changes reset the sample. Upload and file manager commands are disabled in the preview. Extensions can override your settings.{/ts}</p>
    <p id="ck5-preview-status" role="status" aria-live="polite"></p>
    <div id="ck5-preview-editor"></div>
  </fieldset>
  <div class="crm-submit-buttons">{include file="CRM/common/formButtons.tpl" location="bottom"}</div>
</div>
