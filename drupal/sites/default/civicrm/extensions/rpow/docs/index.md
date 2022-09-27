# CiviCRM Replay-on-Write Helper (civirpow)

The extension allows sites to configure a separate
read only connection to the database. This permits
potentially-slow queries like reports and searches to
run on a replica db and reduces the chance of a big
query bringing down the live database.

* [about.md](about.md): More detailed discussion of the design
* [install.md](install.md): Installation
* [develop.md](develop.md): Development tips
