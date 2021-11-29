<?php

class CRM_Omnimail_Page_MailingsView extends CRM_Core_Page {

  public function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    CRM_Utils_System::setTitle(ts('MailingsView'));

    $mailings = civicrm_api3('MailingProviderData', 'get', [
      'contact_id' => CRM_Utils_Request::retrieve('cid', 'Integer'),
      'return' => [
        'event_type',
        'mailing_identifier',
        'contact_identifier',
        'email',
        'recipient_action_datetime',
        'mailing_identifier.name',
        'mailing_identifier.id',
      ],
      'sequential' => 1,
      'options' => ['limit' => 500, 'sort' => 'recipient_action_datetime DESC'],
    ])['values'];

    foreach ($mailings as $index => $mailing) {
      $mailings[$index]['mailing_identifier'] = [
        'display' => (isset($mailing['mailing_identifier.id']) ? '<a href="' . CRM_Utils_System::url(
            'civicrm/mailing/view', 'reset=1&id=' . $mailing['mailing_identifier.id']
          ) . '" class="action-item crm-hover-button" title=' . ts("View Mailing") . '> ' . $mailing['mailing_identifier.name'] . '</a>' : $mailing['mailing_identifier']),
        'name' => (isset($mailing['mailing_identifier.name']) ? $mailing['mailing_identifier.name'] : $mailing['mailing_identifier']),
      ];
      $mailings[$index]['contact_identifier'] = [
        'display' => (isset($mailing['contact_identifier']) ?
          '<a href="https://engage4.silverpop.com/searchRecipient.do?action=edit&listId=9644238&recipientId=' . $mailing['contact_identifier']
          . '" class="action-item crm-hover-button no-popup" title="' . ts("View Contact in Acoustic")
          . '">' . $mailing['contact_identifier'] . '</a>' : ''),
        'name' => $mailing['contact_identifier'],
      ];
    }
    //CRM_Core_Resources::singleton()->ad
    $this->assign('mailings', json_encode($mailings));

    parent::run();
  }

}
