BEGIN;
DELETE FROM {public_reporting};
INSERT INTO {public_reporting}
    ( contribution_id, converted_amount, original_currency, original_amount, received )
    SELECT
        id,
        total_amount,
        SUBSTRING( source, 1, 3 ),
        SUBSTRING( source, 5 ),
        UNIX_TIMESTAMP( receive_date )
    FROM civicrm_contribution
    WHERE total_amount >= 1;
COMMIT;
