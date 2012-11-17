-- Triggers to keep public reporting up-to-date with incoming contributions

DROP TRIGGER IF EXISTS public_reporting_insert;
DROP TRIGGER IF EXISTS public_reporting_update;
DROP TRIGGER IF EXISTS public_reporting_contact_update;
DROP TRIGGER IF EXISTS public_reporting_note_update;
DELIMITER //
CREATE TRIGGER public_reporting_insert AFTER INSERT ON civicrm_contribution FOR EACH ROW
BEGIN
    IF SUBSTRING( NEW.source, 1, 3 ) != 'RFD' THEN -- don't trigger for refunds
        INSERT INTO {public_reporting}
            ( contribution_id, converted_amount, original_currency, original_amount, received )
            VALUES (
                NEW.id,
                NEW.total_amount,
                SUBSTRING( NEW.source, 1, 3 ),
                SUBSTRING( NEW.source, 5 ),
                UNIX_TIMESTAMP( NEW.receive_date )
            );
    END IF;
END
//
CREATE TRIGGER public_reporting_update AFTER UPDATE ON civicrm_contribution FOR EACH ROW
BEGIN
    IF SUBSTRING(NEW.source, 1, 3) = 'RFD' THEN -- trigger for refunds
        DELETE from {public_reporting}
            WHERE {public_reporting}.contribution_id = NEW.id;
    ELSE
        UPDATE {public_reporting} pr
            SET
                pr.converted_amount = NEW.total_amount,
                pr.original_currency = SUBSTRING( NEW.source, 1, 3 ),
                pr.original_amount = SUBSTRING( NEW.source, 5 ),
                pr.received = UNIX_TIMESTAMP( NEW.receive_date )
        WHERE pr.contribution_id = NEW.id;
  END IF;
END
//
DELIMITER ;
