# standalonemigrate

Tools to migrate your existing CiviCRM site from a CMS install (in Drupal, Wordpress, etc) to CiviCRM Standalone.

**Currently in active development. It is advisable to run on a local copy of your site.**

## Getting ready

- Install a fresh Standalone site using the installation instructions. Check everything works as expected. This will be referred to as your *Target Site*.
- *Take a backup of your Source Site*
- (Recommended) Upgrade your *Source Site* to the same version as your Target Site.
- Install this extension on your Source Site.
- Check what CiviCRM extensions are installed on your Source Site. Ensure the code for all  these extensions is available on your Target Site. (note: CMS may have additional extensions bundled in the core `ext` directory. You should copy these to the regular extension directory of your Standalone site)

## Choose a migration method

This extension currently provides two different migration methods:


### Using `cv`
The `cv` method uses `cv` and other shell commands to copy your Source database to the Target site, then makes the necessary alterations. It is generally recommended if you have CLI access to the server and sufficient permissions.

Requires:
- Source Site and Target Site running on the same server
- CLI access and `cv` is intalled
- PHP runs with sufficient permissions to `exec`

Advantages:
- Source Site database is unaffected
- database is loaded into Target Site automatically
- supports transferring roles from Drupal 7, Backdrop, WordPress

Disadvantages:
- more infrastructure requirements (see above)
- dumping and reloading the database may take a large amount of time / disk space for
  large databases
- No user migration for Drupal8+ or Joomla (yet)

### Migrate In Place
The Migrate In Place method This method alters the structure of your existing database so it can be used with a Standalone site. It may be useful if you do not have CLI access or working with a very large databases, as it does not require dumping and reloading.

You should *definitely* back up your Source Site database before running Migrate In Place. The database will not be safe to continue using with the CMS-based site after the migration is run.

Requires:
- Source Site database is "disposable"
- Target Site is at least CiviCRM 6.0
- CMS and CiviCRM use the same database on the source site

Advantages:
- fewer infrastructure requirements

Disadvantages:
- requires some more manual steps to switchover databases / clear caches
- Source Site database is no longer usable with the CMS site after migration
- user migration is only supported for Drupal7 source sites (so far)
- only CMS users that have a CiviCRM contact (`civicrm_uf_match` entry) on the Source Site will be able migrated
- Using an http-triggered Api request for Migrate In Place will be subject to your web server's max execution time (e.g. 30s). This shouldn't be a problem unless you have LOTS of users in the `civicrm_uf_match` table.


## Run the migration - using cv

- From the working directory of your old Source Site, run `cv api4 StandaloneMigration.run targetSitePath=/path/to/new/standalone`

(If the target site is located under `/var/www/vhosts/domain.com/httpdocs`, then the command would be alike
`cv api4 StandaloneMigration.run targetSitePath=/var/www/vhosts/domain.com/httpdocs`)

- To transfer only users, not the rest of the data in the CMS:
`cv api4 StandaloneMigration.run targetSitePath=/var/www/boo transferData=0 skipCheckingTargetEmpty=1`


- To dump users, roles and permissions to JSON files in an empty directory, rather than immediately creating them on the target site
  `cv api4 StandaloneMigration.run targetDirectory=/tmp/userRoleData transferData=0`

- Then you can create the users, roles and permissions from those files, assuming you have matching contact IDs on the target site
  `cv api4 StandaloneMigration.run sourceDirectory=/tmp/userRoleData targetSitePath=/var/www/boo transferData=0 skipCheckingTargetEmpty=1`

- Once the migration completes, head to the Target Site. You should be able to log in with credentials from the Source Site.


## Run the migration - Migrate In Place

- Open the Api4 explorer on your existing site. Choose StandaloneMigrate entity and select MigrateInPlace action.

- If you want to reset users to a single admin, check `WipeUsers` checkbox. (A new admin password will be generated during the migration - check the logs / API result for this.)

- If you are sure, check the `AreYouSure` checkbox.

- Click Execute.

- Once the migration completes, you will need to EITHER dump your Source Site database manually and then load into the Target Site (e.g. using `mysqldump` `adminer` or `phpmyadmin`) OR update the DSN setting of your Target Site to use the DSN from your Source Site (if you do this, you must stop using this DSN with the source site)

- You should now be able to log in to the Target Site using usernames/passwords from the Source Site. If it doesn't work, you may need to Clear Caches on the Target Site before you can log in (delete all files in `private/cache/*`, delete the contents of the `civicrm_cache` table in the database)

- Once you have logged in you will need to clear caches again, use Administer > System Settings > Clear Caches to reconcile Standalone managed records.

- You will then be able to review users in the Administer > Users & Permissions > User Accounts / Roles


## Known Issues

- Site/crypto keys are not transferred - any encrypted values in your database (e.g. SMTP credentials) will not be readable by the new site unless you transfer these manually

- Site files not transferred (yet)

