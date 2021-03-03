# Export Permission

The Export Permission extension is designed to help restrict who has the
ability to export records in your database. 

It adds a new permission that allows the Export option to appear in the drop
down Actions menu after a search.

When this extension is enbled, users that are not explicitly granted this
permission do not see the Export option listed.

**Please note**, this extension does not technically prevent a user from
exporting a CiviCRM database, it *only* removes that option from the Actions
drop down list. Enterprising hackers can figure out ways to convince CiviCRM to
provide the export. Additionally, any user that can view all Contacts can
export the data one way or another - even if it means copy and pasting from
each screen.

Additionally, if you don't want a user to have the export option, be sure to
remove their permission to access CiviReports (since CiviReports provides a
different path to exporting records).
