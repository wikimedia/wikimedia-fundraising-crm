The files in this directory can be used in conjunction
with process-control.

Please document any added files in there

**Japanese Name flip**

- japanese_name_flip.json

This json is intended to be piped to

```
drush @wmff --user=1 -v --in=json cvapi Contact.get
```

It gets contacts with first names that join to
civicrm_contact_name_pair.name_b with a preferred
language of ja_JP (Japanese) are flips their first and
last name
