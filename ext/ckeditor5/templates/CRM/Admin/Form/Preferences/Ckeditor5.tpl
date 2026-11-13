<div id="ckeditor5-config-control">
  {$form.ckeditor5_config.html}
</div>
{literal}
  <script type="text/javascript">
    CRM.$(function($) {
      var editor = $('#editor_id');
      editor.parent().css('flex-wrap', 'wrap');
      var control = $('#ckeditor5-config-control').insertAfter(editor).css({
        'flex-basis': '100%',
        'margin-top': '6px'
      });
      function toggleCKEditor5Config() {
        var editorId = $(this).val();
        control.toggle(['CKEditor5', 'CKEditor5-elfinder', 'CKEditor5-base64'].indexOf(editorId) !== -1);
      }
      $(':input[name=editor_id]').each(toggleCKEditor5Config).on('change', toggleCKEditor5Config);
    });
  </script>
{/literal}
