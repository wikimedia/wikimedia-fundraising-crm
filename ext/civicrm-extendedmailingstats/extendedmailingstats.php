<?php

require_once 'extendedmailingstats.civix.php';

/**
 * Implementation of hook_civicrm_config
 */
function extendedmailingstats_civicrm_config(&$config) {
  _extendedmailingstats_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install
 */
function extendedmailingstats_civicrm_install() {
  return _extendedmailingstats_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable
 */
function extendedmailingstats_civicrm_enable() {
  return _extendedmailingstats_civix_civicrm_enable();
}

/**
 * Implementation of API call to update mailing stats
 *
 * This is intended to be called from cron, most likely nightly.
 * It might take a few minutes to run
 */
function _extendedmailingstats_cron($params) {
//  _extendedmailingstats_cron_db_setup($params, 1);
  _extendedmailingstats_cron_mailing($params); // needs to be done at the start
  _extendedmailingstats_cron_job_timing($params);
  _extendedmailingstats_cron_recipients($params, 'Collect number of recipients',0);
  _extendedmailingstats_cron_recipients($params, 'Collect number of Gmail recipients',1);

  _extendedmailingstats_cron_event($params, 'delivered',
    array (
      'event_type' => "civicrm_mailing_event_delivered",
      'select' => array(
        "delivered" => "count(eqrec.id)",
      ),
      'gmail_only' => 0,
    )
  );
  _extendedmailingstats_cron_event($params, 'gmail_delivered',
    array (
      'event_type' => "civicrm_mailing_event_delivered",
      'select' => array(
        "gmail_delivered" => "count(eqrec.id)",
      ),
      'gmail_only' => 1,
    )
  );
  _extendedmailingstats_cron_event($params, 'Total clicks',
    array (
      'event_type' => "civicrm_mailing_event_trackable_url_open",
      'select' => array(
        "clicked_total" => "count(eqrec.id)",
      ),
      'gmail_only' => 0,
    )
  );
  _extendedmailingstats_cron_event($params, 'Gmail Total clicks',
    array (
      'event_type' => "civicrm_mailing_event_trackable_url_open",
      'select' => array(
        "gmail_clicked_total" => "count(eqrec.id)",
      ),
      'gmail_only' => 1,
    )
  );
  _extendedmailingstats_cron_event($params, 'Unique clicks',
    array (
      'event_type' => "civicrm_mailing_event_trackable_url_open",
      'select' => array(
        "clicked_unique" => "count(distinct eq.contact_id)",
      ),
      'gmail_only' => 0,
    )
  );
  _extendedmailingstats_cron_event($params, 'Gmail Unique clicks',
    array (
      'event_type' => "civicrm_mailing_event_trackable_url_open",
      'select' => array(
        "gmail_clicked_unique" => "count(distinct eq.contact_id)",
      ),
      'gmail_only' => 1,
    )
  );
  _extendedmailingstats_cron_event($params, 'forwarded',
    array (
      'event_type' => "civicrm_mailing_event_forward",
      'select' => array(
        "forwarded" => "count(eqrec.id)",
      ),
      'gmail_only' => 0,
    )
  );
  _extendedmailingstats_cron_event($params, 'Unsubscribed',
    array (
      'event_type' => "civicrm_mailing_event_unsubscribe",
      'select' => array(
        "unsubscribed" => "count(eqrec.id)",
      ),
      'gmail_only' => 0,
    )
  );
  _extendedmailingstats_cron_event($params, 'Opted out',
    array (
      'event_type' => "civicrm_mailing_event_unsubscribe",
      'select' => array(
        "opted_out" => "count(eqrec.id)",
      ),
      'gmail_only' => 0,
    )
  );
  _extendedmailingstats_cron_event($params, 'Bounced',
    array (
      'event_type' => "civicrm_mailing_event_bounce",
      'select' => array(
        "bounced" => "count(eqrec.id)",
      ),
      'gmail_only' => 0,
    )
  );
  _extendedmailingstats_cron_event($params, 'Opened',
    array (
      'event_type' => "civicrm_mailing_event_opened",
      'select' => array(
        "opened" => "count(eqrec.id)",
      ),
      'gmail_only' => 0,
    )
  );
  _extendedmailingstats_cron_event($params, 'Unique Opened',
    array (
      'event_type' => "civicrm_mailing_event_opened",
      'select' => array(
        "unique_opened" => "count(eqrec.id)",
      ),
      'gmail_only' => 0,
    )
  );

  _extendedmailingstats_cron_event($params, 'Gmail Opened',
    array (
      'event_type' => "civicrm_mailing_event_opened",
      'select' => array(
        "gmail_opened" => "count(eqrec.id)",
      ),
      'gmail_only' => 1,
    )
  );

  _extendedmailingstats_cron_trackable_urls($params);
  _extendedmailingstats_cron_contribution_page_clicks($params);
  _extendedmailingstats_cron_contributions($params);
  _extendedmailingstats_cron_send_rate($params);
  _extendedmailingstats_cron_done($params);
  return true;
}

################################################################################

function _extendedmailingstats_do_query($params, $sql) {
  global $options;
  switch( $params['mode']) {
    case 'print':
      print $sql . "\n\n";
      break;
    case 'exec':
    default:
      // use the civi db interface, not the drupal one, because this is all about civi data
      CRM_Core_DAO::executeQuery($sql);

      break;
  }
}

function _extendedmailingstats_record_status($params, $doing) {
  $sql = <<<END
INSERT INTO agc_report_mailing_stats_performance (doing) VALUES ("$doing");
END;
  _extendedmailingstats_do_query($params, $sql);
}

################################################################################




function _extendedmailingstats_cron_event($params, $task, $spec) {
  $event_type = $spec['event_type'];
  $select = $spec['select'];
  $gmail_only = $spec['gmail_only'];


  $select_clauses = array();
  $set_clauses = array();
  foreach($select as $select_into => $select_from) {
      $alias = $event_type . '_' . $select_into;
      $select_clauses[] = "$select_from as $alias";
      $set_clauses[] = "agc_report_mailing_stats.$select_into = ifnull(source.$alias,0)";
  }

  $select_clause_str = implode(",\n    ", $select_clauses);
  $set_clauses_str = implode(",\n  ", $set_clauses);

  // Are we counting unique opens?
  if (!strcmp($task, "Unique Opened")) {
    $select_clause_str = "\n    COUNT(DISTINCT eq.contact_id) AS $alias";
  }

  // Are we counting gmail addresses only?
  if ($gmail_only) {
    $gmail_only_str = <<<END
    STRAIGHT_JOIN civicrm_email e
      ON e.id = eq.email_id
     AND substring_index(e.email, '@', -1) = 'gmail.com'
END;
  }
  else {
    $gmail_only_str = '';
  }

  // Are we counting unsubscribeds or opt outs?
  if (!strcmp($task, "Unsubscribed")) {
    $extra_str = <<<END
    WHERE eqrec.org_unsubscribe = 0
END;
  }
  elseif (!strcmp($task, "Opted out")) {
    $extra_str = <<<END
    WHERE eqrec.org_unsubscribe = 1
END;
  }
  else {
    $extra_str = '';
  }

  $data_model_str='';
  if (!strncmp($spec['event_type'], 'civicrm_mailing_event', 20 )) {
    $data_model_str = <<<END
    civicrm_mailing_job AS j
    JOIN civicrm_mailing_event_queue AS eq
      ON eq.job_id = j.id
    JOIN $event_type eqrec
      ON eqrec.event_queue_id = eq.id
$gmail_only_str
$extra_str
END;
  }
  elseif (!strcmp($spec['event_type'], 'civicrm_mailing_recipients')) {
    $data_model_str = <<<END
    civicrm_mailing_recipients AS r
    JOIN civicrm_mailing_event_queue AS eq
      ON eq.job_id = j.id
$gmail_only_str
    JOIN $event_type eqrec
      ON eqrec.event_queue_id = eq.id
END;
  }


  _extendedmailingstats_record_status($params,$task);
  $sql = <<<END
UPDATE agc_report_mailing_stats
LEFT JOIN (
  SELECT j.mailing_id AS mailing_id,
$select_clause_str
  FROM
$data_model_str
  AND j.is_test = 0
  GROUP BY j.mailing_id
) AS source
ON source.mailing_id = agc_report_mailing_stats.mailing_id
SET
  $set_clauses_str;
END;


  _extendedmailingstats_do_query($params, $sql);
}

################################################################################

function _extendedmailingstats_cron_db_setup($params, $recreate = 0) {


  $sql = <<<END
# For recording how long things take to complete.
CREATE  TABLE IF NOT EXISTS agc_report_mailing_stats_performance (
  time  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  doing  VARCHAR(64) NOT NULL
)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8
COLLATE = utf8_unicode_ci;
END;
  _extendedmailingstats_do_query($params, $sql);

if ($recreate) {

  $sql = <<<END
# Not necessary in future, but good while debugging.
DROP TABLE IF EXISTS agc_report_mailing_stats;
END;
  _extendedmailingstats_do_query($params, $sql);

}
  $sql = <<<END
# Set up the table to store all the per-mailing info ready for presentation
CREATE  TABLE IF NOT EXISTS agc_report_mailing_stats (
  mailing_id INT UNSIGNED NOT NULL,
  mailing_name VARCHAR(128),
  is_completed  TINYINT(4),
  created_date TIMESTAMP NULL,
  start TIMESTAMP NULL,
  finish TIMESTAMP NULL,
  recipients INT UNSIGNED NULL,
  delivered INT UNSIGNED NULL,
  send_rate FLOAT UNSIGNED NULL,
  bounced INT UNSIGNED NULL,
  opened INT UNSIGNED NULL,
  unsubscribed INT UNSIGNED NULL,
  forwarded INT UNSIGNED NULL,
  clicked_total INT UNSIGNED NULL,
  clicked_unique INT UNSIGNED NULL,
  trackable_urls INT UNSIGNED NULL,
  clicked_contribution_page INT UNSIGNED NULL,
  contributions_48hrs_count INT UNSIGNED NULL,
  contributions_48hrs_total FLOAT UNSIGNED NULL,
  gmail_recipients FLOAT UNSIGNED NULL,
  gmail_delivered FLOAT UNSIGNED NULL,
  gmail_opened FLOAT UNSIGNED NULL,
  gmail_clicked_total FLOAT UNSIGNED NULL,
  gmail_clicked_unique FLOAT UNSIGNED NULL,

  PRIMARY KEY(`mailing_id` ASC),
  INDEX start(`start` ASC),
  INDEX finish(`start` ASC))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8
COLLATE = utf8_unicode_ci;
END;

  _extendedmailingstats_do_query($params, $sql);
}

################################################################################

// This needs to be run before most other queries to create the row in the results table
function _extendedmailingstats_cron_mailing($params) {
  _extendedmailingstats_record_status($params,"Getting stuff from mailing table");
  $sql = <<<END
INSERT  IGNORE INTO agc_report_mailing_stats (mailing_id, mailing_name, created_date, is_completed)
(
  SELECT id  AS mailing_id,
    name AS mailing_name,
    created_date AS created_date,
    is_completed AS is_completed
  FROM civicrm_mailing
  WHERE is_completed=1
);
END;
  _extendedmailingstats_do_query($params, $sql);
}

################################################################################

function _extendedmailingstats_cron_job_timing($params) {
_extendedmailingstats_record_status($params,"Collect info on start and end times");

  $sql = <<<END
UPDATE agc_report_mailing_stats JOIN (
SELECT
  mailing_id,
  min(start_date) AS start,
  max(end_date) AS finish
FROM civicrm_mailing_job AS j
WHERE is_test=0
  AND status='Complete'
GROUP BY mailing_id
) AS source
ON source.mailing_id = agc_report_mailing_stats.mailing_id
SET
  agc_report_mailing_stats.start = source.start,
  agc_report_mailing_stats.finish = source.finish;
END;
  _extendedmailingstats_do_query($params, $sql);
}

function _extendedmailingstats_cron_recipients($params, $task, $gmail_only=0) {

if ($gmail_only) {
  $gmail_join = <<<END
  join civicrm_email e ON r.email_id = e.id
  AND  substring_index(e.email, '@', -1) = 'gmail.com'
END;
  $result_row = 'gmail_recipients';
}
else {
  $gmail_join = '';
  $result_row = 'recipients';
}

_extendedmailingstats_record_status($params,$task);

  $sql = <<<END
UPDATE agc_report_mailing_stats
LEFT JOIN (
SELECT
  mailing_id,
  count(r.contact_id) AS recipients
FROM civicrm_mailing_recipients AS r
$gmail_join
GROUP BY mailing_id
) AS source
ON source.mailing_id = agc_report_mailing_stats.mailing_id
SET
  agc_report_mailing_stats.$result_row = ifnull(source.recipients,0);
END;
  _extendedmailingstats_do_query($params, $sql);
}

function _extendedmailingstats_cron_trackable_urls ($params) {
_extendedmailingstats_record_status($params,"Trackable urls");

  $sql = <<<END
UPDATE agc_report_mailing_stats
LEFT JOIN (
  SELECT mailing_id,
    count(url) AS count
  FROM civicrm_mailing_trackable_url AS cmtu
  GROUP BY mailing_id
) AS source
ON source.mailing_id = agc_report_mailing_stats.mailing_id
SET
  agc_report_mailing_stats.trackable_urls = ifnull(source.count,0);

END;
  _extendedmailingstats_do_query($params, $sql);
}

function _extendedmailingstats_cron_contribution_page_clicks ($params) {
_extendedmailingstats_record_status($params,"contribution_page_clicks");
  $sql = <<<END
UPDATE agc_report_mailing_stats
LEFT JOIN (
  SELECT j.mailing_id AS mailing_id,
    count(q.id) AS count
  FROM
    civicrm_mailing_job AS j
    JOIN civicrm_mailing_event_queue AS q
      ON q.job_id = j.id
    JOIN civicrm_mailing_event_trackable_url_open AS etu
      ON etu.event_queue_id = q.id
    JOIN civicrm_mailing_trackable_url AS tu
      ON tu.id = etu.trackable_url_id
     AND tu.url rlike '^https?://[a-z0-9.-]*/civicrm/contribute/transact'
  GROUP BY j.mailing_id
) AS source
ON source.mailing_id = agc_report_mailing_stats.mailing_id
SET
  agc_report_mailing_stats.clicked_contribution_page = ifnull(source.count,0);

END;
  _extendedmailingstats_do_query($params, $sql);
}

// If any value other than 48 hours is to be used, an appropriately named column needs to be added to the results table
function _extendedmailingstats_cron_contributions ($params, $hours = 48) {
_extendedmailingstats_record_status($params,"contributions_{$hours}hrs");
  $sql = <<<END
UPDATE agc_report_mailing_stats
LEFT JOIN (
SELECT
    cm.id AS mailing_id,
    count(cc.id) AS count,
    sum(cc.total_amount) AS sum
  FROM
    civicrm_mailing AS cm
    straight_join civicrm_mailing_trackable_url AS cmtu
        ON cmtu.mailing_id = cm.id
        AND cmtu.url rlike '.*/civicrm/contribute/transact.*'
    straight_join civicrm_contribution AS cc
        ON cc.contribution_page_id  = SUBSTRING_INDEX(SUBSTRING_INDEX(cmtu.url, 'id=', -1), '&', 1)
        AND cc.receive_date BETWEEN cm.scheduled_date AND date_add(cm.scheduled_date,interval $hours hour)
    straight_join civicrm_mailing_recipients AS cmr
        ON  cm.id = cmr.mailing_id
        AND cmr.contact_id = cc.contact_id

GROUP BY cm.id
) AS source
ON source.mailing_id = agc_report_mailing_stats.mailing_id
SET
  agc_report_mailing_stats.contributions_{$hours}hrs_count = ifnull(source.count,0),
  agc_report_mailing_stats.contributions_{$hours}hrs_total = ifnull(source.sum,0);
END;
  _extendedmailingstats_do_query($params, $sql);
}

function _extendedmailingstats_cron_send_rate ($params) {
  _extendedmailingstats_record_status($params,"Calculating Send Rate");

  $sql = <<<END
UPDATE agc_report_mailing_stats
SET send_rate = 60 * delivered / (unix_timestamp(finish)-unix_timestamp(start) );
END;
  _extendedmailingstats_do_query($params, $sql);
}

function _extendedmailingstats_cron_done ($params) {
  $sql = <<<END
INSERT INTO agc_report_mailing_stats_performance (doing) VALUES ("Done");
END;
  _extendedmailingstats_do_query($params, $sql);
}

// /**
//  * Implements hook_civicrm_entityTypes().
//  *
//  * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_entityTypes
//  */
// function extendedmailingstats_civicrm_entityTypes(&$entityTypes) {
//   _extendedmailingstats_civix_civicrm_entityTypes($entityTypes);
// }
