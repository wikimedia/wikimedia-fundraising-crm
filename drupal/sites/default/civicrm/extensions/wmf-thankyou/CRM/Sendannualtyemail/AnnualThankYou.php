<?php

class CRM_Sendannualtyemail_AnnualThankYou {

  /**
   * Pass over the contact_id and year to the wmf_eoy_receipt module which does
   * the heavy lifting and sends the end of year receipt.
   *
   * @param $contact_id
   * @param $year
   * @see \Civi\EoySummary
   *
   * @throws \CRM_Core_Exception
   *
   * @return bool
   */
  public static function send($contact_id, $year): bool {
    // if no contact_id passed jump out here otherwise his would trigger a
    // receipt email for every donor!
    if (empty($contact_id)) {
      return FALSE;
    }

    Civi\Api4\EOYEmail::send(FALSE)
      ->setYear($year)
      ->setContactID($contact_id)
      ->execute();
    return TRUE;
  }

}
