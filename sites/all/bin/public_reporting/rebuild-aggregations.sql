REPLACE INTO {pr_db}{public_reporting_hours}
    ( datehour, country, total, count, average, maximum, insert_timestamp )
    SELECT
        DATE_FORMAT( FROM_UNIXTIME( pr.received ), '%Y-%m-%d %H:00:00' ),
        civicrm_country.iso_code,
        SUM( pr.converted_amount ),
        COUNT( pr.converted_amount ),
        AVG( pr.converted_amount ),
        MAX( pr.converted_amount ),
        NOW()
    FROM {pr_db}{public_reporting} pr
    JOIN civicrm_contribution
        ON civicrm_contribution.id = pr.contribution_id
    LEFT JOIN civicrm_address
        ON civicrm_address.contact_id = civicrm_contribution.contact_id
    LEFT JOIN civicrm_country
        ON civicrm_country.id = civicrm_address.country_id
    WHERE
        civicrm_address.is_primary = 1
    GROUP BY
        DATE_FORMAT( FROM_UNIXTIME( pr.received ), '%Y-%m-%d %H:00:00' ),
        civicrm_country.id;
