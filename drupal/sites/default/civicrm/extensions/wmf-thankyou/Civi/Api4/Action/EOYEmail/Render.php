<?php


namespace Civi\Api4\Action\EOYEmail;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use wmf_communication\Translation;
use Civi\EoySummary;
use wmf_communication\Templating;

/**
 * Class Render.
 *
 * Get the content of the failure email for the specified contributionRecur ID.
 *
 * @method int getContactID() Get the contact id.
 * @method $this setContactID(int $contactID) Set contact ID.
 * @method int getYear() Get the year
 * @method $this setYear(int $year) Set the year
 * @method int getLimit() Get the limit
 * @method $this setLimit(int $limit) Set the limit
 * @method int getJobID() Get the job ID.
 * @method $this setJobID(int $limit) Set job ID.
 */
class Render extends AbstractAction {

  /**
   * Contact ID.
   *
   * Optional, if provided not only recurring emails will be included.
   *
   * @var int
   */
  protected $contactID;

  /**
   * Year.
   *
   * Required.
   *
   * @var int
   */
  protected $year;

  /**
   * Limit.
   *
   * Currently 1 is the only possible number as contact
   * id is required.
   *
   * @var int
   */
  protected $limit = 100;

  /**
   * Required if contact ID is not present.
   *
   * @var int
   */
  protected $jobID;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result) {
    if ($this->getContactID()) {
      $donors = new EoySummary([
        'year' => $this->getYear(),
        'batch' => $this->getLimit(),
        'contact_id' => $this->getContactID(),
      ]);
      $this->setLimit(1);
      $this->setJobID($donors->calculate_year_totals());
    }
    if (!$this->jobID) {
      throw new \API_Exception('Job ID is required if contact ID not present');
    }

    $row = \CRM_Core_DAO::executeQuery("
      SELECT *
      FROM wmf_eoy_receipt_donor
      WHERE
      status = 'queued'
      AND job_id = %1
      LIMIT %2", [1 => [$this->getJobID(), 'Integer'], 2 => [$this->getLimit(), 'Integer']]);
    while ($row->fetch()) {
      $result[$this->getContactID()] = $this->render_letter($row);
    }
  }

  protected function render_letter($row) {
    $contactIds = $this->getContactIdsForEmail($row->email);
    $activeRecurring = $this->doContactsHaveActiveRecurring($contactIds);
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
      'to_name' => $row->name,
      'to_address' => $row->email,
      'subject' => trim($template->render('subject')),
      'html' => str_replace('<p></p>', '', $template->render('html')),
    ];

    return $email;
  }

  protected function get_template($language, $template_params) {
    return new Templating(
      __DIR__ . '/../../../../../../../../all/modules/wmf_eoy_receipt/templates',
      'eoy_thank_you',
      $language,
      $template_params + ['language' => $language]
    );
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
