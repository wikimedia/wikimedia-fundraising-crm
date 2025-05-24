cv api4 Role.create +v name=donor_services +v 'label=Donor Services' +v 'permissions=translate CiviCRM'
cv api4 Role.create +v name=civicrm_admin +v 'label=CiviCRM admin' +v 'permissions=access export menu,translate CiviCRM,allow Move Contribution'
cv api4 RolePermission.update +v granted_staff=1 +w 'name IN ["access export menu","access pdf menu","access print menu","access mailing labels menu"]'
