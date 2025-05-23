# Matching Gifts

This extension connects to providers of data describing companies' policies around matching employee donations.

It creates a custom field group 'Matching Gift Policies' to hold this data and three API calls to manage it.
The extension is licensed under [GPL-3.0+](LICENSE.txt).

## Requirements

* PHP v7.0+
* CiviCRM v5.0+

## Installation (Web UI)

This extension has not yet been published for installation via the web UI.

## Installation (CLI, Zip)

Sysadmins and developers may download the `.zip` file for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
cd <extension-dir>
cv dl matching-gifts@https://github.com/FIXME/matching-gifts/archive/master.zip
```

## Installation (CLI, Git)

Sysadmins and developers may clone the [Git](https://en.wikipedia.org/wiki/Git) repo for this extension and
install it with the command-line tool [cv](https://github.com/civicrm/cv).

```bash
git clone https://github.com/FIXME/matching-gifts.git
cv en matching_gifts
```

## Usage
The following three API calls can be called with cvapi on the command line:

* MatchingGiftPolicies.Fetch uses the information provider's API to search for policies of companies matching our criteria, and returns them in the API result.

* MatchingGiftPolicies.Sync fetches all new policy data from the provider and adds or updates Organization-type contact records in Civi for each company.

* MatchingGiftPolicies.Export creates a CSV listing company IDs and names for use in front-end donation forms.

The only provider implemented as of June 2020 is [HEPData](https://www.hepdata.com/), recently acquired by [SSBinfo](https://ssbinfo.com/).

Configuration is provider-specific. API keys and company match policy filters can be specified in civicrm.settings.php like so:

```php
$civicrm_setting['domain']['matchinggifts.ssbinfo_credentials'] = [
  'api_key' => 'Abcdef1234567890gHIJ',
];
$civicrm_setting['domain']['matchinggifts.ssbinfo_matched_categories'] = [
  'educational_services',
  'educational_funds',
  'libraries',
];
```

## Known Issues

There is no provision made yet for removing company matching gift policies if they are removed from the third party database.
