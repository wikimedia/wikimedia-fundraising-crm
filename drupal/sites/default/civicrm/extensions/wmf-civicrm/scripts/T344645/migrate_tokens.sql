-- After running update_table, use this to copy
-- updates to the real civicrm database
-- First try migrating one by one
SET @crid = 311095;

BEGIN;

UPDATE civicrm.civicrm_payment_token t
  INNER JOIN contribution_recur_copy crc on crc.payment_token_id = t.id
SET t.token = crc.adyen_token,
    t.payment_processor_id = @adyenProcessorId
WHERE crc.adyen_token IS NOT NULL
  AND crc.cycle_day = @cycleDay
  AND crc.id = @crid
  AND crc.has_unique_ingenico_token = 1;

INSERT INTO civicrm.civicrm_contribution_recur_smashpig (entity_id, processor_contact_id)
SELECT crc.id, crc.adyen_processor_contact_id
FROM contribution_recur_copy crc
       LEFT JOIN civicrm.civicrm_contribution_recur_smashpig sp ON sp.entity_id = crc.id
WHERE crc.cycle_day = @cycleDay
  AND crc.adyen_token IS NOT NULL
  AND crc.has_unique_ingenico_token = 1
  AND crc.id = @crid
  AND sp.id IS NULL;

UPDATE civicrm.civicrm_contribution_recur_smashpig sp
  INNER JOIN contribution_recur_copy crc ON sp.entity_id = crc.id
SET sp.processor_contact_id = crc.adyen_processor_contact_id
WHERE sp.processor_contact_id IS NULL
  AND crc.has_unique_ingenico_token = 1
  AND crc.adyen_token IS NOT NULL
  AND crc.id = @crid
  AND crc.cycle_day = @cycleDay;

UPDATE civicrm.civicrm_contribution_recur cr
  INNER JOIN contribution_recur_copy crc ON cr.id = crc.id
SET cr.payment_processor_id = @adyenProcessorId
WHERE cr.payment_processor_id = @ingenicoProcessorId
  AND crc.cycle_day = @cycleDay
  AND crc.id = @crid
  AND crc.has_unique_ingenico_token = 1
  AND crc.adyen_token IS NOT NULL;
-- COMMIT;

-- Then migrate a day's worth at once
SET @cycleDay = 14;
SELECT id INTO @adyenProcessorId FROM civicrm.civicrm_payment_processor WHERE name='adyen' AND is_test=0;
SELECT id INTO @ingenicoProcessorId FROM civicrm.civicrm_payment_processor WHERE name='ingenico' AND is_test=0;
SELECT COUNT(*)
FROM contribution_recur_copy
WHERE cycle_day = @cycleDay
  AND has_unique_ingenico_token = 1;

BEGIN;

UPDATE civicrm.civicrm_payment_token t
INNER JOIN contribution_recur_copy crc on crc.payment_token_id = t.id
SET t.token = crc.adyen_token,
    t.payment_processor_id = @adyenProcessorId
WHERE crc.adyen_token IS NOT NULL
  AND crc.cycle_day = @cycleDay
  AND crc.has_unique_ingenico_token = 1;

INSERT INTO civicrm.civicrm_contribution_recur_smashpig (entity_id, processor_contact_id)
SELECT crc.id, crc.adyen_processor_contact_id
FROM contribution_recur_copy crc
LEFT JOIN civicrm.civicrm_contribution_recur_smashpig sp ON sp.entity_id = crc.id
WHERE crc.cycle_day = @cycleDay
  AND crc.has_unique_ingenico_token = 1
  AND crc.adyen_token IS NOT NULL
  AND sp.id IS NULL;

UPDATE civicrm.civicrm_contribution_recur_smashpig sp
INNER JOIN contribution_recur_copy crc ON sp.entity_id = crc.id
SET sp.processor_contact_id = crc.adyen_processor_contact_id
WHERE sp.processor_contact_id IS NULL
  AND crc.has_unique_ingenico_token = 1
  AND crc.adyen_token IS NOT NULL
  AND crc.cycle_day = @cycleDay;

UPDATE civicrm.civicrm_contribution_recur cr
INNER JOIN contribution_recur_copy crc ON cr.id = crc.id
SET cr.payment_processor_id = @adyenProcessorId
WHERE cr.payment_processor_id = @ingenicoProcessorId
  AND crc.cycle_day = @cycleDay
  AND crc.adyen_token IS NOT NULL
  AND crc.has_unique_ingenico_token = 1;
-- COMMIT;


-- After a day, check how many are in what status
SET @cycleDay = 14;
SELECT id INTO @adyenProcessorId FROM civicrm.civicrm_payment_processor WHERE name='adyen' AND is_test=0;
SELECT !ISNULL(crc.id) AS migrated,
       contribution_status_id,
       count(*)
FROM civicrm.civicrm_contribution_recur cr
  LEFT JOIN contribution_recur_copy crc ON crc.id = cr.id
WHERE cr.cycle_day = @cycleDay
AND cr.payment_processor_id = @adyenProcessorId
AND (cancel_date IS NULL OR cancel_date > DATE_SUB(NOW(), INTERVAL 1 DAY))
GROUP BY ISNULL(crc.id), contribution_status_id
ORDER BY ISNULL(crc.id), contribution_status_id;

-- +----------+------------------------+----------+
-- | migrated | contribution_status_id | count(*) |
-- +----------+------------------------+----------+
-- |        1 |                      3 |       57 |
-- |        1 |                      5 |     3762 |
-- |        1 |                     15 |       83 |
-- |        0 |                      2 |      545 |
-- |        0 |                      3 |       88 |
-- |        0 |                      5 |     9517 |
-- |        0 |                     15 |      232 |
-- +----------+------------------------+----------+
-- Slightly higher percent at cancel status, but they
-- are older recurrings so maybe that makes sense.
-- Canceled + Failing is a more equal percent
-- 3.59 % for migrated, 3.08 % for originally Adyen

