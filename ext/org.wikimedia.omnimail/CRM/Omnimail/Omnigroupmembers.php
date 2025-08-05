<?php

use Omnimail\Silverpop\Responses\RecipientsResponse;
use Omnimail\Omnimail;
use Omnimail\Silverpop\Responses\Contact;

/**
 * Created by IntelliJ IDEA.
 * User: emcnaughton@wikimedia.org
 * Date: 5/16/17
 * Time: 5:53 PM
 */

class CRM_Omnimail_Omnigroupmembers extends CRM_Omnimail_Omnimail{

  /**
   * @var
   */
  protected $request;

  /**
   * Mapping of Acoustic fields to our fields.
   *
   * @var array|string[]
   */
  protected array $customDataMap = [
    'preferred_language' => 'rml_language',
    'source' => 'rml_source',
    'created_date' => 'rml_submitDate',
    'country' => 'rml_country',
    'phone' => 'mobile_phone',
  ];

  /**
   * @var string
   */
  protected string $job = 'omnigroupmembers';

  /**
   * @param array $params
   *
   * @return \Omnimail\Silverpop\Responses\GroupMembersResponse
   *
   * @throws \CRM_Omnimail_IncompleteDownloadException
   * @throws \CRM_Core_Exception
   * @throws \League\Csv\Exception
   */
  public function getResult($params) {
    $settings = CRM_Omnimail_Helper::getSettings();

    $mailerCredentials = CRM_Omnimail_Helper::getCredentials($params);
    $jobParameters = ['timeout' => $params['timeout']];
    if (empty($params['is_suppression_list'])) {
      $jobParameters['exportType'] = 'OPT_IN';
    }

    /* @var \Omnimail\Silverpop\Requests\ExportListRequest $request */
    $request = Omnimail::create($params['mail_provider'], $mailerCredentials)->getGroupMembers($jobParameters);
    $request->setOffset((int) $this->offset);
    if ($this->getLimit($params)) {
      $request->setLimit($this->getLimit($params));
    }

    $startTimestamp = $this->getStartTimestamp($params);
    $this->endTimeStamp = $this->getEndTimestamp(CRM_Utils_Array::value('end_date', $params), $settings, $startTimestamp);

    if ($this->getRetrievalParameters()) {
      $request->setRetrievalParameters($this->getRetrievalParameters());
    }
    elseif ($startTimestamp) {
      $request->setStartTimeStamp($startTimestamp);
      $request->setEndTimeStamp($this->endTimeStamp);
    }
    $request->setGroupIdentifier($params['group_identifier']);
    $request->setColumns($this->getColumns($params['is_suppression_list'] ?? FALSE));

    $result = $request->getResponse();
    $this->setRetrievalParameters($result->getRetrievalParameters());
    for ($i = 0; $i < $settings['omnimail_job_retry_number']; $i++) {
      if ($result->isCompleted()) {
        return $result->getData();
      }
      else {
        sleep((int) $settings['omnimail_job_retry_interval']);
      }
    }

    throw new CRM_Omnimail_IncompleteDownloadException('Download incomplete', 0, [
      'retrieval_parameters' => $this->getRetrievalParameters(),
      'mail_provider' => $params['mail_provider'],
      'end_date' => $this->endTimeStamp,
    ]);

  }

  private function getColumns(bool $isSuppressionList): array {
    $systemFields = [
      'Email',
      'RECIPIENT_ID',
      // These details are all pretty confusing as they show opted in for
      // contacts on the Master suppression list.
      // But these are what we can get - maybe one day we will understand - let's
      // keep them visible.
      'Opt in Date',
      'Opted Out',
      'Opt In Details',
      'Email Type',
      'Opted Out Date',
      'Opt Out Details',
      'Last Modified Date',
    ];
    if ($isSuppressionList) {
      return $systemFields;
    }
    // These seem to be system fields that are not always present.
    $systemFields[] = 'LastSentDate';
    $systemFields[] = 'LastClickDate';
    $systemFields[] = 'LastOpenDate';
    $systemFields[] = 'IsoLang';
    return array_merge($systemFields, ['contactID'], array_values($this->customDataMap));
  }

  private function isMasterSuppressionList(): true {
    return TRUE;
  }

  /**
   * Format the result into the fields we with to return.
   *
   * @param array $params
   * @param \Omnimail\Silverpop\Responses\Contact $result
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function formatResult($params, $result): array {
    $options = _civicrm_api3_get_options_from_params($params);
    $values = [];
    foreach ($result as $row) {
      $groupMember = new Contact($row);
      $groupMember->setContactReferenceField('ContactID');
      $value = $this->formatRow($groupMember);
      $values[] = $value;
      if ($options['limit'] > 0 && count($values) === (int) $options['limit']) {
        // IN theory no longer required as limit is done in library
        break;
      }
    }
    return $values;
  }

  /**
   * Get the requested limit.
   *
   * @param array $params
   *
   * @return int
   */
  public function getLimit($params) {
    return (int) $params['limit'] ?? NULL;
  }

  /**
   * Format a single row of the result.
   *
   * @param Contact $groupMember
   *
   * @return array
   * @throws \CRM_Core_Exception
   */
  public function formatRow($groupMember) {
    $value = [
      'email' => (string) $groupMember->getEmail(),
      'is_opt_out' => (string) $groupMember->isOptOut(),
      'is_opt_in' => !$groupMember->isOptOut() && $groupMember->getOptInIsoDateTime(),
      'opt_in_date' => (string) $groupMember->getOptInIsoDateTime(),
      'opt_in_source' => (string) $groupMember->getOptInSource(),
      'opt_out_source' => (string) $groupMember->getOptOutSource(),
      'opt_out_date' => (string) $groupMember->getOptOutIsoDateTime(),
      'contact_id' => (string) $groupMember->getContactReference(),
      'recipient_id' => $groupMember->getCustomData('RECIPIENT_ID'),
    ];
    $country = $this->getCountry($groupMember);
    foreach ($this->customDataMap as $fieldName => $dataKey) {
      $value[$fieldName] = (string) $this->transform($fieldName, $groupMember->getCustomData($dataKey), $country);
    }
    return $value;
  }

  private function getCountry($groupMember): string {
    return $groupMember->getCustomData('rml_country') ?? '';
  }

  /**
   * @param string $fieldName
   * @param string $value
   * @param string $country
   *
   * @return string
   * @throws \CRM_Core_Exception
   */
  private function transform(string $fieldName, string $value, string $country): string {
    if ($fieldName === 'preferred_language' && $value) {
      return (string) $this->transformLanguage($value, $country);
    }
    return $value;
  }

  /**
   * Get the contact's language.
   *
   * This is a place in the code where I am struggling to keep wmf-specific coding out
   * of a generic extension. The wmf-way would be to call the wmf contact_insert function.
   *
   * That is not so appropriate from an extension, but we have language/country data that
   * needs some wmf specific handling as it might or might not add up to a legit language.
   *
   * At this stage I'm compromising on containing the handling within the extension,
   * ensuring test covering and splitting out & documenting the path taken /issue.
   * Later maybe a more listener/hook type approach is the go.
   *
   * It's worth noting this is probably the least important part of the omnimail work
   * from wmf POV.
   *
   * @param string $language
   * @param $country
   *
   * @return string|null
   */
  private function transformLanguage(string $language, $country): ?string {
    static $languages = NULL;
    if (!$languages) {
      $languages = array_flip(\CRM_Utils_Array::collect('name', \Civi::entity('Contact')->getOptions('preferred_language')));
    }
    $attempts = [
      $language . '_' . strtoupper($country),
      $language,
    ];
    foreach ($attempts as $attempt) {
      if (isset($languages[$attempt])) {
        return $attempt;
      }
    }
    return NULL;
  }

}
