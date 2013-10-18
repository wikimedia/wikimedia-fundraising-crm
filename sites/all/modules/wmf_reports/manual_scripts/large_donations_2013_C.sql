-- UK billing address
-- Gave $500+ in one donation

DROP TABLE IF EXISTS %(scratch)s_condition1;

CREATE table %(scratch)s_condition1
(
    SELECT
        contact_id AS civi_id
    FROM civicrm_contribution
    WHERE
        total_amount >= 500
    GROUP BY
        civi_id
);

ALTER TABLE %(scratch)s_condition1 ADD UNIQUE INDEX UI_civi_id (civi_id);

DROP TABLE IF EXISTS %(scratch)s_condition2;
CREATE table %(scratch)s_condition2
(
    SELECT
        contact_id AS civi_id
    FROM civicrm_address
    JOIN %(scratch)s_condition1 prev
        ON civicrm_address.contact_id = prev.civi_id
    JOIN civicrm_country
        ON civicrm_address.country_id = civicrm_country.id
    WHERE
        civicrm_country.iso_code = 'GB'
    GROUP BY
        civi_id
);

SELECT
    civi_id,
    civicrm_contact.contact_type AS contact_type,
    civicrm_email.email AS email,
    COALESCE( civicrm_contact.display_name ) AS display_name,
    COALESCE( civicrm_contact.first_name ) AS first_name,
    COALESCE( civicrm_contact.last_name ) AS last_name,
    COALESCE( civicrm_contact.organization_name, org.organization_name ) AS organization_name,
    COALESCE( civicrm_address.street_address ) AS address,
    CONCAT(
        COALESCE( supplemental_address_1, "" ),
        " ",
        COALESCE( supplemental_address_2, "" ),
        " ",
        COALESCE( supplemental_address_3, "" )
    ) AS supplemental_address,
    COALESCE( civicrm_address.city ) AS city,
    COALESCE( civicrm_state_province.abbreviation ) AS state,
    COALESCE( civicrm_country.name ) AS country,
    COALESCE( civicrm_address.postal_code ) AS zip,
    COALESCE( civicrm_phone.phone ) AS phone,
    ( SELECT
        GROUP_CONCAT(
            CONCAT( total_amount, ' on ', receive_date )
            ORDER BY receive_date DESC
            SEPARATOR ' & '
        )
        FROM civicrm_contribution
        WHERE contact_id = civi_id
    ) AS contributions,
    ( SELECT
        SUM( total_amount )
        FROM civicrm_contribution
        WHERE contact_id = civi_id
    ) AS total,
    SUM( COALESCE( civicrm_contact.do_not_email, 0 ) ) > 0 AS do_not_email,
    SUM( COALESCE( civicrm_contact.do_not_phone, 0 ) ) > 0 AS do_not_phone
FROM %(scratch)s_condition2
JOIN civicrm_contact
    ON civicrm_contact.id = civi_id
LEFT JOIN civicrm_email
    ON civi_id = civicrm_email.contact_id
LEFT JOIN civicrm_address
    ON civicrm_address.contact_id = civi_id
LEFT JOIN civicrm_phone
    ON civicrm_phone.contact_id = civi_id
LEFT JOIN civicrm_state_province
    ON civicrm_state_province.id = civicrm_address.state_province_id
LEFT JOIN civicrm_country
    ON civicrm_country.id = civicrm_address.country_id
LEFT JOIN civicrm_relationship
    ON civicrm_relationship.contact_id_a = civi_id
LEFT JOIN civicrm_contact org
    ON org.id = civicrm_relationship.contact_id_b
GROUP BY
    civi_id
ORDER BY
    last_name ASC;
