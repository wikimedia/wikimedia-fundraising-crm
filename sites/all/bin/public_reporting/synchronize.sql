BEGIN;
DELETE FROM {pr_db}{public_reporting};
REPLACE INTO {pr_db}{public_reporting}
    ( contribution_id, converted_amount, original_currency, original_amount, received )
    SELECT
        id,
        total_amount,
        SUBSTRING( source, 1, 3 ),
        CONVERT( SUBSTRING( source, 5 ), DECIMAL( 20, 2 ) ),
        UNIX_TIMESTAMP( receive_date )
    FROM civicrm_contribution
    WHERE total_amount > 0;
COMMIT;
