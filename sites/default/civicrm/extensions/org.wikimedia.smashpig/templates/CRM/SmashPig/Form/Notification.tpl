<script>
  {literal}
  CRM.$(function($) {
    $('.notification').on('click', function(e) {
      e.preventDefault();
      CRM.confirm({
        title: "Send Failure Notification Email?",
        message: CRM.ts("Are you sure want to send this email"?")
      })
              .on(
                      'crmConfirm:yes', function () {
                        $('#Notification').submit();
                      });
    });
  });
  {/literal}
</script>

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
  {if $notification.msg_subject}
    <tr>
      <td class="label">{ts}Subject{/ts}</td><td class="view-value">{$notification.msg_subject}</td>
    </tr>
  {/if}
  {if $notification.msg_text}
    <tr>
      <td class="label">{ts}Text{/ts}</td><td class="view-value">{$notification.msg_text|nl2br}</td>
    </tr>
  {/if}
  {if $notification.msg_html}
    <tr>
      <td class="label">{ts}Html message{/ts}</td><td class="view-value">{$notification.msg_html}</td>
    </tr>
  {/if}
  {if $notification.language}
    <tr>
      <td class="label">{ts}language{/ts}</td><td class="view-value">{$notification.language}</td>
    </tr>
  {/if}
</table>
{* FOOTER *}
<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
