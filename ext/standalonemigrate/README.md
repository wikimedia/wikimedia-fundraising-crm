# standalonemigrate

Tool to migrate your existing CiviCRM site from a CMS install (in Drupal, Wordpress, etc) to CiviCRM Standalone.

**Currently in active development. It is advisable to run on a local copy of your site. Currently only the `cv` based pipeline (using `targetSitePath`) is working.**

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

## Getting ready

- Install a fresh Standalone site using the installation instructions
- (Recommended) Upgrade your source site to the same version as your Standalone site
- Install this extension on your existing site
- Ensure the code for all installed extensions to your new Standalone site (note: CMS may have additional extensions bundled in the core `ext` directory. You should copy these to the regular extension directory of your Standalone site)
- If your sites are running on the same server and you have `cv`, find the full file system path to your new Standalone site. If not, find the `CIVICRM_DSN` for your new Standalone site (normally found in `civicrm.settings.php`)

## Run the migration - using cv

- From the working directory of your old site, run `cv api4 StandaloneMigration.run targetSitePath=/path/to/new/standalone`

If the target site is located under `/var/www/vhosts/domain.com/httpdocs`, then the command would be alike
`cv api4 StandaloneMigration.run targetSitePath=/var/www/vhosts/domain.com/httpdocs`

or `cv api4 StandaloneMigration.run targetDsn=[mysql://my:site@dsn/here?new_link=true]`

- To transfer only users, not the rest of the data in the CMS:
`cv api4 StandaloneMigration.run targetSitePath=/var/www/boo transferData=0 skipCheckingTargetEmpty=1`

- To dump users, roles and permissions to JSON files in an empty directory, rather than immediately creating them on the target site
  `cv api4 StandaloneMigration.run targetDirectory=/tmp/userRoleData transferData=0`

- Then you can create the users, roles and permissions from those files, assuming you have matching contact IDs on the target site
  `cv api4 StandaloneMigration.run sourceDirectory=/tmp/userRoleData targetSitePath=/var/www/boo transferData=0 skipCheckingTargetEmpty=1`

## Run the migration - web-based

- Open the Api4 explorer on your existing site. Choose StandaloneMigrate entity and Run action.

- Add in your targetSitePath or targetDsn value.

- Click Execute. Cross your fingers.

## Known Issues

- Using `targetDsn` is not fully implemented

- Site/crypto keys are not transferred - any encrypted values in your database (e.g. SMTP credentials) will not be readable by the new site unless you transfer these manually

- Using an http-triggered Api request via the explorer is only going to work if
  the entire job can be completed within the timeout allowed by your web server's configuration,
  e.g. 30s is typical. Therefore this method will not work unless your CiviCRM is pretty
  low on content.

- Site files not transferred (yet)

- No user migration for Drupal8+ or Joomla (yet)

## Current requirements

- We can run `exec()` commands (`cleanStandaloneInstall`, `bulkTransfer`)
- We have access to the `mysql` and `mysqldump` command line tools on the source sever
- For cv method:
  - `cv` installed and available on PATH
  - sites are on the same server
