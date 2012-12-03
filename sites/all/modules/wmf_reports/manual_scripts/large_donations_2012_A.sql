-- Annual Report - Letter
-- Individuals and Companies
-- Gave $1000+ from July 1, 2011 - April 1, 2012
-- but did not give April 1, 2012 - Current

DROP TABLE IF EXISTS %(scratch)s_condition1;
DROP TABLE IF EXISTS %(scratch)s_condition2;

CREATE table %(scratch)s_condition1
(
    SELECT
        email
    FROM civicrm_contribution
    JOIN civicrm_email
        ON civicrm_email.contact_id = civicrm_contribution.contact_id
    WHERE
        receive_date BETWEEN "2011-07-01" AND "2012-04-01"
    GROUP BY
        email
    HAVING
        SUM(total_amount) > 1000
);

ALTER TABLE %(scratch)s_condition1 ADD UNIQUE INDEX UI_email (email);

CREATE TABLE %(scratch)s_condition2
(
    SELECT
        civicrm_email.email,
        receive_date AS last_donation_date,
        total_amount AS last_donation_amount
    FROM %(scratch)s_condition1
    JOIN civicrm_email
        ON civicrm_email.email = %(scratch)s_condition1.email
    JOIN civicrm_contribution
        ON civicrm_email.contact_id = civicrm_contribution.contact_id
    GROUP BY
        %(scratch)s_condition1.email
    HAVING
        MAX(receive_date) <= "2012-04-01"
        AND receive_date = MAX(receive_date)
);

ALTER TABLE %(scratch)s_condition2 ADD UNIQUE INDEX UI_email (email);

SELECT
    civicrm_contact.id AS civi_id,
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
    ON %(scratch)s_condition2.email = civicrm_email.email
JOIN civicrm_contact
    ON civicrm_contact.id = civicrm_email.contact_id
JOIN civicrm_contribution
    ON civicrm_contribution.contact_id = civicrm_email.contact_id
LEFT JOIN civicrm_address
    ON civicrm_address.contact_id = civicrm_contribution.contact_id
LEFT JOIN civicrm_phone
    ON civicrm_phone.contact_id = civicrm_contribution.contact_id
LEFT JOIN civicrm_state_province
    ON civicrm_state_province.id = civicrm_address.state_province_id
LEFT JOIN civicrm_country
    ON civicrm_country.id = civicrm_address.country_id
WHERE
    COALESCE( civicrm_address.is_primary, 1)
    AND COALESCE( civicrm_phone.is_primary, 1)
HAVING
   last_donation_date <= "2012-04-01";
