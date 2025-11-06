# Omnimail for CiviCRM

<!-- Anchor for external links -->
<a id="api-reference"></a>

This extension exposes external mailing providers to CiviCRM. It was intended
to be generic enough to support the addition of more functionality and providers.
However, over time the likelihood of other providers has dwindled and it
has added functionality that is only appropriate to Acoustic.

This readme should be used in conjunction with [Wikimedia documentation](https://wikitech.wikimedia.org/wiki/Fundraising/Data_and_Integrated_Processes/Acoustic_Integration)
## Table of contents
- [Supported providers](#supported-providers)
- [Functionality](#functionality)
- [Dependencies](#dependencies)
- [Data storage](#data-storage)
- [APIs](#api-reference) (direct link to the full API table)
- [Viewing data](#viewing-data)

## Supported providers {#supported-providers}
<details>
<summary>Click to expand</summary>

*Currently supported:*
- Acoustic (formerly Silverpop)

</details>

## Functionality {#functionality}
<details>
<summary>Click to expand</summary>

*Currently supported Functionality*
- Retrieval of mailings from provider & storage in CiviCRM
- Retrieval of per-recipient data relating to the outcomes of sending mailings
- Updating individual contacts (limited but notably Snooze functionality)
- Retrieving per contact details from Acoustic

</details>

## Dependencies {#dependencies}
<details>
<summary>Click to expand</summary>

-  This extension has an external dependency on my fork of
   [Extended Mailing Statistics CiviCRM](https://github.com/eileenmcnaughton/au.org.greens.extendedmailingstats)
   At some point we should fold that into this extension as it is not
   separately maintained.
- The extension has internal dependencies on 3 composer packages
1. [Omnimail](https://github.com/gabrielbull/omnimail)
  Omnimail is a package that exposes multiple mailers in a standardised way.
  The focus of Omnimail was on sending individual mails. We have extended that
  to cover Acoustic but are probably the only users now.

2. [Omnimail-silverpop](https://github.com/eileenmcnaughton/omnimail-silverpop)

  This extension makes silverpop available to Omnimail. It provides an interface between the
  standardisation of Omnimail & the underlying silverpop integration package.

3. [Silverpop-php-connector](https://github.com/mrmarkfrench/silverpop-php-connector)

</details>

## Data storage {#data-storage}
<details>
<summary>Click to expand</summary>

This extension stores data retrieved in the following places:
1. `civicrm_mailing` table (e.g., HTML & text of emails)
2. `civicrm_mailing_stats` table — statistics about emails — provided by extendedmailingstats extension
3. `civicrm_mailing_provider_data` — provided by this extension, stores data about mailing recipient actions (e.g., contact x was sent a mailing on date y or contact z opened a mailing on date u)
4. `civicrm_activity` table — separate jobs offer the chance to transfer mailing_provider_data to activities. Depending on size this may only be done for some of the data.
5. `civicrm_campaign` — when retrieving mailings a campaign is created for each of them. The campaigns can be custom-data-extended for putting extra information on reports. In addition both contributions & activities (& even recurring contributions) can be linked to campaigns, providing good reporting options.

</details>

## 🔧 APIs
<!-- The anchor above this section is "api-reference" -->
The main way to use this extension is by scheduling API calls.
Both **v3** and **v4** endpoints are exposed and documented below.

| API Entity.Action | Version | Purpose / Description | Key Parameters | Notes |
|--------------------|----------|-----------------------|----------------|-------|
| **Omnimailing.get** | v3 | Retrieve mailing data (text, stats) without storing in Civi. | `mailing_provider`, `username`, `password`, filters (optional) | Use for fetching mailing data for custom handling. |
| **Omnimailing.load** | v3 | Retrieve mailing data and store in tables (`civicrm_campaign`, `civicrm_mailing`, `civicrm_mailing_stats`). | `mailing_provider`, `username`, `password` | Writes to Civi tables and (if installed) Extended Mailing Stats. |
| **Omnirecipient.get** | v3 | Retrieve per-recipient-per-action data (Sent, Opened, Opt out). | Provider connection + selection filters | Reads provider only. |
| **Omnirecipient.load** | v3 | Retrieve recipient-level data and store in `civicrm_mailing_provider_data`. | Provider connection + selection filters | Stores data for reporting. |
| **Omnirecipient.process_unsubscribes** | v3 | Process `civicrm_mailing_provider_data` rows to create unsubscribe activities. | None (typically scheduled job) | Creates unsubscribe activities. |
| **OmniContact.get** | v4 | Retrieve real-time contact snapshot from Acoustic/Silverpop. | `database_id`, `email`, `contact_id`, `group_identifier`, `recipient_id`, `check_permissions` | Returns provider data for that contact. |
| **OmniContact.create** | v4 | Push contact updates to provider (currently supports **group fields** and **snooze**). | `database_id`, `email`, `values`, `group_id` | Used to sync Civi updates to Acoustic. |
| **OmniContact.snooze** | v4 | Queue a contact to set their snooze date in provider (runs via CoWorker). | `email`, `contact_id`, `recipient_id`, `database_id` | Queued async update; logs snooze activity. |
| **OmniContact.verifySnooze** | v4 | Check that snoozed contacts in Civi are snoozed in provider. | `limit`, `database_id`, `mail_provider` | Chains to `OmniContact.create` as needed. |
| **OmniContact.upload** | v4 | Upload CSV via Acoustic ImportList. Maps headers to fields. | `csv_file`, `is_already_uploaded`, `database_id` | Used for nightly Database Update jobs. |
| **OmniPhone.update** | v4 | Fetch SMS/consent data for a phone record from provider and update local `PhoneConsent` + `Activity`. | `database_id`, `phone_id`, `contact_id`, `recipient_id` | Syncs SMS consent state; logs an activity. |
| **OmniPhone.batchUpdate** | v4 | Iterate through phones needing data and call `update` for each. | `database_id`, `limit`, `offset` | Batch processor wrapper around `update`. |
| **OmniGroup.create** | v4 | Create provider-side group from a Civi group. | `group_id`, `database_id` | Mirrors Civi group in Acoustic. |
| **OmniGroup.push** | v4 | Create provider group and push members from Civi. | `group_id`, `database_id` | Not used in production; should be queued. |
| **OmniGroupMember.load** | v4 | Load provider group membership into local table. | `database_id`, `group_id` | Imports membership data. |
| **OmniGroupMember.delete** | v4 | Delete a provider/local group membership record. | `database_id`, `group_id` | Cleanup operation. |
| **OmniActivity.get** | v4 | Retrieve activity-like data feed from provider. | `database_id`, filters | Read provider events. |
| **OmniActivity.load** | v4 | Load activity feed data into Civi tables. | `database_id`, filters | Persists Acoustic activity feed. |
| **OmniMailJobProgress.check** | v4 | Report Acoustic/ImportList job progress. | `database_id`, `job_id` | Monitor ongoing imports. |
| **OmniMailJobProgress.checkStatus** | v4 | Query local CoWorker queue job progress. | `queue_name`, `limit` | Reports queue status. |
| **PhoneConsent.remoteUpdate** | v4 | Push consent record to provider. | `phone_id`, `recipient_id`, `contact_id`, `is_test` | Sends consent flag update upstream. |

**Shared parameters:**
Most v4 actions also accept:
- `mail_provider` (default `"Silverpop"`)
- `database_id` (auto-resolved from settings if omitted)
- `limit`, `offset` for batching
- `client` (optional Guzzle client override)

Example:
```bash
drush cvapi omnimailing.load mailing_provider=Silverpop username=xxx password=yyy
cv api4 OmniContact.upload csv_file=/tmp/contacts.csv
```

## Viewing data {#viewing-data}
<details>
<summary>Click to expand</summary>

The main ways to view data are:
- report on mailings & statistics at `civicrm/report/au.org.greens.extendedmailingstats/extendedmailingstats?reset=1`
- MySQL queries on `civicrm_mailing_provider_data` table
- Activities created against contacts (depending which APIs are scheduled)
- Viewing text & HTML downloaded into mailings

</details>
