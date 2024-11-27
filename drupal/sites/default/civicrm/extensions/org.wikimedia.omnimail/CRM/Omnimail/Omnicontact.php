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
   * @throws \CRM_Core_Exception
   */
  public function create(array $params): array {
    try {
      /* @var \Omnimail\Silverpop\Mailer $mailer */
      $mailer = Omnimail::create($params['mail_provider'], CRM_Omnimail_Helper::getCredentials($params));
      $groupIdentifier = (array) Group::get($params['check_permissions'])
        ->addWhere('id', 'IN', $params['group_id'])
        ->addSelect('Group_Metadata.remote_group_identifier')
        ->execute()
        ->indexBy('Group_Metadata.remote_group_identifier');
      $email = $params['email'];
      $snoozeEndDate = $params['snooze_end_date'];
      $request = $mailer->addContact([
        'groupIdentifier' => array_keys($groupIdentifier),
        'email' => $email,
        'databaseID' => $params['database_id'],
        'fields' => $this->mapFields($params['values']),
        'snoozeTimeStamp' => empty($snoozeEndDate) ? NULL : strtotime($snoozeEndDate),
        'syncFields' => ['Email' => $email],
      ]);
      /* @var Contact $reponse */
      $response = $request->getResponse();
      $activityDetail = "Email $email was successfully snoozed till $snoozeEndDate";
      $activity_id = $params['values']['activity_id'] ?? NULL;
      if ($activity_id) {
        Activity::update(FALSE)
          ->addValue('status_id:name', 'Completed')
          ->addValue('subject', "Email snoozed")
          ->addValue('details', $activityDetail)
          ->addWhere('id', '=', $activity_id)
          ->execute();
      }

      return [
        'contact_identifier' => $response->getContactIdentifier(),
      ];
    }
    catch (Exception $e) {
      Civi::log('omnimail')->error('Contact update failed {message}
{exception}',

      [
        'message' => $e->getMessage(),
        'exception' => $e,
      ]);
      throw $e;
    }
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

  public function upload(array $params) {
    /* @var \Omnimail\Silverpop\Mailer $mailer */
    $mailer = Omnimail::create($params['mail_provider'], CRM_Omnimail_Helper::getCredentials($params));
    $request = $mailer->importList([
      'xmlFile' => $params['mapping_file'],
      'csvFile' => $params['csv_file'],
      'isAlreadyUploaded' => $params['is_already_uploaded'] ?? FALSE,
    ]);
    /* @var \Omnimail\Silverpop\Responses\ImportListResponse $reponse */
    return $request->getResponse();
  }

  /**
   * @param array $params
   *
   * @return array
   * @throws \CRM_Core_Exception
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
      throw new CRM_Core_Exception('Valid Contact ID or email not provided');
    }
    /* @var \Omnimail\Silverpop\Requests\SelectRecipientData $request */
    $request = $mailer->getContact([
      'groupIdentifier' => $params['group_identifier'],
      'email' => $params['email'],
      'databaseID' => $params['database_id'],
      'syncFields' => ['Email' => $params['email']],
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
      $return = array_merge($return, $response->getFields());
      if (!empty($return['mobile_phone'])) {
        /* @var \Omnimail\Silverpop\Responses\Consent $consent */
        $consent = $mailer->consentInformationRequest([
          'database_id' => $params['database_id'],
          'short_code' => $this->getShortCode(),
          'phone' => $return['mobile_phone'],
        ])->getResponse();
        $return['sms_consent_status'] = $consent->getStatus();
        $return['sms_consent_source'] = $consent->getSource();
        $return['sms_consent_timestamp'] = $consent->getTimestamp();
        $return['sms_consent_datetime'] = date('Y-m-d H:i:s', $consent->getTimestamp());
      }
      else {
        $return['sms_consent_status'] = NULL;
        $return['sms_consent_source'] = NULL;
        $return['sms_consent_timestamp'] = NULL;
        $return['sms_consent_datetime'] = NULL;
      }
      return $return;
    }
    catch (Exception $e) {
      throw new CRM_Core_Exception($e->getMessage());
    }

  }

  /**
   * @param \Omnimail\Silverpop\Responses\Contact $response
   * @param array $return
   *
   * @return array
   * @throws \CRM_Core_Exception
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

  /**
   * @return int
   */
  public function getShortCode(): int {
    return \Civi::settings()->get('omnimail_sms_short_code');
  }

}
