<?php

class CRM_Omnimail_Page_MailingsView extends CRM_Core_Page {

  public function run() {
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    CRM_Utils_System::setTitle(ts('MailingsView'));
    $contactID = CRM_Utils_Request::retrieve('cid', 'Integer');
    $this->assign('remoteDataURL', '/civicrm/a/#/omnimail/remote-contact?cid=' . $contactID);

    $mailings = civicrm_api3('MailingProviderData', 'get', [
      'contact_id' => $contactID,
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
          '<a href="https://cloud.goacoustic.com/campaign-automation/Data/Databases?cuiOverrideSrc=https%253A%252F%252Fcampaign-us-4.goacoustic.com%252FsearchRecipient.do%253FisShellUser%253D1%2526action%253Dedit%2526listId%253D9644238%2526recipientId%253D' . $mailing['contact_identifier']
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
