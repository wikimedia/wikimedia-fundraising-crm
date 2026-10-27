<?php

use CRM_Ckeditor5_ExtensionUtil as E;

/**
 * Preset configuration form.
 */
class CRM_Ckeditor5_Form_Presets extends CRM_Core_Form {

  public function preProcess() {
    $presets = CRM_Core_OptionGroup::values('wysiwyg_presets', FALSE, FALSE, FALSE, NULL, 'label', TRUE, FALSE, 'name');
    $presets = ['default' => E::ts('Default')] + $presets;
    $preset = CRM_Utils_Request::retrieve('preset', 'String', $this, FALSE, 'default', 'GET');
    if (!isset($presets[$preset])) {
      CRM_Core_Error::statusBounce(E::ts('Unknown editor preset.'));
    }
    $this->set('preset', $preset);
    $this->assign('preset', $preset);
    $this->assign('presets', $presets);
    CRM_Utils_System::setTitle(E::ts('CKEditor 5 presets'));
    Civi::resources()->addScriptFile('ckeditor5', 'js/admin-preset-config.js', 5, 'html-header')
      ->addScriptFile('ckeditor5', 'js/admin-presets.js', 10, 'html-header')
      ->addStyleFile('ckeditor5', 'css/admin-presets.css', 0, 'html-header')
      ->addVars('ckeditor5Admin', ['buttons' => CRM_Ckeditor5_Presets::buttons()]);
  }

  public function buildQuickForm() {
    foreach (['toolbar' => 'Customize toolbar', 'find_replace' => 'Customize find and replace', 'fullscreen' => 'Customize fullscreen mode', 'table_layout' => 'Customize layout tables', 'html_ruleset' => 'Customize HTML ruleset', 'upload_mode' => 'Customize image upload mode', 'qa' => 'Customize HTML QA mode', 'fonts' => 'Customize font families', 'headings' => 'Customize headings', 'sizes' => 'Customize font sizes', 'palette' => 'Customize colors', 'styles' => 'Customize named styles'] as $key => $label) {
      $this->add('checkbox', 'custom_' . $key, E::ts($label));
    }
    $this->add('select', 'find_replace_ui', E::ts('Find and replace interface'), [
      'dialog' => E::ts('Dialog'),
      'dropdown' => E::ts('Toolbar dropdown'),
    ]);
    $this->add('checkbox', 'fullscreen_menu_bar', E::ts('Show the menu bar in fullscreen mode'));
    $this->add('checkbox', 'fullscreen_ungrouped_toolbar', E::ts('Keep every toolbar item visible in fullscreen mode'));
    $this->add('select', 'external_table_type', E::ts('Tables without an explicit type'), [
      'auto' => E::ts('Detect automatically'),
      'content' => E::ts('Content table'),
      'layout' => E::ts('Layout table'),
    ]);
    $this->add('checkbox', 'strip_content_table_figure', E::ts('Remove the figure wrapper from content tables'));
    $this->add('select', 'html_ruleset', E::ts('HTML preservation ruleset'), [
      'template' => E::ts('Email and templates (preserve &lt;style&gt; blocks, classes and inline presentation)'),
      'presentation' => E::ts('Presentation without &lt;style&gt; blocks (preserve classes and inline styles only)'),
      'strict' => E::ts('Strict (standard CKEditor schema only, recommended for application forms)'),
    ]);
    $this->add('select', 'upload_mode', E::ts('Image uploads and file manager'), [
      'elfinder' => E::ts('Server file manager (elFinder, when permitted by site settings and user permissions)'),
      'base64' => E::ts('Embedded Base64 images only (no access to server file manager)'),
      'none' => E::ts('Disabled (no image uploads or file manager, recommended for application forms)'),
    ]);
    $this->add('checkbox', 'qa_enabled', E::ts('Enable the HTML transformation report'));
    $this->add('textarea', 'fonts', E::ts('Font families'), ['rows' => 5, 'cols' => 70]);
    $this->add('text', 'headings', E::ts('Available headings'), ['size' => 40]);
    $this->add('textarea', 'toolbar_items', E::ts('Ordered toolbar buttons (JSON)'), ['rows' => 3, 'cols' => 80]);
    $this->add('text', 'sizes', E::ts('Font sizes'), ['size' => 70, 'maxlength' => 500]);
    $this->add('textarea', 'palette', E::ts('Colors'), ['rows' => 8, 'cols' => 70]);
    $this->add('textarea', 'styles', E::ts('Named styles'), ['rows' => 6, 'cols' => 70]);
    $this->addFormRule([__CLASS__, 'validateConfig']);
    $this->addButtons([['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE], ['type' => 'cancel', 'name' => E::ts('Cancel')]]);
  }

  public function setDefaultValues() {
    $all = Civi::settings()->get('ckeditor5_presets') ?: [];
    $config = $all[$this->get('preset')] ?? [];
    return [
      'custom_fonts' => isset($config['fontFamily']),
      'custom_headings' => isset($config['heading']),
      'fonts' => implode("\n", $config['fontFamily']['options'] ?? ['default', 'Arial, Helvetica, sans-serif', 'Georgia, serif']),
      'headings' => implode(', ', isset($config['heading']) ? array_column($config['heading']['options'], 'view') : ['h2', 'h3']),
      'custom_toolbar' => isset($config['toolbar']),
      'custom_find_replace' => isset($config['findAndReplace']),
      'find_replace_ui' => $config['findAndReplace']['uiType'] ?? 'dialog',
      'custom_fullscreen' => isset($config['fullscreen']),
      'fullscreen_menu_bar' => $config['fullscreen']['menuBar']['isVisible'] ?? FALSE,
      'fullscreen_ungrouped_toolbar' => $config['fullscreen']['toolbar']['shouldNotGroupWhenFull'] ?? TRUE,
      'custom_table_layout' => isset($config['table']['tableLayout']),
      'external_table_type' => $config['table']['tableLayout']['preferredExternalTableType'] ?? 'content',
      'strip_content_table_figure' => $config['table']['tableLayout']['stripFigureFromContentTable'] ?? FALSE,
      'custom_html_ruleset' => isset($config['civiHtmlRuleset']),
      'html_ruleset' => $config['civiHtmlRuleset'] ?? 'template',
      'custom_upload_mode' => isset($config['civiUploadMode']),
      'upload_mode' => $config['civiUploadMode'] ?? 'elfinder',
      'custom_qa' => isset($config['civiHtmlQa']),
      'qa_enabled' => $config['civiHtmlQa']['enabled'] ?? FALSE,
      'custom_sizes' => isset($config['fontSize']),
      'custom_palette' => isset($config['fontColor']),
      'custom_styles' => isset($config['style']),
      'toolbar_items' => json_encode($config['toolbar']['items'] ?? ['heading', '|', 'bold', 'italic', 'link', 'undo', 'redo']),
      'sizes' => implode(', ', $config['fontSize']['options'] ?? ['default', 10, 12, 14, 16, 18, 24, 32]),
      'palette' => implode("\n", array_map(function ($color) { return $color['color'] . ' | ' . $color['label']; }, $config['fontColor']['colors'] ?? [['color' => '#000000', 'label' => 'Black'], ['color' => '#FFFFFF', 'label' => 'White']])),
      'styles' => implode("\n", array_map(function ($style) { return $style['name'] . ' | ' . $style['element'] . ' | ' . implode(' ', $style['classes']); }, $config['style']['definitions'] ?? [])),
    ];
  }

  public static function validateConfig($values) {
    try {
      CRM_Ckeditor5_Presets::parse($values);
      return TRUE;
    }
    catch (InvalidArgumentException $e) {
      return ['toolbar_items' => E::ts('Invalid preset configuration: %1', [1 => $e->getMessage()])];
    }
  }

  public function postProcess() {
    $config = CRM_Ckeditor5_Presets::parse($this->exportValues());
    $all = Civi::settings()->get('ckeditor5_presets') ?: [];
    $preset = $this->get('preset');
    if ($config) {
      $all[$preset] = $config;
    }
    else {
      unset($all[$preset]);
    }
    Civi::settings()->set('ckeditor5_presets', $all);
    Civi::resources()->resetCacheCode();
    CRM_Core_Session::setStatus(E::ts('Editor preset saved. Reload open editing forms to use it.'), E::ts('Saved'), 'success');
    CRM_Core_Session::singleton()->pushUserContext(CRM_Utils_System::url('civicrm/admin/ckeditor5', ['reset' => 1, 'preset' => $preset]));
  }
}
