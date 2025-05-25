On CiviCRM 5.30+ installs it is possible to get a query output file using
```
env CIVICRM_DEBUG_LOG_QUERY=xyz drush mycommand
```

This also works with other command line civi tools. You can get backtraces using the word
`backtrace` instead of 1 above. However, this extension will not help you parse logs generated
with backtrace.

Once the command has completed a file will be created or updated in your [ConfigAndLog
directory](https://docs.civicrm.org/dev/en/latest/tools/debugging/#viewing-log-files).

The file will have a name like CiviCRM.sql_log.xyz.log (if you don't pass in backtrace).
Note that if the file exists the data will be appended to the existing file.

Next run the api - it's visible in the apiv4 explorer if you need help with that - here
is the code for me.

```
 echo '{"fileName":"/Users/eileenmcnaughton/CiviCRM.sql_log.7a880382d2e1d80611365ce1.log" "version":4}' | drush @wmff Querylog.parse --in=json
 ```
or
 ```
echo '{"fileName":"/Users/eileenmcnaughton/CiviCRM.sql_log.7a880382d2e1d80611365ce1.log"}' | cv api4 Querylog.parse --in=json
 ```
The api call will output a csv file with the main details of the query in columns.

Further reading https://wikitech.wikimedia.org/wiki/Fundraising#Queries_%26_timing

## Known Issues
Regex is included to replace any emails but other personal data may be in the file so
be careful!

