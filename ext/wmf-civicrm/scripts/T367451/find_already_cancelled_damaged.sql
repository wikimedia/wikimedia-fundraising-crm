CREATE TABLE damaged_rescue_refs (
  rescue_reference varchar(16) not null,
  damaged_id int(10) unsigned primary key,
  contribution_recur_id int(10) unsigned,
  contribution_status_id int(10) unsigned,
  key rref (rescue_reference),
  key dcrid (contribution_recur_id)
);

INSERT INTO damaged_rescue_refs (rescue_reference, damaged_id)
SELECT RIGHT(LEFT(`error`, 99), 16) AS rescue_reference, id
FROM smashpig.damaged
WHERE `error` LIKE '%reference%' AND message LIKE '%subscr_cancel%';

UPDATE damaged_rescue_refs d
INNER JOIN civicrm.log_civicrm_contribution_recur_smashpig l ON d.rescue_reference = l.rescue_reference
INNER JOIN civicrm.civicrm_contribution_recur r ON l.entity_id = r.id
SET d.contribution_recur_id = l.entity_id,
    d.contribution_status_id = r.contribution_status_id;

SELECT contribution_status_id, count(*) FROM damaged_rescue_refs GROUP BY contribution_status_id;
-- +------------------------+----------+
-- | contribution_status_id | count(*) |
-- +------------------------+----------+
-- |                      3 |      642 |
-- +------------------------+----------+
-- All are in cancelled status

DELETE FROM smashpig.damaged WHERE id IN
(SELECT damaged_id FROM damaged_rescue_refs where contribution_status_id = 3);
