This folder holds the mgd files for all entities managed
by wmf-civicrm - e.g payment instruments, financial types etc

See https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_managed/
and in particular note that the options for cleanup & update should be
set to 'never' for anything that should not
be changed on prod if the extension is altered or removed (this
latter is salient because if the extension were
accidentally removed we would not want financial types deleted.)
