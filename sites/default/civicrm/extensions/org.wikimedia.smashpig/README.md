# org.wikimedia.smashpig

![Screenshot](/images/screenshot.png)

This extension adds a job to charge tokenized recurring payments using the SmashPig payments library.
So far, it only supports the Ingenico Connect payment processor API.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v5.6+
* CiviCRM 4+
* SmashPig payments library v0.5.5+ (installed via composer)

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl org.wikimedia.smashpig@https://gitlab.com/ejegg/org.wikimedia.smashpig/-/archive/master/org.wikimedia.smashpig-master.zip
composer install
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://gitlab.com/ejegg/org.wikimedia.smashpig.git
composer install
cv en smashpig
```

## Usage

(* FIXME: Where would a new user navigate to get started? What changes would they see? *)

## Known Issues

(* FIXME *)
