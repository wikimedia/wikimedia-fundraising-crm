# Deduper extension
## Overview

Provides tools for deduping

  - resolvers to make intelligent decisions about deduping

  - apis for code cleanup

  - alternate UI for more rapid deduping

## Features

### Deduper interface

- Deduper screen - this is an angular screen that allows you to search for duplicates using nuanced criteria. You can dedupe from this screen.
  This can be found under the contacts menu.
  ![Deduper Screen](images/Deduper.png?raw=true "Deduper screen")

### Resolvers

CiviCRM offers the ability to do safe merges and force merges. These
are invoked when you run the dedupe batch script or when you choose
batch merge in the inbuilt UI or when you use the deduper UI.

If CiviCRM determines the fields are in conflict then the deduper may
be able to resolve that conflict so that they merge in a sensible way.

For example if one version of the contact has the first name
'Jose' and the other has 'José' then the deduper will resolve that
conflict by choosing 'José'.

In force mode the safe-mode resolvers will be applied first and
then it will resolve any unresolved differences using your
configured resolution method (e.g prefer details from most recent donor).

More information about resolvers is in [resolvers](resolvers.md)

### Data management

As clean data is an important part of deduping the deduper extension
provides some data hygiene apis to do things like ensure
contacts don't have multiple primary email addresses and to
parse out (mostly Anglicised) names into parts and to find
Japanese names where the family name is in the first name.

More information about data management is in [data-management](data-management.md)

## Requirements

* CiviCRM 5.28+ but later CiviCRM releases work with later
versions of deduper, which have more fixes / features.

## Known Issues



## Future plans
Integrate these feature notes into the docs

- api Merge.redo - undeletes a contact deleted by merge & re-merges - useful if contributions etc got added to the deleted contact.

- search tasks - adds a task to search to find duplicates for selected contacts

- contact task - adds an action to contact summary to find duplicates for the shown contact. We have
  a rule we use with this called 'fishing net' which uses fields like 'state' & 'city' to cast a wider net.
  These fields can't be used on large databases for a group dedupe for performance reasons but on a search
  for individual matches it works - e.g we probably have hundreds if not thousands of 'John Smith's in our database so if deduping
  a John Smith we want to compare him with contacts with the same first & last name and at least one
  address field in common.

- merge conflicts api - does analysis on current conflicted merges to look for patterns.


