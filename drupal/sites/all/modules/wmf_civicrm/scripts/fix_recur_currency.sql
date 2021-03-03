CREATE TEMPORARY TABLE fix_recur (
  id int(10) unsigned PRIMARY KEY,
  currency varchar(3)
);

INSERT INTO fix_recur
SELECT cr.id, lcr.currency
FROM civicrm_contribution_recur cr
INNER JOIN log_civicrm_contribution_recur lcr ON cr.id = lcr.id
  AND lcr.currency <> cr.currency
  AND lcr.log_conn_id NOT LIKE 'c_%'
  AND lcr.log_user_id IS NULL
  AND lcr.log_date > '2016-05-01'
  AND cr.modified_date > '2016-05-01'
  AND cr.currency = 'USD'
GROUP BY cr.id, lcr.currency;

UPDATE civicrm_contribution_recur cr
INNER JOIN fix_recur f on cr.id = f.id
SET cr.currency = f.currency;

