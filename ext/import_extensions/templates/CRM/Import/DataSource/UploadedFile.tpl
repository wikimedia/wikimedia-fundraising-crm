{$upload_message}
{if array_key_exists('file_name', $form)}
  <table class="form-layout">
    <tr>
      <td class="label">{$form.file_name.label}</td>
      <td>{$form.file_name.html}</td>
    </tr>
    <tr>
      <td class="label">{$form.skipColumnHeader.label}</td>
      <td>{$form.skipColumnHeader.html}</td>
    </tr>
    <tr>
      <td class="label">{$form.number_of_rows_to_validate.label}</td>
      <td>{$form.number_of_rows_to_validate.html}</td>
    </tr>
  </table>
{/if}
