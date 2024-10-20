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

class CRM_Omnimail_OmnimailJobProgress extends CRM_Omnimail_Omnimail {

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
   */
  public function checkStatus(array $params): array {
    /* @var \Omnimail\Silverpop\Mailer $mailer */
    $mailer = Omnimail::create($params['mail_provider'], CRM_Omnimail_Helper::getCredentials($params));
    $request = $mailer->getJobStatus([
      'job_id' => $params['job_id'],
    ]);
    /* @var \Omnimail\Silverpop\Responses\JobStatusResponse $reponse */
    $response = $request->getResponse();
    return ['is_complete' => $response->isCompleted()];
  }

}
