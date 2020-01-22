# Resolving conflicts with the deduper

The  deduper extension provides various  conflict resolutions. The  following resolutions occur by default

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

![Deduper Screen](../docs/images/lukeNamePair.gif?raw=true "Saving a name pair")

## Still in WMF custom code - to be ported

- diacritic resolver
- casing resolver.
