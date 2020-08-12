# CiviCRM Replay-on-Write: Development

## Debug Extension

The bundled extension [rpowdbg](../rpowdbg/) provides a UI to help
investigate how rpow works. I suggest enabling it.

## Usage Example (Development)

One way to see it working is to use an exaggeratedly-slow replication process where you can watch the steps. The [install.md](install.md) describes an approach using `rebuild-ro` in which synchronization is only done manually. Here are  few steps to try in the `rebuild-ro` config:

* In the browser
    * Navigate to your CiviCRM dev site
    * Do a search for all contacts
    * View a contact
    * Edit their name and save
    * Make a mental note of the CID. (Ex: `123`)
    * __Note__: The saved changes do not currently appear in the UI. Why?
      Because they were saved to the read-write master, but we're viewing data from the
      read-only slave.
* In the CLI
    * Lookup the contact record (ex: `123`) in both the master (`civi`) and slave (`civiro`) databases.
      You should see that the write went to the master (`civi`) but not the slave (`civiro`).
      ```
      SQL="select id, display_name from civicrm_contact where id = 123;"
      echo $SQL | amp sql -N civi ; echo; echo; echo $SQL | amp sql -N civiro
      ```
    * Update the slave.
      ```
      ./bin/rebuild-ro
      ```

TIP: After you finish doing development, delete the file
`civicrm.settings.d/pre.d/100-civirpow.php`.  This will put your dev site back
into a normal configuration with a single MySQL DSN.

## Unit Tests

Simply run `phpunit` without any arguments.

## TODO

Add integration tests covering DB_civirpow

cookie expires relative to first edit; should be relative to last edit

debug toolbar is wonky, and it's hard to tell if it's ux or underlying
behavior. change ux to be a full-width bar at the bottom which displays
all available info. (instead of requiring extra clicks to drilldown)

optimistic-locking doesn't work -- it always reads the timestamp from rodb
before reconnecting to rwdb. any use-case that does optimistic-locking needs
a hint to force the reconnect beforehand.

packaging as a separate project makes it feel a bit sketchy to drop hints
into civicrm-core.  consider ways to deal with this (e.g.  package as part
of core s.t.  the hint notation is built-in; e.g.  figure out a way to make
the hint-notation abstract...  like with a listner/dispatcher pattern...
but tricky b/c DB and some caches come online during bootstrap, before we've
setup our regular dispatch subsystem)
