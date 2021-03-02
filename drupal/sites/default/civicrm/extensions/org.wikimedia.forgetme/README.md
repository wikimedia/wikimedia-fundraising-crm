# org.wikimedia.forgetme

![Screenshot](images/forgetme.png)

This extension helps you to honour privacy requests by removing data from CiviCRM about donors/contacts.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v5.6+
* CiviCRM 5.6

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl org.wikimedia.forgetme@https://github.com/FIXME/org.wikimedia.forgetme/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/org.wikimedia.forgetme.git
cv en forgetme
```

## Usage

This adds a new action for contacts called 'forgetme'. On choosing this a page shows information about the contact with a button giving the option to 'forget' the contact. This button takes an optional reference which is used in a created activity.

Name and address data is retained for tax reasons but data such as phone, email, some activities, mailings, notes, relationships and logging is removed.

## Dev notes

The forget me button is an api call to Contact.forget api. The data displayed comes from
Contact.showme.

These 2 apis in turn call the any other apis with a forgetme or showme action. This could be
in this extension - or in a different extension - e.g check the org.wikimedia.omnimail extension adds forgetme for omnimail data.

For example to add an api to delete PaymentToken information and api file would be
created in this extension api/v3/PaymentToken/Forgetme.php which
would implement civicrm_api3_payment_token_forgetme & _civicrm_api3_payment_token_forgetme_spec

Each action should have a unit test in the tests folder in this extension.

The CiviCRM Unit testing guide is here https://docs.civicrm.org/dev/en/latest/testing/phpunit/
