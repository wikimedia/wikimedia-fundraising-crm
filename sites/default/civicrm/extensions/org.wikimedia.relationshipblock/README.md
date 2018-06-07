# Relationship Block
org.wikimedia.relationshipblock

![Screenshot](/images/relationship_block.gif)

This extension adds a block to the contact summary that allows editing of 
any configured relationship type with a UI similar to the employer field.

When the field is edited the relationship is updated.

Note that this works like the employer field in that there is an expectation
of only one relationship of this type. Do not use this field for relationships
where you expect a contact to have many.


The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v5.6+
* CiviCRM 5.3+

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl org.wikimedia.relationshipblock@https://github.com/FIXME/org.wikimedia.relationshipblock/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/org.wikimedia.relationshipblock.git
cv en relationshipblock
```

## Usage

The configuration for which relationship types will show is in on the normal relationship type
admin page.

Once you have configured one or more types to display on the relationship type page the
box will appear.

## Known Issues

Per above - this is envisaged for relationships where only one is expected.
