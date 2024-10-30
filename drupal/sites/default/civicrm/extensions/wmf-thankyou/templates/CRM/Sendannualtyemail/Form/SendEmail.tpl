<script>
{literal}
CRM.$(function($) {
  CRM.renderEOY = function () {
    CRM.api4('EOYEmail', 'render', {
      contactID: CRM.vars.coreForm.contact_id,
      dateRelative: CRM.$('#date_range_relative').val(),
      startDateTime : CRM.$('#date_range_low').val(),
      endDateTime : CRM.$('#date_range_high').val()
    }).then(function(results) {
      for (key in results) {
        CRM.$('#eoy_message_message').html(results[key]['html']);
        CRM.$('#eoy_message_subject').html(results[key]['subject']);
        break;
      }

    }, function(failure) {
      CRM.$('#eoy_message_message').html(failure['error_message']);
    })
  }
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

  CRM.$('#date_range_relative').on('change', function(e) {
    CRM.renderEOY();
  });
  CRM.$('#date_range_low').on('change', function(e) {
    CRM.renderEOY();
  });
  CRM.$('#date_range_high').on('change', function(e) {
    CRM.renderEOY();
  });

});

{/literal}
</script>

{if $isEmailable}
  <div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="top"}
  </div>
<div class="crm-section">
  <div class="content">
    {include file="CRM/Core/DatePickerRangeWrapper.tpl" fieldName="date_range" to='' from='' colspan="2" hideRelativeLabel=1 class =''}
  </div>
</div>

  <div>
      {ts}Submitting this form will send a summary email to the email of the contact you are viewing.{/ts}
      {ts}All contributions associated with contacts with this primary email address will be included.{/ts}
      {ts}The name & language from the contact with the most recent donation will be used.{/ts}
  </div>
  <hr>
  <h2>Message preview</h2>
  <div id="eoy_message_subject">{$subject}</div>
  <div id="eoy_message_message">{$message}</div>

  {* FOOTER *}
  <div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
{else}
  <p>{$errorText}</p>
{/if}
