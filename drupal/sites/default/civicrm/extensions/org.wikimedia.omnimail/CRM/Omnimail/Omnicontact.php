<?php

use Civi\Api4\Email;
use Civi\Api4\Group;
use Omnimail\Silverpop\Responses\RecipientsResponse;
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
        'url' => 'https://engage4.silverpop.com/searchRecipient.do?action=edit&recipientId=' . $response->getContactIdentifier() . '&listId=' . $params['database_id'],
      ];
      return array_merge($return, $response->getFields());
    }
    catch (Exception $e) {
      return ['message' => $e->getMessage()];
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
