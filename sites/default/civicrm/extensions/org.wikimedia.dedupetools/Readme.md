This extension contains some tools to help with duplicates (in various states of maturity)

**Curently it *requires* Civi 5.13 - NOT later as upstream cleanup has not yet been incorporated**
- Deduper screen - this is an angular screen that allows you to search for duplicates using nuanced criteria. You can dedupe from this screen.
This can be found under the contacts menu. See [the planning doc](docs/Planning.md) for thoughts about where I see this going

- api Merge.redo - undeletes a contact deleted by merge & re-merges - useful if contributions etc got added to the deleted contact.

- colour coding - re-establishes dedupe screen colours when using shoreditch

- search tasks - adds a task to search to find duplicates for selected contacts

- contact task - adds an action to contact summary to find duplicates for the shown contact. We have
a rule we use with this called 'fishing net' which uses fields like 'state' & 'city' to cast a wider net.
These fields can't be used on large databases for a group dedupe for performance reasons but on a search
 for individual matches it works - e.g we probably have hundreds if not thousands of 'John Smith's in our database so if deduping
 a John Smith we want to compare him with contacts with the same first & last name and at least one
 address field in common.

- merge conflicts api - does anaylsis on current conflicted merges to look for patterns.

**Note**

There are a bunch of other api in there that I see as transitional. These should be
seen as internal use only I'm working to get the logic out of the form layer in code & to expose if via an api so this & other extensions
 can interact with the deduping subsystem

