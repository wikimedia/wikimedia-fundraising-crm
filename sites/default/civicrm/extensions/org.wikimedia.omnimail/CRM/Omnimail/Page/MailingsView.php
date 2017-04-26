<?php

class CRM_Omnimail_Page_MailingsView extends CRM_Core_Page {

  public function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    CRM_Utils_System::setTitle(ts('MailingsView'));

    $mailings = civicrm_api3('MailingProviderData', 'get', array(
      'contact_id' => CRM_Utils_Request::retrieve('cid', 'Integer'),
      'return' => array('event_type', 'mailing_identifier', 'email', 'recipient_action_datetime'),
      'sequential' => 1,
      'options' => array('limit' => 500, 'sort' => 'recipient_action_datetime DESC')
    ));
    //CRM_Core_Resources::singleton()->ad
    $this->assign('mailings', json_encode($mailings['values']));

    parent::run();
  }

}
