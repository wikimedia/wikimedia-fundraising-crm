-- Trigger file updates to public reporting
-- We do not pass names if either we have "do not trade" set OR its a check
-- and instead we pass null which will get set as Anonymous by the PHP code

DROP TRIGGER IF EXISTS public_reporting_insert;
DROP TRIGGER IF EXISTS public_reporting_update;
DROP TRIGGER IF EXISTS public_reporting_contact_update;
DROP TRIGGER IF EXISTS public_reporting_note_update;
DELIMITER //
CREATE TRIGGER public_reporting_insert AFTER INSERT ON civicrm_value_1_note_11 FOR EACH ROW
BEGIN
    DECLARE cc_payment_instrument_id INT(10) unsigned DEFAULT NULL;
    DECLARE cc_contact_id INT(10) unsigned;
    DECLARE public_name VARCHAR(128) charset utf8;
    SET cc_payment_instrument_id := (SELECT payment_instrument_id FROM civicrm_contribution WHERE id = NEW.entity_id);
    SET cc_contact_id := (SELECT contact_id FROM civicrm_contribution WHERE id = NEW.entity_id);
	IF SUBSTRING((SELECT source FROM civicrm_contribution WHERE id = NEW.entity_id LIMIT 1), 1, 3) != 'RFD' THEN -- don't trigger for refunds
		SET public_name := (SELECT IF(do_not_trade = 1 or cc_payment_instrument_id = 4, NULL, SUBSTRING_INDEX(display_name, "@", 1))    
			FROM civicrm_contact WHERE id = cc_contact_id);
		INSERT INTO public_reporting (contribution_id, contact_id, name, converted_amount, original_currency, original_amount, note, received)
			SELECT cc.id, cc.contact_id, public_name, cc.total_amount, 
				SUBSTRING(cc.source, 1, 3), SUBSTRING(cc.source, 5), NEW.donor_comment, UNIX_TIMESTAMP(cc.receive_date)
				FROM civicrm_contribution cc WHERE cc.id = NEW.entity_id;
	END IF;
END
//
CREATE TRIGGER public_reporting_update AFTER UPDATE ON civicrm_contribution FOR EACH ROW
BEGIN
  DECLARE anonymous INTEGER;
  DECLARE public_name VARCHAR(128) charset utf8;
  IF SUBSTRING(NEW.source, 1, 3) = 'RFD' THEN -- trigger for refunds
    DELETE from public_reporting 
      WHERE public_reporting.contribution_id = NEW.id;
  ELSE
    SET public_name := (SELECT IF(do_not_trade = 1 or NEW.payment_instrument_id = 4, NULL, SUBSTRING_INDEX(display_name, "@", 1))
      FROM civicrm_contact WHERE id = NEW.contact_id);
    UPDATE public_reporting pr
      SET pr.contact_id = NEW.contact_id, pr.name = public_name, pr.converted_amount = NEW.total_amount,
        pr.original_currency = SUBSTRING(NEW.source, 1, 3), pr.original_amount = SUBSTRING(NEW.source, 5),
        pr.received = UNIX_TIMESTAMP(NEW.receive_date)
      WHERE pr.contribution_id = NEW.id;
  END IF;
END
//
CREATE TRIGGER public_reporting_contact_update AFTER UPDATE ON civicrm_contact FOR EACH ROW
BEGIN
  DECLARE public_name VARCHAR(128) charset utf8;
  SET public_name := (SELECT IF(do_not_trade = 1, NULL, SUBSTRING_INDEX(display_name, "@", 1))
    FROM civicrm_contact WHERE id = NEW.id);
  UPDATE public_reporting pr SET pr.name = public_name WHERE pr.contact_id = NEW.id;
END
//
CREATE TRIGGER public_reporting_note_update AFTER UPDATE ON civicrm_value_1_note_11 FOR EACH ROW
BEGIN
  UPDATE public_reporting pr SET pr.note = NEW.donor_comment WHERE pr.contribution_id = NEW.entity_id;
END
//
DELIMITER ;
