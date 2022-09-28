/*jshint esversion: 6 */
// https://civicrm.org/licensing
(function($, _) {

  var instances = {};

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
      const connectorUrl = CRM.url('civicrm/image/access', null, 'back');
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
                    _fm.file(open)? resolve(_fm) : reject(_fm, 'errFolderNotFound');
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
                      reject(_fm, err? err : 'errFolderNotFound');
                    });
                  }).catch((err) => {
                    reject(_fm, err? err : 'errFolderNotFound');
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
          }).catch((fm, err) => {
            console.log(err);
            const error = fm.parseError(err);
            reject(fm.i18n(error? (error === 'userabort'? 'errAbort' : error) : 'errUploadNoFiles'));
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
      var debounce,
        name = $(editor.sourceElement).attr('name') || $(editor.sourceElement).attr('id');

      instances[name] = editor;

      // Update source element on blur; especially important for javascript-based UIs
      editor.editing.view.document.on('blur', function() {
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

    /**
     * Create the editor instance.
     */
    function initialize() {
      if (CRM.config.ELFinderLocation) {
        if (CRM.config.CKEditor5CustomConfig) {
          initializeCustomWithELFinder();
        }
        else{
          initializeWithELFinder();
        }
      }
      else {
        initializeWithBaseUploader();
      }
    }

    /**
     * Create a custom editor instance with Elfinder.
     */
    function initializeCustomWithELFinder() {
      $(item).addClass('crm-wysiwyg-enabled');
      ClassicEditor.create($(item)[0], CRM.config.CKEditor5CustomConfig).then(onReadyElFinder);
    }

    /**
     * Create the editor instance with the BaseUploader.
     */
    function initializeWithBaseUploader() {
      $(item).addClass('crm-wysiwyg-enabled');
      // @todo make this language-alterable and configurable in some way  (UI or config file).
      // Note that because the base-uploader package is constructed as a 'build' it
      // has no toolbar by default (as opposed to the elfinder which leverages the classic-build')
      ClassicEditor.create($(item)[0], {
          toolbar: {
            // With this it wraps rather than offering a drop down for more.
            // I'm on the fence about which is better but this is consistent with
            // ckeditor4. If we added configurability we could expose this.
            // https://ckeditor.com/docs/ckeditor5/latest/api/module_core_editor_editorconfig-EditorConfig.html#member-toolbar
            shouldNotGroupWhenFull: true,
            items: [
              'heading',
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
              '|',
              'link',
              'imageUpload',
              'blockQuote',
              'insertTable',
              'alignment',
              'mediaEmbed',
              'undo',
              'redo',
              'pageBreak',
              'horizontalLine'
            ]
          },
          language: (typeof CRM.config.locale == 'string' ? CRM.config.locale.substr(0,2) : 'en'),
          image: {
            toolbar: [
              'imageTextAlternative',
              'imageStyle:full',
              'imageStyle:side'
            ]
          },
          table: {
            contentToolbar: [
              'tableColumn',
              'tableRow',
              'mergeTableCells',
              'tableCellProperties',
              'tableProperties'
            ]
          },
          licenseKey: '',

        }
      ).then(onReady);
    }

    /**
     * Create the editor instance with Elfinder.
     */
    function initializeWithELFinder() {
      $(item).addClass('crm-wysiwyg-enabled');
      ClassicEditor.create($(item)[0], {
          toolbar: {
            // With this it wraps rather than offering a drop down for more.
            // I'm on the fence about which is better but this is consistent with
            // ckeditor4. If we added configurability we could expose this.
            // https://ckeditor.com/docs/ckeditor5/latest/api/module_core_editor_editorconfig-EditorConfig.html#member-toolbar
            shouldNotGroupWhenFull: true,
            items: [
              'heading',
              '|',
              'bold',
              'underline',
              'italic',
              'strikethrough',
              'superscript',
              'subscript',
              '|',
              'bulletedList',
              'numberedList',
              '|',
              'removeFormat',
              'fontFamily',
              'fontColor',
              'fontBackgroundColor',
              'fontSize',
              '|',
              'indent',
              'outdent',
              'alignment',
              '|',
              'link',
              'imageUpload',
              'ckfinder',
              'blockQuote',
              'insertTable',
              'horizontalLine',
              'specialCharacters',
              'mediaEmbed',
              'undo',
              'redo'
            ]
          },
          language: (typeof CRM.config.locale == 'string' ? CRM.config.locale.substr(0,2) : 'en'),
          image: {
            toolbar: [
              'imageResize',
              '|',
              'imageTextAlternative',
              'imageStyle:full',
              'imageStyle:side',
              'imageStyle:alignLeft',
              'imageStyle:alignCenter',
              'imageStyle:alignRight',
            ],
            styles: [
              'full',
              'side',
              'alignLeft',
              'alignCenter',
              'alignRight',
            ],
          },
          table: {
            contentToolbar: [
              'tableColumn',
              'tableRow',
              'mergeTableCells',
              'tableCellProperties',
              'tableProperties'
            ]
          },
          licenseKey: '',

        }).then(onReadyElFinder);
    }

    if ($(item).hasClass('crm-wysiwyg-enabled')) {
      deferred.resolve();
    }
    else if ($(item).length) {
      // Lazy-load ckeditor.js
      if (window.ClassicEditor) {
        initialize();
      } else {
        if (CRM.config.ELFinderLocation) {
          if (CRM.config.CKEditor5Language) {
            CRM.loadScript(CRM.config.CKEditor5Language).done(CRM.loadScript(CRM.config.CKEditor5Location).done(CRM.loadScript(CRM.config.ELFinderLocation).done(initialize)));
          }
          else {
            CRM.loadScript(CRM.config.CKEditor5Location).done(CRM.loadScript(CRM.config.ELFinderLocation).done(initialize));
          }
        }
        else {
          CRM.loadScript(CRM.config.CKEditor5Location).done(initialize);
        }
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

  CRM.wysiwyg.updateElement = function(item) {
    var editor = getInstance(item);
    if (editor) {
      editor.updateSourceElement();
    }
  };

  CRM.wysiwyg.getVal = function(item) {
    var editor = getInstance(item);
    if (editor) {
      return editor.getData();
    } else {
      return $(item).val();
    }
  };

  CRM.wysiwyg.setVal = function(item, val) {
    var editor = getInstance(item);
    if (editor) {
      return editor.setData(val);
    } else {
      return $(item).val(val);
    }
  };

  CRM.wysiwyg.insert = function(item, text) {
    var editor = getInstance(item);
    if (editor) {
      editor.model.change(writer => {
        const insertPosition = editor.model.document.selection.getFirstPosition();
        writer.insertText(text, insertPosition);
      });
    } else {
      CRM.wysiwyg._insertIntoTextarea(item, text);
    }
  };

  CRM.wysiwyg.focus = function(item) {
    var editor = getInstance(item);
    if (editor) {
      editor.focus();
    } else {
      $(item).focus();
    }
  };

})(CRM.$, CRM._);
