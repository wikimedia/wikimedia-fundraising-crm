<?php

class CRM_Wmf_Page_EmailRedirect extends CRM_Core_Page {

  public function run() {
    $email = CRM_Utils_Request::retrieve('email', 'String');

    if (!empty($email)) {
      $emailContacts = \Civi\Api4\Email::get(FALSE)
        ->addWhere('email', '=', $email)
        ->addWhere('contact_id.is_deleted', '=', FALSE)
        ->addSelect('contact_id')
        ->addGroupBy('contact_id')
        ->execute()->column('contact_id');

      if (count($emailContacts) === 1) {
        CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/contact/view', ['reset' => 1, 'cid' => $emailContacts[0]]));
      }
    }

    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/contactsbyemail/') . '#?email=' . urlencode($email));
  }

}
