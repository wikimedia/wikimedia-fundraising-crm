# wmf-thankyou

![Screenshot](/images/screenshot.png)

WMF thank you communication in the form of
- thank you email for one off donations or
- end of year summary email for recurring donors

The latter one can also be generated for individual donors from the UI
in which case non-recurring emails will be included.

The extension is licensed under [AGPL-3.0](LICENSE.txt).

## Requirements

* PHP v7.3+
* CiviCRM 5.43

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl wmf-thankyou@https://github.com/FIXME/wmf-thankyou/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/wmf-thankyou.git
cv en wmf_thankyou
```

## Usage

In the case of the End of year thank you email normally there would be
1) a one-off api call to generate a list of all the contacts to email:
2) a scheduled job to send out emails to them in small batches.

The first of these commands has been migrated over as
`drush @wmff cvapi EOYEmail.MakeJob version=4 year=2021`
This command will put a job for the job into wmf_eoy_donor_job
and a row per relevant email into wmf_eoy_donor_receipt.

The year should be the year for which donations are to be receipted
and will default to 'last year'.

## Known Issues

(* FIXME *)
