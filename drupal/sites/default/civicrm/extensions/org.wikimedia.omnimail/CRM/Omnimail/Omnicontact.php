<?php

use Civi\Api4\Activity;
use Civi\Api4\Email;
use Civi\Api4\Group;
use Omnimail\Omnimail;
use Omnimail\Silverpop\Responses\Contact;

/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton@wikimedia.org
 */

class CRM_Omnimail_Omnicontact extends CRM_Omnimail_Omnimail{

  /**
   * @var
   */
  protected $request;

  /**
   * Create a contact with an optional group (list) membership in Acoustic DB.
   *
   * @param array $params
   *
   * @return array
   * @throws \API_Exception
   */
  public function create(array $params): array {
    /* @var \Omnimail\Silverpop\Mailer $mailer */
    $mailer = Omnimail::create($params['mail_provider'], CRM_Omnimail_Helper::getCredentials($params));
    $groupIdentifier = (array) Group::get($params['check_permissions'])->addWhere('id', 'IN', $params['group_id'])->addSelect('Group_Metadata.remote_group_identifier')->execute()->indexBy('Group_Metadata.remote_group_identifier');
    $email = $params['email'];
    $snoozeEndDate = $params['snooze_end_date'];
    $request = $mailer->addContact([
      'groupIdentifier' => array_keys($groupIdentifier),
      'email' => $email,
      'databaseID' => $params['database_id'],
      'fields' => $this->mapFields($params['values']),
      'snoozeTimeStamp' => empty($snoozeEndDate) ? NULL : strtotime($snoozeEndDate),
    ]);
    /* @var Contact $reponse */
    $response = $request->getResponse();
    $activityDetail = "Email $email was successfully snoozed till $snoozeEndDate";
				$activity_id = $params['values']['activity_id'] ?? NULL;
    if (!empty($activity_id)) {
      Activity::update(FALSE)
        ->addValue('status_id:name', 'Completed')
        ->addValue('subject', "Email snoozed")
        ->addValue('details', $activityDetail)
        ->addWhere('id', '=',$activity_id)
        ->execute();
    } else {
      // When the contact is snoozed in the process of creation
      $contact = \Civi\Api4\Contact::get(FALSE)->addWhere('email_primary.email', '=', $email)->addSelect('id')->execute()->first();
      if (!empty($contact)) {
        $contact_id = $contact['id'];
        Activity::create(FALSE)
          ->addValue('activity_type_id:name', 'EmailSnoozed')
          ->addValue('status_id:name', 'Completed')
          ->addValue('subject', "Email snoozed")
          ->addValue('details', $activityDetail)
          ->addValue('source_contact_id', $contact_id)
          ->addValue('source_record_id', $contact_id)
          ->addValue('activity_date_time', 'now')
          ->execute()
          ->first();
      }
    }

    return [
      'contact_identifier' => $response->getContactIdentifier(),
    ];
  }

  /**
   * Map CiviCRM field names to Acoustic.
   */
  protected function mapFields($values): array {
    $fields = [];
    $mapping = Civi::settings()->get('omnimail_field_mapping');
    foreach ($values as $key => $value) {
      if (isset($mapping[$key])) {
        $fields[$mapping[$key]] = $value;
      }
    }
    return $fields;
  }

  /**
   * @param array $params
   *
   * @return array
   * @throws \API_Exception
   */
  public function get(array $params) {
    /* @var \Omnimail\Silverpop\Mailer $mailer */
    $mailer = Omnimail::create($params['mail_provider'], CRM_Omnimail_Helper::getCredentials($params));
    if (empty($params['email'])) {
      $params['email'] = Email::get()
        ->addWhere('contact_id', '=', $params['contact_id'])
        ->addWhere('is_primary', '=', TRUE)
        ->addSelect('email')->execute()->first()['email'];
    }
    if (empty($params['email'])) {
      throw new API_Exception('Valid Contact ID or email not provided');
    }
    /* @var \Omnimail\Silverpop\Requests\SelectRecipientData $request */
    $request = $mailer->getContact([
      'groupIdentifier' => $params['group_identifier'],
      'email' => $params['email'],
      'databaseID' => $params['database_id'],
    ]);
    /* @var \Omnimail\Silverpop\Responses\Contact $reponse */
    try {
      $response = $request->getResponse();
      $return = [
        'email' => $response->getEmail(),
        'groups' => $this->getReturnGroups($response, $params['check_permissions']),
        'contact_identifier' => $response->getContactIdentifier(),
        'opt_in_date' => $response->getOptInIsoDateTime() ?: NULL,
        'opt_out_date' => $response->getOptOutIsoDateTime() ?: NULL,
        'snooze_end_date' => $response->getSnoozeEndIsoDateTime() ?: NULL,
        'last_modified_date' => $response->getLastModifiedIsoDateTime()?: NULL,
        'url' => 'https://cloud.goacoustic.com/campaign-automation/Data/Databases?cuiOverrideSrc=https%253A%252F%252Fcampaign-us-4.goacoustic.com%252FsearchRecipient.do%253FisShellUser%253D1%2526action%253Dedit%2526listId%253D9644238%2526recipientId%253D' . $response->getContactIdentifier() . '&listId=' . $params['database_id'],
      ];
      return array_merge($return, $response->getFields());
    }
    catch (Exception $e) {
      throw new API_Exception($e->getMessage());
    }

  }

  /**
   * @param \Omnimail\Silverpop\Responses\Contact $response
   * @param array $return
   *
   * @return array
   * @throws \API_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getReturnGroups(Contact $response, $checkPermissions): array {
    $groups = $response->getGroupIdentifiers();
    $return = [];
    if (!empty($groups)) {
      $groups = Group::get($checkPermissions)
        ->addWhere('Group_Metadata.remote_group_identifier', 'IN', $groups)
        ->addSelect('id', 'title', 'Group_Metadata.remote_group_identifier')
        ->execute();
      foreach ($groups as $group) {
        $group['group_identifier'] = $group['Group_Metadata.remote_group_identifier'];
        $group['url'] = 'https://engage4.silverpop.com/lists.do?action=listSummary&listId=' . $group['group_identifier'];
        unset($group['Group_Metadata.remote_group_identifier']);
        $return[$group['id']] = $group;
      }
    }
    return $return;
  }

}
