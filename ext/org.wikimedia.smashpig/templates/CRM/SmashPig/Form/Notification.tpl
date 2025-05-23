{* HEADER *}

<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="top"}
</div>

{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}</div>
    <div class="clear"></div>
  </div>
{/foreach}

<table class="crm-info-panel">
  <tr>
    <td class="label">{ts}Email{/ts}</td><td class="view-value">{$notification.email}</td>
  </tr>
  {if $notification.display_name}
    <tr>
      <td class="label">{ts}Name{/ts}</td><td class="view-value">{$notification.display_name}</td>
    </tr>
  {/if}
    {if $notification.language}
      <tr>
        <td class="label">{ts}language{/ts}</td><td class="view-value">{$notification.language}</td>
      </tr>
    {/if}
  {if $notification.msg_subject}
    <tr>
      <td class="label">{ts}Subject{/ts}</td><td class="view-value">{$notification.msg_subject}</td>
    </tr>
  {/if}
  {if $notification.msg_html}
    <tr>
      <td class="label">{ts}Html message{/ts}</td><td class="view-value">{$notification.msg_html}</td>
    </tr>
  {/if}
    {if $notification.msg_text}
      <tr>
        <td class="label">{ts}Text{/ts}</td><td class="view-value">{$notification.msg_text|nl2br}</td>
      </tr>
    {/if}
</table>

{if $qanotification}
  <h2>{ts}The following message text is in QA{/ts}</h2>
  <p>{ts}You can send yourself a copy or approve it here but it will not go to the donor until approved.{/ts}</p>
  <table>
    {if $qanotification.msg_subject}
      <tr>
        <td class="label">{ts}Subject{/ts}</td><td class="view-value">{$qanotification.msg_subject}</td>
      </tr>
    {/if}
    {if $qanotification.msg_html}
      <tr>
        <td class="label">{ts}Html message{/ts}</td><td class="view-value">{$qanotification.msg_html}</td>
      </tr>
    {/if}
    {if $qanotification.msg_text}
      <tr>
        <td class="label">{ts}Text{/ts}</td><td class="view-value">{$qanotification.msg_text|nl2br}</td>
      </tr>
    {/if}
    {if $qanotification.language}
      <tr>
        <td class="label">{ts}language{/ts}</td><td class="view-value">{$qanotification.language}</td>
      </tr>
    {/if}
  </table>
{/if}
{* FOOTER *}
<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
