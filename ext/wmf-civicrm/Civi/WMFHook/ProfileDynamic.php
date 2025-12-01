<?php

namespace Civi\WMFHook;

use CRM_Core_BAO_CustomField;

class ProfileDynamic {

  /**
   * Add the last original amount to a profile page when it contains
   * the all_funds_last_donation_date field. There is no all_funds
   * version of the last original amount or original currency field
   * available in the Wmf_donor rollup table.
   *
   * @param \CRM_Core_Page $page
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function pageRun(\CRM_Core_Page $page) {
    if ($page instanceof \CRM_Profile_Page_Dynamic) {
      $vars = $page->getTemplateVars();
      if (isset($vars['profileFields'])) {
        $customFieldId = CRM_Core_BAO_CustomField::getCustomFieldID('all_funds_last_donation_date', 'wmf_donor');
        if (isset($vars['profileFields']['custom_' . $customFieldId])) {
          $contactId = $page->getVar('_id');
          $lastDonation = \Civi\Api4\Contribution::get(FALSE)
            ->addWhere('contact_id', '=', $contactId)
            ->addSelect('contribution_extra.original_amount')
            ->addSelect('contribution_extra.original_currency')
            ->addOrderBy('receive_date', 'DESC')
            ->setLimit(1)
            ->execute()
            ->first();
          $formattedAmount = $lastDonation['contribution_extra.original_amount'] . ' ' .
            $lastDonation['contribution_extra.original_currency'];
          $vars['profileFields']['fake'] = [
            'label' => 'Last donation amount',
            'value' => $formattedAmount
          ];
          $vars['row']['Last original amount'] = $formattedAmount;
          $page->assign('profileFields', $vars['profileFields']);
          $page->assign('row', $vars['row']);
        }
      }
    }
  }
}
