-- Triggers to keep public reporting up-to-date with incoming contributions

DROP TRIGGER IF EXISTS public_reporting_insert;
DROP TRIGGER IF EXISTS public_reporting_update;
DROP TRIGGER IF EXISTS public_reporting_contact_update;
DROP TRIGGER IF EXISTS public_reporting_note_update;
DROP TRIGGER IF EXISTS public_reporting_custom_insert;
DROP TRIGGER IF EXISTS public_reporting_custom_update;
DELIMITER //
CREATE TRIGGER public_reporting_update AFTER UPDATE ON civicrm_contribution FOR EACH ROW
BEGIN
    IF NOT (SELECT finance_only = 1 FROM wmf_contribution_extra WHERE entity_id = NEW.id) THEN
        REPLACE INTO {pr_db}{public_reporting}
            SET
                contribution_id = NEW.id,
                converted_amount = NEW.total_amount,
                original_currency = SUBSTRING( NEW.source, 1, 3 ),
                original_amount = CONVERT( SUBSTRING( NEW.source, 5 ), DECIMAL( 20, 2 ) ),
                received = UNIX_TIMESTAMP( NEW.receive_date );
    END IF;
END
//
CREATE TRIGGER public_reporting_custom_insert AFTER INSERT ON wmf_contribution_extra FOR EACH ROW
BEGIN
    IF NEW.finance_only = 1 THEN
        DELETE from {pr_db}{public_reporting}
            WHERE {pr_db}{public_reporting}.contribution_id = NEW.entity_id;
    ELSE
        REPLACE INTO {pr_db}{public_reporting}
            (contribution_id, converted_amount, original_currency, original_amount, received)
            (SELECT
                    id,
                    total_amount,
                    SUBSTRING( source, 1, 3 ),
                    CONVERT( SUBSTRING( source, 5 ), DECIMAL( 20, 2 ) ),
                    UNIX_TIMESTAMP( receive_date )
                FROM civicrm_contribution
                WHERE id = NEW.entity_id
            );
    END IF;
END
//
CREATE TRIGGER public_reporting_custom_update AFTER UPDATE ON wmf_contribution_extra FOR EACH ROW
BEGIN
    IF NEW.finance_only = 1 THEN
        DELETE from {pr_db}{public_reporting}
            WHERE {pr_db}{public_reporting}.contribution_id = NEW.entity_id;
    ELSE
        REPLACE INTO {pr_db}{public_reporting}
            (contribution_id, converted_amount, original_currency, original_amount, received)
            (SELECT
                    id,
                    total_amount,
                    SUBSTRING( source, 1, 3 ),
                    CONVERT( SUBSTRING( source, 5 ), DECIMAL( 20, 2 ) ),
                    UNIX_TIMESTAMP( receive_date )
                FROM civicrm_contribution
                WHERE id = NEW.entity_id
            );
    END IF;
END
//
DELIMITER ;
