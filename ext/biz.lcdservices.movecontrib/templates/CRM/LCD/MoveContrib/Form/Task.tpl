{crmScope extensionKey='biz.lcdservices.movecontrib'}
<div id="moveContribution" class="crm-block crm-form-block crm-contribution-move-form-block">
  <div class="help">
    <div class="icon inform-icon"></div> {ts 1=$count}%1 contribution(s) will be moved.{/ts}
  </div>

  {foreach from=$elementNames item=elementName}
    <div class="crm-section">
      <div class="label">{$form.$elementName.label}</div>
      <div class="content">{$form.$elementName.html}</div>
      <div class="clear"></div>
    </div>
  {/foreach}

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
{/crmScope}
