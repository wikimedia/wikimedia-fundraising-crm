create view drupal.contribution_source_view
    as select id as contribution_tracking_id,
    banner,
    landing_page,
      CASE
       WHEN payment_method_id = 1 THEN 'cc'
       WHEN payment_method_id = 2 THEN 'dd'
       WHEN payment_method_id = 3 THEN 'cash'
       WHEN payment_method_id = 5 THEN 'obt'
       WHEN payment_method_id = 6 THEN 'ew'
       WHEN payment_method_id = 9 THEN 'rtbt'
       WHEN payment_method_id = 14 THEN 'bt'
       WHEN payment_method_id = 25 THEN 'paypal'
       WHEN payment_method_id = 189 THEN 'amazon'
       WHEN payment_method_id = 240 THEN 'apple'
       WHEN payment_method_id = 243 THEN 'google'
    END as payment_method
    from civicrm.civicrm_contribution_tracking;
