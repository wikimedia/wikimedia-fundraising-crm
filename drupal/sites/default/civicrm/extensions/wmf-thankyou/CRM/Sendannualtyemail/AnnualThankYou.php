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
   * @return bool
   */
  public static function send($contact_id, $year) {
    // if no contact_id passed jump out here otherwise his would trigger a
    // receipt email for every donor!
    if(empty($contact_id)) {
      return false;
    }

    wmf_eoy_receipt_run(['contact_id' => $contact_id, 'year' => $year]);
    return true;
  }
}
