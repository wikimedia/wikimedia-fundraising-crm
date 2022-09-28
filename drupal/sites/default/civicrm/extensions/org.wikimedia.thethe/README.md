# org.wikimedia.thethe

![Screenshot](images/admin_config.png)

This extension alters the sort name saved for organisations. It allows you to remove the 'The' from the beginning. The sort name is used from quicksearch so removing optional strings makes it easier for users to search and to realise the contact already exists with or without the 'the'.

Having standardised sort names is also useful for doing deduping - sort_name can be used as a field in a rule and The Justice League can then be deduped with Justice League.

The extension also permits suffixes to be removed or strings to be removed from anywhere in the sort name. The prefixes and suffixes to be replaced can be configured under Administer->Customize Data and Screens->Organization Sort Name Settings (per the screenshot above).

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.1+
* CiviCRM 5.13+

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl org.wikimedia.thethe@https://github.com/FIXME/org.wikimedia.thethe/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/org.wikimedia.thethe.git
cv en thethe
```

## Usage

If you install this it will start removing the The from the start of the sort name
of any organization that is created or edited. This difference will be most noticeable
from quicksearch - especially in organizations that have turned off the Automatic Wildcard 
search setting (turning this off gives a substantial performance improvement on larger sites)

## Known Issues

This only addresses Organizations. It could easily be extended to Households but more
core changes would be needed for any changes to Individuals.
