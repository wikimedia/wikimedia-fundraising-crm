# import_extensions

![Screenshot](/images/screenshot.png)

This extension allows additional data sources for importing
into CiviCRM. The data sources are

- uploaded file
- json

**Uploaded file**

The use case for this is when files are too large for the normal
upload. In this case a user may have access to ftp a file
to a location on the server, or an automated process
may do so. However, the end user can potentially
manage the mappings.

In the case of the uploaded file it is necessary for the
sysadmin to define the directory the file can be uploaded to.
e.g
`define('IMPORT_EXTENSIONS_UPLOAD_FOLDER', /var/www/abc/xyz');

Absent this define a sample data set is available for testing.

Note there is no intension to use a setting for the upload folder
as the define requires command line server access & is more secure.

**JSON**

This is a limited opportunity to pull in data from a very simple
external api. It supports passing a header in the format 'x-auth:abc'
and a url.


The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.4+
* CiviCRM 5.70

## Installation (Web UI)

Learn more about installing CiviCRM extensions in the [CiviCRM Sysadmin Guide](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/).

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl import_extensions@https://github.com/FIXME/import_extensions/archive/master.zip
```
or
```bash
cd <extension-dir>
cv dl import_extensions@https://lab.civicrm.org/extensions/import_extensions/-/archive/main/import_extensions-main.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/import_extensions.git
cv en import_extensions
```
or
```bash
git clone https://lab.civicrm.org/extensions/import_extensions.git
cv en import_extensions
```

## Getting Started

(* FIXME: Where would a new user navigate to get started? What changes would they see? *)

## Known Issues

(* FIXME *)
