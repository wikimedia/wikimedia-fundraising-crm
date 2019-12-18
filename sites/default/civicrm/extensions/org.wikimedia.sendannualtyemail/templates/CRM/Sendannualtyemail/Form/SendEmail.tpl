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



{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}</div>
    <div class="clear"></div>
  </div>
{/foreach}

<div>
    {ts}Submitting this form will send an end of year summary email to the email of the contact you are viewing.{/ts}
    {ts}All contributions associated with contacts with this primary email address will be included.{/ts}
    {ts}The name & language from the contact with the highest contact ID will be used.{/ts}
</div>

{* FOOTER *}
<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
