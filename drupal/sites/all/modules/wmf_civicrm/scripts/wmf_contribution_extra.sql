CREATE TEMPORARY TABLE missing_extra (
  entity_id int(10) unsigned PRIMARY KEY,
  source varchar(255) COLLATE utf8_unicode_ci,
  trxn_id varchar(255) COLLATE utf8_unicode_ci,
  total_usd decimal(20,2),
  gateway varchar(255) COLLATE utf8_unicode_ci,
  gateway_txn_id varchar(255) COLLATE utf8_unicode_ci,
  original_amount decimal(20,2),
  original_currency varchar(255) COLLATE utf8_unicode_ci
);

INSERT INTO missing_extra ( entity_id, source, trxn_id, total_usd )
SELECT c.id, source, trxn_id, total_amount
FROM civicrm_contribution c
LEFT OUTER JOIN wmf_contribution_extra ex ON ex.entity_id = c.id
WHERE ex.id IS NULL;

UPDATE missing_extra
SET
  original_amount = SUBSTR( source, 4 ),
  original_currency = UPPER( LEFT( source, 3 ) )
WHERE source RLIKE '^[a-zA-Z]{3} [0-9.]+$';

UPDATE missing_extra
SET
  original_amount = total_usd,
  original_currency = 'USD'
WHERE original_currency IS NULL;

UPDATE missing_extra
SET trxn_id = REPLACE( trxn_id, 'RECURRING ', '' );

UPDATE missing_extra
SET gateway = 
CASE
  WHEN trxn_id LIKE 'PAYPAL %' THEN 'paypal'
  WHEN trxn_id LIKE 'PAYFLOWPRO %' THEN 'payflowpro'
  WHEN trxn_id LIKE 'GLOBALCOLLECT %' THEN 'globalcollect'
  WHEN trxn_id RLIKE '^ ?[0-9A-Z]{17} [0-9]{9,10}' THEN 'PAYPAL'
ELSE 'unknown'
END;

UPDATE missing_extra
SET gateway_txn_id = 
CASE
  WHEN gateway = 'unknown' THEN entity_id
  WHEN trxn_id rlike '^ [0-9A-Z]{17} [0-9]{9,10}' THEN SUBSTR( trxn_id, 2 )
  ELSE REPLACE( trxn_id, CONCAT( UPPER( gateway ), ' ' ), '' )
END;

INSERT INTO wmf_contribution_extra
  ( entity_id, total_usd, gateway, gateway_txn_id, original_amount, original_currency, source_name )
SELECT
  entity_id,
  total_usd,
  gateway,
  gateway_txn_id,
  original_amount,
  original_currency,
  'BACKFILL'
FROM missing_extra;

