(function ($) {
  'use strict';
  $(function () {
    const root = $('.ck5-presets');
    if (!root.length) return;
    const ts = CRM.ts('ckeditor5');
    const source = root.find('[name="toolbar_items"]');
    const list = root.find('#ck5-toolbar');
    const picker = root.find('#ck5-button');
    const available = CRM.vars.ckeditor5Admin.buttons;
    let items;
    try { items = JSON.parse(source.val()); }
    catch (error) { return; }
    if (!Array.isArray(items)) return;
    available.forEach(function (name) {
      $('<option>').val(name).text(name === '|' ? ts('Separator') : name).appendTo(picker);
    });
    let dragged = null;
    function render(focusIndex, action) {
      source.val(JSON.stringify(items));
      list.empty();
      items.forEach(function (name, index) {
        const row = $('<li draggable="true">').attr('data-index', index).appendTo(list);
        $('<span>').text(name === '|' ? ts('Separator') : name).appendTo(row);
        [['up', ts('Up')], ['down', ts('Down')], ['remove', ts('Remove')]].forEach(function (entry) {
          $('<button type="button">').attr('data-action', entry[0]).text(entry[1])
            .prop('disabled', (entry[0] === 'up' && index === 0) || (entry[0] === 'down' && index === items.length - 1))
            .on('click', function () {
              let next = index;
              if (entry[0] === 'remove') items.splice(index, 1);
              else {
                next = index + (entry[0] === 'up' ? -1 : 1);
                [items[index], items[next]] = [items[next], items[index]];
              }
              render(Math.min(next, items.length - 1), entry[0]);
            }).appendTo(row);
        });
        row.on('dragstart', function (event) {
          dragged = index;
          event.originalEvent.dataTransfer.setData('text/plain', String(index));
          event.originalEvent.dataTransfer.effectAllowed = 'move';
        }).on('dragover', function (event) { if (dragged !== null) event.preventDefault(); })
          .on('drop', function (event) {
            event.preventDefault();
            if (dragged === null) return;
            const moved = items.splice(dragged, 1)[0];
            items.splice(index, 0, moved);
            dragged = null;
            render(index, 'remove');
          }).on('dragend', function () { dragged = null; });
      });
      picker.find('option').each(function () { this.disabled = this.value !== '|' && items.includes(this.value); });
      if (picker.find(':selected').prop('disabled')) picker.val(picker.find('option:not(:disabled)').first().val());
      if (focusIndex >= 0) list.children().eq(focusIndex).find('[data-action="' + action + '"]').trigger('focus');
    }
    root.find('#ck5-add').on('click', function () {
      const name = picker.val();
      if (available.includes(name) && (name === '|' || !items.includes(name))) {
        items.push(name);
        render(items.length - 1, 'remove');
      }
    });
    root.find('.ck5-section').each(function () {
      const section = $(this);
      const checkbox = root.find('[name="' + section.data('control') + '"]');
      function toggle() { section.toggle(checkbox.prop('checked')); }
      checkbox.on('change', toggle);
      toggle();
    });
    function swatches() {
      const target = root.find('#ck5-swatches').empty();
      root.find('[name="palette"]').val().split(/\r?\n/).forEach(function (line) {
        const parts = line.split('|').map(part => part.trim());
        if (/^#[0-9a-f]{6}$/i.test(parts[0])) {
          $('<span>').addClass('ck5-swatch').css('border-left-color', parts[0])
            .text(parts[1] || parts[0]).appendTo(target);
        }
      });
    }
    root.find('[name="palette"]').on('input', swatches);
    // Keep textareas as a non-JavaScript fallback and the submitted source.
    function editableRows(field, headings, types) {
      const input = root.find('[name="' + field + '"]');
      const table = $('<table class="ck5-values">').insertAfter(input);
      const header = $('<tr>').appendTo($('<thead>').appendTo(table));
      headings.concat([ts('Actions')]).forEach(label => $('<th scope="col">').text(label).appendTo(header));
      const body = $('<tbody>').appendTo(table);
      function sync() {
        const lines = [];
        body.children().each(function () {
          const values = $(this).find('input, select').map(function () { return $(this).val(); }).get();
          if (values.some(value => value.trim() !== '')) lines.push(values.join(' | '));
        });
        input.val(lines.join('\n')).trigger('input');
      }
      function add(values) {
        const row = $('<tr>').appendTo(body);
        types.forEach(function (type, index) {
          const cell = $('<td>').appendTo(row);
          const control = Array.isArray(type) ? $('<select>') : $('<input>').attr('type', type);
          if (Array.isArray(type)) type.forEach(value => $('<option>').val(value).text(value).appendTo(control));
          control.attr('aria-label', headings[index]).val(values[index] || (type === 'color' ? '#000000' : Array.isArray(type) ? type[0] : ''))
            .on('input change', sync).appendTo(cell);
        });
        $('<button type="button">').text(ts('Remove')).on('click', function () { row.remove(); sync(); })
          .appendTo($('<td>').appendTo(row));
      }
      input.val().split(/\r?\n/).filter(line => line.trim()).forEach(line => add(line.split('|').map(value => value.trim())));
      $('<button type="button">').text(ts('Add row')).insertAfter(table).on('click', function () {
        add([]);
        sync();
        body.children().last().find('input, select').first().trigger('focus');
      });
      input.hide();
    }
    editableRows('palette', [ts('Color'), ts('Label')], ['color', 'text']);
    editableRows('styles', [ts('Style name'), ts('HTML element'), ts('CSS classes')], ['text', ['p', 'h2', 'h3', 'h4', 'span', 'div', 'blockquote', 'table', 'td', 'th'], 'text']);
    render();
    swatches();
    root.find('#ck5-toolbar-source').hide();
    let dirty = false;
    root.on('input change click drop', 'input, textarea, button, #ck5-toolbar', function () { dirty = true; });
    root.find('.ck5-preset-tabs a').on('click', function (event) {
      if (dirty && !window.confirm(ts('Discard unsaved preset changes?'))) event.preventDefault();
    });

    const status = root.find('#ck5-preview-status');
    const preview = root.find('#ck5-preview-editor');
    const preset = root.find('#ck5-preview').attr('data-preset');
    preview.attr('data-preset', preset);
    if (!CRM.config.CKEditor5Location) {
      status.text(ts('Select CKEditor 5 as the site editor to enable the live preview.'));
      return;
    }
    let editor = null;
    let revision = 0;
    let timer;
    let queue = Promise.resolve();
    const load = url => new Promise((resolve, reject) => CRM.loadScript(url).done(resolve).fail(reject));
    const ready = (async function () {
      if (!CRM.wysiwyg.getConfig) await load(CRM.config.wysisygScriptLocation);
      if (CRM.config.CKEditor5Language) await load(CRM.config.CKEditor5Language);
      if (!window.ClassicEditor) await load(CRM.config.CKEditor5Location);
      if (CRM.ckeditor5 && typeof CRM.ckeditor5.loadPluginAssets === 'function') {
        await CRM.ckeditor5.loadPluginAssets();
      }
    })();
    // Attach a rejection handler immediately, before the first debounce fires.
    ready.catch(() => status.text(ts('Unable to load the editor preview. Reload the page to retry.')));
    function schedulePreview() {
      const current = ++revision;
      clearTimeout(timer);
      status.text(ts('Updating preview…'));
      timer = setTimeout(function () {
        queue = queue.then(async function () {
          await ready;
          if (current !== revision) return;
          const values = {};
          root.find('input[name], textarea[name], select[name]').each(function () {
            values[this.name] = this.type === 'checkbox' ? this.checked : $(this).val();
          });
          const overrides = CRM.ckeditor5Admin.parse(values);
          const config = CRM.wysiwyg.getConfig(preview[0], Boolean(CRM.config.ELFinderLocation), overrides);
          if (editor) { await editor.destroy(); editor = null; }
          if (current !== revision) return;
          preview.empty();
          $('<h2>').text(ts('Example heading')).appendTo(preview);
          $('<p>').attr('data-qa-sample', 'removed').attr('style', 'color: #123456; position: relative')
            .text(ts('Write a short article or event description here to test the selected settings.')).appendTo(preview);
          const sourceHtml = preview.html();
          editor = await window.ClassicEditor.create({...config, attachTo: preview[0]});
          CRM.ckeditor5.attachHtmlQaReport(editor, sourceHtml);
          editor.ui.view.element.setAttribute('data-ckeditor5-preset', preset);
          ['uploadImage', 'imageUpload', 'ckfinder'].forEach(name => {
            const command = editor.commands.get(name);
            if (command) command.forceDisabled('adminPreview');
          });
          if (current === revision) status.text(ts('Preview updated. Sample content is not saved.'));
        }).catch(function (error) {
          if (current === revision) status.text(ts('Preview unavailable: %1', {1: error && error.message ? error.message : ts('Loading failed')}));
        });
      }, 500);
    }
    root.on('input change', '.ck5-section input, .ck5-section textarea, .ck5-section select, input[type="checkbox"]', schedulePreview);
    // Toolbar updates write the hidden JSON source programmatically.
    root.on('click drop', '#ck5-add, #ck5-toolbar', schedulePreview);
    schedulePreview();
  });
})(CRM.$);
