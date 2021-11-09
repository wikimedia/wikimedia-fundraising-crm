<?php

namespace wmf_eoy_receipt;

use Civi\Api4\EOYEmail;
use CRM_Contribute_PseudoConstant;
use CRM_Core_PseudoConstant;
use wmf_communication\Mailer;
use wmf_communication\Templating;
use wmf_communication\Translation;
use Civi\Omnimail\MailFactory;

class EoySummary {

  static protected $templates_dir;

  static protected $template_name;

  static protected $option_keys = [
    'year',
    'contact_id',
    'batch',
    'job_id',
  ];

  protected $batch = 100;

  protected $year;

  protected $contact_id;

  protected $from_address;

  protected $from_name;

  /**
   * Temporary tables.
   *
   * These are instances of CRM_Utils_SQL_TempTable and are dropped when the object deconstructs.
   *
   * @var \CRM_Utils_SQL_TempTable[]
   */
  protected $temporaryTables = [];

  protected $job_id;

  /**
   * EoySummary constructor.
   *
   * @param array $options
   *
   * @throws \CRM_Core_Exception
   */
  public function __construct($options = []) {
    $this->year = variable_get('wmf_eoy_target_year', NULL);
    $this->batch = variable_get('wmf_eoy_batch', 100);

    foreach (self::$option_keys as $key) {
      if (array_key_exists($key, $options)) {
        $this->$key = $options[$key];
      }
    }

    $this->from_address = variable_get('wmf_eoy_from_address', NULL);
    $this->from_name = variable_get('wmf_eoy_from_name', NULL);

    self::$templates_dir = __DIR__ . '/templates';
    self::$template_name = 'eoy_thank_you';
  }

  /**
   * FIXME remove in favour of calling makeJob directly.
   *
   * @return int|false the job ID for use in scheduling email sends
   * @throws \Exception
   */
  public function calculate_year_totals() {
    $job = EOYEmail::makeJob(FALSE)
      ->setYear($this->year);
    if ($this->contact_id) {
      $job->setContactID($this->contact_id);
    }
    $this->job_id = $job->execute()->first()['job_id'];
    return $this->job_id;
  }

  public function send_letters() {
    $mailer = MailFactory::singleton();
    $row = \CRM_Core_DAO::executeQuery("
      SELECT *
      FROM wmf_eoy_receipt_donor
      WHERE
      status = 'queued'
      AND job_id = %1
LIMIT " . (int) $this->batch, [1 => [$this->job_id, 'Integer']]);
    $succeeded = 0;
    $failed = 0;

    while($row->fetch()) {
      $contactIds = $this->getContactIdsForEmail($row->email);
      $hasActiveRecurring = $this->doContactsHaveActiveRecurring($contactIds);
      $email = $this->render_letter($row, $hasActiveRecurring);

      try {
        $success = $mailer->send($email, []);
      }
      // Should be just phpMailer exception but weird normalizeConten throws WMFException
      catch (\Exception $e) {
        // Invalid email address or something
        \Civi::log('wmf')->info('wmf_eoy_receipt send error ' . $e->getMessage());
        $success = FALSE;
      }

      if ($success) {
        $this->record_activities($email, $contactIds);
        $status = 'sent';
        $succeeded += 1;
      }
      else {
        $status = 'failed';
        $failed += 1;
      }

      \CRM_Core_DAO::executeQuery('UPDATE wmf_eoy_receipt_donor SET status = %1 WHERE email = %2', [
        1 => [$status, 'String'],
        2 => [$row->email, 'String'],
      ]);
    }

    \Civi::log('wmf')->info('wmf_eoy_receipt Successfully sent {succeeded} messages, failed to send {failed} messages.', [
      'succeeded' => $succeeded,
      'failed' => $failed,
    ]);
  }

  public function render_letter($row, $activeRecurring) {
    if (!$this->from_address || !$this->from_name) {
      throw new \Exception('Must configure a valid return address in the Thank-you module');
    }
    $language = Translation::normalize_language_code($row->preferred_language);
    $totals = [];
    $contributions = [];
    foreach (explode(',', $row->contributions_rollup) as $contribution_string) {
      $terms = explode(' ', $contribution_string);
      $contribution = [
        'date' => $terms[0],
        // FIXME not every currency uses 2 sig digs
        'amount' => round($terms[1], 2),
        'currency' => $terms[2],
      ];
      $contributions[] = $contribution;
      if (!isset($totals[$contribution['currency']])) {
        $totals[$contribution['currency']] = [
          'amount' => 0.0,
          'currency' => $contribution['currency'],
        ];
      }
      $totals[$contribution['currency']]['amount'] += $contribution['amount'];
    }
    // Sort contributions by date
    usort($contributions, function ($c1, $c2) {
      return $c1['date'] <=> $c2['date'];
    });
    foreach ($contributions as $index => $contribution) {
      $contributions[$index]['index'] = $index + 1;
    }

    $template_params = [
      'name' => $row->name,
      'contributions' => $contributions,
      'totals' => $totals,
      'year' => $this->year,
      'active_recurring' => $activeRecurring
    ];
    $template = $this->get_template($language, $template_params);
    $email = [
      'from_name' => $this->from_name,
      'from_address' => $this->from_address,
      'to_name' => $row->name,
      'to_address' => $row->email,
      'subject' => trim($template->render('subject')),
      'html' => str_replace('<p></p>', '', $template->render('html')),
    ];

    return $email;
  }

  protected function get_template($language, $template_params) {
    return new Templating(
      self::$templates_dir,
      self::$template_name,
      $language,
      $template_params + ['language' => $language]
    );
  }

  protected function record_activities(array $email, array $contactIds) {
    foreach ($contactIds as $contactId) {
      civicrm_api3('Activity', 'create', [
        'activity_type_id' => 'wmf_eoy_receipt_sent',
        'source_contact_id' => $contactId,
        'target_contact_id' => $contactId,
        'assignee_contact_id' => $contactId,
        'subject' => "Sent contribution summary receipt for year $this->year to {$email['to_address']}",
        'details' => $email['html'],
      ]);
    }
  }

  /**
   * Get contact IDs associated with an email address
   *
   * @param string $email
   *
   * @return int[] IDs of non-deleted contacts with that email
   * @throws \CiviCRM_API3_Exception
   */
  protected function getContactIdsForEmail($email): array {
    $contactIds = [];
    $emailRecords = civicrm_api3('Email', 'get', [
      'email' => $email,
      'is_primary' => TRUE,
      'contact_id.is_deleted' => FALSE,
      'return' => 'contact_id',
    ]);
    foreach ($emailRecords['values'] as $emailRecord) {
      $contactIds[] = $emailRecord['contact_id'];
    }
    return $contactIds;
  }

  /**
   * Determine whether any of an array of contact IDs have an active recurring
   * donation associated.
   *
   * @param array $contactIds
   *
   * @return bool
   * @throws \CiviCRM_API3_Exception
   */
  protected function doContactsHaveActiveRecurring(array $contactIds) {
    if (empty($contactIds)) {
      return FALSE;
    }
    $recurringCount = civicrm_api3('ContributionRecur', 'getCount', [
      'contact_id' => [
        'IN' => $contactIds
      ],
      'contribution_status_id' => [
        'IN' => ['Completed', 'Pending', 'In Progress']
      ],
    ]);
    return $recurringCount > 0;
  }
}
