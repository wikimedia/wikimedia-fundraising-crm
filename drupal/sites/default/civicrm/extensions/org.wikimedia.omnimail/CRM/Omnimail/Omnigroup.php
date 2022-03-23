<?php

use Civi\Api4\Group;
use Omnimail\Omnimail;
use Omnimail\Silverpop\Responses\Contact;

/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton@wikimedia.org
 */

class CRM_Omnimail_Omnigroup extends CRM_Omnimail_Omnimail{

  /**
   * @var
   */
  protected $request;

  /**
   * @var string
   */
  protected $job = 'omnigroup';

  /**
   * Create a group (list) in Acoustic DB.
   *
   * @param array $params
   *
   * @return array
   * @throws \API_Exception
   */
  public function create(array $params): array {
    /* @var \Omnimail\Silverpop\Mailer $mailer */
    $mailer = Omnimail::create($params['mail_provider'], CRM_Omnimail_Helper::getCredentials($params));
    /* @var \Omnimail\Silverpop\Requests\CreateContactListRequest $request */
    $groupName = Group::get($params['check_permissions'])->addWhere('id', '=', $params['group_id'])->addSelect('name')->execute()->first()['name'];
    if (!$groupName) {
      throw new API_Exception('invalid group ID');
    }
    $request = $mailer->createGroup([
      'name' => $groupName,
      'databaseID' => $params['database_id'],
      'visibility' => $params['visibility'],
    ]);
    $response = $request->getResponse();
    return [
      'list_id' => $response->getListID(),
      'parent_id' => $response->getParentListID(),
      'name' => $response->getName(),
    ];
  }

}
