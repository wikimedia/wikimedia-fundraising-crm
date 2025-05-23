# Unsubscribe Email form

This extension adds a new form that allows you to enter an email address, find the related contact
and unsubscribe (set the opt-out flag) them from mailings.

Unsubscribe emails, defined as setting `is_opt_out = 1` on the Contact if primary email address and `is_bulkmail = 0` on the Email (for all).

## Setup

This extension adds a form with the URL `civicrm/a/#/email/unsubscribe` and a link to the menu: *Contacts->Unsubscribe email*

Access to the form is controlled by the permission: `CiviCRM UnsubscribeEmail: access unsubscribe email form` or `CiviCRM: edit all contacts`.

## Usage
It is intended to assist in data entry for unsubscribe requests.

From the screen find the emails you wish to unsubscribe, and deselect any actions you do not
wish to take before clicking unsubscribe.

![Find matching emails](docs/find.png?raw=true "Find matching emails")

![Unsubscribe emails](docs/unsubscribe.png?raw=true "Unsubscribe emails")
