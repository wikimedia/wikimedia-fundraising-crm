This extension exposes external mailing providers to CiviCRM. It was intended
to be generic enough to support the addition of more functionality and providers.
However, over time the likelihood of other providers has dwindled and it
has added functionality that is only appropriate to Acoustic.

*Currently supported:*
- Acoustic (formerly Silverpop)

*Currently supported Functionality*
- Retrieval of mailings from provider & storage in CiviCRM
- Retrieval of per-recipient data relating to the outcomes of sending mailings
- Updating individual contacts (limited but notably Snooze functionality)
- Retrieving per contact details from Acoustic

*Dependencies*
-  This extension has an external dependency on the Extended Mailing Statistics CiviCRM
extension if you wish to store mailings data. The omnimailing.load api function
stores statistical data about mailings to the tables created by this extension. This
function does not degrade gracefully, but it can be bypassed. omnimailing.get api
function does not store statistical data, allowing you to store it yourself.

  Subject to resolution of a pull request I am using [my fork](https://github.com/eileenmcnaughton/au.org.greens.extendedmailingstats) of the extension  - I have had some discussions with the Australian Green party about agreeing the changes in principle.

- The extension has internal dependencies on 3 composer packages
1. [Omnimail](https://github.com/gabrielbull/omnimail)

  Omnimail is a package that exposes multiple mailers in a standardised way.
  The focus of Omnimail was on sending individual mails.
  I discussed with the maintainer & he was open to adding interaction with bulk mailings so
  I worked with him to add a factory class. Pending his consideration of open PRs
  this factory class wrapper is the main thing Omnimail is currently delivering. I have
  proposed interfaces for Mailing & Recipients

  However, I think collaborating towards a standardised interface is a good thing going forwards.
  In addition I think we could wind up implementing sending of mailings and that would
  leverage the interfaces in that class much more. I currently have [a PR open against the repo](https://github.com/gabrielbull/omnimail/pull/27)

2. [Omnimail-silverpop](https://github.com/eileenmcnaughton/omnimail-silverpop)

  This extension makes silverpop available to Omnimail. It provides an interface between the
  standardisation of Omnimail & the underlying silverpop integration package.

3. [Silverpop-php-connector](https://github.com/mrmarkfrench/silverpop-php-connector)
  This extension exposes most of the Silverpop apis.

*Data storage*
  This extension stores data retrieved in the following places:
  1. civicrm_mailing table (e.g html & text of emails)
  2. civicrm_mailing_stats table - statistics about emails - provided by extendedmailingstats extension
  3. civicrm_mailing_provider_data - provided by this extension, stores data about mailing recipient
  actions (e.g contact x was sent a mailing on date y or contact z opened a mailing on date u)
  4. civicrm_activity table - separate jobs offer the chance to transfer mailing_provider_data to
  activities. Depending on size this may only be done for some of the data.
  5. civicrm_campaign - when retrieving mailings a campaign is created for each of them. The
  campaigns can be custom-data-extended for putting extra information on reports. In addition
  both contributions & activities (& even recurring contributions)can be linked to campaigns, providing
  good reporting options.

*APIs*

The main way to use this extension is by scheduling apis. The following apis are exposed:
- **Omnimailing.get** - retrieve mailing data (text, stats)
- **Omnimailing.load** - retrieve mailing data and store to tables (a combination of civicrm_campaign, civicrm_mailing and civicrm_mailing_stats)

- **Omnirecipient.get** - retrieve per-recipient-per-action data (Sent, Opened, Opt out)
- **Omnirecipient.load** - retrieve mailing data and store to civicrm_mailing_provider_data
- **Omnirecipient.process_unsubscribes**  - process from civicrm_mailing_provider_data to create unsubscribe activities.
- **OmniContact.get (v4)** - retrieves real-time data about a contact from the mailing provider
- **OmniContact.create (v4)** - push contact update to Mailing provider (currently only group fields & snooze work)
- **OmniContact.snooze (v4)** - queue a contact to have their snooze date set (runs via coworker)
- **OmniGroup.create (v4)** - create a group in Acoustic from a CiviCRM group
- **OmniGroup.push(v4)** - create a group in Acoustic and push contacts in CiviCRM in that group
to that group in Acoustic (note this is not used in prod & needs to be coverted to use coworker
to run the queue)

e.g drush cvapi omnimailing.load mailing_provider=Silverpop username=xxx password=yyy

*Viewing Data*

The main ways to view data are:
- report on mailings & statistics at civicrm/report/au.org.greens.extendedmailingstats/extendedmailingstats?reset=1
- mysql queries on civicrm_mailing_provider_data table
- activities created against contacts (depending which apis are scheduled)
- viewing text & html downloaded into mailings.
