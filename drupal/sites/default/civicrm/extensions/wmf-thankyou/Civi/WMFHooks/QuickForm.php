<?php
// Class to hold wmf functionality that alters core quickforms.

namespace Civi\WMFHooks;

class QuickForm {

  /**
   * @param string $formName
   * @param \CRM_Core_Form $form
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function buildForm($formName, $form) {
    switch ($formName) {
      case 'CRM_Custom_Form_CustomDataByType':
        if ($form->_type === 'Contribution' && empty($form->_entityId)) {
          // New hand-entered contributions get a default for no_thank_you
          $no_thank_you_reason_field_name = self::getFormCustomFieldName('no_thank_you');
          $giftSourceField = self::getFormCustomFieldName('Campaign');

          $no_thank_you_toggle_form_elements = [
            $giftSourceField,
            'financial_type_id'
          ];

          if ($no_thank_you_reason_field_name && $form->elementExists($no_thank_you_reason_field_name)) {
            if (\CRM_Core_PseudoConstant::getName('CRM_Contribute_BAO_Contribution', 'financial_type_id', $form->_subType ?? NULL) === 'Stock') {
              $form->setDefaults([$no_thank_you_reason_field_name => '']);
            }
            else {
              $form->setDefaults(
                [$no_thank_you_reason_field_name => 'Manually entered']
              );
            }
            \CRM_Core_Resources::singleton()->addScript(self::getNoThanksYouJS($no_thank_you_reason_field_name, $no_thank_you_toggle_form_elements));
          }
        }
        break;
      case 'CRM_Contribute_Form_Contribution':
        self::buildFormContributionForm($form);
        break;

      case 'CRM_Contribute_Form_Search':
      case 'CRM_Contact_Form_Search_Advanced':
        // Remove the field 'Contributions OR Soft Credits?' from the contribution search
        // and advanced search pages.
        // This filter has to be removed as it attempts to create an insanely big
        // temporary table that kills the server.
        if ($form->elementExists('contribution_or_softcredits')) {
          $form->removeElement('contribution_or_softcredits');
        }
        break;

    }
  }

  /**
   * Get javascript to add to contribution page to reduce data-entry issues on thank yous.
   *
   * Our rules are
   *  1) We send out thank you emails in our thank you job unless there is a value in the no-thank-you reason field.
   *  2) For online donations we almost always leave this blank (regular recurring being an exception).
   *  3) For manually entered emails we default to setting a value so thank-yous do NOT go out.
   *  4a) For donor advised emails we want to default to sending when Engage enters these. Clearing out the value from no-thank-you reason addresses this.
   *  4b) For Stock gift emails we want to default to sending. Clearing out the value from no-thank-you reason addresses this.
   *  5) The thank you mailer sends to contacts who have a primary email (it does not respect on hold &
   *   if this is an issue we should raise a phab to consider).
   *  6) MG suggested we also should set no-thank-you-reason when there is no email. In fact an email couldn't go out, but I have respected
   *   this suggestion as adding an email feels like it might unexpectedly cause an email to go out - although by default
   *   I think the thank you job doesn't delve too far back.
   *
   * In order to implement we have a php hook that sets the default for no-thank-you reason to 'Manually Entered' (suppressing the email).
   * The javascript injected from this function adds change events to 3 fields
   *   1) The gift source field - if this is changed to Donor Advised Fund it will check if the contact has a primary email (see
   *      above note about on_hold) and if so it will clear out the no-thank-you-reason field (meaning a thank you will go out).
   *   2) The financial type field - if this is changed to Stock it will check if the contact has a primary email (see
   *      above note about on_hold) and if so it will clear out the no-thank-you-reason field (meaning a thank you will go out).
   *   3) The no-thank-you-reason field. Changes to this field will add text near the submit buttons indicating whether or not
   *      a mail would go out. I didn't want to get into mucking around on what to do if the gift source field was changed
   *      and changed again - so I opted to try to give a visual queue as to what to expect. I'm also mindful I consider this
   *      js method to be a bit hacky & non-robust so hopefully they would notice there NOT being a visual queue if it stops
   *      working. Note the change happens when they click out of the field - I think that might be unintuitive while testing but
   *      should be fine in a data entry flow.
   *
   * Preferred solution? Use the afform functionality to create a cut down targetted form for Engage to do their data entry.
   *
   * Bug: T233374/T259173
   *
   * @param string $no_thank_you_reason_field_name
   * @param array $no_thank_you_toggle_form_elements
   *
   * @return string $js
   */
  protected static function getNoThanksYouJS(string $no_thank_you_reason_field_name, array $no_thank_you_toggle_form_elements): string {
    $element_selectors = '';
    foreach($no_thank_you_toggle_form_elements as $el) {
      $element_selectors .= "#{$el},";
    }
    $element_selectors = substr($element_selectors,0,-1);
    $js =  "
      if (!cj('.wmf-email-text').length) {
        cj('.crm-submit-buttons').append('<div class=\'wmf-email-text\'>No thank-you email will be sent unless you clear the no thank-you reason field</div>');
      }
      cj('#{$no_thank_you_reason_field_name}').change(function() {
        if (cj('#{$no_thank_you_reason_field_name}').val().length) {
          cj('.wmf-email-text').text('No thank-you email will be sent');
        }
        else {
         CRM.api4('Email', 'get', {
            select: ['email'],
            where: [['contact_id', '=', CRM.vars.coreForm.contact_id], ['is_primary', '=', 1]]
          }).then(function(email) {
            if (email.length === 0) {
             cj('#{$no_thank_you_reason_field_name}').val('No available email').trigger('change');
            }
            else {
              cj('.wmf-email-text').text('A thank-you email will be sent');
            }
          })
        }
      });
      cj('$element_selectors').change(function() {
        if (cj('option:selected',this).text() === 'Donor Advised Fund'
        || cj('option:selected',this).text() === 'Stock' ) {
          cj('#{$no_thank_you_reason_field_name}').val('').trigger('change');
        }
      })";

    return $js;
  }

  /**
   * @param \CRM_Core_Form $form
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected static function buildFormContributionForm(\CRM_Core_Form $form): void {
    // Only run this validation for users having the Engage role.
    // @todo - move the user_has_role out of the extension. In order
    // to ready this for drupal we can switch to using a permission
    // for engage 'engage role'.
    // Once the addition of this permission is deployed we need to
    // add it to the engage user role and then we can replace this with
    // \CRM_Core_Permission::check('engage_role')
    if (wmf_civicrm_user_has_role('Engage Direct Mail')) {
      self::addEngageUIFeatures($form);
    }

  }

  /**
   * Get the name of the custom field as it would be shown on the form.
   *
   * This is basically 'custom_x_-1' for us. The -1 will always be 1
   * except for multi-value custom groups which we don't really use.
   *
   * @param string $fieldName
   *
   * @return string
   * @throws \CiviCRM_API3_Exception
   */
  public static function getFormCustomFieldName(string $fieldName): string {
    // @todo - make this protected once further consolidation is done.
    return 'custom_' . \CRM_Core_BAO_CustomField::getCustomFieldID($fieldName) . '_-1';
  }

  /**
   * @param \CRM_Core_Form $form
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected static function addEngageUIFeatures(\CRM_Core_Form $form): void {
    // Default to the Engage contribution type, if this is a new contribution.
    if ($form->_action & \CRM_Core_Action::ADD) {
      $engage_contribution_type_id = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', 'Engage');
      $form->setDefaults([
        'financial_type_id' => $engage_contribution_type_id,
      ]);
      $form->assign('customDataSubType', $engage_contribution_type_id);
    }

    // Make Batch Number required, if the field exists.
    $batch_num_field_name = self::getFormCustomFieldName('import_batch_number');
    if ($batch_num_field_name && $form->elementExists($batch_num_field_name)) {
      $form->addRule($batch_num_field_name, t('Batch number is required'), 'required');
    }
  }

}
