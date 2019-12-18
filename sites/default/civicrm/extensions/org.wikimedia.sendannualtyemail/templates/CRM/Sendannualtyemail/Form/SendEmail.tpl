<script>
{literal}
CRM.$(function($) {
    $('.email-send-submit').on('click', function(e) {
        e.preventDefault();
        CRM.confirm({
            title: "Send Year Summary Email?",
            message: "Are you sure you want to send this contact an email with all contributions from " + $('#year').val() + "?"
        })
            .on(
                'crmConfirm:yes', function () {
                    $('#SendEmail').submit();
                });
    });
});
{/literal}
</script>


<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="top"}
</div>

{* FIELD EXAMPLE: OPTION 1 (AUTOMATIC LAYOUT) *}

{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}</div>
    <div class="clear"></div>
  </div>
{/foreach}

{* FIELD EXAMPLE: OPTION 2 (MANUAL LAYOUT)

  <div>
    <span>{$form.favorite_color.label}</span>
    <span>{$form.favorite_color.html}</span>
  </div>

{* FOOTER *}
<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
