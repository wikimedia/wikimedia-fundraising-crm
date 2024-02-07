-- After populating the first 3 columns from the CSV run this
UPDATE T344645
SET contribution_tracking_id = SUBSTRING_INDEX(invoice_id, '.', 1);
-- Query OK, 3676219 rows affected (1 min 12.629 sec)
-- Rows matched: 3676219  Changed: 3676219  Warnings: 0

UPDATE T344645 t
INNER JOIN civicrm.civicrm_contribution_tracking ct ON ct.id = t.contribution_tracking_id
SET t.contribution_id = ct.contribution_id;
-- Query OK, 3669124 rows affected (1 min 55.668 sec)
-- Rows matched: 3676218  Changed: 3669124  Warnings: 0

UPDATE T344645 t
  INNER JOIN civicrm.civicrm_contribution c ON c.id = t.contribution_id
SET t.contact_id = c.contact_id;
-- Query OK, 3669124 rows affected (1 min 22.634 sec)
-- Rows matched: 3669124  Changed: 3669124  Warnings: 0

-- (Optional, if we've tried some updates and need to reset)
UPDATE contribution_recur_copy
SET has_unique_ingenico_token = NULL,
    adyen_processor_contact_id = NULL,
    adyen_token = NULL;

-- Mark which contribution_recur rows share a token value
UPDATE contribution_recur_copy crc
INNER JOIN (
    SELECT ingenico_token
    FROM contribution_recur_copy
    GROUP BY ingenico_token
    HAVING count(*) > 1
) as t ON t.ingenico_token = crc.ingenico_token
SET crc.has_unique_ingenico_token = 0;
-- Query OK, 7957 rows affected (1.592 sec)
-- Rows matched: 7957  Changed: 7957  Warnings: 0

-- And mark the rest as having unique values
UPDATE contribution_recur_copy
SET has_unique_ingenico_token = 1
WHERE has_unique_ingenico_token IS NULL;
-- Query OK, 117732 rows affected (1.502 sec)
-- Rows matched: 117732  Changed: 117732  Warnings: 0

-- Match on token
UPDATE contribution_recur_copy crc
  INNER JOIN T344645 t ON crc.ingenico_token = t.ingenico_token
SET crc.adyen_token = t.adyen_token,
    crc.adyen_processor_contact_id = t.invoice_id;
-- Query OK, 125629 rows affected (2.617 sec)
-- Rows matched: 125629  Changed: 125629  Warnings: 0

INSERT INTO missing_tokens (ingenico_token, contribution_recur_id, invoice_id)
SELECT crc.ingenico_token, min(id), min(crc.invoice_id)
FROM contribution_recur_copy crc
       LEFT JOIN T344645 t ON t.ingenico_token = crc.ingenico_token
WHERE crc.ingenico_token IS NOT NULL
  AND t.ingenico_token IS NULL
GROUP BY crc.ingenico_token;

UPDATE missing_tokens mt
  INNER JOIN contribution_recur_copy crc ON crc.ingenico_token = mt.ingenico_token
  INNER JOIN civicrm.civicrm_contribution c ON crc.id = c.contribution_recur_id
SET mt.invoice_id = c.invoice_id
WHERE mt.invoice_id IS NULL;

UPDATE missing_tokens
SET invoice_id = SUBSTRING_INDEX(invoice_id, '|', 1);

UPDATE missing_tokens
SET invoice_id = CONCAT(invoice_id, '1')
WHERE invoice_id LIKE '%\.';

-- 11275 missing tokens
