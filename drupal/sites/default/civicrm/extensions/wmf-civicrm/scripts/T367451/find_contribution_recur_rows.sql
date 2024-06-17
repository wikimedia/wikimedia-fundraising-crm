create table T367451 (
  id int auto_increment primary key,
  rescue_reference varchar(16),
  date datetime,
  contribution_recur_id int(10),
  key crid (contribution_recur_id)
);

source T367451.sql;

select contribution_status_id, count(*)
from T367451 t
  INNER JOIN civicrm.civicrm_contribution_recur_smashpig s on s.rescue_reference = t.rescue_reference
  INNER JOIN civicrm.civicrm_contribution_recur r on r.id = s.entity_id
group by contribution_status_id;
-- +------------------------+----------+
-- | contribution_status_id | count(*) |
-- +------------------------+----------+
-- |                      2 |       27 |
-- |                      3 |      443 |
-- |                      5 |     4252 |
-- +------------------------+----------+
-- https://wikitech.wikimedia.org/wiki/Fundraising/Data_and_flow/Database_cheatsheet
-- 2 = Pending, 3 = Cancelled, 5 = In Progress

-- Rows were put in 'Pending' status when they entered the autorescue flow, but were set back to
-- 'In Progress' when we mistakenly recorded a donation against a failed authorization IPN (see T365519).
-- Let's tag all the ones in In Progress or Pending for cancellation
UPDATE T367451 t
  INNER JOIN civicrm.civicrm_contribution_recur_smashpig s on s.rescue_reference = t.rescue_reference
  INNER JOIN civicrm.civicrm_contribution_recur r on r.id = s.entity_id
SET t.contribution_recur_id = s.entity_id
WHERE r.contribution_status_id IN (2, 5);

-- Delete all the duplicates. Leave the last one.
DELETE t
FROM T367451 t
  INNER JOIN T367451 t2 ON t.contribution_recur_id = t2.contribution_recur_id
WHERE t2.id > t.id;

-- Delete rows where we have recorded a real contribution since the last autorescue failed
-- All the mistakenly recorded contributions have been deleted.
DELETE t
FROM T367451 t
  INNER JOIN civicrm.civicrm_contribution c ON c.contribution_recur_id = t.contribution_recur_id
WHERE c.receive_date > t.date;
-- 339 rows
