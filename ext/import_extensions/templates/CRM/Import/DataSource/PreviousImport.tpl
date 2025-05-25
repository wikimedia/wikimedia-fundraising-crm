{if array_key_exists('import_id', $form)}
  <table class="form-layout">
    <tr>
      <td class="label">{$form.import_id.label}</td>
      <td>{$form.import_id.html}</td>
    </tr>
  </table>
{else}
  {ts}No available previous imports{/ts}
{/if}
