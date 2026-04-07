{if $no_go_reason}
  <p>{$no_go_reason|escape}</p>
{else}
  {if $results}
    {foreach $results as $result}
      <p>{$result.trxn_id} status: {$result.refund_status}</p>
    {/foreach}
  {else}
    <p>Will refund:</p>
    <table class="form-layout-compressed">
    {foreach $to_refund as $contribution}
      <tr>
        <td>{$contribution.display_name}</td>
        <td>{$contribution.trxn_id}</td>
        <td>{$contribution.original_currency} {$contribution.original_amount}</td>
        <td>{$contribution.receive_date}</td>
      </tr>
    {/foreach}
    </table>
    {if $not_to_refund}
      Will NOT refund:
      <table class="form-layout-compressed">
      {foreach $not_to_refund as $contribution}
        <tr>
          <td>{$contribution.display_name}</td>
          <td>{$contribution.trxn_id}</td>
          <td>{$contribution.original_currency} {$contribution.original_amount}</td>
          <td>{$contribution.receive_date}</td>
        </tr>
      {/foreach}
    </table>
    {/if}
    <table class="form-layout-compressed">
      <tr>
        <td class="label">{$form.is_fraud.label}</td>
        <td class="html-adjust">{$form.is_fraud.html}</td>
      </tr>
    </table>

    {if $async_message}
      <p>{$async_message}</p>
    {/if}
    <div class="crm-submit-buttons">
      {include file="CRM/common/formButtons.tpl" location="bottom"}
    </div>
  {/if}
{/if}
