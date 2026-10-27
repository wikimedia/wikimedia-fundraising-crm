(function () {
  'use strict';
  CRM.ckeditor5Admin = CRM.ckeditor5Admin || {};
  CRM.ckeditor5Admin.parse = function (values) {
    const config = {};
    const lines = text => (text || '').split(/\r?\n/).map(s => s.trim()).filter(Boolean);
    const words = text => (text || '').split(/[\s,]+/).filter(Boolean);
    if (values.custom_toolbar) config.toolbar = {items: JSON.parse(values.toolbar_items)};
    if (values.custom_find_replace) {
      if (!['dialog', 'dropdown'].includes(values.find_replace_ui)) throw Error('Invalid find and replace interface.');
      config.findAndReplace = {uiType: values.find_replace_ui};
    }
    if (values.custom_fullscreen) {
      config.fullscreen = {
        menuBar: {isVisible: Boolean(values.fullscreen_menu_bar)},
        toolbar: {shouldNotGroupWhenFull: Boolean(values.fullscreen_ungrouped_toolbar)}
      };
    }
    if (values.custom_table_layout) {
      if (!['auto', 'content', 'layout'].includes(values.external_table_type)) throw Error('Invalid external table type.');
      config.table = config.table || {};
      config.table.tableLayout = {stripFigureFromContentTable: Boolean(values.strip_content_table_figure)};
      if (values.external_table_type !== 'auto') config.table.tableLayout.preferredExternalTableType = values.external_table_type;
    }
    if (values.custom_html_ruleset) {
      if (!['template', 'presentation', 'strict'].includes(values.html_ruleset)) throw Error('Invalid HTML ruleset.');
      config.civiHtmlRuleset = values.html_ruleset;
    }
    if (values.custom_upload_mode) {
      if (!['elfinder', 'base64', 'none'].includes(values.upload_mode)) throw Error('Invalid upload mode.');
      config.civiUploadMode = values.upload_mode;
    }
    if (values.custom_qa) config.civiHtmlQa = {enabled: Boolean(values.qa_enabled)};
    if (values.custom_fonts) {
      const options = [...new Set(lines(values.fonts))];
      if (!options.length || options.some(font => !/^[a-zA-Z0-9 ,'"_-]+$/.test(font))) throw Error('Invalid font stack.');
      config.fontFamily = {options, supportAllValues: true};
    }
    if (values.custom_headings) {
      const options = [{model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph'}];
      [...new Set(words(values.headings))].forEach(tag => {
        if (!/^h[1-6]$/.test(tag)) throw Error('Headings must be h1 through h6.');
        options.push({model: 'heading' + tag[1], view: tag, title: 'Heading ' + tag[1], class: 'ck-heading_heading' + tag[1]});
      });
      config.heading = {options};
    }
    if (values.custom_sizes) {
      const options = [...new Set(words(values.sizes).map(size => {
        if (size === 'default') return size;
        if (!/^\d+$/.test(size) || Number(size) < 1 || Number(size) > 200) throw Error('Invalid font size.');
        return Number(size);
      }))];
      if (!options.length) throw Error('Provide at least one font size.');
      config.fontSize = {options};
    }
    if (values.custom_palette) {
      const colors = lines(values.palette).map(line => {
        const [color, ...label] = line.split('|').map(s => s.trim());
        if (!/^#[0-9a-f]{6}$/i.test(color)) throw Error('Use six-digit hexadecimal colors.');
        return {color: color.toUpperCase(), label: label.length ? label.join('|') : color.toUpperCase()};
      });
      if (!colors.length) throw Error('Provide at least one color.');
      config.fontColor = {colors, colorPicker: {format: 'hex'}};
      config.fontBackgroundColor = {colors, colorPicker: {format: 'hex'}};
      config.table = config.table || {};
      ['tableProperties', 'tableCellProperties'].forEach(key => {
        config.table[key] = {borderColors: colors, backgroundColors: colors, colorPicker: {format: 'hex'}};
      });
    }
    if (values.custom_styles) {
      const names = new Set();
      config.style = {definitions: lines(values.styles).map(line => {
        const parts = line.split('|').map(s => s.trim());
        if (parts.length !== 3 || !parts[0] || names.has(parts[0]) || !/^(p|h[2-4]|span|div|blockquote|table|td|th)$/.test(parts[1])) throw Error('Invalid named style.');
        const classes = parts[2].split(/\s+/).filter(Boolean);
        if (!classes.length || classes.some(name => !/^[a-zA-Z_][a-zA-Z0-9_-]*$/.test(name))) throw Error('Invalid CSS class.');
        names.add(parts[0]);
        return {name: parts[0], element: parts[1], classes: [...new Set(classes)]};
      })};
    }
    return config;
  };
})();
