<?php

use Omnimail\Omnimail;
use Omnimail\Silverpop\Responses\WebUser;

/**
 * Tracking for
 */
class CRM_Omnimail_Omniactivity extends CRM_Omnimail_Omnimail {

  /**
   * @var string
   */
  protected string $job = 'omniactivity';

  /**
   * @param array $params
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   * @throws \CRM_Omnimail_IncompleteDownloadException
   * @throws \League\Csv\Exception
   * @throws \SilverpopConnector\SilverpopConnectorException
   */
  public function getResult(array $params): array {
    $this->job .= implode('', $params['type']);
    if ($params['limit'] > 0) {
      $this->limit = (int) $params['limit'];
    }
    $settings = CRM_Omnimail_Helper::getSettings();

    $mailerCredentials = CRM_Omnimail_Helper::getCredentials($params);

    /** @var \Omnimail\Silverpop\Requests\WebTrackingDataExportRequest $request */
    $request = Omnimail::create($params['mail_provider'], $mailerCredentials)->getWebActions();
    $request->setOffset($this->offset);
    $actions = $sites = [];
    if (in_array('remind_me_later', $params['type'])) {
      $actions[] = 'INCLUDE_FORM_SUBMIT_EVENTS';
      $sites[] += $this->getRemindMeLaterSiteID();
    }

    if (in_array('snooze', $params['type']) || in_array('opt_out', $params['type']) || in_array('unsubscribe', $params['type'])) {
      $actions[] = 'INCLUDE_FORM_SUBMIT_EVENTS';
      $sites[] += $this->getUnsubscribeSiteID();
    }
    // Actions are one or more of the include flags - if not provided all actions will be retrieved.
    // @see https://developer.goacoustic.com/acoustic-campaign/reference/webtrackingdataexport
    $request->setActions($actions);
    $request->setSites($sites);

    $startTimestamp = $this->getStartTimestamp($params);
    $this->endTimeStamp = $this->getEndTimestamp($params['end_date'] ?? NULL, $settings, $startTimestamp);

    if ($this->getRetrievalParameters()) {
      $request->setRetrievalParameters($this->getRetrievalParameters());
    }
    elseif ($startTimestamp) {
      if ($this->endTimeStamp < $startTimestamp) {
        throw new CRM_Core_Exception(ts("End timestamp: " . date('Y-m-d H:i:s', $this->endTimeStamp) . " is before " . "Start timestamp: " . date('Y-m-d H:i:s', $startTimestamp)));
      }
      $request->setStartTimeStamp($startTimestamp);
      $request->setEndTimeStamp($this->endTimeStamp);
    }

    $result = $request->getResponse();
    $this->setRetrievalParameters($result->getRetrievalParameters());
    for ($i = 0; $i < $settings['omnimail_job_retry_number']; $i++) {
      if ($result->isCompleted()) {
        $data = $result->getData();
        $rows = [];
        foreach ($data as $row) {
          $recipient = new WebUser($row);
          // Skip - confirmation activities -this is just telling us that after submitting the form
          // a confirmation page was presented.
          if ((string) $recipient->getRecipientActionName() === 'Confirmation'
            // The unsubscribe form action seems to always happen in conjunction
            // with an opt out or snooze action so skip the 'Form' actions (maybe, probably).
            // Note there seem to be 2 types of form actions too....
            || (($recipient->getRecipientActionName() === 'Form' || $recipient->getRecipientAction() === 'form')
              && str_starts_with($recipient->getRecipientActionUrlName(), 'WMF Unsubscribe'))
          ) {
            $this->skippedRows++;
            continue;
          }
          $rows[] = [
            'contact_identifier' => (string) $recipient->getContactIdentifier(),
            'mailing_identifier' => (string) ($params['mailing_prefix'] ?? '') . $recipient->getMailingIdentifier(),
            'email' => (string) $recipient->getEmail(),
            'event_type' => (string) $recipient->getRecipientAction(),
            'event_name' => (string) $recipient->getRecipientActionName(),
            'recipient_action_datetime' => (string) $recipient->getRecipientActionIsoDateTime(),
            'contact_id' => (string) $recipient->getContactReference(),
            'action_url' => (string) $recipient->getRecipientActionUrl(),
            'action_url_identifier' => (string) $recipient->getRecipientActionUrlIdentifier(),
            'referrer_type' => (string) $recipient->getRecipientReferrerType(),
            'referrer_url' => (string) $recipient->getRecipientReferrerUrl(),
            'mailing_name' => (string) $recipient->getMailingName(),
            'action_url_name' => (string) $recipient->getRecipientActionUrlName(),
            'activity_type' => $this->getActivityType($recipient),
            'subject' => $this->getSubject($recipient),
          ];
          if ($this->limit > 0 && count($rows) === (int) $this->limit) {
            break;
          }
        }
        return $rows;
      }
      else {
        sleep($settings['omnimail_job_retry_interval']);
      }
    }
    throw new CRM_Omnimail_IncompleteDownloadException('Download incomplete', 0, [
      'retrieval_parameters' => $this->getRetrievalParameters(),
      'mail_provider' => $params['mail_provider'],
      'end_date' => $this->endTimeStamp,
    ]);

  }

  /**
   * @param \Omnimail\Silverpop\Responses\WebUser $recipient
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  private function getActivityType(WebUser $recipient): string {
    if ($recipient->getRecipientAction() === 'snooze') {
      return 'EmailSnoozed';
    }
    if ($recipient->getRecipientAction() === 'OPT_OUT'
    ) {
      return 'unsubscribe';
    }
    if ($recipient->getRecipientActionUrlName() === 'Remind Me Later'
    || $recipient->getRecipientActionName() === 'RML - Phone') {
      return 'remind_me_later';
    }
    $this->throwException('omni-mystery', $recipient);
  }

  /**
   * @param \Omnimail\Silverpop\Responses\WebUser $recipient
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  private function getSubject(WebUser $recipient): string {
    $method = $this->getReferralMethod($recipient);
    if ($this->getActivityType($recipient) === 'remind_me_later') {
      return 'Remind me later via ' . $method;
    }
    if ($this->getActivityType($recipient) === 'unsubscribe') {
      return 'Unsubscribed via ' . $method;
    }
    if ($this->getActivityType($recipient) === 'EmailSnoozed') {
      return 'Snoozed via ' . $method;
    }
    $this->throwException('unidentified flying object', $recipient);
  }

  /**
   * Get the site ID for remind me later - this might be moved to config later.
   *
   * The site ID appears to be directly tied to a url - here
   * 440438 seems to be http://www.pages04.net/wikimedia/remind
   *
   * @return array
   */
  private function getRemindMeLaterSiteID(): array {
    return [440438];
  }

  /**
   * Get the site ID for remind me later - this might be moved to config later.
   *
   * The site ID appears to be directly tied to a url - here
   * 643318 seems to be http://lp.email.wikimedia.org/wiki
   * 319898 seems to be https://www.pages04.net/wikimedia/WMFUnsubscribe
   *
   * @return array
   */
  private function getUnsubscribeSiteID(): array {
    return [643318, 319898];
  }

  /**
   * @param \Omnimail\Silverpop\Responses\WebUser $recipient
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  public function getReferralMethod(WebUser $recipient): string {
    $method = 'Acoustic';
    $referrer_type = $recipient->getRecipientReferrerType();
    if ($referrer_type === 'url') {
      $method = 'web link to Acoustic form';
    }
    if ($referrer_type === 'direct') {
      if ($recipient->getRecipientActionName() === 'RML - Phone') {
        return 'SMS';
      }
      if ($this->getActivityType($recipient) === 'remind_me_later') {
        return 'Remind me later form';
      }
      $method = 'Email client or Donor Relations';
    }
    if ($referrer_type == 'mailing') {
      return 'Acoustic Email link';
    }
    return $method;
  }

  /**
   * @param string $message
   * @param \Omnimail\Silverpop\Responses\WebUser $recipient
   *
   * @throws \CRM_Core_Exception
   */
  public function throwException(string $message, WebUser $recipient) {
    throw new CRM_Core_Exception($message
      . ' : action ' . $recipient->getRecipientAction() . ' ' . $recipient->getRecipientActionName()
      . ' date ' . $recipient->getRecipientActionIsoDateTime()
      . ' recipient ' . $recipient->getContactReference()
      . ' referrer ' . $recipient->getRecipientReferrerType()
    );
  }

}
