<style>
  table.donor-lookup {
    width: 100%;
    border-collapse: collapse;
  }
  table.donor-lookup td {
    padding: 3px 4px;
    vertical-align: top;
    word-break: break-word;
  }
  table.donor-lookup td.label {
    width: 1%;
    white-space: nowrap;
    padding-right: 6px;
  }
  table.donor-lookup tr.plain td {
    padding-top: 6px;
  }
  .bad {
    color: #900;
    font-weight: bold;
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
        <td class="label"></td>
        <td class="bad">Secondary email</td>
      </tr>
    {/if}
    <tr class="plain">
      <td class="label"></td>
      <td>
        <a href="{crmURL p='civicrm/contact/view' q="reset=1&cid=`$donor.id`"}" target="_blank">{$donor.display_name|escape}</a>
      </td>
    </tr>
    {if $donor.country}
      <tr class="plain">
        <td class="label"></td>
        <td>{$donor.country|escape}</td>
      </tr>
    {/if}
    {if $donor.segment}
      <tr class="plain">
        <td class="label"></td>
        <td>{$donor.segment|escape}</td>
      </tr>
    {/if}
    <tr class="plain">
      <td class="label"></td>
      <td>
        {$donor.opt_in}
        <button type="button" id="snoozeToggle" onclick="document.getElementById('snoozeRow').style.display=''; this.style.display='none';">Snooze</button>
      </td>
    </tr>
    <tr id="snoozeRow" style="display: none">
      <td class="label"></td>
      <td>
        <form method="post">
          {include file="CRM/AuthenticatedIframe/HandshakeParams.tpl"}
          <input type="date" name="snoozeDate" required>
          <button type="submit">Snooze</button>
          <button type="button" onclick="document.getElementById('snoozeRow').style.display='none'; document.getElementById('snoozeToggle').style.display='';">Cancel</button>
        </form>
      </td>
    </tr>
    <tr>
      <td class="label"><a href="{crmURL p='civicrm/contact/view' q="reset=1&cid=`$donor.id`&selectedChild=contribute"}" target="_blank">OTG</a></td>
      <td>{$donor.otg_status|escape}</td>
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
      <td{if $donor.recur_status.is_failing} class="bad"{/if}>
        {$donor.recur_status.text|escape}
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
