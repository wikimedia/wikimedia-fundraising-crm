<?php
// Class to hold wmf functionality that alters core quickforms.

namespace Civi\WMFHook;

class QuickForm {

  /**
   * @param string $formName
   * @param \CRM_Core_Form $form
   *
   * @throws \CiviCRM_API3_Exception
   */
  public static function buildForm($formName, $form) {
    switch ($formName) {
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
          $form->addOptionalQuickFormElement('contribution_or_softcredits');
        }
        break;

      case 'CRM_Contact_Form_Merge':
        $template = \CRM_Core_Form::getTemplate();
        $groupId = CalculatedData::getCalculatedCustomFieldGroupID();
        $rows = $template->getTemplateVars('rows');
        if (isset($rows["custom_group_$groupId"])) {
          unset($rows["custom_group_$groupId"]);
          $form->assign('rows', $rows);
        }
        foreach (CalculatedData::getCalculatedCustomFields() as $field) {
          $id = $field['id'];
          $elementName = "move_custom_$id";
          $rowExists = isset($rows[$elementName]);
          if ($field['name'] === 'all_funds_last_donation_date' && $rowExists) {
            // Add the last donation date to the summary fields that show at the
            // top of the merge screen - this makes it easier for Donor relations.
            // See https://phabricator.wikimedia.org/T256314#8385450
            $lastDonationValues = $rows[$elementName];
            $summaryRows = $form->getTemplateVars('summary_rows');
            $summaryRows[] = [
              'name' => 'all_funds_last_donation_date',
              'label' => $field['label'],
              'main_contact_value' => $lastDonationValues['main'] ?? '',
              'other_contact_value' => $lastDonationValues['other'] ?? '',
            ];
            $form->assign('summary_rows', $summaryRows);
          }
          if ($form->elementExists($elementName)) {
            $form->removeElement($elementName);
          }
          if ($rowExists) {
            unset($rows[$elementName]);
            $form->assign('rows', $rows);
          }
        }
        break;
    }
  }

  /**
   *
   */
  protected static function getSourceJS() {
    return file_get_contents(__DIR__ . '/js/' . 'validateSource.js');
  }

  /**
   * @param \CRM_Core_Form $form
   *
   * @throws \CiviCRM_API3_Exception
   */
  protected static function buildFormContributionForm(\CRM_Core_Form $form): void {
    \CRM_Core_Resources::singleton()->addScript(self::getSourceJS());

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
