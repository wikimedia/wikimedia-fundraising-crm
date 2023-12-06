UPDATE civicrm_value_matching_gift
SET matching_gifts_provider_info_url = 'https://matchinggifts.com/wikimedia_iframe'
WHERE matching_gifts_provider_info_url IS NOT NULL
AND matching_gifts_provider_info_url <> '';

-- Also set contacts last modified date to now when their employer has an info url,
-- to ensure all records are marked for the nightly bulk email sender sync.
UPDATE civicrm_contact c
INNER JOIN civicrm_contact org ON c.employer_id = org.id
INNER JOIN civicrm_value_matching_gift mg ON mg.entity_id = org.id
SET c.modified_date = NOW()
WHERE matching_gifts_provider_info_url IS NOT NULL
  AND matching_gifts_provider_info_url <> '';
