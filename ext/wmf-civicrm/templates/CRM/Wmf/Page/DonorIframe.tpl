<!DOCTYPE html>
<style>
  body {
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", Arial, sans-serif;
    font-size: 14px;
  }
  button,
  input {
    font: inherit;
  }
  button {
    padding: 2px 10px;
    border: 1px solid rgb(31, 115, 183);
    border-radius: 4px;
    background: #fff;
    color: rgb(31, 115, 183);
    cursor: pointer;
  }
  button:hover {
    background: rgba(31, 115, 183, 0.08);
  }
  button:disabled {
    border-color: #d8dcde;
    color: #87929d;
    background: #fff;
    cursor: default;
  }
  table.donor-lookup {
    width: 100%;
    border-collapse: collapse;
  }
  table.donor-lookup {
    color: rgb(41, 50, 57);
  }
  a,
  a:visited {
    color: rgb(31, 115, 183);
    text-decoration: none;
  }
  table.donor-lookup td {
    padding: 4px 4px;
    vertical-align: top;
    word-break: break-word;
  }
  table.donor-lookup td.label {
    width: 1%;
    white-space: nowrap;
    padding-right: 6px;
    color: #68737d;
  }
  table.donor-lookup tr.plain td {
    padding-top: 6px;
  }
  table.donor-lookup tr.section-start td {
    padding-top: 14px;
  }
  .bad {
    padding: 1px 6px;
    border: 1px solid #f5c2c2;
    border-radius: 4px;
    background: #fdf1f1;
  }
</style>

{if $message}
  <p>{$message}</p>
{elseif $email}
  <p>More than one email match.</p>
  <p><a href="{crmURL p='civicrm/emailredirect' q="email=`$email|escape:'url'`"}" target="_blank">View all</a></p>
{elseif $donor}
  <table class="donor-lookup">
    {if $donor.is_secondary_email}
      <tr class="plain">
        <td colspan="2"><span class="bad">Secondary email</span></td>
      </tr>
    {/if}
    <tr class="plain">
      <td colspan="2">
        <a href="{crmURL p='civicrm/contact/view' q="reset=1&cid=`$donor.id`"}" target="_blank">{$donor.display_name|escape}</a>
      </td>
    </tr>
    {if $donor.country}
      <tr class="plain">
        <td colspan="2">{$donor.country|escape}</td>
      </tr>
    {/if}
    {if $donor.segment}
      <tr class="plain">
        <td colspan="2">{$donor.segment|escape}</td>
      </tr>
    {/if}
    <tr class="plain">
      <td colspan="2">
        {$donor.opt_in}
        <button type="button" id="snoozeToggle" onclick="document.getElementById('snoozeRow').style.display=''; this.style.display='none';">Snooze</button>
      </td>
    </tr>
    <tr id="snoozeRow" style="display: none">
      <td colspan="2">
        <form method="post">
          {include file="CRM/AuthenticatedIframe/HandshakeParams.tpl"}
          <input type="date" name="snoozeDate" required>
          <button type="submit">Snooze</button>
          <button type="button" onclick="document.getElementById('snoozeRow').style.display='none'; document.getElementById('snoozeToggle').style.display='';">Cancel</button>
        </form>
      </td>
    </tr>
    <tr class="section-start">
      <td class="label">OTG</td>
      <td><a href="{crmURL p='civicrm/contact/view' q="reset=1&cid=`$donor.id`&selectedChild=contribute"}" target="_blank">{$donor.otg_status|escape}</a></td>
    </tr>
    {if $donor.last_otg}
      <tr>
        <td class="label">Last OTG</td>
        <td>
          {if $donor.last_otg.is_bad}<span class="bad">{$donor.last_otg.status|escape}</span> {/if}
          {$donor.last_otg.date} {$donor.last_otg.amount}
        </td>
      </tr>
    {/if}
    <tr>
      <td class="label"><!-- TODO: Once the contributions tab is SK, link direct to the recur tab -->Recur</a></td>
      <td>
        {if $donor.recur_status.is_failing}<span class="bad">{$donor.recur_status.text|escape}</span>{else}{$donor.recur_status.text|escape}{/if}
        {if $donor.active_recur_id}
          <a href="{crmURL p='civicrm/contribute/unsubscribe' q="reset=1&crid=`$donor.active_recur_id`&cid=`$donor.id`&context=contribution"}" target="_blank">Cancel</a>
        {elseif $donor.active_recur_count > 1}
          ({$donor.active_recur_count} active)
        {/if}
      </td>
    </tr>
    {if $donor.last_recur}
      <tr>
        <td class="label">Last recur</td>
        <td>
          {if $donor.last_recur.is_bad}<span class="bad">{$donor.last_recur.status|escape}</span> {/if}
          {$donor.last_recur.date} {$donor.last_recur.amount} {$donor.last_recur.frequency_unit|escape}
        </td>
      </tr>
    {/if}
    {foreach from=$donor.employer key=employerID item=employerName}
      <tr>
        <td class="label">Employer</td>
        <td>
          <a href="{crmURL p='civicrm/contact/view' q="reset=1&cid=`$employerID`"}" target="_blank">{$employerName|escape}</a>
        </td>
      </tr>
    {/foreach}
    {foreach from=$donor.daf key=dafID item=dafName}
      <tr>
        <td class="label">DAF</td>
        <td>
          <a href="{crmURL p='civicrm/contact/view' q="reset=1&cid=`$dafID`"}" target="_blank">{$dafName|escape}</a>
        </td>
      </tr>
    {/foreach}
    {if $donor.donor_portal_login}
      <tr>
        <td class="label">Donor portal login</td>
        <td>{$donor.donor_portal_login}</td>
      </tr>
    {/if}
    <tr>
      <td class="label">{if $linkSent}Link sent{else}Send{/if}</td>
      <td>
        <form method="post" style="display: inline">
          {include file="CRM/AuthenticatedIframe/HandshakeParams.tpl"}
          <input type="hidden" name="sendLink" value="DonorPortal">
          <button type="submit"{if $linkSent} disabled{/if}>Donor portal link</button>
        </form>
        <form method="post" style="display: inline">
          {include file="CRM/AuthenticatedIframe/HandshakeParams.tpl"}
          <input type="hidden" name="sendLink" value="EmailPreferences">
          <button type="submit"{if $linkSent} disabled{/if}>EPC link</button>
        </form>
      </td>
    </tr>
  </table>
{/if}
