CREATE TABLE cancelled_rescue_references (
  id int(10) unsigned primary key,
  rescue_reference varchar(16),
  prev_log_date datetime
);

INSERT INTO cancelled_rescue_references (id, prev_log_date)
SELECT l.id, max(l.log_date) as prev_log_date from civicrm.civicrm_contribution_recur r
  INNER JOIN civicrm.civicrm_contribution_recur_smashpig s
    ON r.id = s.entity_id
  INNER JOIN civicrm.log_civicrm_contribution_recur_smashpig l
    ON l.id = s.id
  WHERE r.modified_date > '2024-06-20'
    AND r.contribution_status_id=3
    AND r.cancel_reason='Payment cannot be rescued: maximum failures reached'
    AND s.rescue_reference = ''
    AND l.rescue_reference <> ''
    AND l.log_date < '2024-06-22'
GROUP BY l.id;

UPDATE cancelled_rescue_references c
INNER JOIN civicrm.log_civicrm_contribution_recur_smashpig l
ON c.id = l.id AND c.prev_log_date = l.log_date
SET c.rescue_reference = l.rescue_reference;

UPDATE civicrm.civicrm_contribution_recur_smashpig s
INNER JOIN cancelled_rescue_references c ON c.id = s.id
SET s.rescue_reference = c.rescue_reference;

DROP TABLE cancelled_rescue_references;
