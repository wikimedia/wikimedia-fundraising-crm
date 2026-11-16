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
    const buttonLabels = CRM.vars.ckeditor5Admin.buttonLabels || {};
    const buttonLabel = name => buttonLabels[name] || (name === '|' ? ts('Separator') : name);
    const iconCache = {};
    let draggedAvailable = null;
    let items;
    try { items = JSON.parse(source.val()); }
    catch (error) { return; }
    if (!Array.isArray(items)) return;
    available.forEach(function (name) {
      $('<option>').val(name).text(buttonLabel(name)).appendTo(picker);
    });
    let dragged = null;
    function appendButtonVisual(target, name) {
      if (name === '|') {
        $('<span class="ck5-toolbar-separator" aria-hidden="true">').appendTo(target);
      }
      else if (iconCache[name]) {
        $('<span aria-hidden="true">').html(iconCache[name]).appendTo(target);
      }
      else {
        $('<span class="ck5-toolbar-text">').text(buttonLabel(name)).appendTo(target);
      }
    }
    function renderAvailable() {
      const target = root.find('#ck5-toolbar-available').empty();
      available.forEach(function (name) {
        const used = name !== '|' && items.includes(name);
        const choice = $('<button type="button" class="ck5-toolbar-choice">')
          .attr({'data-name': name, 'aria-label': buttonLabel(name), title: buttonLabel(name), draggable: !used})
          .prop('disabled', used)
          .on('click', function () {
            if (!used) addItem(name, items.length);
          })
          .on('dragstart', function (event) {
            if (used) return;
            draggedAvailable = name;
            event.originalEvent.dataTransfer.setData('text/plain', name);
            event.originalEvent.dataTransfer.effectAllowed = 'copy';
          })
          .on('dragend', function () { draggedAvailable = null; })
          .appendTo(target);
        appendButtonVisual(choice, name);
      });
    }
    function addItem(name, index) {
      if (!available.includes(name) || (name !== '|' && items.includes(name))) return;
      items.splice(index, 0, name);
      render(index, 'remove');
    }
    function render(focusIndex, action) {
      source.val(JSON.stringify(items));
      list.empty();
      items.forEach(function (name, index) {
        const row = $('<li draggable="true">').attr('data-index', index).appendTo(list);
        const visual = $('<span class="ck5-toolbar-button">')
          .attr({'aria-label': buttonLabel(name), title: buttonLabel(name)})
          .appendTo(row);
        appendButtonVisual(visual, name);
        const actions = $('<span class="ck5-toolbar-actions">').appendTo(row);
        [['up', ts('Move left'), '&#8592;'], ['down', ts('Move right'), '&#8594;'], ['remove', ts('Remove'), '&times;']].forEach(function (entry) {
          $('<button type="button">').attr({'data-action': entry[0], 'aria-label': entry[1], title: entry[1]}).html(entry[2])
            .prop('disabled', (entry[0] === 'up' && index === 0) || (entry[0] === 'down' && index === items.length - 1))
            .on('click', function () {
              let next = index;
              if (entry[0] === 'remove') items.splice(index, 1);
              else {
                next = index + (entry[0] === 'up' ? -1 : 1);
                [items[index], items[next]] = [items[next], items[index]];
              }
              render(Math.min(next, items.length - 1), entry[0]);
            }).appendTo(actions);
        });
        row.on('dragstart', function (event) {
          dragged = index;
          event.originalEvent.dataTransfer.setData('text/plain', String(index));
          event.originalEvent.dataTransfer.effectAllowed = 'move';
        }).on('dragover', function (event) { if (dragged !== null) event.preventDefault(); })
          .on('drop', function (event) {
            event.preventDefault();
            if (draggedAvailable !== null) {
              const name = draggedAvailable;
              draggedAvailable = null;
              addItem(name, index);
              return;
            }
            if (dragged === null) return;
            const moved = items.splice(dragged, 1)[0];
            items.splice(index, 0, moved);
            dragged = null;
            render(index, 'remove');
          }).on('dragend', function () { dragged = null; });
      });
      picker.find('option').each(function () { this.disabled = this.value !== '|' && items.includes(this.value); });
      if (picker.find(':selected').prop('disabled')) picker.val(picker.find('option:not(:disabled)').first().val());
      renderAvailable();
      if (focusIndex >= 0) list.children().eq(focusIndex).find('[data-action="' + action + '"]').trigger('focus');
    }
    root.find('#ck5-add').on('click', function () {
      const name = picker.val();
      if (available.includes(name) && (name === '|' || !items.includes(name))) {
        addItem(name, items.length);
      }
    });
    list.on('dragover', function (event) {
      if (dragged !== null || draggedAvailable !== null) event.preventDefault();
    }).on('drop', function (event) {
      if (event.target !== this || draggedAvailable === null) return;
      event.preventDefault();
      const name = draggedAvailable;
      draggedAvailable = null;
      addItem(name, items.length);
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
    root.find('#ck5-toolbar-fallback').hide();
    root.find('#ck5-toolbar-source').hide();
    let dirty = false;
    root.on('input change click drop', 'input, textarea, button, #ck5-toolbar', function () { dirty = true; });
    root.find('.ck5-preset-tabs a').on('click', function (event) {
      if (dirty && !window.confirm(ts('Discard unsaved preset changes?'))) event.preventDefault();
    });

    const status = root.find('#ck5-preview-status');
    const preview = root.find('#ck5-preview-editor');
    const preset = root.find('#ck5-preview').attr('data-preset');
    const customToolbar = root.find('[name="custom_toolbar"]');
    if (!customToolbar.prop('checked')) customToolbar.prop('disabled', true);
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
      if (!window.ClassicEditor) await CRM.ckeditor5.loadEditorRuntime(CRM.config.CKEditor5Location);
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
          const inheritedToolbar = CRM.ckeditor5Admin.copyToolbarItems(config);
          if (!values.custom_toolbar && inheritedToolbar) {
            items = inheritedToolbar;
            render();
          }
          customToolbar.prop('disabled', false);
          if (editor) { await editor.destroy(); editor = null; }
          if (current !== revision) return;
          preview.empty();
          $('<h2>').text(ts('Example heading')).appendTo(preview);
          $('<p>').attr('data-qa-sample', 'removed').attr('style', 'color: #123456; position: relative')
            .text(ts('Write a short article or event description here to test the selected settings.')).appendTo(preview);
          const sourceHtml = preview.html();
          editor = await window.ClassicEditor.create({...config, attachTo: preview[0]});
          available.forEach(function (name) {
            if (name === '|' || iconCache[name]) return;
            try {
              const view = editor.ui.componentFactory.create(name);
              view.render();
              const svg = view.element && view.element.querySelector('svg');
              if (svg) iconCache[name] = svg.outerHTML;
              view.destroy();
            }
            catch (error) {
              // Some contextual components cannot render outside their editor state.
            }
          });
          render();
          CRM.ckeditor5.attachHtmlQaReport(editor, sourceHtml);
          editor.ui.view.element.setAttribute('data-ckeditor5-preset', preset);
          ['uploadImage', 'imageUpload'].forEach(name => {
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
