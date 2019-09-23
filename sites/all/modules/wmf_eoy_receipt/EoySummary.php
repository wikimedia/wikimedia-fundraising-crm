<?php

namespace wmf_eoy_receipt;

use wmf_communication\Mailer;
use wmf_communication\Templating;
use wmf_communication\Translation;

class EoySummary {

  static protected $templates_dir;

  static protected $template_name;

  static protected $option_keys = [
    'year',
    'test',
    'batch',
    'job_id',
  ];

  protected $batch_max = 100;

  protected $year;

  protected $test;

  protected $from_address;

  protected $from_name;

  /**
   * @var string
   */
  protected $civi_prefix = '';

  protected $job_id;

  function __construct($options = []) {
    $this->year = variable_get('wmf_eoy_target_year', NULL);
    $this->batch_max = variable_get('wmf_eoy_batch_max', 100);
    $this->test = variable_get('wmf_eoy_test_mode', TRUE);

    foreach (self::$option_keys as $key) {
      if (array_key_exists($key, $options)) {
        $this->$key = $options[$key];
      }
    }

    $this->from_address = variable_get('thank_you_from_address', NULL);
    $this->from_name = variable_get('thank_you_from_name', NULL);
    if (!$this->from_address || !$this->from_name) {
      throw new \Exception("Must configure a valid return address in the Thank-you module");
    }

    // FIXME: this is not required on the production configuration.
    // However, it will require code changes if the databases are
    // actually hosted on separate servers.  You will need to specify
    // the database name: 'wmf_civi.' if you are isolating for dev.
    $this->civi_prefix = '';

    self::$templates_dir = __DIR__ . '/templates';
    self::$template_name = 'eoy_thank_you';
  }

  //FIXME rename
  function calculate_year_totals() {
    $job_timestamp = date("YmdHis");
    $year_start = "{$this->year}-01-01 00:00:01";
    $year_end = "{$this->year}-12-31 23:59:59";

    $select_query = <<<EOS
SELECT
    {$this->job_id} AS job_id,
    COALESCE( billing_email.email, primary_email.email ) AS email,
    contact.first_name,
    contact.preferred_language,
    'queued',
    GROUP_CONCAT( CONCAT(
        DATE_FORMAT( contribution.receive_date, '%%Y-%%m-%%d' ),
        ' ',
        contribution.total_amount,
        ' ',
        contribution.currency
    ) )
FROM {$this->civi_prefix}civicrm_contribution contribution
LEFT JOIN {$this->civi_prefix}civicrm_email billing_email
    ON billing_email.contact_id = contribution.contact_id AND billing_email.is_billing
LEFT JOIN {$this->civi_prefix}civicrm_email primary_email
    ON primary_email.contact_id = contribution.contact_id AND primary_email.is_primary
JOIN {$this->civi_prefix}civicrm_contact contact
    ON contribution.contact_id = contact.id
WHERE
    receive_date BETWEEN '{$year_start}' AND '{$year_end}'
GROUP BY
    email
EOS;

    db_insert('wmf_eoy_receipt_job')->fields([
      'start_time' => $job_timestamp,
      'year' => $this->year,
    ])->execute();

    $sql = <<<EOS
SELECT job_id FROM {wmf_eoy_receipt_job}
    WHERE start_time = :start
EOS;
    $result = db_query($sql, [':start' => $job_timestamp]);
    $row = $result->fetch();
    $this->job_id = $row->job_id;

    $sql = <<<EOS
INSERT INTO {wmf_eoy_receipt_donor}
  ( job_id, email, name, preferred_language, status, contributions_rollup )
  {$select_query}
EOS;
    $result = db_query($sql);

    $num_rows = $result->rowCount();
    watchdog('wmf_eoy_receipt',
      t("Compiled summaries for !num donors giving during !year",
        [
          "!num" => $num_rows,
          "!year" => $this->year,
        ]
      )
    );
  }

  function send_letters() {
    $mailer = Mailer::getDefault();

    $sql = <<<EOS
SELECT *
FROM {wmf_eoy_receipt_donor}
WHERE
    status = 'queued'
    AND job_id = :id
LIMIT {$this->batch_max}
EOS;
    $result = db_query($sql, [':id' => $this->job_id]);
    $succeeded = 0;
    $failed = 0;

    foreach ($result as $row) {
      $email = $this->render_letter($row);

      if ($this->test) {
        $email['to_address'] = variable_get('wmf_eoy_test_email', NULL);
      }

      $success = $mailer->send($email);

      if ($success) {
        $status = 'sent';
        $succeeded += 1;
      }
      else {
        $status = 'failed';
        $failed += 1;
      }

      db_update('wmf_eoy_receipt_donor')->fields([
        'status' => $status,
      ])->condition('email', $row->email)->execute();
    }

    watchdog('wmf_eoy_receipt',
      t("Successfully sent !succeeded messages, failed to send !failed messages.",
        [
          "!succeeded" => $succeeded,
          "!failed" => $failed,
        ]
      )
    );
  }

  function render_letter($row) {
    $language = Translation::normalize_language_code($row->preferred_language);
    $totals = [];
    $contributions = [];
    foreach ($row->contributions_rollup as $contribution_string) {
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
    usort($contributions, function($c1, $c2) {
      return $c1['date'] <=> $c2['date'];
    });

    $template_params = [
      'name' => $row->name,
      'contributions' => $contributions,
      'totals' => $totals,
      'year' => $this->year,
    ];
    $template = $this->get_template($language, $template_params);
    $email = [
      'from_name' => $this->from_name,
      'from_address' => $this->from_address,
      'to_name' => $row->name,
      'to_address' => $row->email,
      'subject' => $template->render('subject'),
      'plaintext' => $template->render('txt'),
      'html' => $template->render('html'),
    ];

    return $email;
  }

  function get_template($language, $template_params) {
    return new Templating(
      self::$templates_dir,
      self::$template_name,
      $language,
      $template_params
    );
  }
}
