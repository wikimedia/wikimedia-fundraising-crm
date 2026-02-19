<?php
// Class to hold wmf functionality that alters core quickforms.

namespace Civi\WMFHook;

use Civi\WMFHelper\ContributionRecur;
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
          $reasons = ContributionRecur::getDonorCancelReasons();
          $props['options'] = array_combine($reasons, $reasons);
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
        $summaryRows = $form->getTemplateVars('summary_rows');
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
            $summaryRows[] = [
              'name' => 'all_funds_last_donation_date',
              'label' => $field['label'],
              'main_contact_value' => $lastDonationValues['main'] ?? '',
              'other_contact_value' => $lastDonationValues['other'] ?? '',
            ];
          }
          if ($form->elementExists($elementName)) {
            $form->removeElement($elementName);
          }
          if ($rowExists) {
            unset($rows[$elementName]);
          }
        }
        $mainCid = $form->getTemplateVars('main_cid');
        $otherCid = $form->getTemplateVars('other_cid');
        $doubleOptInActivities = \Civi\Api4\Activity::get(FALSE)
          ->addWhere('target_contact_id', 'IN', [$mainCid, $otherCid])
          ->addWhere('activity_type_id:name', '=', 'Double Opt-In')
          ->addWhere('status_id:name', '=', 'Completed')
          ->addSelect('subject', 'target_contact_id')
          ->execute();
        if ($doubleOptInActivities->count() > 0) {
          $optInSummaryRow = [
            'name' => 'double_opt_in_email',
            'label' => 'Double Opt-In Email'
          ];
          foreach($doubleOptInActivities as $activity) {
            if (in_array($mainCid, $activity['target_contact_id'])) {
              $optInSummaryRow['main_contact_value'] = $activity['subject'];
            }
            if (in_array($otherCid, $activity['target_contact_id'])) {
              $optInSummaryRow['other_contact_value'] = $activity['subject'];
            }
          }
          $summaryRows[] = $optInSummaryRow;
        }
        $form->assign('rows', $rows);
        $form->assign('summary_rows', $summaryRows);
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
      case 'CRM_Contribute_Form_AdditionalPayment':
        // Add links to transaction info in payment processor consoles
        /** @var \CRM_Contribute_Form_AdditionalPayment $form */
        if (!$form->isViewMode()) {
          return;
        }
        // fall through to next
      case 'CRM_Contribute_Form_ContributionView':
        $payments = $form->getTemplateVars('payments');
        foreach ($payments as &$payment) {
          if (!empty($payment['trxn_id'])) {
            $url = \Civi\WMFHelper\Contribution::getURLForTransactionID($payment['trxn_id']);
            if ($url) {
              $payment['trxn_id'] = "<a href=\"$url\" target=\"_blank\">{$payment['trxn_id']}</a>";
            }
          }
        }
        $form->assign('payments', $payments);
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
