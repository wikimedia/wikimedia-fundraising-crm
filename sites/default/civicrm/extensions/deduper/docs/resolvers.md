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

