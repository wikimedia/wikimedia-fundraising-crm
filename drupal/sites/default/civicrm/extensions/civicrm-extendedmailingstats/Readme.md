# ExtendedMailingStats CiviCRM extension

@todo - This was originally an extension from the Australian Greens butmerge this into Omnimail - we aren't really getting updates
they are not actively maintaining it & we should probably rationalise it
into our own extensions now. Having added additional columns report_id
and is_multiple_report to the civicrm_mailing_stats we have added Acoustic
specific fields - meaning we have forked it. This could all live in the
Omnimail extension - which is increasingly the Acoustic extension anyway.

This extension provides extended summary reports on CiviCRM mailings

## Status

This is currently an Alpha release.  The semantics of the columns are still being finalised.

Third party testing and feedback are encouraged.

## Installation

You can install this from github by doing:

    cd [your civicrm extensions directory]
    git@github.com:australiangreens/au.org.greens.extendedmailingstats

You will then need to enable the extension module in civicrm by going to using the civicrm admin page at:

    'Administer' -> 'Customise data and Screens' -> 'Manage Extensions'

You then go to:

    'Reports' -> 'Create Reports from Templates'

And you'll see  'Extended Mailing Stats' in the list.


## Cron job setup

Stats are collected by a cron job rather than when the report is collected.  The cron job
needs to be set up to run a command along the lines of:

    drush -r /var/www/example.org/htdocs -l example.org -u 1 civicrm-api extendedmailingstats.cron auth=0 -y

The cron job should run as the web server user.

## Data Provided

 * Mailing Name
 * Date Created
 * Start Date
 * End Date
 * recipients
 * delivered
 * bounced
 * blocked
 * suppressed
 * abuse_complaints
 * opened_total
 * opened_unique
 * unsubscribed
 * forwarded
 * clicked_total
 * clicked_unique
 * trackable_urls
 * clicked_contribution_page
 * contributions_count
 * contributions_total


### Mailing Name

The name of the mailing


### Date Created

The date on which the Mailing was created.  It may be sent significantly later, or not yet sent.


### Start Date

The time when the first non-test mailing job associated with the mailing began to be processed.

Note that in other reports, CiviCRM uses the scheduled time as the start date.  This will typically be a little earlier than the stat date of the first mailing job, as the job doesn't start until the next cron run.  (We run them every 2 minutes).

Possible change: use the scheduled date for consistency with other reports, or display the scheduled date as an extra column.


### End Date

The time when the last non-test mailing job associated with the mailing finished being processed.

### recipients

The number of recipients the email was configured to be delivered to.  This is recorded explicitly in the database in relation to the mailing, and this figure will not subsequently change as membership of target groups changes.

### delivered

The number of actual deliveries made which are associated with non-test jobs for the mailing.


### bounced

The number of deliveries which bounce, as recorded by civicrm.

When an external provider is used to deliver mail they are likely to provide more accurate
figures for this than CiviCRM will determine itself as not all bounces may reach CiviCRM.

### blocked

The number of deliveries subject to hard blocks by recipient email providers.

### suppressed

The number of deliveries suppressed by the external email provider (if one is used). The provider
may have additional information about mailing preferences that it will impose.


### abuse_complaints

If using an external provider they may receive abuse complaints (e.g people marking mail as spam).


### trackable_urls


The number of different trackable URLs in the mailing.  Note that this counts the URLs, not the links, whereas it's not unusual for the same URL to appear more than once.  The CiviCRM data structure does not allow us to distinguish between these links.


### opened_total

CiviCRM embeds a transparent single pixel image in sent emails, so that whenever that email is displayed, the image is loaded from civicrm, and an event is recorded, identifying the mailing, the user the email was sent to and a timestamp.

For each mailing, This field currently records the number of such events.  Ie if the same user opens the email more than once, it is counted multiple times.

### opened_unique

Number of unique opens - the number of unique contacts who have opened the mail.


### clicked_total, clicked_unique


CiviCRM substitutes URLs in sent emails, so that whenever a user clicks a link, it goes to a CiviCRM URL where the click is recorded, and the user is then redirected to the target URL.  The event record identifies the mailing, the user the email was sent to, the target URL and a timestamp.

It is not possible to distinguish between clicks on different links to the same URL which may appear in the same mailing.

Current:

Total Clicks records the number of click events.  Ie if the same user clicks through from the same email more than once, it is counted multiple times, and if they click multiple links those are all counted also.

Unique Clicks records the number of Users who clicked on a link

Proposed change:

Unique Clicks arguably be better to count more than once where one user clicks more than one link.

Total Clicks semantics currently lines up pretty well with its name, but may not be what's wanted.

"Unique" is vague.  Unique combination of what?  Maybe we want better naming. eg "Click-through Events", "Users Who Clicked", and one that also counts separately clicks on different urls by the same user (how should we label that?).

### gmail_recipients, gmail_delivered, gmail_opened, gmail_clicked_total, gmail_clicked_unique


Exactly as for the non-gmail equivalents, except that reporting is only for those users with "@gmail.com" email addresses.


### unsubscribed

Civicrm records unsubscribe events associated with the mailing.

[Presumably this works in much the same manner as other click throughs?, with an unsubscribe link at the bottom?]

This column counts all unsubscribe events.  I'm not clear on whether this is done when the unsubscribe action is completed, or when the URL is clicked, so I'm unsure what happens if a user clicks on the unsubscribe link more than once without necessarily even completing the unsubscribe process.

Looking in the database though, it is clear that there are times where multiple events are being counted which involve the same mailing and the same user.

Proposed change: count number of users associated with events rather than number of events.  Rename column as "Unsubscribed Users"


### forwarded

CiviCRM records when users use its mechanism for forwarding emails.  Some CiviCRM mailings includea link for this, but not all.  Where such a link is present, and used, the event is recorded, much as for other events.

Currently we record the number of forwarding events.  I think this is probably useful, as multiple forwarding events from the same user are likely to be forwarded to different recpients.  It may be possible to verify that, but I haven't investigated as yet.  I note that there's a 'dest_queue_id' recorded with each such event that likely leads to the recipient info.

### clicked_contribution_page

This is a count of the total number of click events associated with the mailing where the url involved is recognised as being for a civicrm contribution page.  Ie it matches on the url path (after the domain name) starting with "/civicrm/contribute/transact" .

Ie Currently if one user clicks multiple times it's counted more than once.

Note that where the mailing contains a URL with a different format which redirects to the CiviCRM page, we can't count clicks on those links.

Would we prefer to count the number of users who clicked on a recognised contribution link?


### contributions_count

For each mailing, we identify the recognisable contribution page URLs in the mailing, and we count the contributions associated with those URLs which are made in the 48 hour period after the Scheduled time of the mailing by a contact who recieved the mailing.

It's not all htat unusual for a single contact to donate more than once.  Each such contribution is counted.

### contributions_total


The contributions associated with the mailing are collected as for contributions_48hrs_count, but this column gives the total amount contributed.




## Authorship

This module was developed by Andrew McNaughton <andrew@mcnaughty.com> for the Australian Greens <http://greens.org.au>





