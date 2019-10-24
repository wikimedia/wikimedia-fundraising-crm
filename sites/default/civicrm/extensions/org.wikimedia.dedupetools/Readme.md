This extension contains some tools to help with duplicates (in various states of maturity)

Requires CiviCRM 5.18

- Deduper screen - this is an angular screen that allows you to search for duplicates using nuanced criteria. You can dedupe from this screen.
This can be found under the contacts menu.
![Deduper Screen](docs/images/Deduper.png?raw=true "Deduper screen")

See [the planning doc](docs/Planning.md) for thoughts about where I see this going

- api Merge.redo - undeletes a contact deleted by merge & re-merges - useful if contributions etc got added to the deleted contact.

- search tasks - adds a task to search to find duplicates for selected contacts

- contact task - adds an action to contact summary to find duplicates for the shown contact. We have
a rule we use with this called 'fishing net' which uses fields like 'state' & 'city' to cast a wider net.
These fields can't be used on large databases for a group dedupe for performance reasons but on a search
 for individual matches it works - e.g we probably have hundreds if not thousands of 'John Smith's in our database so if deduping
 a John Smith we want to compare him with contacts with the same first & last name and at least one
 address field in common.

 - merge resolvers - adds merge resolvers so some conflicts can be resolved in safe mode.
 Currently only the Yes resolverr which allows to choose yes-no fields to resolve as 'YES'  - useful
 for things like is_opt_out.
 ![Resolvers](docs/images/Settings.png?raw=true "Deduper screen")

- merge conflicts api - does anaylsis on current conflicted merges to look for patterns.

**Note**

The apis Merge.getcount & Merge.mark_duplicates should be
seen as internal use only I'm working to get the logic out of the form layer in code & to expose if via an api so this & other extensions
 can interact with the deduping subsystem

