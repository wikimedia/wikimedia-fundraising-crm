# org.wikimedia.systemtools

![Screenshot](images/screenshot.png)

This extension provides 2 helpers for keeping on top of queries
1) Drupal only - it appends a commented string to any query that is run with the id of the user who ran the query
  (useful if a slow query is impacting the server & you want to find out what triggered it)
2) It adds an api to parse query logs - see under usage

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.1+
* CiviCRM 5.30+

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl org.wikimedia.systemtools@https://github.com/FIXME/org.wikimedia.systemtools/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/org.wikimedia.systemtools.git
cv en systemtools
```

## Usage
On CiviCRM 5.30+ installs it is possible to get a query output file using
```
env CIVICRM_DEBUG_LOG_QUERY=1 drush mycommand
```

This also works with other command line civi tools. You can get backtraces using the word
`backtrace` instead of 1 above. However, this extension will not help you parse logs generated
with backtrace.

Once the command has completed a file will be created or updated in your [ConfigAndLog
directory](https://docs.civicrm.org/dev/en/latest/tools/debugging/#viewing-log-files).

The file will have a name like CiviCRM.sql_log.xyz.log (if you don't pass in backtrace).
There is a [proposal to add more nuance to that](https://lab.civicrm.org/dev/core/-/issues/2032).
However, for now you need to extract the part of the log that is relevant to the action (e.g delete
if first & a new one will be created for your process)


Next run the api - it's visible in the apiv4 explorer if you need help with that - here
is the code for me.

```
 echo '{"fileName":"/Users/eileenmcnaughton/CiviCRM.sql_log.7a880382d2e1d80611365ce1.log"}' | cv api4 Querylog.parse --in=json
 ```

The api call will output a csv file with the main details of the query in columns.


## Known Issues
Regex is included to replace any emails but other personal data may be in the file so
be careful!


