This extension contains some tools to help with duplicates (in various states of maturity)

Requires CiviCRM 5.28

- Deduper screen - this is an angular screen that allows you to search for duplicates using nuanced criteria. You can dedupe from this screen.
This can be found under the contacts menu.
![Deduper Screen](docs/images/Deduper.png?raw=true "Deduper screen")


- apis email.clean, phone.clean, address.clean - these run before deduping ensuring that
  each contact has exactly one primary of each of the above and only one of each location type.
  This latter could be argued as the UI permits multiple home emails but the dedupe (and I believe
  export) is unreliable in that scenario. The UI enforces a single address per location. For
  phones it is unique as a location-type combo.

- api Merge.redo - undeletes a contact deleted by merge & re-merges - useful if contributions etc got added to the deleted contact.

- search tasks - adds a task to search to find duplicates for selected contacts

- contact task - adds an action to contact summary to find duplicates for the shown contact. We have
a rule we use with this called 'fishing net' which uses fields like 'state' & 'city' to cast a wider net.
These fields can't be used on large databases for a group dedupe for performance reasons but on a search
 for individual matches it works - e.g we probably have hundreds if not thousands of 'John Smith's in our database so if deduping
 a John Smith we want to compare him with contacts with the same first & last name and at least one
 address field in common.

 - merge resolvers - adds merge resolvers so some conflicts can be resolved in safe mode.
 Current resolvers are
  - the Yes resolver which allows to choose yes-no fields to resolve as 'YES'  - useful
 for things like is_opt_out.
 ![Resolvers](docs/images/Settings.png?raw=true "Deduper screen")
 - The Uninformative characters resolver. This strips a range of white space and punctuation characters out
 when comparing names. Currently the list is hard coded but I'm open to making it configurable. It also has a shorter
 list of characters that it will strip only if that resolves the conflict. For example a '.' is stripped in the
 uninformative characters resolver as that will mean later the initial resolver has a better chance of working.
 By contract the "'" preferred in.
 - The diacritics resolver - chooses José over Jose
 - The Misplaced Name resolver. This addresses the situation where it can determine the full name is in the first
 or last name field.
 - The Initials resolver. This addresses the situation where it can determine the Initial is in the first or
 last name field.
 - The silly names resolver. The ensures that a number in a name field or a known 'silly' name
 does not block a merge (currently 'first', 'last' & 'blah').
 - The preferred contact field resolver. This allows you set fields as being 'use whatever my preferred contact uses'.
 Preferred contact is determined by a setting - current options are most recently created, least recently created,
 most recently modified, least recently modified, most recent donor, most prolific donor.
See [the resolvers doc](docs/resolvers.md) for details about the conflict resolutions
documented so far.
- The equivalent address resolver. Resolves (some) cases where the addresses are the same
 but one has more detail (eg. one is just the country & the other is a full address in that country)

- merge conflicts api - does analysis on current conflicted merges to look for patterns.

**Note**

The apis Merge.getcount & Merge.mark_duplicates should be
seen as internal use only I'm working to get the logic out of the form layer in code & to expose it via an api so this & other extensions
 can interact with the deduping subsystem

