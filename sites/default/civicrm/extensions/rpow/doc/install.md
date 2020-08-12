# CiviCRM Replay-on-Write: Installation

The "General" section gives a general overview of the install process. Many specifics can depend on your
organization/deployment, iso t is a bit light on MySQL details - and only gives details that are specific to `rpow`.

The subsequent sections provide more specific, concrete install options. These are geared toward local development
and experimentation:

* "Using civibuild and harvey-dent" provides the lightest simulation. It uses the existing DB and sets up a split personality, with two users (RO user and RW user).
* "Using civibuild and rundb" provides the most realistic simulation. It launches two new instances of `mysqld` and automatically syncs between their databases.
* "Using rebuild-ro" provides a more manual simulation. It uses your existing `mysqld` and makes a read-only copy of the Civi DB. It does *not* sync automatically - you must run `rebuild-ro` whenever you want to sync. (*This is useful for carefully inspecting/playing each step or edge-case manually.*)

## General

* [Setup CiviCRM to store caches in Redis.](https://docs.civicrm.org/sysadmin/en/latest/setup/cache/)

* Download the `rpow` extension, e.g.

  ```
  cd /var/www/sites/default/civicrm/ext
  git clone https://github.com/totten/rpow
  ```

* Setup the MySQL read-write and read-only databases -- and determine their
  DSNs.  That process is outside the scope of this README. See also: [MySQL 5.7 Reference Manual: Replication Howto](https://dev.mysql.com/doc/refman/5.7/en/replication-howto.html)

* Edit `civicrm.settings.php`. In lieu of setting `define('CIVICRM_DSN', '...')`, call this:

  ```php
  require_once '<RPOW-SRC>/autoload.php';
  rpow_init([
    'masters' => ['mysql://rw_user:rw_pass@rw_host/rw_db?new_link=true'],
    'slaves' => ['mysql://ro_user:ro_pass@ro_host/ro_db?new_link=true'],
  ]);
  ```

## Using civibuild and harvey-dent

If you're doing local development on a `civibuild` site, then you can simulate a master/slave topology using the script
`harvey-dent`.  This will create a split between two *user accounts* which have access to the same DB.  The original
MySQL account has read-write access -- and the new secondary account has read-only access.

This is handy for quick-and-dirty experiments without having a proper MySQL replication environment.

* [Setup CiviCRM to store caches in Redis.](https://docs.civicrm.org/sysadmin/en/latest/setup/cache/)

* Download the `rpow` extension, e.g.

  ```
  cd ~/buildkit/build/dmaster/web/sites/default/civicrm/ext
  git clone https://github.com/totten/rpow
  ```

* Create the read-only user and register DSNs.

  ```
  ./bin/harvey-dent -r ~/buildkit/build/dmaster/web/
  ```

The `harvey-dent` script will:

* Add a user with read-only permission for the existing Civi database.
* Create a file `civicrm.settings.d/pre.d/100-civirpow.php` to call `rpow_init()` with the appropriate credentials
  for the `masters` and `slaves`.

## Using civibuild and rundb

If you usually develop locally with a `civibuild` site, then you might temporarily spin-up a master-slave DB (via
[rundb](https://github.com/totten/rundb)) and temporarily migrate to it.  These instances run on alternate, local-only
ports with insecure passwords.

* Install the [nix package manager](https://nixos.org/nix/)

  ```
  curl https://nixos.org/nix/install | sh
  ```

* [Setup CiviCRM to store caches in Redis](https://docs.civicrm.org/sysadmin/en/latest/setup/cache/)

    * Tip: This command will download and start redis-server. Any persistence/dumps will go in /tmp.
      ```
      cd /tmp; nix run nixpkgs.redis -c redis-server
      ```

* Download the `rpow` extension, e.g.

  ```
  cd /var/www/sites/default/civicrm/ext
  git clone https://github.com/totten/rpow
  cd rpow
  ```

* Setup rundb

  ```
  ## Start the temporary database process in the foreground
  ./bin/use-rundb fg

  ## Open a console; then...
  ## Migate to the new database
  ./bin/use-rundb copy on
  ```

## Using rebuild-ro

If you're doing local development on a `civibuild` site, then you can simulate a
master/slave topology using the script the script `rebuild-ro` to make a read-only
copy of your database.

* [Setup CiviCRM to store caches in Redis.](https://docs.civicrm.org/sysadmin/en/latest/setup/cache/)

* Download the `rpow` extension, e.g.

  ```
  cd /var/www/sites/default/civicrm/ext
  git clone https://github.com/totten/rpow
  ```

* Setup a config file (`rebuild-ro.conf`), esp `CIVIRO_PASS (for security) and `SITE_ROOT` (for convenience)

  ```
  cp etc/rebuild-ro.conf.example etc/rebuild-ro.conf
  vi etc/rebuild-ro.conf

* Create the read-only DB. Copy data from the main DB. Register the DSNs via civicrm.settings.d.

  ```
  ./bin/rebuild-ro
  ```

The `rebuild-ro` script will:

* Make a new database
* Copy the CiviCRM tables to the new database
* Add a user with read-only permission for the new database
* Create a file `civicrm.settings.d/pre.d/100-civirpow.php`
  to call `rpow_init()` with the appropriate credentials
  for the `masters` and `slaves`.

This is handy for simulating master=>slave replication manually. It does
not require any special mysqld options, but it does assume that you have a
`civibuild`-based environment.
