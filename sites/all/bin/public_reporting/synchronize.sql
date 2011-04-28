BEGIN;
DELETE FROM public_reporting;
INSERT INTO public_reporting (contribution_id, contact_id, name, converted_amount, original_currency,
  original_amount, note, received)
SELECT cn.id, ct.id, IF(ct.do_not_trade, NULL, SUBSTRING_INDEX(ct.display_name, "@", 1)), cn.total_amount,
  SUBSTRING(cn.source, 1, 3), CONVERT(SUBSTRING(cn.source, 5), DECIMAL(20,2)), nt.donor_comment, UNIX_TIMESTAMP(cn.receive_date)
  FROM civicrm_contribution cn
  INNER JOIN civicrm_contact ct ON cn.contact_id = ct.id
  LEFT JOIN civicrm_value_1_note_11 nt ON cn.id = nt.entity_id
  WHERE cn.total_amount >= 1;
COMMIT;

/* Use if donor comments are stored in notes table instead of custom data field
BEGIN;
DELETE FROM public_reporting;
CREATE TEMPORARY TABLE temp_notes (
	contribution_id int(10) unsigned NOT NULL,
	note text collate utf8_unicode_ci,
	PRIMARY KEY (`contribution_id`)
	);
INSERT INTO temp_notes (contribution_id, note)
SELECT entity_id, note FROM civicrm_note WHERE entity_table = 'civicrm_contribution';
INSERT INTO public_reporting (contribution_id, contact_id, name, converted_amount, original_currency,
  original_amount, note, received)
SELECT cn.id, ct.id, IF(ct.do_not_trade, NULL, SUBSTRING_INDEX(ct.display_name, "@", 1)), cn.total_amount,
  SUBSTRING(cn.source, 1, 3), CONVERT(SUBSTRING(cn.source, 5), DECIMAL(20,2)), tn.note, UNIX_TIMESTAMP(cn.receive_date)
  FROM civicrm_contribution cn
  INNER JOIN civicrm_contact ct ON cn.contact_id = ct.id
  LEFT JOIN temp_notes tn ON cn.id = tn.contribution_id
  WHERE cn.total_amount >= 1;
COMMIT;
*/