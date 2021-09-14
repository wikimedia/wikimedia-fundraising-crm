This folder declares the various roles
and permissions wmf uses

It has

roles.txt - list of our roles
{$roleName.txt} - list of permissions for the role.

The format is
add Administer CiviCRM

where 'add' or 'remove' are the actions and
'Administer CiviCRM' is the permission.

Note that for some roles (e.g administrator)
there are some starting permissions.

This format reflects a the buildkit
command's expectations.
