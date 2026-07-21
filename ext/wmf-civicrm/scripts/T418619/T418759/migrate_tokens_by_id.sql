-- insert into payment_token the values of token, payment_processor_id
-- insert into contribution_recur payment_processor_id
-- insert into contribution_recur_smashpig processor_contact_id

USE civicrm;
SELECT id INTO @adyenProcessorId FROM civicrm.civicrm_payment_processor WHERE name='adyen' AND is_test=0;
SELECT id INTO @gravyProcessorId FROM civicrm.civicrm_payment_processor WHERE name='gravy' AND is_test=0;

SET @contribution_recur_id=22;

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
WHERE cr.id = @contribution_recur_id
   AND t.token = s.adyen_payment_token
   AND (t.payment_processor_id != @gravyProcessorId
   OR cr.payment_processor_id != @gravyProcessorId
   OR spig.processor_contact_id != s.gravy_buyer_id);
