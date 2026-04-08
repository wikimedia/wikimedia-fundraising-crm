{if $no_go_reason}
  <p>{$no_go_reason|escape}</p>
{else}
  <p>Transaction ID: {$trxn_id}</p>
  <p>{$amount} {$currency}, received on {$receive_date} via {$processor}</p>
  <table class="form-layout-compressed">
    <tr>
      <td class="label">{$form.is_fraud.label}</td>
      <td class="html-adjust">{$form.is_fraud.html}</td>
    </tr>
  </table>
  {* FOOTER *}
  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
{/if}
