# Resolving conflicts with the deduper

The deduper extension provides various  conflict resolutions. The  following resolutions occur by default

## Misplaced names

Resolves names in the  wrong  fields e.g in the  table below  the following will be
successfully merged to Bob M Smith. Where an extra initial exists this is appended to the
existing middle initial.

|First Name|Middle Name|Last Name|
|-----------|-----------|-----------|
|Bob | | M Smith|
|Bob | | M J  Smith|
|Bob M | | Smith|
|Bob M  Smith |||
|  |  |Bob M  Smith |

## Equivalent Names

The deduper allows you to save names like 'Bob' and 'Robert' as equivalent. You can specify one
name as inferior (a misspelling or using the English alphabet where you prefer the original
 Japanese alphabet) or as a nick name or as equally good.  Depending on
the setting you choose under >> Administer >> Customize Data and Screens >> Deduper Conflict Resolution
it will prefer nick names over non-nicknames or vice versa or use your default preference
(e.g most recent donor).

![Deduper Screen](images/lukeNamePair.gif?raw=true "Saving a name pair")

- merge resolvers - adds merge resolvers so some conflicts can be resolved in safe mode.
  Current resolvers are
- the Yes resolver which allows to choose yes-no fields to resolve as 'YES'  - useful
  for things like is_opt_out.
  ![Resolvers](images/Settings.png?raw=true "Deduper screen")
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

- The equivalent address resolver. Resolves (some) cases where the addresses are the same
  but one has more detail (eg. one is just the country & the other is a full address in that country)

