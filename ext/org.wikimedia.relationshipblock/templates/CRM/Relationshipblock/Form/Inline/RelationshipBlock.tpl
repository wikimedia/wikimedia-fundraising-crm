<div class="crm-inline-edit-form">
  <div class="crm-inline-button">
    {include file="CRM/common/formButtons.tpl"}
  </div>

  <div class="crm-clear">
    {foreach from=$elementNames item=elementName}
      <div class="crm-summary-row">
        <div class="crm-label">{$form.$elementName.label}</div>
        <div class="crm-content">{$form.$elementName.html}</div>
      </div>
    {/foreach}
  </div>
</div>
