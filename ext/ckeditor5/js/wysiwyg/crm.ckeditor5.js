/*jshint esversion: 6 */
// https://civicrm.org/licensing
(function($, _) {

  var instances = {};
  const registeredPlugins = Object.create(null);
  const loadedPluginStylesheets = Object.create(null);
  const base64MaximumFileSize = 10 * 1024 * 1024;
  const base64ImageTypes = new Set([
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/bmp',
    'image/webp',
    'image/tiff'
  ]);
  let pluginAssetsPromise;

  CRM.ckeditor5 = CRM.ckeditor5 || {};

  /**
   * Register a plugin constructor built against the shared CKEditor runtime.
   *
   * @param {string} name
   * @param {Function} pluginConstructor
   * @param {object} metadata
   */
  CRM.ckeditor5.registerPlugin = function(name, pluginConstructor, metadata) {
    if (typeof name !== 'string' || !name.trim()) {
      throw new TypeError('CKEditor 5 plugin registration requires a non-empty name.');
    }
    if (typeof pluginConstructor !== 'function') {
      throw new TypeError(`CKEditor 5 plugin "${name}" must be a constructor.`);
    }
    if (pluginConstructor.pluginName && pluginConstructor.pluginName !== name) {
      throw new Error(`CKEditor 5 plugin name mismatch: expected "${name}", got "${pluginConstructor.pluginName}".`);
    }
    const runtimeVersion = window.CKEDITOR_VERSION || (window.CKEDITOR && window.CKEDITOR.version);
    if (!metadata || typeof metadata.ckeditorVersion !== 'string') {
      throw new Error(`CKEditor 5 plugin "${name}" must declare metadata.ckeditorVersion.`);
    }
    if (!runtimeVersion) {
      throw new Error(`CKEditor 5 plugin "${name}" cannot be registered before the shared runtime is loaded.`);
    }
    if (metadata.ckeditorVersion !== runtimeVersion) {
      throw new Error(`CKEditor 5 plugin "${name}" targets ${metadata.ckeditorVersion}; the shared runtime is ${runtimeVersion}.`);
    }
    if (registeredPlugins[name] && registeredPlugins[name] !== pluginConstructor) {
      throw new Error(`CKEditor 5 plugin "${name}" is already registered with another constructor.`);
    }
    registeredPlugins[name] = pluginConstructor;
  };

  CRM.ckeditor5.getRegisteredPlugins = function() {
    return Object.assign({}, registeredPlugins);
  };

  /**
   * Decode a local image in the browser before embedding it as Base64.
   *
   * @param {File|Blob} file
   * @return {Promise<void>}
   */
  function decodeBase64Image(file) {
    return new Promise(function(resolve, reject) {
      if (!window.URL || typeof window.URL.createObjectURL !== 'function' || typeof window.Image !== 'function') {
        reject(new Error('This browser cannot validate images before embedding them.'));
        return;
      }

      const objectUrl = window.URL.createObjectURL(file);
      const image = new window.Image();
      let finished = false;
      const finish = function(error) {
        if (finished) return;
        finished = true;
        image.onload = null;
        image.onerror = null;
        window.URL.revokeObjectURL(objectUrl);
        if (error) reject(error);
        else resolve();
      };
      const acceptDecodedImage = function() {
        if (!image.naturalWidth || !image.naturalHeight) {
          finish(new Error('The selected file could not be decoded as an image.'));
          return;
        }
        finish();
      };

      image.onerror = function() {
        finish(new Error('The selected file could not be decoded as an image.'));
      };
      image.onload = function() {
        if (typeof image.decode === 'function') {
          image.decode().then(acceptDecodedImage).catch(function() {
            finish(new Error('The selected file could not be decoded as an image.'));
          });
        }
        else {
          acceptDecodedImage();
        }
      };
      image.src = objectUrl;
    });
  }

  /**
   * Build a Base64 adapter with a hard byte limit and mandatory browser decode.
   *
   * @param {object} loader
   * @return {object}
   */
  function createValidatedBase64UploadAdapter(loader) {
    let reader = null;
    let aborted = false;

    return {
      upload: function() {
        return Promise.resolve(loader.file).then(function(file) {
          if (!file || !base64ImageTypes.has(String(file.type || '').toLowerCase())) {
            throw new Error('Only supported image files can be embedded.');
          }
          if (!Number.isFinite(file.size) || file.size > base64MaximumFileSize) {
            throw new Error('Base64 images must not exceed 10 MiB.');
          }
          return decodeBase64Image(file).then(function() {
            if (aborted) throw new Error('Image embedding was aborted.');
            return new Promise(function(resolve, reject) {
              reader = new window.FileReader();
              reader.addEventListener('load', function() {
                resolve({default: reader.result});
              });
              reader.addEventListener('error', reject);
              reader.addEventListener('abort', function() {
                reject(new Error('Image embedding was aborted.'));
              });
              reader.readAsDataURL(file);
            });
          });
        });
      },
      abort: function() {
        aborted = true;
        if (reader && reader.readyState === window.FileReader.LOADING) reader.abort();
      }
    };
  }

  // Exposed for focused regression tests and third-party upload diagnostics.
  CRM.ckeditor5.createValidatedBase64UploadAdapter = createValidatedBase64UploadAdapter;
  CRM.ckeditor5.base64MaximumFileSize = base64MaximumFileSize;

  function appendRegisteredPlugins(editorConfig) {
    const plugins = Array.isArray(editorConfig.extraPlugins) ? editorConfig.extraPlugins.slice() : [];
    Object.values(registeredPlugins).forEach(function(pluginConstructor) {
      if (!plugins.includes(pluginConstructor)) plugins.push(pluginConstructor);
    });
    editorConfig.extraPlugins = plugins;
  }

  function localAssetUrl(url) {
    if (typeof url !== 'string' || !url.trim()) {
      throw new TypeError('CKEditor 5 plugin asset URLs must be non-empty strings.');
    }
    const resolved = new URL(url, window.location.href);
    if (resolved.origin !== window.location.origin) {
      throw new Error(`CKEditor 5 plugin assets must be local: ${url}`);
    }
    return resolved.href;
  }

  function loadPluginStylesheet(url) {
    const href = localAssetUrl(url);
    if (loadedPluginStylesheets[href]) return loadedPluginStylesheets[href];
    loadedPluginStylesheets[href] = new Promise(function(resolve, reject) {
      const existing = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .find(link => link.href === href);
      if (existing) {
        resolve();
        return;
      }
      const link = document.createElement('link');
      link.rel = 'stylesheet';
      link.href = href;
      link.dataset.ckeditor5PluginAsset = 'true';
      link.onload = resolve;
      link.onerror = () => reject(new Error(`Unable to load CKEditor 5 plugin stylesheet: ${url}`));
      document.head.appendChild(link);
    });
    return loadedPluginStylesheets[href];
  }

  /**
   * Load extension-owned assets after the shared runtime and before editors.
   * Returns null when there is no work so the normal initialization stays synchronous.
   *
   * @return {Promise|null}
   */
  function loadPluginAssets() {
    const declarations = [];
    for (const setting of ['CKEditor5CorePluginAssets', 'CKEditor5PluginAssets']) {
      const configured = CRM.config[setting];
      if (configured === undefined) continue;
      if (!Array.isArray(configured)) {
        throw new TypeError(`${setting} must be an array.`);
      }
      declarations.push(...configured);
    }
    if (!declarations.length) return null;
    if (pluginAssetsPromise) return pluginAssetsPromise;

    const expectedPlugins = [];
    const stylesheets = [];
    const scripts = [];
    declarations.forEach(function(declaration) {
      if (!$.isPlainObject(declaration) || typeof declaration.name !== 'string' || !declaration.name.trim()) {
        throw new TypeError('Each CKEditor5PluginAssets entry requires a non-empty name.');
      }
      for (const key of ['stylesheets', 'scripts', 'plugins']) {
        if (declaration[key] !== undefined && !Array.isArray(declaration[key])) {
          throw new TypeError(`CKEditor5PluginAssets "${declaration.name}" ${key} must be an array.`);
        }
      }
      (declaration.stylesheets || []).forEach(url => stylesheets.push(url));
      (declaration.scripts || []).forEach(url => scripts.push(url));
      (declaration.plugins || []).forEach(function(name) {
        if (typeof name !== 'string' || !name.trim()) {
          throw new TypeError(`CKEditor5PluginAssets "${declaration.name}" plugin names must be non-empty strings.`);
        }
        expectedPlugins.push(name);
      });
    });

    const stylePromise = Promise.all([...new Set(stylesheets)].map(loadPluginStylesheet));
    const scriptPromise = [...new Set(scripts)].reduce(function(chain, url) {
      return chain.then(function() {
        const scriptUrl = localAssetUrl(url);
        return new Promise(function(resolve, reject) {
          CRM.loadScript(scriptUrl).done(resolve).fail(function() {
            reject(new Error(`Unable to load CKEditor 5 plugin script: ${url}`));
          });
        });
      });
    }, Promise.resolve());

    pluginAssetsPromise = Promise.all([stylePromise, scriptPromise]).then(function() {
      expectedPlugins.forEach(function(name) {
        if (!registeredPlugins[name]) {
          throw new Error(`CKEditor 5 plugin asset did not register "${name}".`);
        }
      });
    }).catch(function(error) {
      pluginAssetsPromise = null;
      throw error;
    });
    return pluginAssetsPromise;
  }

  CRM.ckeditor5.loadPluginAssets = loadPluginAssets;

  function presentationHtmlSupport(allowStyleElements = true) {
    const elements = /^(p|div|span|br|hr|h[1-6]|strong|b|em|i|u|s|del|ins|mark|small|big|sup|sub|ul|ol|li|dl|dt|dd|blockquote|pre|code|a|img|figure|figcaption|table|caption|thead|tbody|tfoot|tr|td|th|colgroup|col|center|font|address|section|article|header|footer|main|aside|nav|abbr|cite|q)$/;
    const styles = [
      'color', 'background-color', 'background', 'background-image',
      'background-position', 'background-repeat', 'background-size',
      'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant',
      'line-height', 'letter-spacing', 'word-spacing', 'text-align',
      'text-decoration', 'text-indent', 'text-transform', 'vertical-align',
      'white-space', 'word-break', 'word-wrap', 'overflow-wrap',
      'width', 'height', 'min-width', 'max-width', 'min-height', 'max-height',
      'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
      'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
      'border', 'border-width', 'border-style', 'border-color', 'border-radius',
      'border-top', 'border-right', 'border-bottom', 'border-left',
      'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
      'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style',
      'border-top-color', 'border-right-color', 'border-bottom-color', 'border-left-color',
      'border-collapse', 'border-spacing', 'table-layout', 'box-sizing',
      'display', 'direction', 'float', 'clear', 'opacity', 'outline',
      'list-style', 'list-style-type', 'list-style-position',
      'mso-padding-alt', 'mso-table-lspace', 'mso-table-rspace', 'mso-line-height-rule',
      '-webkit-text-size-adjust', '-ms-text-size-adjust'
    ];
    const allow = [
      {name: elements, classes: true, styles: styles, attributes: ['id', 'lang', 'dir', 'title', ' aria-label', 'role'].map(a => a.trim())},
      {name: /^(table|thead|tbody|tfoot|tr|td|th|colgroup|col)$/, attributes: [
        'width', 'height', 'align', 'valign', 'bgcolor', 'border',
        'cellpadding', 'cellspacing', 'colspan', 'rowspan', 'scope', 'role'
      ]},
      {name: /^(table|td|th)$/, attributes: {background: /^https?:\/\//i}},
      {name: /^(p|div|h[1-6]|img|table|tr|td|th)$/, attributes: ['align']},
      {name: 'a', attributes: ['target', 'rel', 'name']},
      {name: 'img', attributes: ['width', 'height', 'alt', 'border', 'hspace', 'vspace']},
      {name: 'font', attributes: ['color', 'face', 'size']}
    ];
    if (allowStyleElements) {
      allow.push({name: 'style', attributes: ['type', 'media', 'title']});
    }
    return {
      allow: allow,
      disallow: [
        {name: /^(script|iframe|object|embed|form|input|button|textarea|select|link|meta|base)$/},
        {name: /.*/, attributes: [/^on/i, /^ng-/i, /^data-ng-/i, 'srcdoc']}
      ]
    };
  }

  function htmlSupportForRuleset(ruleset) {
    if (ruleset === 'strict') {
      return {
        allow: [],
        disallow: presentationHtmlSupport(false).disallow
      };
    }
    if (ruleset === 'presentation') {
      return presentationHtmlSupport(false);
    }
    return presentationHtmlSupport(true);
  }

  function preserveLegacyEmailLayout(root) {
    root.querySelectorAll('[align]').forEach(function(element) {
      const alignment = String(element.getAttribute('align') || '').toLowerCase();
      if (!['left', 'center', 'right', 'justify'].includes(alignment)) return;
      if (element.tagName === 'TABLE') {
        if (alignment === 'center') {
          element.classList.add('civi-email-align-center');
          if (!element.style.marginLeft) element.style.marginLeft = 'auto';
          if (!element.style.marginRight) element.style.marginRight = 'auto';
        }
        else if (alignment === 'left' || alignment === 'right') {
          element.classList.add(`civi-email-align-${alignment}`);
          if (!element.style.marginLeft) element.style.marginLeft = alignment === 'right' ? 'auto' : '0';
          if (!element.style.marginRight) element.style.marginRight = alignment === 'left' ? 'auto' : '0';
        }
      }
      else if (!element.style.textAlign) {
        element.style.textAlign = alignment;
      }
    });
    root.querySelectorAll('[valign]').forEach(function(element) {
      if (!element.style.verticalAlign) element.style.verticalAlign = element.getAttribute('valign');
    });
    root.querySelectorAll('[bgcolor]').forEach(function(element) {
      if (!element.style.backgroundColor) element.style.backgroundColor = element.getAttribute('bgcolor');
    });
    root.querySelectorAll('td, th').forEach(function(cell) {
      if (cell.children.length === 1 && cell.firstElementChild.tagName === 'TABLE') {
        const innerTable = cell.firstElementChild;
        let alignSource = cell;
        let cellAlign = '';
        while (alignSource && !cellAlign) {
          cellAlign = String(alignSource.getAttribute('align') || (alignSource.style && alignSource.style.textAlign) || '').toLowerCase();
          alignSource = alignSource.parentElement && typeof alignSource.parentElement.closest === 'function'
            ? alignSource.parentElement.closest('td, th')
            : null;
        }
        if (cellAlign === 'center' && !innerTable.classList.contains('civi-email-align-center')) {
          innerTable.classList.add('civi-email-align-center');
          if (!innerTable.style.marginLeft) innerTable.style.marginLeft = 'auto';
          if (!innerTable.style.marginRight) innerTable.style.marginRight = 'auto';
        }
        else if ((cellAlign === 'left' || cellAlign === 'right') && !innerTable.classList.contains(`civi-email-align-${cellAlign}`)) {
          innerTable.classList.add(`civi-email-align-${cellAlign}`);
          if (!innerTable.style.marginLeft) innerTable.style.marginLeft = cellAlign === 'right' ? 'auto' : '0';
          if (!innerTable.style.marginRight) innerTable.style.marginRight = cellAlign === 'left' ? 'auto' : '0';
        }
      }
      if (cell.children.length !== 1) return;
      let child = cell.firstElementChild;
      if (child.tagName === 'FIGURE' && child.classList.contains('image') && !child.querySelector('figcaption')) {
        const image = child.querySelector('img');
        const linkedImage = image && image.parentElement.tagName === 'A' ? image.parentElement : image;
        if (linkedImage) {
          cell.insertBefore(linkedImage, child);
          child.remove();
          child = linkedImage;
          if (!cell.style.textAlign) cell.style.textAlign = 'center';
        }
      }
      const image = child && child.tagName === 'IMG'
        ? child
        : child && child.tagName === 'A' && child.children.length === 1 && child.firstElementChild.tagName === 'IMG'
          ? child.firstElementChild
          : null;
      if (!image) return;
      if (image.style && image.style.display === 'block') {
        image.style.display = 'inline-block';
      }
      const inlineWrapper = root.ownerDocument.createElement('span');
      inlineWrapper.style.display = 'inline-block';
      cell.insertBefore(inlineWrapper, child);
      inlineWrapper.appendChild(child);
    });
  }

  function sanitizeHeadElement(head) {
    head.querySelectorAll('script, iframe, object, embed, form, input, button, textarea, select, base').forEach(function(element) {
      element.remove();
    });
    head.querySelectorAll('*').forEach(function(element) {
      Array.from(element.attributes).forEach(function(attr) {
        const name = attr.name.toLowerCase();
        if (name.startsWith('on') || name.startsWith('ng-') || name.startsWith('data-ng-') || name === 'srcdoc') {
          element.removeAttribute(attr.name);
        }
        else if (element.tagName === 'LINK' && name === 'href' && !/^(?:https?:\/\/|\/)/i.test(attr.value.trim())) {
          element.remove();
        }
      });
    });
  }

  function preserveTemplateStyles(editor) {
    const rawMode = editor.config.get('civiRawHtmlMode') === true;
    const preserveStyles = editor.config.get('civiPreserveTemplateStyles') !== false;
    if (!rawMode && !preserveStyles) return;
    const processor = editor.data.processor;
    const toView = processor.toView.bind(processor);
    const toData = typeof processor.toData === 'function' ? processor.toData.bind(processor) : null;
    let documentEnvelope = null;
    let isSettingData = !(editor.data && typeof editor.data.on === 'function');
    if (editor.data && typeof editor.data.on === 'function') {
      editor.data.on('init', function() { isSettingData = true; }, {priority: 'highest'});
      editor.data.on('init', function() { isSettingData = false; }, {priority: 'lowest'});
      editor.data.on('set', function() { isSettingData = true; }, {priority: 'highest'});
      editor.data.on('set', function() { isSettingData = false; }, {priority: 'lowest'});
      editor.data.on('get', function(evt, args) {
        if (documentEnvelope && args) {
          args[0] = Object.assign({}, args[0], {trim: 'none'});
        }
      }, {priority: 'highest'});
    }
    processor.toView = function(data) {
      if (rawMode && typeof data === 'string' && data.trim() !== '') {
        if (isSettingData) documentEnvelope = null;
        const fragment = toView('<div class="raw-html-embed"></div>');
        const rawContainer = fragment && typeof fragment.getChild === 'function' ? fragment.getChild(0) : null;
        if (rawContainer && typeof rawContainer._setCustomProperty === 'function') {
          rawContainer._setCustomProperty('$rawContent', data);
          return fragment;
        }
      }
      const isHtmlDocument = preserveStyles && typeof data === 'string'
        && /<(?:!doctype|html|head|body)(?:\s|>)/i.test(data);
      const isHeadFragment = !isHtmlDocument && preserveStyles && typeof data === 'string'
        && (/<title(?:\s|>)/i.test(data)
          || /<link\b[^>]*rel=["']?stylesheet/i.test(data)
          || /<!--\[if\b/i.test(data)
          || (/<meta(?:\s|>)/i.test(data) && /<style(?:\s|>)/i.test(data)));
      if (isHtmlDocument) {
        const parsed = new DOMParser().parseFromString(data, 'text/html');
        if (isSettingData) {
          const doctypeMatch = data.match(/^\s*(<!doctype[^>]*>\s*)/i);
          const headClone = parsed.head.cloneNode(true);
          sanitizeHeadElement(headClone);
          const headStyles = Array.from(headClone.querySelectorAll('style'));
          if (headStyles.length) {
            const marker = parsed.createElement('meta');
            marker.setAttribute('data-civi-head-styles', 'true');
            headStyles[0].parentNode.insertBefore(marker, headStyles[0]);
            headStyles.forEach(styleEl => styleEl.remove());
          }
          documentEnvelope = {
            mode: 'document',
            doctype: doctypeMatch ? doctypeMatch[1] : '',
            htmlAttributes: Array.from(parsed.documentElement.attributes).map(a => ({name: a.name, value: a.value})),
            headAttributes: Array.from(parsed.head.attributes).map(a => ({name: a.name, value: a.value})),
            headHtml: headClone.innerHTML,
            bodyAttributes: Array.from(parsed.body.attributes).map(a => ({name: a.name, value: a.value}))
          };
        }
        const styles = Array.from(parsed.head.querySelectorAll('style'));
        preserveLegacyEmailLayout(parsed.body);
        const wrapper = parsed.createElement('div');
        wrapper.classList.add('civi-email-body');
        for (const name of ['class', 'id', 'style', 'lang', 'dir']) {
          if (parsed.body.hasAttribute(name)) {
            if (name === 'class') {
              parsed.body.getAttribute('class').split(/\s+/).filter(Boolean).forEach(cls => wrapper.classList.add(cls));
            }
            else {
              wrapper.setAttribute(name, parsed.body.getAttribute(name));
            }
          }
        }
        if (parsed.body.hasAttribute('bgcolor') && !wrapper.style.backgroundColor) {
          wrapper.style.backgroundColor = parsed.body.getAttribute('bgcolor');
        }
        if (parsed.body.attributes.length) {
          wrapper.append(...Array.from(parsed.body.childNodes));
          parsed.body.replaceChildren(wrapper);
        }
        parsed.body.prepend(...styles);
        data = parsed.body.innerHTML;
      }
      else if (isHeadFragment) {
        const parsed = new DOMParser().parseFromString(data, 'text/html');
        if (isSettingData) {
          const headClone = parsed.head.cloneNode(true);
          sanitizeHeadElement(headClone);
          const headStyles = Array.from(headClone.querySelectorAll('style'));
          if (headStyles.length) {
            const marker = parsed.createElement('meta');
            marker.setAttribute('data-civi-head-styles', 'true');
            headStyles[0].parentNode.insertBefore(marker, headStyles[0]);
            headStyles.forEach(styleEl => styleEl.remove());
          }
          documentEnvelope = {
            mode: 'headFragment',
            headHtml: headClone.innerHTML
          };
        }
        const styles = Array.from(parsed.head.querySelectorAll('style'));
        preserveLegacyEmailLayout(parsed.body);
        parsed.body.prepend(...styles);
        data = parsed.body.innerHTML;
      }
      else if (preserveStyles && typeof data === 'string' && data.trim() !== '') {
        if (isSettingData) documentEnvelope = null;
        const parsed = new DOMParser().parseFromString(data, 'text/html');
        const styles = Array.from(parsed.head.querySelectorAll('style'));
        preserveLegacyEmailLayout(parsed.body);
        parsed.body.prepend(...styles);
        data = parsed.body.innerHTML;
      }
      else if (isSettingData) {
        documentEnvelope = null;
      }
      return toView(data);
    };
    if (toData) {
      processor.toData = function(viewFragment) {
        if (rawMode) {
          const root = editor.model && editor.model.document && typeof editor.model.document.getRoot === 'function'
            ? editor.model.document.getRoot()
            : null;
          if (root && root.childCount === 1) {
            const firstChild = root.getChild(0);
            if (firstChild && firstChild.name === 'rawHtml' && typeof firstChild.getAttribute === 'function') {
              return String(firstChild.getAttribute('value') || '');
            }
          }
        }
        const fragmentHtml = toData(viewFragment);
        if (!preserveStyles) {
          return fragmentHtml;
        }
        if (!documentEnvelope) {
          if (!/<style[\s>]/i.test(fragmentHtml) || !fragmentHtml.includes('&gt;')) {
            return fragmentHtml;
          }
          return fragmentHtml.replace(/(<style\b[^>]*>)([\s\S]*?)(<\/style>)/gi, function(match, open, css, close) {
            return open + css.replace(/&gt;/g, '>') + close;
          });
        }
        const parsedFragment = new DOMParser().parseFromString(`<body>${fragmentHtml}</body>`, 'text/html');
        const allStyles = [...parsedFragment.head.querySelectorAll('style'), ...parsedFragment.body.querySelectorAll('style')];
        allStyles.forEach(function(styleEl) {
          if (styleEl.textContent && styleEl.textContent.includes('&gt;')) {
            styleEl.textContent = styleEl.textContent.replace(/&gt;/g, '>');
          }
          styleEl.remove();
        });
        const outDoc = document.implementation.createHTMLDocument('');
        if (documentEnvelope.mode === 'headFragment') {
          outDoc.head.innerHTML = documentEnvelope.headHtml;
          const styleMarker = outDoc.head.querySelector('meta[data-civi-head-styles]');
          if (styleMarker) {
            allStyles.forEach(styleEl => styleMarker.parentNode.insertBefore(styleEl, styleMarker));
            styleMarker.remove();
          }
          else {
            allStyles.forEach(styleEl => outDoc.head.appendChild(styleEl));
          }
          outDoc.body.append(...Array.from(parsedFragment.body.childNodes));
          return `${outDoc.head.innerHTML}${outDoc.body.innerHTML}`;
        }
        documentEnvelope.htmlAttributes.forEach(attr => outDoc.documentElement.setAttribute(attr.name, attr.value));
        documentEnvelope.headAttributes.forEach(attr => outDoc.head.setAttribute(attr.name, attr.value));
        outDoc.head.innerHTML = documentEnvelope.headHtml;
        const styleMarker = outDoc.head.querySelector('meta[data-civi-head-styles]');
        if (styleMarker) {
          allStyles.forEach(styleEl => styleMarker.parentNode.insertBefore(styleEl, styleMarker));
          styleMarker.remove();
        }
        else {
          allStyles.forEach(styleEl => outDoc.head.appendChild(styleEl));
        }
        documentEnvelope.bodyAttributes.forEach(attr => outDoc.body.setAttribute(attr.name, attr.value));
        const bodyWrapper = parsedFragment.body.children.length === 1
          && parsedFragment.body.firstElementChild.classList.contains('civi-email-body')
          ? parsedFragment.body.firstElementChild
          : null;
        if (bodyWrapper) {
          bodyWrapper.classList.remove('civi-email-body');
          for (const name of ['class', 'id', 'style', 'lang', 'dir']) {
            if (bodyWrapper.hasAttribute(name)) {
              const val = bodyWrapper.getAttribute(name);
              if (name !== 'class' || val.trim() !== '') {
                outDoc.body.setAttribute(name, val);
              }
            }
          }
          outDoc.body.append(...Array.from(bodyWrapper.childNodes));
        }
        else {
          outDoc.body.append(...Array.from(parsedFragment.body.childNodes));
        }
        return `${documentEnvelope.doctype}${outDoc.documentElement.outerHTML}`;
      };
    }
    if (preserveStyles && editor.conversion && typeof editor.conversion.for === 'function') {
      editor.conversion.for('dataDowncast').add(function(dispatcher) {
        dispatcher.on('insert:table', function(event, data, conversionApi) {
          const viewTable = findViewElement(conversionApi.mapper.toViewElement(data.item), 'table');
          if (!viewTable) return;
          const writer = conversionApi.writer;
          if (viewTable.hasClass('civi-email-align-center')) {
            writer.setStyle('margin-left', 'auto', viewTable);
            writer.setStyle('margin-right', 'auto', viewTable);
          }
          else if (viewTable.hasClass('civi-email-align-left')) {
            writer.setStyle('margin-left', '0', viewTable);
            writer.setStyle('margin-right', 'auto', viewTable);
          }
          else if (viewTable.hasClass('civi-email-align-right')) {
            writer.setStyle('margin-left', 'auto', viewTable);
            writer.setStyle('margin-right', '0', viewTable);
          }
        }, {priority: 'lowest'});
      });
    }
  }

  function findViewElement(viewElement, name) {
    if (!viewElement) return null;
    if (viewElement.is('element', name)) return viewElement;
    if (typeof viewElement.getChildren === 'function') {
      for (const child of viewElement.getChildren()) {
        const found = findViewElement(child, name);
        if (found) return found;
      }
    }
    return null;
  }

  // Export CiviMail images with dimensions understood by email clients.
  function emailImageOutput(editor) {
    const configuredMaxWidth = Number(editor.config.get('civiEmailImageMaxWidth'));
    if (!Number.isFinite(configuredMaxWidth) || configuredMaxWidth <= 0) return;
    const maxWidth = Math.round(configuredMaxWidth);

    editor.conversion.for('dataDowncast').add(function(dispatcher) {
      const convertImage = function(event, data, conversionApi) {
        const image = data.item;
        const viewImage = findViewElement(conversionApi.mapper.toViewElement(image), 'img');
        if (!viewImage) return;

        const writer = conversionApi.writer;
        writer.setAttribute('border', '0', viewImage);
        writer.setStyle('max-width', '100%', viewImage);
        writer.setStyle('height', 'auto', viewImage);

        const naturalWidth = Number(image.getAttribute('width'));
        const naturalHeight = Number(image.getAttribute('height'));
        const resizedWidth = String(image.getAttribute('resizedWidth') || '');
        const pixelResize = resizedWidth.match(/^([0-9]+(?:\.[0-9]+)?)px$/i);
        let displayWidth = pixelResize ? Number(pixelResize[1]) : naturalWidth;
        if (!Number.isFinite(displayWidth) || displayWidth <= 0) return;
        displayWidth = Math.max(1, Math.min(Math.round(displayWidth), maxWidth));

        writer.setAttribute('width', String(displayWidth), viewImage);
        if (Number.isFinite(naturalWidth) && naturalWidth > 0
          && Number.isFinite(naturalHeight) && naturalHeight > 0) {
          writer.setAttribute('height', String(Math.max(1, Math.round(naturalHeight * displayWidth / naturalWidth))), viewImage);
        }
        writer.setStyle('width', `${displayWidth}px`, viewImage);
      };

      dispatcher.on('insert:imageInline', convertImage, {priority: 'lowest'});
      dispatcher.on('insert:imageBlock', convertImage, {priority: 'lowest'});
    });
  }

  // Preserve the established palette while emitting email-safe hex values.
  const emailColorPalette = [
    { color: '#000000', label: 'Black' },
    { color: '#4D4D4D', label: 'Dim grey' },
    { color: '#999999', label: 'Grey' },
    { color: '#E6E6E6', label: 'Light grey' },
    { color: '#FFFFFF', label: 'White', hasBorder: true },
    { color: '#E64D4D', label: 'Red' },
    { color: '#E6994D', label: 'Orange' },
    { color: '#E6E64D', label: 'Yellow' },
    { color: '#99E64D', label: 'Light green' },
    { color: '#4DE64D', label: 'Green' },
    { color: '#4DE699', label: 'Aquamarine' },
    { color: '#4DE6E6', label: 'Turquoise' },
    { color: '#4D99E6', label: 'Light blue' },
    { color: '#4D4DE6', label: 'Blue' },
    { color: '#994DE6', label: 'Purple' }
  ];

  // Clone configuration containers, preserving plugin constructors/callbacks.
  function cloneConfig(value) {
    if (Array.isArray(value)) {
      return value.map(cloneConfig);
    }
    if ($.isPlainObject(value)) {
      return mergeConfig({}, value);
    }
    return value;
  }

  // Merge nested settings, but replace lists (especially toolbar items).
  function mergeConfig(target, source) {
    Object.keys(source).forEach(function(key) {
      if (['__proto__', 'constructor', 'prototype'].includes(key)) {
        return;
      }
      const value = source[key];
      if ($.isPlainObject(value)) {
        target[key] = mergeConfig($.isPlainObject(target[key]) ? target[key] : {}, value);
      }
      else {
        target[key] = cloneConfig(value);
      }
    });
    return target;
  }

  function compactQaValue(value) {
    const normalized = String(value || '').replace(/\s+/g, ' ').trim();
    if (/^data:/i.test(normalized)) return `[data URL, ${normalized.length} characters]`;
    return normalized.length > 500 ? normalized.slice(0, 497) + '...' : normalized;
  }

  function htmlQaInventory(html) {
    const parsed = new DOMParser().parseFromString(String(html || ''), 'text/html');
    const fullDocument = /<(?:html|head|body)(?:\s|>)/i.test(String(html || ''));
    const buckets = {
      elements: new Map(),
      attributes: new Map(),
      classes: new Map(),
      styles: new Map(),
      stylesheets: new Map()
    };
    const add = function(bucket, key) {
      bucket.set(key, (bucket.get(key) || 0) + 1);
    };
    const styleFamily = function(property) {
      if (/^border-(?:(?:top|right|bottom|left)-(?:width|style|color)|width|style|color|image(?:-|$))/.test(property)) return 'border';
      for (const family of ['background', 'font', 'margin', 'padding', 'text-decoration']) {
        if (property === family || property.startsWith(family + '-')) return family;
      }
      return property;
    };
    let elements;
    if (fullDocument) {
      elements = [parsed.documentElement, ...parsed.documentElement.querySelectorAll('*')];
    }
    else {
      // DOMParser may move a fragment-level style element into the synthetic head.
      elements = [...parsed.head.querySelectorAll('style'), ...parsed.body.querySelectorAll('*')];
    }
    elements.forEach(function(element) {
      const tag = element.tagName.toLowerCase();
      add(buckets.elements, `<${tag}>`);
      if (tag === 'style') {
        add(buckets.stylesheets, compactQaValue(element.textContent));
      }
      Array.from(element.classList || []).forEach(function(className) {
        add(buckets.classes, `<${tag}> .${className}`);
      });
      if (element.hasAttribute('style')) {
        const properties = new Set();
        for (let index = 0; index < element.style.length; index++) {
          properties.add(styleFamily(element.style[index]));
        }
        properties.forEach(property => add(buckets.styles, `<${tag}> ${property}`));
      }
      Array.from(element.attributes).forEach(function(attribute) {
        if (attribute.name === 'class' || attribute.name === 'style') return;
        add(buckets.attributes, `<${tag}> ${attribute.name}="${compactQaValue(attribute.value)}"`);
      });
    });
    return buckets;
  }

  function compareHtmlForQa(sourceHtml, outputHtml) {
    const source = htmlQaInventory(sourceHtml);
    const output = htmlQaInventory(outputHtml);
    const compare = function(left, right) {
      const changes = [];
      Object.keys(left).forEach(function(category) {
        left[category].forEach(function(count, key) {
          const difference = count - (right[category].get(key) || 0);
          if (difference > 0) changes.push({category: category, key: key, count: difference});
        });
      });
      return changes;
    };
    return {
      removed: compare(source, output),
      generated: compare(output, source)
    };
  }

  function htmlQaReportText(report) {
    const lines = ['CKEditor 5 HTML QA'];
    [['Removed from input', report.removed], ['Generated or transformed output', report.generated]].forEach(function(section) {
      lines.push('', section[0]);
      if (!section[1].length) lines.push('- None');
      section[1].forEach(function(change) {
        lines.push(`- ${change.category}: ${change.key}${change.count > 1 ? ` (${change.count})` : ''}`);
      });
    });
    return lines.join('\n');
  }

  function attachHtmlQaReport(editor, sourceHtml) {
    const setting = editor.config.get('civiHtmlQa');
    if (!setting || setting.enabled !== true) return null;
    if (editor._civiHtmlQaPanel && typeof editor._civiHtmlQaPanel.remove === 'function') {
      editor._civiHtmlQaPanel.remove();
    }
    const report = compareHtmlForQa(sourceHtml, editor.getData());
    const count = changes => changes.reduce((total, change) => total + change.count, 0);
    const removedCount = count(report.removed);
    const generatedCount = count(report.generated);
    const ts = CRM.ts('ckeditor5');
    const panel = document.createElement('details');
    panel.className = 'ck5-html-qa';
    panel.open = removedCount > 0 || generatedCount > 0;
    const summary = document.createElement('summary');
    summary.textContent = removedCount || generatedCount
      ? ts('HTML QA: %1 removed, %2 generated or transformed', {1: removedCount, 2: generatedCount})
      : ts('HTML QA: no transformation detected');
    panel.appendChild(summary);
    const description = document.createElement('p');
    description.textContent = ts('This diagnostic compares the initial HTML with CKEditor’s immediate output. It does not inspect server-side processing or later manual edits.');
    panel.appendChild(description);
    const columns = document.createElement('div');
    columns.className = 'ck5-html-qa__columns';
    const labels = {
      elements: ts('Element'),
      attributes: ts('Attribute'),
      classes: ts('CSS class'),
      styles: ts('Inline CSS property'),
      stylesheets: ts('Stylesheet block')
    };
    [[ts('Removed from input'), report.removed], [ts('Generated or transformed output'), report.generated]].forEach(function(section) {
      const column = document.createElement('section');
      const heading = document.createElement('h4');
      heading.textContent = section[0];
      column.appendChild(heading);
      if (!section[1].length) {
        const empty = document.createElement('p');
        empty.textContent = ts('None');
        column.appendChild(empty);
      }
      else {
        const list = document.createElement('ul');
        section[1].forEach(function(change) {
          const item = document.createElement('li');
          item.textContent = `${labels[change.category]}: ${change.key}${change.count > 1 ? ` (${change.count})` : ''}`;
          list.appendChild(item);
        });
        column.appendChild(list);
      }
      columns.appendChild(column);
    });
    panel.appendChild(columns);
    const copy = document.createElement('button');
    copy.type = 'button';
    copy.className = 'ck5-html-qa__copy';
    copy.textContent = ts('Copy QA report');
    copy.addEventListener('click', function() {
      if (!navigator.clipboard || !navigator.clipboard.writeText) return;
      navigator.clipboard.writeText(htmlQaReportText(report)).then(function() {
        copy.textContent = ts('QA report copied');
      });
    });
    panel.appendChild(copy);
    editor._civiHtmlQaPanel = panel;
    editor.ui.view.element.insertAdjacentElement('afterend', panel);
    if (!editor._civiHtmlQaDestroyHooked) {
      editor._civiHtmlQaDestroyHooked = true;
      editor.on('destroy', function() {
        if (editor._civiHtmlQaPanel) editor._civiHtmlQaPanel.remove();
      });
    }
    return report;
  }

  CRM.ckeditor5.compareHtmlForQa = compareHtmlForQa;
  CRM.ckeditor5.attachHtmlQaReport = attachHtmlQaReport;

  // Keep CKEditor's sticky toolbar and contextual UI below the CMS menus.
  // The menus can be stacked differently depending on the CiviCRM preference.
  function getUiViewportTopOffset() {
    if (typeof document === 'undefined' || typeof window === 'undefined' || !window.getComputedStyle) return 0;
    let bottom = 0;
    ['#toolbar-bar', '#civicrm-menu'].forEach(function(selector) {
      const element = document.querySelector(selector);
      if (!element) return;
      const style = window.getComputedStyle(element);
      if (!['fixed', 'sticky'].includes(style.position) || style.display === 'none' || style.visibility === 'hidden') return;
      const rect = element.getBoundingClientRect();
      if (rect.width > 0 && rect.height > 0 && rect.bottom > 0) bottom = Math.max(bottom, rect.bottom);
    });
    return Math.max(0, Math.ceil(bottom));
  }

  CRM.ckeditor5.getUiViewportTopOffset = getUiViewportTopOffset;

  function getInstance(item) {
    var name = $(item).attr("name"),
      id = $(item).attr("id");
    if (name && instances[name]) {
      return instances[name];
    }
    if (id && instances[id]) {
      return instances[id];
    }
  }

  /**
   * Build the shared editor configuration.
   *
   * @param {boolean} useElFinder
   * @return {object}
   */
  function getEditorConfig(item, useElFinder, adminOverride) {
    const preset = $(item).attr('data-preset') || 'default';
    const toolbarItems = [
      'heading',
      '|',
      'findAndReplace',
      '|',
      'bold',
      'underline',
      'italic',
      'strikethrough',
      'superscript',
      'subscript',
      'highlight',
      '|',
      'removeFormat',
      'specialCharacters',
      'fontFamily',
      'fontColor',
      'fontBackgroundColor',
      'fontSize',
      '|',
      'bulletedList',
      'numberedList',
      '|',
      'indent',
      'outdent',
      'alignment',
      '|',
      'link',
      'imageUpload',
      'blockQuote',
      'insertTable',
      'horizontalLine',
      'mediaEmbed',
      'undo',
      'redo',
      'pageBreak',
      '|',
      'htmlEmbed',
      'sourceEditing',
      'code',
      '|',
      'fullscreen'
    ];

    if (useElFinder) {
      toolbarItems.splice(toolbarItems.indexOf('imageUpload') + 1, 0, 'ckfinder');
    }

    const emailSafePreset = preset === 'default' || preset === 'civimail';

    let editorConfig = {
      htmlSupport: presentationHtmlSupport(),
      extraPlugins: emailSafePreset
        ? [preserveTemplateStyles, emailImageOutput]
        : [preserveTemplateStyles],
      civiPreserveTemplateStyles: true,
      civiEmailImageMaxWidth: emailSafePreset ? 600 : false,
      fontFamily: {supportAllValues: true},
      fontSize: {options: ['default', 10, 12, 14, 16, 18, 20, 24, 32], supportAllValues: true},
      ui: {
        viewportOffset: {top: getUiViewportTopOffset()}
      },
      toolbar: {
        // Wrap the toolbar instead of grouping overflowing items in a dropdown.
        shouldNotGroupWhenFull: true,
        items: toolbarItems
      },
      findAndReplace: {
        uiType: 'dialog'
      },
      fullscreen: {
        menuBar: {isVisible: false},
        toolbar: {shouldNotGroupWhenFull: true},
        onEnterCallback: function(container) {
          const editableWrapper = (container && typeof container.querySelector === 'function' && container.querySelector('.ck-fullscreen__editable'))
            || container;
          const sourceEl = item && $(item)[0];
          const crmContainer = (sourceEl && typeof sourceEl.closest === 'function' && sourceEl.closest('.crm-container'))
            || (typeof document !== 'undefined' && document.querySelector && document.querySelector('.crm-container'))
            || null;
          if (editableWrapper && editableWrapper.classList) {
            if (crmContainer && crmContainer.classList) {
              Array.from(crmContainer.classList).forEach(function(cls) {
                editableWrapper.classList.add(cls);
              });
            }
            editableWrapper.classList.add('crm-container');
          }
          if (crmContainer && crmContainer.id === 'crm-container' && editableWrapper && !editableWrapper.id) {
            crmContainer.removeAttribute('id');
            editableWrapper.id = 'crm-container';
            editableWrapper._civiRestoredCrmContainer = crmContainer;
          }
        },
        onLeaveCallback: function(container) {
          const editableWrapper = (container && typeof container.querySelector === 'function' && container.querySelector('.ck-fullscreen__editable'))
            || container;
          if (editableWrapper && editableWrapper._civiRestoredCrmContainer) {
            const crmContainer = editableWrapper._civiRestoredCrmContainer;
            delete editableWrapper._civiRestoredCrmContainer;
            if (editableWrapper.id === 'crm-container') {
              editableWrapper.removeAttribute('id');
            }
            if (!crmContainer.id) {
              crmContainer.id = 'crm-container';
            }
          }
        }
      },
      language: (typeof CRM.config.locale == 'string' ? CRM.config.locale.substr(0,2) : 'en'),
      fontColor: {
        colors: emailColorPalette,
        columns: 5,
        documentColors: 5,
        colorPicker: { format: 'hex' }
      },
      fontBackgroundColor: {
        colors: emailColorPalette,
        columns: 5,
        documentColors: 5,
        colorPicker: { format: 'hex' }
      },
      image: {
        ...(emailSafePreset ? {
          insert: {type: 'inline'},
          resizeUnit: 'px',
          toolbar: ['imageResize', '|', 'imageTextAlternative'],
          styles: ['inline']
        } : {
          toolbar: [
            'imageResize',
            '|',
            'imageTextAlternative',
            'imageStyle:block',
            'imageStyle:side',
            'imageStyle:alignLeft',
            'imageStyle:alignCenter',
            'imageStyle:alignRight'
          ],
          styles: [
            'block',
            'side',
            'alignLeft',
            'alignCenter',
            'alignRight'
          ]
        })
      },
      table: {
        tableLayout: {
          preferredExternalTableType: 'content',
          stripFigureFromContentTable: false
        },
        tableProperties: {
          colorGridColumns: 5,
          borderColors: emailColorPalette,
          backgroundColors: emailColorPalette,
          colorPicker: { format: 'hex' },
          alignment: {useInlineStyles: emailSafePreset}
        },
        tableCellProperties: {
          colorGridColumns: 5,
          borderColors: emailColorPalette,
          backgroundColors: emailColorPalette,
          colorPicker: { format: 'hex' }
        },
        contentToolbar: [
          'tableColumn',
          'tableRow',
          'mergeTableCells',
          'tableCellProperties',
          'tableProperties'
        ]
      },
      link: {
        decorators: {
          toggleDownloadable: {
            mode: 'manual',
            label: 'Downloadable file',
            attributes: {
              download: ''
            }
          },
          openInNewTab: {
            mode: 'manual',
            label: 'Open in a new tab',
            defaultValue: true,
            attributes: {
              target: '_blank',
              rel: 'noopener noreferrer'
            }
          }
        }
      },
      licenseKey: 'GPL'
    };

    // Preserve the custom full configuration used by ckeditor5-extras while
    // allowing it for either upload adapter. Top-level values are replaced so
    // arrays such as toolbar.items are not combined index by index.
    if (CRM.config.CKEditor5CustomConfig) {
      editorConfig = $.extend({}, editorConfig, CRM.config.CKEditor5CustomConfig);
    }

    // Give each editor its own configuration, including its palette arrays.
    editorConfig = cloneConfig(editorConfig);
    const adminPresets = cloneConfig(CRM.config.CKEditor5AdminPresets || {});
    if (adminOverride !== undefined) adminPresets[preset] = cloneConfig(adminOverride);
    const presets = CRM.config.CKEditor5Presets || {};
    const hasConfig = (registry, name) => Object.prototype.hasOwnProperty.call(registry, name)
      && $.isPlainObject(registry[name]);
    const hasPreset = hasConfig(presets, preset) || hasConfig(adminPresets, preset);
    const names = preset === 'default' ? ['default'] : ['default', preset];
    names.forEach(function(name) {
      [adminPresets, presets].forEach(function(registry) {
        if (hasConfig(registry, name)) {
          const entry = registry[name];
          if (typeof entry.civiHtmlRuleset === 'string') {
            editorConfig.htmlSupport = htmlSupportForRuleset(entry.civiHtmlRuleset);
            editorConfig.civiPreserveTemplateStyles = entry.civiHtmlRuleset === 'template';
          }
          mergeConfig(editorConfig, entry);
        }
      });
    });
    const siteUploadMode = CRM.config.CKEditor5UploadMode === 'none' && !useElFinder
      ? 'none'
      : (useElFinder ? 'elfinder' : 'base64');
    const resolveUploadMode = function(requestedMode) {
      if (siteUploadMode === 'none' || requestedMode === 'none') return 'none';
      if (requestedMode === 'base64') return 'base64';
      if (requestedMode === 'elfinder' && useElFinder) return 'elfinder';
      return siteUploadMode;
    };
    const rawAttr = $(item).attr('data-ckeditor5-raw') || $(item).attr('data-wysiwyg-raw');
    if (rawAttr === '1' || rawAttr === 'true' || $(item).attr('data-wysiwyg-mode') === 'raw' || editorConfig.civiRawHtmlMode === true) {
      editorConfig.civiRawHtmlMode = true;
      editorConfig.htmlEmbed = mergeConfig({showPreviews: true}, editorConfig.htmlEmbed || {});
    }
    editorConfig.civiTokens = mergeConfig(editorConfig.civiTokens || {}, {preset: preset});
    appendRegisteredPlugins(editorConfig);
    const rulesetBeforeEvent = editorConfig.civiHtmlRuleset;
    // Extensions may alter the configuration for this specific field before
    // CKEditor is instantiated. The event bubbles from the source element.
    $(item).trigger('crmCkeditor5Config', [editorConfig, {
      uploadAdapter: resolveUploadMode(editorConfig.civiUploadMode),
      preset: preset,
      resolvedPreset: hasPreset ? preset : 'default'
    }]);
    if (typeof editorConfig.civiHtmlRuleset === 'string' && editorConfig.civiHtmlRuleset !== rulesetBeforeEvent) {
      editorConfig.htmlSupport = htmlSupportForRuleset(editorConfig.civiHtmlRuleset);
      editorConfig.civiPreserveTemplateStyles = editorConfig.civiHtmlRuleset === 'template';
    }
    if (editorConfig.civiRawHtmlMode === true) {
      editorConfig.htmlEmbed = mergeConfig({showPreviews: true}, editorConfig.htmlEmbed || {});
    }

    // Named size presets cannot be combined with CKEditor's supportAllValues.
    // Keep existing site configurations using tiny/small/big/huge operational.
    if (editorConfig.fontSize && Array.isArray(editorConfig.fontSize.options)
      && editorConfig.fontSize.options.some(option => typeof option === 'string' && option !== 'default')) {
      editorConfig.fontSize.supportAllValues = false;
    }

    // Select one upload mode after all overrides. Preserve unrelated exclusions.
    const effectiveUploadMode = resolveUploadMode(editorConfig.civiUploadMode);
    const modePlugins = effectiveUploadMode === 'none'
      ? ['Base64UploadAdapter', 'CKFinder', 'CKFinderUploadAdapter', 'ImageUpload']
      : ['Base64UploadAdapter', 'CKFinder', 'CKFinderUploadAdapter'];
    const removed = (editorConfig.removePlugins || []).filter(plugin =>
      !modePlugins.includes(typeof plugin === 'string' ? plugin : plugin.pluginName));
    if (effectiveUploadMode === 'elfinder') {
      editorConfig.removePlugins = removed.concat(['Base64UploadAdapter']);
    }
    else if (effectiveUploadMode === 'base64') {
      editorConfig.removePlugins = removed.concat(['CKFinder', 'CKFinderUploadAdapter']);
    }
    else {
      editorConfig.removePlugins = removed.concat(['Base64UploadAdapter', 'CKFinder', 'CKFinderUploadAdapter', 'ImageUpload']);
    }
    if (effectiveUploadMode !== 'elfinder') {
      const disallowedButtons = effectiveUploadMode === 'none' ? ['ckfinder', 'imageUpload'] : ['ckfinder'];
      if (Array.isArray(editorConfig.toolbar)) {
        editorConfig.toolbar = editorConfig.toolbar.filter(item => !disallowedButtons.includes(item));
      }
      else if (editorConfig.toolbar && Array.isArray(editorConfig.toolbar.items)) {
        editorConfig.toolbar.items = editorConfig.toolbar.items.filter(item => !disallowedButtons.includes(item));
      }
    }
    return editorConfig;
  }

  function findActiveRawTextarea(editor) {
    const rootEl = editor && editor.ui && editor.ui.view && editor.ui.view.element;
    if (!rootEl || typeof rootEl.querySelector !== 'function') return null;
    const sourceTextarea = rootEl.querySelector('.ck-source-editing-area textarea:not([disabled])');
    if (sourceTextarea) {
      return {textarea: sourceTextarea, mode: 'sourceEditing'};
    }
    const embedTextarea = rootEl.querySelector('textarea.raw-html-embed__source:not([disabled])');
    if (embedTextarea) {
      return {textarea: embedTextarea, mode: 'htmlEmbed'};
    }
    return null;
  }

  function findRawHtmlModelElement(editor) {
    if (!editor || !editor.model || !editor.model.document) return null;
    const selection = editor.model.document.selection;
    const selected = selection && typeof selection.getSelectedElement === 'function'
      ? selection.getSelectedElement()
      : null;
    if (selected && selected.name === 'rawHtml') {
      return selected;
    }
    const root = typeof editor.model.document.getRoot === 'function' ? editor.model.document.getRoot() : null;
    if (root && root.childCount === 1) {
      const firstChild = root.getChild(0);
      if (firstChild && firstChild.name === 'rawHtml') {
        return firstChild;
      }
    }
    return null;
  }

  function syncActiveRawTextarea(editor) {
    const active = findActiveRawTextarea(editor);
    if (!active) return false;
    if (active.mode === 'htmlEmbed') {
      const value = active.textarea.value;
      const target = findRawHtmlModelElement(editor);
      if (target && editor.model && typeof editor.model.change === 'function') {
        if (target.getAttribute('value') !== value) {
          editor.model.change(writer => writer.setAttribute('value', value, target));
        }
        return true;
      }
      if (typeof editor.execute === 'function') {
        editor.execute('htmlEmbed', value);
        return true;
      }
    }
    return false;
  }

  function insertIntoRawTextarea(editor, rawTarget, text) {
    const textarea = rawTarget.textarea;
    const current = String(textarea.value || '');
    const start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : current.length;
    const end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : start;
    const nextValue = current.slice(0, start) + text + current.slice(end);
    const nextPos = start + text.length;
    textarea.value = nextValue;
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
    if (rawTarget.mode === 'htmlEmbed') {
      syncActiveRawTextarea(editor);
    }
    if (typeof textarea.focus === 'function') {
      textarea.focus();
    }
    if (typeof editor.updateSourceElement === 'function') {
      editor.updateSourceElement();
    }
    if (editor.sourceElement) {
      $(editor.sourceElement).trigger('change');
    }
  }

  CRM.wysiwyg.getConfig = getEditorConfig;
  CRM.wysiwyg.supportsFileUploads = true;

  CRM.wysiwyg._create = function(item) {
    var deferred = $.Deferred();

    /**
     * Add script for elfinder - this might be cleaner as it's own file but for now....
     * @param editor
     */
    function onReadyElFinder(editor) {
      // elfinder folder hash of the destination folder to be uploaded in this CKeditor 5
      const uploadTargetHash = 'l1_Lw';
      // elFinder connector URL
      const connectorUrl = CRM.url('civicrm/ckeditor5/image-manager', null, 'back');
      const ckf = editor.commands.get('ckfinder'),
        fileRepo = editor.plugins.get('FileRepository'),
        ntf = editor.plugins.get('Notification'),
        i18 = editor.locale.t,
        // Insert images to editor window
        insertImages = urls => {
          const imgCmd = editor.commands.get('imageUpload');
          if (!imgCmd.isEnabled) {
            ntf.showWarning(i18('Could not insert image at the current position.'), {
              title: i18('Inserting image failed'),
              namespace: 'ckfinder'
            });
            return;
          }
          editor.execute('imageInsert', { source: urls });
        },
        // To get elFinder instance
        getfm = open => {
          return new Promise((resolve, reject) => {
            // Execute when the elFinder instance is created
            const done = () => {
              if (open) {
                // request to open folder specify
                if (!Object.keys(_fm.files()).length) {
                  // when initial request
                  _fm.one('open', () => {
                    _fm.file(open)? resolve(_fm) : reject('errFolderNotFound');
                  });
                } else {
                  // elFinder has already been initialized
                  new Promise((res, rej) => {
                    if (_fm.file(open)) {
                      res();
                    } else {
                      // To acquire target folder information
                      _fm.request({cmd: 'parents', target: open}).done(e =>{
                        _fm.file(open)? res() : rej();
                      }).fail(() => {
                        rej();
                      });
                    }
                  }).then(() => {
                    // Open folder after folder information is acquired
                    _fm.exec('open', open).done(() => {
                      resolve(_fm);
                    }).fail(err => {
                      reject(err ? err : 'errFolderNotFound');
                    });
                  }).catch((err) => {
                    reject(err ? err : 'errFolderNotFound');
                  });
                }
              } else {
                // show elFinder manager only
                resolve(_fm);
              }
            };

            // Check elFinder instance
            if (_fm) {
              // elFinder instance has already been created
              done();
            } else {
              // To create elFinder instance
              _fm = $('<div/>').dialogelfinder({
                // dialog title
                title : 'File Manager',
                // connector URL
                url : connectorUrl,
                cssAutoLoad : false,
                // start folder setting
                startPathHash : open? open : void(0),
                // Set to do not use browser history to un-use location.hash
                useBrowserHistory : false,
                // Disable auto open
                autoOpen : false,
                // elFinder dialog width
                width : '80%',
                // set getfile command options
                commandsOptions : {
                  getfile: {
                    oncomplete : 'close',
                    multiple : true
                  }
                },
                // Insert in CKEditor when choosing files
                getFileCallback : (files, fm) => {
                  let imgs = [];
                  fm.getUI('cwd').trigger('unselectall');
                  $.each(files, function(i, f) {
                    if (f && f.mime.match(/^image\//i)) {
                      imgs.push(fm.convAbsUrl(f.url));
                    } else {
                      editor.execute('link', fm.convAbsUrl(f.url));
                    }
                  });
                  if (imgs.length) {
                    insertImages(imgs);
                  }
                }
              }).elfinder('instance');
              done();
            }
          });
        };

      // elFinder instance
      let _fm;

      if (ckf) {
        // Take over ckfinder execute()
        ckf.execute = () => {
          getfm().then(fm => {
            fm.getUI().dialogelfinder('open');
          });
        };
      }

      // Make uploader
      const uploder = function(loader) {
        let upload = function(file, resolve, reject) {
          getfm(uploadTargetHash).then(fm => {
            let fmNode = fm.getUI();
            fmNode.dialogelfinder('open');
            fm.exec('upload', {files: [file], target: uploadTargetHash}, void(0), uploadTargetHash)
              .done(data => {
                if (data.added && data.added.length) {
                  fm.url(data.added[0].hash, { async: true }).done(function(url) {
                    resolve({
                      'default': fm.convAbsUrl(url)
                    });
                    fmNode.dialogelfinder('close');
                  }).fail(function() {
                    reject('errFileNotFound');
                  });
                } else {
                  reject(fm.i18n(data.error? data.error : 'errUpload'));
                  fmNode.dialogelfinder('close');
                }
              })
              .fail(err => {
                const error = fm.parseError(err);
                reject(fm.i18n(error? (error === 'userabort'? 'errAbort' : error) : 'errUploadNoFiles'));
              });
          }).catch(err => {
            const error = _fm && typeof _fm.parseError === 'function' ? _fm.parseError(err) : null;
            const message = _fm && typeof _fm.i18n === 'function'
              ? _fm.i18n(error ? (error === 'userabort' ? 'errAbort' : error) : 'errUploadNoFiles')
              : (err && err.message ? err.message : String(err || 'errUploadNoFiles'));
            reject(message);
          });
        };

        this.upload = function() {
          return new Promise(function(resolve, reject) {
            if (loader.file instanceof Promise || (loader.file && typeof loader.file.then === 'function')) {
              loader.file.then(function(file) {
                upload(file, resolve, reject);
              });
            } else {
              upload(loader.file, resolve, reject);
            }
          });
        };
        this.abort = function() {
          _fm && _fm.getUI().trigger('uploadabort');
        };
      };

      // Set up image uploader
      fileRepo.createUploadAdapter = loader => {
        return new uploder(loader);
      };
      onReady(editor);
    }

    function onReady(editor) {
      editor.ui.view.element.setAttribute('data-ckeditor5-preset', $(item).attr('data-preset') || 'default');
      if (editor.config.get('civiRawHtmlMode') === true) {
        editor.ui.view.element.setAttribute('data-ckeditor5-raw', 'true');
      }
      var debounce,
        name = $(editor.sourceElement).attr('name') || $(editor.sourceElement).attr('id');

      instances[name] = editor;

      if (typeof editor.ui.view.element.addEventListener === 'function') {
        editor.ui.view.element.addEventListener('input', function(event) {
          const target = event && event.target;
          if (!target || !target.matches) return;
          if (target.matches('textarea.raw-html-embed__source:not([disabled]), .ck-source-editing-area textarea:not([disabled])')) {
            syncActiveRawTextarea(editor);
            if (debounce) clearTimeout(debounce);
            debounce = setTimeout(function() {
              editor.updateSourceElement();
              $(item).trigger('change');
            }, 50);
          }
        });
      }

      // Keep CiviCRM's source element current while editing. In particular,
      // image uploads finish asynchronously and may otherwise be omitted when
      // an action reads the textarea before CKEditor loses focus.
      editor.model.document.on('change:data', function() {
        if (debounce) clearTimeout(debounce);
        debounce = setTimeout(function() {
          editor.updateSourceElement();
          $(item).trigger("change");
        }, 50);
      });

      // Update source element on blur; especially important for javascript-based UIs
      editor.editing.view.document.on('blur', function() {
        if (debounce) clearTimeout(debounce);
        syncActiveRawTextarea(editor);
        editor.updateSourceElement();
        $(item).trigger("blur");
        $(item).trigger("change");
      });

      editor.on('destroy', function(e) {
        var name = $(e.source.sourceElement).attr('name') || $(e.source.sourceElement).attr('id');
        delete instances[name];
      });
      $(editor.sourceElement).trigger('crmWysiwygCreate', ['ckeditor', editor]);
      deferred.resolve();
    }

    function onReadyBase64(editor) {
      const fileRepository = editor.plugins.get('FileRepository');
      fileRepository.createUploadAdapter = createValidatedBase64UploadAdapter;
      onReady(editor);
    }


    /**
     * Create the editor instance.
     */
    function initialize() {
      const useElFinder = Boolean(CRM.config.ELFinderLocation);
      const sourceHtml = item && typeof item.value === 'string' ? item.value : '';
      const config = getEditorConfig(item, useElFinder);
      const removed = Array.isArray(config.removePlugins) ? config.removePlugins : [];
      const uploadsDisabled = removed.includes('ImageUpload');
      const activeElFinder = useElFinder && !uploadsDisabled && !removed.includes('CKFinder');

      $(item).addClass('crm-wysiwyg-enabled');
      ClassicEditor
        .create({...config, attachTo: $(item)[0]})
        .then(function(editor) {
          try {
            attachHtmlQaReport(editor, sourceHtml);
          }
          catch (error) {
            console.error('Unable to build the CKEditor 5 HTML QA report.', error);
          }
          if (uploadsDisabled) {
            onReady(editor);
          }
          else {
            (activeElFinder ? onReadyElFinder : onReadyBase64)(editor);
          }
        })
        .catch(function(error) {
          deferred.reject(error);
          console.error(error);
        });
    }

    function loadExtensionsAndInitialize() {
      try {
        const loading = loadPluginAssets();
        if (loading) {
          loading.then(initialize).catch(function(error) {
            deferred.reject(error);
            console.error(error);
          });
        }
        else {
          initialize();
        }
      }
      catch (error) {
        deferred.reject(error);
        console.error(error);
      }
    }

    /**
     * Load translations, the editor build, and elFinder in dependency order.
     */
    function loadEditor() {
      function loadBuild() {
        CRM.loadScript(CRM.config.CKEditor5Location).done(function() {
          if (CRM.config.ELFinderLocation) {
            CRM.loadScript(CRM.config.ELFinderLocation).done(loadExtensionsAndInitialize);
          }
          else {
            loadExtensionsAndInitialize();
          }
        });
      }

      if (CRM.config.CKEditor5Language) {
        CRM.loadScript(CRM.config.CKEditor5Language).always(loadBuild);
      }
      else {
        loadBuild();
      }
    }

    if ($(item).hasClass('crm-wysiwyg-enabled')) {
      deferred.resolve();
    }
    else if ($(item).length) {
      // Lazy-load ckeditor.js
      if (window.ClassicEditor) {
        loadExtensionsAndInitialize();
      }
      else {
        loadEditor();
      }
    } else {
      deferred.reject();
    }
    return deferred;
  };

  CRM.wysiwyg.destroy = function(item) {
    $(item).removeClass('crm-wysiwyg-enabled');
    var editor = getInstance(item);
    if (editor) {
      editor.destroy();
    }
  };

  function insertIntoEditor(editor, text) {
    if (!editor || typeof text !== 'string' || !text.length) return;
    let activeRaw = findActiveRawTextarea(editor);
    if (!activeRaw && findRawHtmlModelElement(editor)) {
      const rootEl = editor.ui && editor.ui.view && editor.ui.view.element;
      const editBtn = rootEl && typeof rootEl.querySelector === 'function'
        ? rootEl.querySelector('.raw-html-embed__buttons-wrapper button')
        : null;
      if (editBtn && typeof editBtn.click === 'function') {
        editBtn.click();
        activeRaw = findActiveRawTextarea(editor);
      }
    }
    if (activeRaw) {
      insertIntoRawTextarea(editor, activeRaw, text);
      return;
    }
    const rawModelEl = findRawHtmlModelElement(editor);
    if (rawModelEl && editor.model && typeof editor.model.change === 'function') {
      const existing = String(rawModelEl.getAttribute('value') || '');
      editor.model.change(writer => writer.setAttribute('value', existing + text, rawModelEl));
      return;
    }
    editor.model.change(writer => {
      if (typeof editor.model.insertContent === 'function' && typeof writer.createText === 'function') {
        editor.model.insertContent(writer.createText(text), editor.model.document.selection);
      }
      else {
        const insertPosition = editor.model.document.selection.getFirstPosition();
        writer.insertText(text, insertPosition);
      }
    });
  }

  CRM.ckeditor5.insertIntoEditor = insertIntoEditor;

  CRM.wysiwyg.updateElement = function(item) {
    var editor = getInstance(item);
    if (editor) {
      syncActiveRawTextarea(editor);
      editor.updateSourceElement();
    }
  };

  CRM.wysiwyg.getVal = function(item) {
    var editor = getInstance(item);
    if (editor) {
      syncActiveRawTextarea(editor);
      return editor.getData();
    } else {
      return $(item).val();
    }
  };

  CRM.wysiwyg.setVal = function(item, val) {
    var editor = getInstance(item);
    if (editor) {
      var result = editor.setData(val);
      try {
        attachHtmlQaReport(editor, val);
      }
      catch (error) {
        console.error('Unable to build the CKEditor 5 HTML QA report.', error);
      }
      return result;
    } else {
      return $(item).val(val);
    }
  };

  CRM.wysiwyg.insert = function(item, text) {
    var editor = getInstance(item);
    if (editor) {
      insertIntoEditor(editor, text);
    } else {
      CRM.wysiwyg._insertIntoTextarea(item, text);
    }
  };

  CRM.wysiwyg.focus = function(item) {
    var editor = getInstance(item);
    if (editor) {
      const activeRaw = findActiveRawTextarea(editor);
      if (activeRaw && typeof activeRaw.textarea.focus === 'function') {
        activeRaw.textarea.focus();
      }
      else {
        editor.focus();
      }
    } else {
      $(item).focus();
    }
  };

})(CRM.$, CRM._);
