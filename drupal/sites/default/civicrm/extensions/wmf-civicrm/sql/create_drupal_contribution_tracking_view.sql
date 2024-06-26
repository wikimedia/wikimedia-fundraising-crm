create view drupal.contribution_tracking_view
    as select id,
    contribution_id,
    concat(currency,' ', ROUND(amount,2)) as form_amount,
    usd_amount,
    referrer,
    utm_source,
    utm_medium,
    utm_campaign,
    utm_key,
    concat(gateway, '.', ifnull( appeal, concat( 'v=', payments_form_variant ) ) ) as payments_form,
    language,
    country,
    DATE_FORMAT(tracking_date, '%Y%m%d%H%i%s') AS ts,
    null as note,
    null as anonymous,
    null as owa_ref,
    null as owa_session,
    null as optout
    from civicrm.civicrm_contribution_tracking;
