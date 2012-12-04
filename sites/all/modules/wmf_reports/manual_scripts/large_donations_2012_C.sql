-- Snail Mail 2
-- Individuals and Companies
-- Gave $500-$999.99 from July 1, 2011 - April 1, 2012
-- but did not give after

DROP TABLE IF EXISTS %(scratch)s_condition1;
DROP TABLE IF EXISTS %(scratch)s_condition2;

CREATE table %(scratch)s_condition1
(
    SELECT
        contact_id AS civi_id
    FROM civicrm_contribution
    WHERE
        receive_date BETWEEN "2011-07-01" AND "2012-04-01"
    GROUP BY
        civi_id
    HAVING
        SUM(total_amount) > 500
);

ALTER TABLE %(scratch)s_condition1 ADD UNIQUE INDEX UI_civi_id (civi_id);

CREATE TABLE %(scratch)s_condition2
(
    SELECT
        civi_id,
        receive_date AS last_donation_date,
        total_amount AS last_donation_amount
    FROM %(scratch)s_condition1
    JOIN civicrm_contribution
        ON contact_id = civi_id
    WHERE civicrm_contribution.receive_date IN (
        SELECT MAX(receive_date)
        FROM civicrm_contribution
        WHERE contact_id = civi_id
    )
    GROUP BY
        civi_id
    HAVING
        receive_date <= "2012-04-01"
);

ALTER TABLE %(scratch)s_condition2 ADD UNIQUE INDEX UI_civi_id (civi_id);
ALTER TABLE %(scratch)s_condition2 ADD INDEX KI_last_donation_date (last_donation_date);

SELECT
    civi_id,
    civicrm_email.email,
    COALESCE( civicrm_contact.display_name ) AS name,
    COALESCE( civicrm_address.street_address ) AS address,
    CONCAT(
        COALESCE( supplemental_address_1, "" ),
        " ",
        COALESCE( supplemental_address_2, "" ),
        " ",
        COALESCE( supplemental_address_3, "" )
    ) AS supplemental_address,
    COALESCE( civicrm_address.city ) AS city,
    COALESCE( civicrm_state_province.name ) AS state,
    COALESCE( civicrm_country.name ) AS country,
    COALESCE( civicrm_address.postal_code ) AS zip,
    COALESCE( civicrm_phone.phone ) AS phone,
    last_donation_date,
    last_donation_amount
FROM %(scratch)s_condition2
JOIN civicrm_email
    ON civi_id = civicrm_email.contact_id
JOIN civicrm_contact
    ON civicrm_contact.id = civi_id
JOIN civicrm_contribution
    ON civicrm_contribution.contact_id = civi_id
LEFT JOIN civicrm_address
    ON civicrm_address.contact_id = civi_id
LEFT JOIN civicrm_phone
    ON civicrm_phone.contact_id = civi_id
LEFT JOIN civicrm_state_province
    ON civicrm_state_province.id = civicrm_address.state_province_id
LEFT JOIN civicrm_country
    ON civicrm_country.id = civicrm_address.country_id
GROUP BY
    civi_id
