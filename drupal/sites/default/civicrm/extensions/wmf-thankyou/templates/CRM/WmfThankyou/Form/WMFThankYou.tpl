{if $contact}
  <div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="top"}
  </div>
  <p>Click send to send a thank you mail to {$contact.display_name|escape} in their preferred language of
    <em>{$language|escape}</em> to email address {$contact.email|escape}</p>

  {* FOOTER *}
  <div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
{/if}

{if !$contact}
    <p>An email cannot be sent because of {$no_go_reason|escape}</p>
{/if}
