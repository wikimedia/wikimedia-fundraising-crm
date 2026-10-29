<?php

/**
 * Parse and validate preset administration settings.
 */
class CRM_Ckeditor5_Presets {

  public static function buttons() {
    return explode(' ', 'heading | findAndReplace civiTokens bold underline italic strikethrough superscript subscript highlight removeFormat specialCharacters fontFamily fontColor fontBackgroundColor fontSize bulletedList numberedList indent outdent alignment link imageUpload ckfinder blockQuote insertTable insertTableLayout tableType horizontalLine mediaEmbed undo redo pageBreak htmlEmbed sourceEditing code style fullscreen');
  }

  public static function parse(array $values) {
    $config = [];
    foreach (['toolbar_items', 'sizes', 'palette', 'styles', 'fonts', 'headings', 'find_replace_ui', 'external_table_type', 'html_ruleset', 'upload_mode'] as $field) {
      if (isset($values[$field]) && (!is_string($values[$field]) || strlen($values[$field]) > 20000)) {
        throw new InvalidArgumentException('Invalid configuration field.');
      }
    }
    if (!empty($values['custom_fonts'])) {
      $fonts = self::lines($values['fonts'] ?? '');
      if (!$fonts || count($fonts) > 50) {
        throw new InvalidArgumentException('Provide between 1 and 50 font stacks.');
      }
      foreach ($fonts as $font) {
        if (!preg_match('/^[a-zA-Z0-9 ,\x27"_-]+$/D', $font)) {
          throw new InvalidArgumentException('Invalid font stack.');
        }
      }
      $config['fontFamily'] = ['options' => array_values(array_unique($fonts)), 'supportAllValues' => TRUE];
    }
    if (!empty($values['custom_headings'])) {
      $headings = preg_split('/[\s,]+/', trim($values['headings'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
      $options = [['model' => 'paragraph', 'title' => 'Paragraph', 'class' => 'ck-heading_paragraph']];
      foreach (array_unique($headings) as $heading) {
        if (!preg_match('/^h[1-6]$/D', $heading)) {
          throw new InvalidArgumentException('Headings must be h1 through h6. Paragraph is always available.');
        }
        $level = substr($heading, 1);
        $options[] = ['model' => 'heading' . $level, 'view' => $heading, 'title' => 'Heading ' . $level, 'class' => 'ck-heading_heading' . $level];
      }
      $config['heading']['options'] = $options;
    }
    if (!empty($values['custom_toolbar'])) {
      $items = json_decode($values['toolbar_items'] ?? '[]', TRUE);
      if (!is_array($items) || array_values($items) !== $items || count($items) > 100) {
        throw new InvalidArgumentException('Invalid toolbar list.');
      }
      foreach ($items as $item) {
        if (!is_string($item) || !in_array($item, self::buttons(), TRUE)) {
          throw new InvalidArgumentException('Unknown toolbar button.');
        }
      }
      $buttons = array_values(array_diff($items, ['|']));
      if (count($buttons) !== count(array_unique($buttons))) {
        throw new InvalidArgumentException('Toolbar buttons must not be duplicated.');
      }
      $config['toolbar']['items'] = $items;
    }
    if (!empty($values['custom_find_replace'])) {
      $uiType = $values['find_replace_ui'] ?? '';
      if (!in_array($uiType, ['dialog', 'dropdown'], TRUE)) {
        throw new InvalidArgumentException('Find and replace must use a dialog or dropdown.');
      }
      $config['findAndReplace']['uiType'] = $uiType;
    }
    if (!empty($values['custom_fullscreen'])) {
      $config['fullscreen'] = [
        'menuBar' => ['isVisible' => !empty($values['fullscreen_menu_bar'])],
        'toolbar' => ['shouldNotGroupWhenFull' => !empty($values['fullscreen_ungrouped_toolbar'])],
      ];
    }
    if (!empty($values['custom_table_layout'])) {
      $tableType = $values['external_table_type'] ?? '';
      if (!in_array($tableType, ['auto', 'content', 'layout'], TRUE)) {
        throw new InvalidArgumentException('External tables must use automatic, content or layout detection.');
      }
      $config['table']['tableLayout'] = [
        'stripFigureFromContentTable' => !empty($values['strip_content_table_figure']),
      ];
      if ($tableType !== 'auto') {
        $config['table']['tableLayout']['preferredExternalTableType'] = $tableType;
      }
    }
    if (!empty($values['custom_html_ruleset'])) {
      $ruleset = $values['html_ruleset'] ?? '';
      if (!in_array($ruleset, ['template', 'presentation', 'strict'], TRUE)) {
        throw new InvalidArgumentException('HTML ruleset must be template, presentation or strict.');
      }
      $config['civiHtmlRuleset'] = $ruleset;
    }
    if (!empty($values['custom_upload_mode'])) {
      $uploadMode = $values['upload_mode'] ?? '';
      if (!in_array($uploadMode, ['elfinder', 'base64', 'none'], TRUE)) {
        throw new InvalidArgumentException('Upload mode must be elfinder, base64 or none.');
      }
      $config['civiUploadMode'] = $uploadMode;
    }
    if (!empty($values['custom_qa'])) {
      $config['civiHtmlQa'] = ['enabled' => !empty($values['qa_enabled'])];
    }
    if (!empty($values['custom_sizes'])) {
      $sizes = preg_split('/[\s,]+/', trim($values['sizes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
      $options = [];
      foreach ($sizes as $size) {
        if ($size !== 'default' && (!ctype_digit($size) || (int) $size < 1 || (int) $size > 200)) {
          throw new InvalidArgumentException('Font sizes must be default or integers from 1 to 200.');
        }
        $options[] = $size === 'default' ? $size : (int) $size;
      }
      if (!$options || count($options) > 50) {
        throw new InvalidArgumentException('Provide between 1 and 50 font sizes.');
      }
      $config['fontSize']['options'] = array_values(array_unique($options));
    }
    if (!empty($values['custom_palette'])) {
      $colors = [];
      foreach (self::lines($values['palette'] ?? '') as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/D', $parts[0])) {
          throw new InvalidArgumentException('Palette colors must use six-digit hexadecimal notation.');
        }
        $colors[] = ['color' => strtoupper($parts[0]), 'label' => $parts[1] ?? strtoupper($parts[0])];
      }
      if (!$colors || count($colors) > 50) {
        throw new InvalidArgumentException('Provide between 1 and 50 colors.');
      }
      foreach (['fontColor', 'fontBackgroundColor'] as $key) {
        $config[$key] = ['colors' => $colors, 'colorPicker' => ['format' => 'hex']];
      }
      foreach (['tableProperties', 'tableCellProperties'] as $key) {
        $config['table'][$key] = ['borderColors' => $colors, 'backgroundColors' => $colors, 'colorPicker' => ['format' => 'hex']];
      }
    }
    if (!empty($values['custom_styles'])) {
      $definitions = [];
      $names = [];
      foreach (self::lines($values['styles'] ?? '') as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) !== 3 || $parts[0] === '' || isset($names[$parts[0]]) || !in_array($parts[1], ['p', 'h2', 'h3', 'h4', 'span', 'div', 'blockquote', 'table', 'td', 'th'], TRUE)) {
          throw new InvalidArgumentException('Each style needs a unique name, a supported element and CSS classes.');
        }
        $classes = preg_split('/\s+/', $parts[2], -1, PREG_SPLIT_NO_EMPTY);
        if (!$classes) {
          throw new InvalidArgumentException('A style must have at least one CSS class.');
        }
        foreach ($classes as $class) {
          if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $class)) {
            throw new InvalidArgumentException('Invalid CSS class name.');
          }
        }
        $definitions[] = ['name' => $parts[0], 'element' => $parts[1], 'classes' => array_values(array_unique($classes))];
        $names[$parts[0]] = TRUE;
      }
      if (count($definitions) > 50) {
        throw new InvalidArgumentException('At most 50 styles are supported.');
      }
      $config['style']['definitions'] = $definitions;
    }
    return $config;
  }

  private static function lines($text) {
    if (strlen($text) > 20000) {
      throw new InvalidArgumentException('Configuration is too long.');
    }
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $text)), 'strlen'));
  }
}
