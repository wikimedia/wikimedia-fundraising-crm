The extension exposes api actions to check & fix data in your DB.

To run all checks:
``
civicrm_api3('Data', 'check', array())
``

or

``
drush cvapi Data.check
``

A specific check (that ships with it) is run by

``
civicrm_api3('Data', 'check', array('check' => 'PrimaryLocation'))
``

To run the fix:
``
civicrm_api3('Data', 'fix', array('check' => 'PrimaryLocation'))
``


Note that the checks are declared in the function
datachecks_civicrm_datacheck_checks
or by implementing the civicrm_datacheck_checks hook.

Descriptions of the checks are likely to be determined by that function.

Future thoughts
- a drush wrapper that throws an exception if anything is found (meaning it will be quiet if no
data checks fail)
- cleanup options for the DuplicateLocation check
- possibly a UI option, I'm reluctant to add it to the system check as they will
be run when all system checks are run and given these may be slow the intent is not to
'accidentally' run them.
