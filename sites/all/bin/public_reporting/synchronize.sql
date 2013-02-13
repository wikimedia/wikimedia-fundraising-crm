BEGIN;
DELETE FROM {public_reporting};
INSERT INTO {public_reporting}
    ( contribution_id, converted_amount, original_currency, original_amount, received )
    SELECT
        civicrm_contribution.id,
        civicrm_contribution.total_amount,
        -- in the future, the following two columns will be taken from wmf_contribution_extra.original_*
        SUBSTRING( civicrm_contribution.source, 1, 3 ),
        CONVERT( SUBSTRING( civicrm_contribution.source, 5 ), DECIMAL( 20, 2 ) ),
        UNIX_TIMESTAMP( civicrm_contribution.receive_date )
    LEFT JOIN wmf_contribution_extra
        ON wmf_contribution_extra.entity_id = id
    FROM civicrm_contribution
    WHERE
        COALESCE(financial_only, 0) = 0
        -- this will be deprecated by "financial_only"
        AND total_amount > 0
COMMIT;
