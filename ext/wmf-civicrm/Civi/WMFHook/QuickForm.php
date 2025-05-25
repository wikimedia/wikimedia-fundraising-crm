<?php
// Class to hold wmf functionality that alters core quickforms.

namespace Civi\WMFHook;

use CRM_Activity_Form_Activity;
use CRM_Core_Form;

class QuickForm {

  /**
   * @param string $formName
   * @param CRM_Core_Form $form
   *
   * @throws \CRM_Core_Exception
   */
  public static function buildForm($formName, $form) {
    switch ($formName) {
      case 'CRM_Activity_Form_Activity':
        /** @var CRM_Activity_Form_Activity $form */
        if (self::isRecurringUpgradeDeclineForm($form)) {
          $completedID = self::getCompletedStatusID();
          $statusElement = $form->getElement('status_id');
          $statusElement->setValue($completedID);
        }
        break;

      case 'CRM_Contribute_Form_Contribution':
        self::buildFormContributionForm($form);
        break;

      case 'CRM_Contribute_Form_CancelSubscription':
        if ($form->elementExists('cancel_reason')) {
          $form->removeElement('cancel_reason');
          $props['options'] = [
            // Any changes to this list must also be made in the Acoustic export code.
            'Other and Unspecified' => 'Other and Unspecified',
            'Financial Reasons' => 'Financial Reasons',
            'Duplicate recurring donation' => 'Duplicate recurring donation',
            'Wikipedia content related complaint' => 'Wikipedia content related complaint',
            'Wikimedia Foundation related complaint' => 'Wikimedia Foundation related complaint',
            'Lack of donation management tools' => 'Lack of donation management tools',
            'Matching Gift' => 'Matching Gift',
            'Unintended recurring donation' => 'Unintended recurring donation',
            'Chapter' => 'Chapter',
            'Update' => 'Update',
            'Frequency' => 'Frequency',
          ];
          // Adds the modified cancel_reason as required with TRUE
          $form->addSelect('cancel_reason', $props, TRUE);
        }

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
        $template = CRM_Core_Form::getTemplate();
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

  public static function preProcess(string $formName, CRM_Core_Form $form): void {
    switch ($formName) {
      case 'CRM_Activity_Form_Activity':
        /** @var CRM_Activity_Form_Activity $form */
        if (self::isRecurringUpgradeDeclineForm($form)) {
          $form->setDefaults([
            'subject' => 'Decline recurring upgrade',
            'status_id' => self::getCompletedStatusID()
          ]);
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
   * @param CRM_Core_Form $form
   *
   * @throws \CRM_Core_Exception
   */
  protected static function buildFormContributionForm(CRM_Core_Form $form): void {
    \CRM_Core_Resources::singleton()->addScript(self::getSourceJS());
  }

  protected static function isRecurringUpgradeDeclineForm(CRM_Activity_Form_Activity $form): bool {
    $activityTypeName = \CRM_Core_PseudoConstant::getName(
      'CRM_Activity_BAO_Activity', 'activity_type_id', $form->_activityTypeId
    );
    return ('Recurring Upgrade Decline' === $activityTypeName);
  }

  /**
   * @return false|int|string
   */
  public static function getCompletedStatusID() {
    $statusOptions = \CRM_Activity_BAO_Activity::buildOptions( 'status_id' );
    return array_search( 'Completed', $statusOptions );
  }

}
