<?php
// This is in the directory with the other managed files for visibility
// but the method used is different - instead of storing the values
// the alterSettingsMetaData hook
//https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterSettingsMetaData/
// is used to set the default value - which will apply unless something else has been
// actively defined.
return [
  'contact_default_language' => 'undefined',
];
