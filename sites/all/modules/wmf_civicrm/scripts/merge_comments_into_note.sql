-- Consolidate a single-field custom group 
-- Should affect 2,148 rows
UPDATE civicrm_contribution cc
INNER JOIN civicrm_value_1_note_11 dc ON dc.entity_id = cc.id
SET cc.`note` = dc.donor_comment
WHERE cc.`note` IS NULL or cc.`note` = ''
AND dc.donor_comment <> '';

