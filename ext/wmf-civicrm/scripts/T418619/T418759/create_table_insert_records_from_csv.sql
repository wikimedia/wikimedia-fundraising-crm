use civicrm;

DROP TABLE IF EXISTS T418759;
CREATE TABLE T418759 (
  adyen_shopper_ref varchar(255),
  gravy_audit_reference_id varchar(255),
  gravy_import_status varchar(64),
  adyen_payment_token varchar(255),
  gravy_buyer_id varchar(255),
  gravy_token varchar(255),
  card_bin varchar(16),
  card_last4 varchar(8),
  card_expiration_date varchar(16),
  card_fingerprint varchar(255),
  gravy_notes varchar(255)
);

LOAD DATA LOCAL INFILE 'T418759.csv'
INTO TABLE T418759
FIELDS TERMINATED BY ','
OPTIONALLY ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 LINES
(gravy_audit_reference_id, gravy_import_status, adyen_shopper_ref, adyen_payment_token,
 gravy_buyer_id, gravy_token, card_bin, card_last4, card_expiration_date, card_fingerprint, gravy_notes);

-- rows from the CSV that find no match in CiviCRM (nothing below will touch these)
SELECT s.adyen_shopper_ref AS unmatched_adyen_shopper_ref
FROM T418759 s
LEFT JOIN civicrm_contribution_recur_smashpig spig ON spig.processor_contact_id = s.adyen_shopper_ref
WHERE spig.entity_id IS NULL;