<?php

$messages['en'] = array(
	"thank_you_from_name" => "Sue Gardner",
	"thank_you_to_name" => "{contact.display_name}",
	"thank_you_to_name_secondary" => "Wikimedian",
	"thank_you_subject" => "Thank you from the Wikimedia Foundation",
	"thank_you_unsubscribe_title" => "Wikimedia Foundation Unsubscribe",
	"thank_you_unsubscribe_button" => "Unsubscribe",
	"thank_you_unsubscribe_confirm" => "Are you sure you want to unsubscribe <b>{contact.email}</b>?",
	"thank_you_unsubscribe_warning" => "This will opt you out of emails from the Wikimedia Foundation sent to you as a donor. You may still receive emails to this email address if it is associated with an account on one of our projects. If you have any questions, please contact <a href=\"mailto:donations@wikimedia.org\">donations@wikimedia.org</a>.",
	"thank_you_unsubscribe_success" => "You have been successfully removed from our mailing list.",
	"thank_you_unsubscribe_delay" => "Please allow up to seven (7) days for the changes to take effect. We apologize for any emails you receive during this time. If you have any questions, please contact <a href=\"mailto:donations@wikimedia.org\">donations@wikimedia.org</a>.",
	"thank_you_unsubscribe_fail" => "There was an error processing your request, please contact <a href=\"mailto:donations@wikimedia.org\">donations@wikimedia.org</a>.",
);
$messages['en']['thank_you_body_plaintext'] =<<<'EOD'
Dear {contact.first_name},

Thank you for your gift of {contribution.source} to the Wikimedia Foundation, received on {contribution.date}. I’m very grateful for your support.

Your donation celebrates everything Wikipedia and its sister sites stand for: the power of information to help people live better lives, and the importance of sharing, freedom, learning and discovery. Thank you so much for helping to keep these projects freely available for their more than 400 million monthly readers around the world.

Your money supports technology and people. The Wikimedia Foundation develops and improves the technology behind Wikipedia and nine other projects, and sustains the infrastructure that keeps them up and running. The Foundation has a staff of about fifty, which provides technical, administrative, legal and outreach support for the global community of volunteers who write and edit Wikipedia.

Many people love Wikipedia, but a surprising number don't know it's run by a non-profit. Please help us spread the word by telling a few of your friends.

And again, thank you for supporting free knowledge.

Sincerely Yours,


Sue Gardner
Executive Director

* To donate: http://donate.wikimedia.org
* To visit our Blog: http://blog.wikimedia.org
* To follow us on Twitter: http://twitter.com/wikimedia
* To follow us on Facebook: http://www.facebook.com/wikipedia

We'll remind you by email next year around this time to renew your donation.  If you'd rather not receive an email reminder from us, please click below and we'll take you off the list:
{unsubscribe_link}

This letter can serve as a record for tax purposes. No goods or
services were provided, in whole or in part, for this contribution.
The Wikimedia Foundation, Inc. is a non-profit charitable corporation
with 501(c)(3) tax exempt status in the United States.
Our address is 149 New Montgomery, 3rd Floor, San Francisco, CA, 94105.
Tax-exempt number: 20-0049703
EOD;

$messages['en']['thank_you_body_html'] =<<<'EOD'
<p>Dear {contact.first_name},</p>

<p>Thank you for your gift of {contribution.source} to the Wikimedia Foundation, received on {contribution.date}. I’m very grateful for your support.</p>

<p>Your donation celebrates everything Wikipedia and its sister sites stand for: the power of information to help people live better lives, and the importance of sharing, freedom, learning and discovery. Thank you so much for helping to keep these projects freely available for their more than 400 million monthly readers around the world.</p>

<p>Your money supports technology and people. The Wikimedia Foundation develops and improves the technology behind Wikipedia and nine other projects, and sustains the infrastructure that keeps them up and running. The Foundation has a staff of about fifty, which provides technical, administrative, legal and outreach support for the global community of volunteers who write and edit Wikipedia.</p>

<p>Many people love Wikipedia, but a surprising number don't know it's run by a non-profit. Please help us spread the word by telling a few of your friends.</p>

<p>And again, thank you for supporting free knowledge.</p>

<p>Sincerely Yours,</p>


<p><b>Sue Gardner</b><br />
Executive Director</p>

<ul>
<li>To donate: <a href="http://donate.wikimedia.org">http://donate.wikimedia.org</a></li>
<li>To visit our Blog: <a href="http://blog.wikimedia.org">http://blog.wikimedia.org</a></li>
<li>To follow us on Twitter: <a href="http://twitter.com/wikimedia">http://twitter.com/wikimedia</a></li>
<li>To follow us on Facebook: <a href="http://www.facebook.com/wikipedia">http://www.facebook.com/wikipedia</a></li>
</ul>

<p>We'll remind you by email next year around this time to renew your donation.  If you'd rather not receive an email reminder from us, please click below and we'll take you off the list:</p>
<p><a href="{unsubscribe_link}">{unsubscribe_link}</a></p>

<p>This letter can serve as a record for tax purposes. No goods or
services were provided, in whole or in part, for this contribution.
The Wikimedia Foundation, Inc. is a non-profit charitable corporation
with 501(c)(3) tax exempt status in the United States.
Our address is 149 New Montgomery, 3rd Floor, San Francisco, CA, 94105.
Tax-exempt number: 20-0049703</p>
EOD;


