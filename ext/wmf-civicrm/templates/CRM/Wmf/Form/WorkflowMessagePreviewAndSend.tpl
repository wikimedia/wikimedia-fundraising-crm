{if $no_go_reason}
  <p>An email cannot be sent because of {$no_go_reason}</p>
{else}
  <p>Send a {$templateTitle} message to {$contact.display_name|escape} in their preferred language of
    {$language} at {$contact.email|escape}</p>

  <hr>
  <h2>Message preview</h2>
  <div id="workflow_message_subject"><b>Subject:</b> {$subject}</div>
  <div id="workflow_message_body">{$message}</div>

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
{/if}
