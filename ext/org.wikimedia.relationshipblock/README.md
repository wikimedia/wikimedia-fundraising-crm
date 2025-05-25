# Relationship Block
org.wikimedia.relationshipblock

![Screenshot](/images/relationship_block.gif)

This extension adds a block to the contact summary that allows editing of 
any configured relationship type with a UI similar to the employer field.

When the field is edited the relationship is updated.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v5.6+
* CiviCRM 5.7+

## Installation (Web UI)

Navigate to **Administer -> System Settings -> Extensions** and select this extension from the list.

## Usage

Once installed the extension will do nothing until you select one or more relationship types for display on the contact summary page:

- Navigate to **Administer -> Customize Data & Screens -> Relationship Types** and click edit on a desired relationship type.
- Select "Display block on contact summary." for that relationship type.
- Now you can visit a contact to view and edit their key relationships.

### Extras

- To change the position of the block on the contact summary screen, install the [Contact Layout Editor](https://github.com/civicrm/org.civicrm.contactlayout) extension.
