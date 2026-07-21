-- Loads T418759.csv into a staging table and updates CiviCRM directly via
-- joins through it (spig -> cr -> payment_token), without materializing the
-- T418759 mapping table.

USE civicrm;
SELECT id INTO @gravyProcessorId FROM civicrm.civicrm_payment_processor WHERE name='gravy' AND is_test=0;

-- payment_token.token/processor, contribution_recur.processor, and
-- contribution_recur_smashpig.processor_contact_id all updated together
UPDATE civicrm_payment_token t
INNER JOIN civicrm_contribution_recur cr ON cr.payment_token_id = t.id
INNER JOIN civicrm_contribution_recur_smashpig spig ON spig.entity_id = cr.id
INNER JOIN T418759 s ON s.adyen_shopper_ref = spig.processor_contact_id
SET t.token = s.gravy_token,
    t.payment_processor_id = @gravyProcessorId,
    cr.payment_processor_id = @gravyProcessorId,
    spig.processor_contact_id = s.gravy_buyer_id
WHERE t.token = s.adyen_payment_token
   AND (t.payment_processor_id != @gravyProcessorId
   OR cr.payment_processor_id != @gravyProcessorId
   OR spig.processor_contact_id != s.gravy_buyer_id);
