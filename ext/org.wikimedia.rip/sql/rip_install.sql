UPDATE civicrm_contact SET
  is_opt_out = 1,
  do_not_email = 1,
  do_not_phone = 1,
  do_not_mail = 1,
  do_not_sms = 1,
  do_not_trade = 1
WHERE is_deceased = 1;
