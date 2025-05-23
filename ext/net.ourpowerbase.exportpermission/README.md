# Export Permission

The Export Permission extension is designed to help restrict who has the
ability to export records in your database.

### It adds the following new permissions:

- CiviCRM Export Permissions: access export menu - Access "Export as CSV" drop down menu item from actions menu on after search/report.
- CiviCRM Export Permissions: access print menu - Access "Print" drop down menu item from actions menu on search/report.
- CiviCRM Export Permissions: access print pdf menu - Access "Print/Merge document (PDF Letter)" drop down menu item from actions menu on search/report.
- CiviCRM Export Permissions: access mailing labels menu - Access "Print Mailing Labels" drop down menu item from actions menu on search/report.

When this extension is enabled, users that are not explicitly granted this
permission do not see the export options listed in the "Actions" dropdown for Searches/Reports.

### Warning:
This extension removes the export options from the actions menu and removes access to most of
the export forms but there will always be ways to extract the data.

Enterprising hackers can figure out ways to convince CiviCRM to
provide the export. Additionally, any user that can view all Contacts can
export the data one way or another - even if it means copy and pasting from
each screen.
