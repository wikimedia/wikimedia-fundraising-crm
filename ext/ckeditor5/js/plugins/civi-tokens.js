/* jshint esversion: 6 */
(function(CRM, CKEditor) {
  'use strict';

  const $ = CRM.$;
  const ts = CRM.ts('ckeditor5');
  const cataloguePromises = Object.create(null);
  const catalogueFailures = Object.create(null);
  const RETRY_COOLDOWN_MS = 30000;
  let dialogSequence = 0;

  function normalizeTokenId(rawId) {
    const trimmed = String(rawId || '').trim();
    if (!trimmed) return '';
    if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
      return trimmed;
    }
    return `{${trimmed.replace(/^\{+|\}+$/g, '')}}`;
  }

  function normalizeTokenGroups(raw) {
    if (!raw) return [];
    let list = raw;
    if (typeof list === 'function') {
      try {
        list = list();
      }
      catch (e) {
        return [];
      }
    }
    if (list && Array.isArray(list.results)) {
      list = list.results;
    }
    if (list && Array.isArray(list.groups)) {
      list = list.groups;
    }
    if (!Array.isArray(list) && list && typeof list === 'object') {
      const children = Object.keys(list).map(function(key) {
        const value = list[key];
        if (value && typeof value === 'object' && !Array.isArray(value)) {
          const id = normalizeTokenId(value.id || value.token || value.name || key);
          return id ? {id: id, text: String(value.text || value.label || id)} : null;
        }
        const id = normalizeTokenId(key);
        return id ? {id: id, text: String(value || id)} : null;
      }).filter(Boolean);
      return children.length ? [{text: ts('Tokens'), children: children}] : [];
    }
    if (!Array.isArray(list)) return [];

    const groups = [];
    const ungrouped = [];
    list.forEach(function(entry) {
      if (!entry || typeof entry !== 'object') return;
      if (Array.isArray(entry.children)) {
        const children = [];
        entry.children.forEach(function(child) {
          if (!child || typeof child !== 'object') return;
          const id = normalizeTokenId(child.id || child.token || child.name);
          if (!id) return;
          children.push({
            id: id,
            text: String(child.text || child.label || id)
          });
        });
        if (children.length) {
          groups.push({
            text: String(entry.text || entry.label || ts('Other')),
            children: children
          });
        }
      }
      else {
        const id = normalizeTokenId(entry.id || entry.token || entry.name);
        if (!id) return;
        ungrouped.push({
          id: id,
          text: String(entry.text || entry.label || id)
        });
      }
    });
    if (ungrouped.length) {
      groups.push({
        text: ts('Tokens'),
        children: ungrouped
      });
    }
    return groups;
  }

  function flattenTokenGroups(groups) {
    const flat = [];
    const seen = new Set();
    (Array.isArray(groups) ? groups : []).forEach(function(group) {
      const groupName = String((group && group.text) || '');
      (Array.isArray(group && group.children) ? group.children : []).forEach(function(token) {
        if (!token || !token.id || seen.has(token.id)) return;
        seen.add(token.id);
        const code = String(token.id).replace(/^[{[]|[}\]]$/g, '').toLocaleLowerCase();
        const label = String(token.text || token.id);
        const labelLower = label.toLocaleLowerCase();
        const groupLower = groupName.toLocaleLowerCase();
        flat.push({
          id: String(token.id),
          text: label,
          group: groupName,
          codeLower: code,
          labelLower: labelLower,
          searchText: `${code} ${labelLower} ${groupLower}`
        });
      });
    });
    return flat;
  }

  function extractSelect2Tokens($elements) {
    if (!$elements || !$elements.length || typeof $elements.each !== 'function') return [];
    let groups = [];
    $elements.each(function() {
      if (groups.length) return;
      const $el = $(this);
      const select2 = typeof $el.data === 'function' ? $el.data('select2') : null;
      const rawData = select2 && select2.opts ? select2.opts.data : (typeof $el.data === 'function' ? $el.data('tokens') : null);
      const normalized = normalizeTokenGroups(rawData);
      if (normalized.length) {
        groups = normalized;
      }
    });
    return groups;
  }

  /**
   * Discover tokens from the native CiviCRM token selector widget on the page
   * before falling back to the remote catalogue endpoint.
   */
  function discoverPageTokens(editor) {
    const sourceElement = editor && editor.sourceElement;
    if (sourceElement && typeof sourceElement.closest === 'function') {
      const richText = sourceElement.closest('civi-rich-text-input');
      if (richText && typeof richText.getTokens === 'function') {
        try {
          const groups = normalizeTokenGroups(richText.getTokens());
          if (groups.length) return groups;
        }
        catch (e) {
          // Ignore and try next token source.
        }
      }
    }
    if (typeof $ === 'function' && sourceElement) {
      const $source = $(sourceElement);
      const fieldId = typeof $source.attr === 'function' ? ($source.attr('id') || '') : '';
      const fieldName = typeof $source.attr === 'function' ? ($source.attr('name') || '') : '';
      const $form = typeof $source.closest === 'function' ? $source.closest('form') : null;
      if ($form && $form.length && typeof $form.data === 'function') {
        const formTokens = normalizeTokenGroups($form.data('tokens'));
        if (formTokens.length) return formTokens;
      }
      const $scope = ($form && $form.length)
        ? $form
        : (typeof $source.closest === 'function' ? $source.closest('[ng-form], .crm-container, body') : null);
      if ($scope && $scope.length && typeof $scope.find === 'function') {
        if (fieldId || fieldName) {
          const $matching = $scope.find('.crm-token-selector').filter(function() {
            const target = $(this).attr('data-field') || $(this).data('field') || '';
            return target === fieldId || target === fieldName;
          });
          const matchedGroups = extractSelect2Tokens($matching);
          if (matchedGroups.length) return matchedGroups;
        }
        const scopedGroups = extractSelect2Tokens(
          $scope.find('.crm-token-selector, .crmMailingToken, .rich-text-token-picker, [crm-mailing-token] input')
        );
        if (scopedGroups.length) return scopedGroups;
      }
    }
    if (CRM && CRM.crmMailing && CRM.crmMailing.mailTokens) {
      const mailingGroups = normalizeTokenGroups(CRM.crmMailing.mailTokens);
      if (mailingGroups.length) return mailingGroups;
    }
    return [];
  }

  function loadCatalogue(preset, options = {}) {
    const force = options && options.force === true;
    const failedAt = catalogueFailures[preset] || 0;
    if (!force && failedAt && (Date.now() - failedAt) < RETRY_COOLDOWN_MS) {
      return Promise.reject(new Error('Unable to load CiviCRM tokens.'));
    }
    if (!cataloguePromises[preset]) {
      cataloguePromises[preset] = new Promise(function(resolve, reject) {
        $.getJSON(CRM.url('civicrm/ckeditor5/tokens', {preset: preset}))
          .done(function(response) {
            if (!response || !Array.isArray(response.groups)) {
              reject(new Error('The CKEditor token endpoint returned an invalid catalogue.'));
              return;
            }
            delete catalogueFailures[preset];
            resolve(normalizeTokenGroups(response.groups));
          })
          .fail(function(xhr, status, error) {
            reject(new Error(error || status || 'Unable to load CiviCRM tokens.'));
          });
      }).catch(function(error) {
        catalogueFailures[preset] = Date.now();
        delete cataloguePromises[preset];
        throw error;
      });
    }
    return cataloguePromises[preset];
  }

  function resolveEditorTokens(editor, options = {}) {
    const pageGroups = discoverPageTokens(editor);
    if (pageGroups.length) {
      return Promise.resolve(pageGroups);
    }
    if (options && options.allowRemote === false) {
      return Promise.resolve([]);
    }
    const config = (editor && editor.config && typeof editor.config.get === 'function' && editor.config.get('civiTokens')) || {};
    return loadCatalogue(config.preset || 'default', options);
  }

  function filterTokens(flatTokens, query, limit = 12) {
    const needle = String(query || '').replace(/^\{+|\}+$/g, '').trim().toLocaleLowerCase();
    if (!needle) {
      return flatTokens.slice(0, limit);
    }
    const codePrefix = [];
    const labelPrefix = [];
    const substring = [];
    for (let i = 0; i < flatTokens.length; i += 1) {
      const item = flatTokens[i];
      if (item.codeLower.startsWith(needle)) {
        codePrefix.push(item);
      }
      else if (item.labelLower.startsWith(needle)) {
        labelPrefix.push(item);
      }
      else if (item.searchText.includes(needle)) {
        substring.push(item);
      }
    }
    return codePrefix.concat(labelPrefix, substring).slice(0, limit);
  }

  function insertToken(editor, token) {
    if (typeof token !== 'string' || !token.length) return;
    if (CRM && CRM.ckeditor5 && typeof CRM.ckeditor5.insertIntoEditor === 'function'
      && editor && editor.ui && editor.ui.view && editor.ui.view.element) {
      CRM.ckeditor5.insertIntoEditor(editor, token);
      if (editor.editing && editor.editing.view && typeof editor.editing.view.focus === 'function') {
        editor.editing.view.focus();
      }
      return;
    }
    editor.model.change(function(writer) {
      editor.model.insertContent(writer.createText(token), editor.model.document.selection);
    });
    editor.editing.view.focus();
  }

  function openDialog(editor, groups) {
    dialogSequence += 1;
    const searchId = `crm-ckeditor5-token-search-${dialogSequence}`;
    const selectId = `crm-ckeditor5-token-select-${dialogSequence}`;
    const dialog = $('<div class="crm-ckeditor5-token-dialog">');
    const searchLabel = $('<label>').attr('for', searchId).text(ts('Search tokens'));
    const search = $('<input type="search" class="crm-ckeditor5-token-search">')
      .attr('id', searchId)
      .attr('placeholder', ts('Search by label or token'));
    const selectLabel = $('<label>').attr('for', selectId).text(ts('Available tokens'));
    const select = $('<select class="crm-ckeditor5-token-select" size="14">').attr('id', selectId);

    function render(query) {
      const needle = (query || '').trim().toLocaleLowerCase();
      select.empty();
      groups.forEach(function(group) {
        const matches = (Array.isArray(group.children) ? group.children : []).filter(function(token) {
          return !needle || `${token.text || ''} ${token.id || ''}`.toLocaleLowerCase().includes(needle);
        });
        if (!matches.length) return;
        const optionGroup = $('<optgroup>').attr('label', group.text || ts('Other'));
        matches.forEach(function(token) {
          $('<option>').val(token.id).text(`${token.text || token.id} — ${token.id}`).appendTo(optionGroup);
        });
        optionGroup.appendTo(select);
      });
      select.prop('selectedIndex', select.find('option').length ? 0 : -1);
    }

    function close() {
      dialog.dialog('close');
    }

    function choose() {
      const token = select.val();
      if (!token) return;
      insertToken(editor, token);
      close();
    }

    search.on('input', function() {
      render(this.value);
    });
    select.on('dblclick', choose);
    dialog.append(searchLabel, search, selectLabel, select);
    render('');
    dialog.dialog({
      title: ts('Insert CiviCRM token'),
      modal: true,
      width: Math.max(280, Math.min(620, window.innerWidth - 40)),
      buttons: [
        {text: ts('Insert'), click: choose},
        {text: ts('Cancel'), click: close}
      ],
      open: function() {
        const widget = dialog.dialog('widget').addClass('crm-ckeditor5-token-dialog-shell');
        widget.prev('.ui-widget-overlay').addClass('crm-ckeditor5-token-dialog-overlay');
      },
      close: function() {
        dialog.dialog('destroy').remove();
        editor.editing.view.focus();
      }
    });
    search.trigger('focus');
  }

  function getModelPrefixBeforeSelection(editor) {
    const selection = editor && editor.model && editor.model.document && editor.model.document.selection;
    if (!selection || !selection.isCollapsed) return null;
    const focus = selection.focus;
    if (!focus || !focus.parent) return null;
    let textBefore = '';
    const parent = focus.parent;
    if (typeof parent.getChildren === 'function') {
      let offset = 0;
      for (const child of parent.getChildren()) {
        const size = child.offsetSize || 0;
        if (offset + size <= focus.offset) {
          textBefore += child.is && child.is('$text') ? child.data : '\uFFFC';
        }
        else if (offset < focus.offset && child.is && child.is('$text')) {
          textBefore += child.data.slice(0, focus.offset - offset);
          break;
        }
        else {
          break;
        }
        offset += size;
      }
    }
    else if (focus.textNode && typeof focus.textNode.data === 'string') {
      textBefore = focus.textNode.data.slice(0, focus.offset);
    }
    const match = textBefore.match(/\{([^\s{}<>"]*)$/);
    if (!match) return null;
    return {
      mode: 'model',
      query: match[1],
      matchLength: match[0].length
    };
  }

  function getTextareaPrefixBeforeCursor(textarea) {
    if (!textarea || typeof textarea.selectionStart !== 'number') return null;
    if (textarea.selectionStart !== textarea.selectionEnd) return null;
    const before = String(textarea.value || '').slice(0, textarea.selectionStart);
    const match = before.match(/\{([^\s{}<>"]*)$/);
    if (!match) return null;
    return {
      mode: 'textarea',
      textarea: textarea,
      query: match[1],
      matchLength: match[0].length,
      cursor: textarea.selectionStart
    };
  }

  function getAnchorRect(editor, state) {
    if (state && state.mode === 'textarea' && state.textarea && typeof state.textarea.getBoundingClientRect === 'function') {
      const rect = state.textarea.getBoundingClientRect();
      return {
        left: rect.left + 16,
        bottom: Math.min(rect.bottom, rect.top + 48),
        top: rect.top + 24
      };
    }
    if (typeof window !== 'undefined' && typeof window.getSelection === 'function') {
      const domSelection = window.getSelection();
      if (domSelection && domSelection.rangeCount > 0) {
        const range = domSelection.getRangeAt(0);
        if (range && typeof range.getBoundingClientRect === 'function') {
          const rect = range.getBoundingClientRect();
          if (rect && (rect.width || rect.height || rect.top || rect.left)) {
            return rect;
          }
        }
      }
    }
    const rootEl = editor && editor.ui && editor.ui.view && editor.ui.view.element;
    if (rootEl && typeof rootEl.getBoundingClientRect === 'function') {
      const rect = rootEl.getBoundingClientRect();
      return {
        left: rect.left + 16,
        bottom: rect.top + 60,
        top: rect.top + 40
      };
    }
    return {left: 20, bottom: 60, top: 40};
  }

  function attachInlineAutocomplete(editor) {
    if (typeof document === 'undefined' || !document.body || typeof document.createElement !== 'function') {
      return;
    }
    let popup = null;
    let currentMatches = [];
    let selectedIndex = 0;
    let activeState = null;
    let dismissedPrefixKey = null;
    let cachedFlatTokens = null;

    function getFlatTokens() {
      if (cachedFlatTokens !== null) {
        return Promise.resolve(cachedFlatTokens);
      }
      return resolveEditorTokens(editor).then(function(groups) {
        const flat = flattenTokenGroups(groups);
        cachedFlatTokens = flat;
        return flat;
      }).catch(function() {
        return [];
      });
    }

    function ensurePopup() {
      if (popup) return popup;
      popup = document.createElement('div');
      popup.className = 'crm-ckeditor5-token-autocomplete';
      popup.setAttribute('role', 'listbox');
      popup.style.display = 'none';
      document.body.appendChild(popup);
      return popup;
    }

    function hidePopup() {
      activeState = null;
      currentMatches = [];
      selectedIndex = 0;
      if (popup) {
        popup.style.display = 'none';
        popup.innerHTML = '';
      }
    }

    function isPopupVisible() {
      return Boolean(popup && popup.style.display !== 'none' && currentMatches.length > 0 && activeState);
    }

    function applyTokenCompletion(token) {
      if (!token || !activeState) return;
      const state = activeState;
      dismissedPrefixKey = null;
      hidePopup();
      if (state.mode === 'textarea' && state.textarea) {
        const textarea = state.textarea;
        const current = String(textarea.value || '');
        const cursor = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : state.cursor;
        const start = Math.max(0, cursor - state.matchLength);
        let end = cursor;
        if (current.charAt(end) === '}') {
          end += 1;
        }
        textarea.value = current.slice(0, start) + token.id + current.slice(end);
        const nextPos = start + token.id.length;
        if (typeof textarea.setSelectionRange === 'function') {
          textarea.setSelectionRange(nextPos, nextPos);
        }
        else {
          textarea.selectionStart = nextPos;
          textarea.selectionEnd = nextPos;
        }
        if (typeof Event === 'function' && typeof textarea.dispatchEvent === 'function') {
          textarea.dispatchEvent(new Event('input', {bubbles: true}));
        }
        if (typeof textarea.focus === 'function') {
          textarea.focus();
        }
        return;
      }
      if (editor.model && typeof editor.model.change === 'function') {
        editor.model.change(function(writer) {
          const selection = editor.model.document.selection;
          const focus = selection && selection.focus;
          if (!focus || typeof focus.getShiftedBy !== 'function') {
            editor.model.insertContent(writer.createText(token.id), selection);
            return;
          }
          const startPos = focus.getShiftedBy(-state.matchLength);
          let endPos = focus;
          const nodeAfter = focus.nodeAfter;
          if (nodeAfter && nodeAfter.is && nodeAfter.is('$text') && String(nodeAfter.data || '').startsWith('}')) {
            endPos = focus.getShiftedBy(1);
          }
          const range = writer.createRange(startPos, endPos);
          const attrs = typeof selection.getAttributes === 'function'
            ? Object.fromEntries(selection.getAttributes())
            : {};
          writer.remove(range);
          writer.insertText(token.id, attrs, startPos);
          if (typeof writer.setSelection === 'function') {
            writer.setSelection(startPos.getShiftedBy(token.id.length));
          }
        });
        if (editor.editing && editor.editing.view && typeof editor.editing.view.focus === 'function') {
          editor.editing.view.focus();
        }
      }
    }

    function renderPopup(state, matches) {
      if (!matches.length) {
        hidePopup();
        return;
      }
      const el = ensurePopup();
      activeState = state;
      currentMatches = matches;
      if (selectedIndex >= matches.length) {
        selectedIndex = 0;
      }
      el.innerHTML = '';
      matches.forEach(function(item, index) {
        const option = document.createElement('div');
        option.className = 'crm-ckeditor5-token-autocomplete__item'
          + (index === selectedIndex ? ' is-active' : '');
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', index === selectedIndex ? 'true' : 'false');

        const labelSpan = document.createElement('span');
        labelSpan.className = 'crm-ckeditor5-token-autocomplete__label';
        labelSpan.textContent = item.text && item.text !== item.id ? item.text : item.id;

        const codeSpan = document.createElement('code');
        codeSpan.className = 'crm-ckeditor5-token-autocomplete__code';
        codeSpan.textContent = item.id;

        option.appendChild(labelSpan);
        option.appendChild(codeSpan);
        option.addEventListener('mousedown', function(event) {
          event.preventDefault();
          applyTokenCompletion(item);
        });
        el.appendChild(option);
      });

      const rect = getAnchorRect(editor, state);
      const viewportWidth = (typeof window !== 'undefined' && window.innerWidth) || 1024;
      const viewportHeight = (typeof window !== 'undefined' && window.innerHeight) || 768;
      el.style.display = 'block';
      const popupHeight = el.offsetHeight || 220;
      const popupWidth = el.offsetWidth || 320;
      let top = (rect.bottom || 60) + 4;
      if (top + popupHeight > viewportHeight - 12 && (rect.top || 40) > popupHeight + 12) {
        top = (rect.top || 40) - popupHeight - 4;
      }
      const left = Math.max(8, Math.min(rect.left || 20, viewportWidth - popupWidth - 12));
      el.style.top = `${Math.round(top)}px`;
      el.style.left = `${Math.round(left)}px`;
    }

    function updateHighlight() {
      if (!popup) return;
      const items = popup.querySelectorAll('.crm-ckeditor5-token-autocomplete__item');
      items.forEach(function(item, index) {
        if (index === selectedIndex) {
          item.classList.add('is-active');
          item.setAttribute('aria-selected', 'true');
          if (typeof item.scrollIntoView === 'function') {
            item.scrollIntoView({block: 'nearest'});
          }
        }
        else {
          item.classList.remove('is-active');
          item.setAttribute('aria-selected', 'false');
        }
      });
    }

    function checkStateAndTrigger(state) {
      if (!state) {
        dismissedPrefixKey = null;
        hidePopup();
        return;
      }
      const prefixKey = `${state.mode}:${state.matchLength}:${state.query}`;
      if (dismissedPrefixKey && dismissedPrefixKey === prefixKey) {
        return;
      }
      dismissedPrefixKey = null;
      getFlatTokens().then(function(flatTokens) {
        if (!flatTokens.length) {
          hidePopup();
          return;
        }
        const matches = filterTokens(flatTokens, state.query, 12);
        renderPopup(state, matches);
      });
    }

    function handleNavigationKey(key, preventDefaultAndStop) {
      if (!isPopupVisible()) return false;
      if (key === 'ArrowDown' || key === 40) {
        preventDefaultAndStop();
        selectedIndex = (selectedIndex + 1) % currentMatches.length;
        updateHighlight();
        return true;
      }
      if (key === 'ArrowUp' || key === 38) {
        preventDefaultAndStop();
        selectedIndex = (selectedIndex - 1 + currentMatches.length) % currentMatches.length;
        updateHighlight();
        return true;
      }
      if (key === 'Enter' || key === 'Tab' || key === 13 || key === 9) {
        preventDefaultAndStop();
        applyTokenCompletion(currentMatches[selectedIndex]);
        return true;
      }
      if (key === 'Escape' || key === 27) {
        preventDefaultAndStop();
        if (activeState) {
          dismissedPrefixKey = `${activeState.mode}:${activeState.matchLength}:${activeState.query}`;
        }
        hidePopup();
        return true;
      }
      return false;
    }

    if (editor.editing && editor.editing.view && editor.editing.view.document
      && typeof editor.editing.view.document.on === 'function') {
      editor.editing.view.document.on('keydown', function(evt, data) {
        const domEvt = data && data.domEvent;
        const key = (domEvt && domEvt.key) || (data && data.keyCode);
        handleNavigationKey(key, function() {
          if (typeof data.preventDefault === 'function') data.preventDefault();
          if (typeof evt.stop === 'function') evt.stop();
        });
      }, {priority: 'highest'});

      editor.editing.view.document.on('blur', function() {
        setTimeout(hidePopup, 150);
      });
    }

    if (editor.model && editor.model.document && typeof editor.model.document.on === 'function') {
      editor.model.document.on('change:data', function(evt, batch) {
        if (batch && batch.isUndo === false && batch.isLocal === false) {
          return;
        }
        const rootEl = editor && editor.ui && editor.ui.view && editor.ui.view.element;
        const hasFocus = (editor.editing && editor.editing.view && editor.editing.view.document && editor.editing.view.document.isFocused)
          || (rootEl && typeof document !== 'undefined' && document.activeElement && rootEl.contains(document.activeElement));
        if (!hasFocus) {
          return;
        }
        checkStateAndTrigger(getModelPrefixBeforeSelection(editor));
      });
      if (editor.model.document.selection && typeof editor.model.document.selection.on === 'function') {
        editor.model.document.selection.on('change:range', function() {
          const state = getModelPrefixBeforeSelection(editor);
          if (!state) hidePopup();
        });
      }
    }

    function bindRootEvents() {
      const rootEl = editor && editor.ui && editor.ui.view && editor.ui.view.element;
      if (!rootEl || typeof rootEl.addEventListener !== 'function') return;

      rootEl.addEventListener('keydown', function(event) {
        const target = event && event.target;
        if (!target || !target.matches) return;
        if (target.matches('textarea.raw-html-embed__source:not([disabled]), .ck-source-editing-area textarea:not([disabled])')) {
          handleNavigationKey(event.key || event.keyCode, function() {
            event.preventDefault();
            event.stopPropagation();
          });
        }
      }, true);

      rootEl.addEventListener('input', function(event) {
        const target = event && event.target;
        if (!target || !target.matches) return;
        if (target.matches('textarea.raw-html-embed__source:not([disabled]), .ck-source-editing-area textarea:not([disabled])')) {
          checkStateAndTrigger(getTextareaPrefixBeforeCursor(target));
        }
      });

      rootEl.addEventListener('click', function(event) {
        const target = event && event.target;
        if (target && target.matches && target.matches('textarea.raw-html-embed__source:not([disabled]), .ck-source-editing-area textarea:not([disabled])')) {
          const state = getTextareaPrefixBeforeCursor(target);
          if (!state) hidePopup();
        }
      });
    }

    if (editor.ui && editor.ui.view && editor.ui.view.element) {
      bindRootEvents();
    }
    else if (typeof editor.on === 'function') {
      editor.on('ready', bindRootEvents);
    }

    if (typeof editor.on === 'function') {
      editor.on('destroy', function() {
        if (popup && popup.parentNode) {
          popup.parentNode.removeChild(popup);
        }
        popup = null;
      });
    }
  }

  class CiviTokens extends CKEditor.Plugin {
    static get pluginName() {
      return 'CiviTokens';
    }

    init() {
      const editor = this.editor;
      attachInlineAutocomplete(editor);
      editor.ui.componentFactory.add('civiTokens', function(locale) {
        const button = new CKEditor.ButtonView(locale);
        button.set({
          label: ts('Tokens'),
          tooltip: true,
          withText: true
        });
        button.on('execute', function() {
          button.isEnabled = false;
          resolveEditorTokens(editor, {force: true})
            .then(function(groups) {
              openDialog(editor, groups);
            })
            .catch(function(error) {
              CRM.alert(error.message, ts('Unable to load tokens'), 'error');
            })
            .finally(function() {
              button.isEnabled = true;
            });
        });
        return button;
      });
    }
  }

  CRM.ckeditor5.tokenPicker = Object.freeze({
    insert: insertToken,
    discoverPageTokens: discoverPageTokens,
    normalizeTokenGroups: normalizeTokenGroups,
    filterTokens: function(groupsOrFlat, query, limit) {
      const flat = Array.isArray(groupsOrFlat) && groupsOrFlat[0] && groupsOrFlat[0].codeLower !== undefined
        ? groupsOrFlat
        : flattenTokenGroups(normalizeTokenGroups(groupsOrFlat));
      return filterTokens(flat, query, limit);
    }
  });
  CRM.ckeditor5.registerPlugin('CiviTokens', CiviTokens, {
    ckeditorVersion: CKEditor.version
  });
})(CRM, window.CKEDITOR);
