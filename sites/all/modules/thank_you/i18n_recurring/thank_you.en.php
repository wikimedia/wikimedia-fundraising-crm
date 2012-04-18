<?php

$TYmsgs_recur['en'] = array(
	"thank_you_from_name" => "Sue Gardner",
	"thank_you_to_name" => "{contact.display_name}",
	"thank_you_to_name_secondary" => "friend of the Wikimedia Foundation",
	"thank_you_subject" => "Thank you from the Wikimedia Foundation",
	"thank_you_unsubscribe_title" => "Wikimedia Foundation unsubscribe",
	"thank_you_unsubscribe_button" => "Unsubscribe",
//	"thank_you_unsubscribe_confirm" => "Are you sure you want to unsubscribe <b>{contact.email}</b>?",
//	"thank_you_unsubscribe_warning" => "This will opt you out of emails from the Wikimedia Foundation sent to you as a donor. You may still receive emails to this email address if it is associated with an account on one of our projects. If you have any questions, please contact <a href=\"mailto:donations@wikimedia.org\">donations@wikimedia.org</a>.",
	"thank_you_unsubscribe_success" => "You have successfully been removed from our mailing list",
	"thank_you_unsubscribe_delay" => "Please allow up to four (4) days for the changes to take effect. We apologize for any emails you receive during this time. If you have any questions, please contact <a href='donations@wikimedia.org'>donations@wikimedia.org</a>.",
	"thank_you_unsubscribe_fail" => "There was an error processing your request, please contact <a href='mailto:donations@wikimedia.org'>donations@wikimedia.org</a>.",
);
$TYmsgs_recur['en']['thank_you_body_plaintext'] =<<<'EOD'
Dear {contact.first_name},

You are amazing, thank you so much for donating to the Wikimedia Foundation!

This is how we pay our bills -- it's people like you, giving five dollars, twenty dollars, a hundred dollars. My favourite donation last year was five pounds from a little girl in England, who had persuaded her parents to let her donate her allowance. It's people like you, joining with that girl, who make it possible for Wikipedia to continue providing free, easy access to unbiased information, for everyone around the world. For everyone who helps pay for it, and for those who can't afford to help. Thank you so much.

I know it's easy to ignore our appeals, and I'm glad that you didn't. From me, and from the tens of thousands of volunteers who write Wikipedia: thank you for helping us make the world a better place. We will use your money carefully, and I thank you for your trust in us.

Thanks,
Sue Gardner
Wikimedia Foundation Executive Director

---

For your records: Your donation on {contribution.date} was {contribution.source}. This donation is part of a recurring subscription. Monthly payments will be debited by the Wikimedia Foundation until you notify us to stop. If you’d like to cancel the payments please see our easy cancellation instructions at:

https://wikimediafoundation.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_payments&basic=true&country={$country}language={$language}{/if}

Recurring monthly donations scheduled for the months of January, February, and March were not processed. Your scheduled donation subscription will resume recurring monthly as of this notification. We apologize for any confusion this may have caused. If you have any questions, please contact giving@wikimedia.org

This letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. The Wikimedia Foundation, Inc. is a non-profit charitable corporation with 501(c)(3) tax exempt status in the United States. Our address is 149 New Montgomery, 3rd Floor, San Francisco, CA, 94105. U.S. tax-exempt number: 20-0049703

---

Opt out option:
We'd like to keep you as a donor informed of our community activities and fundraisers.  If you prefer however not to receive such emails from us, please click below and we'll take you off the list:

{unsubscribe_link}
EOD;

$TYmsgs_recur['en']['thank_you_body_html'] =<<<'EOD'
<p>Dear {contact.first_name},</p>

<p>You are amazing, thank you so much for donating to the Wikimedia Foundation!</p>

<p>This is how we pay our bills -- it's people like you, giving five dollars, twenty dollars, a hundred dollars. My favourite donation last year was five pounds from a little girl in England, who had persuaded her parents to let her donate her allowance. It's people like you, joining with that girl, who make it possible for Wikipedia to continue providing free, easy access to unbiased information, for everyone around the world. For everyone who helps pay for it, and for those who can't afford to help. Thank you so much.</p>

<p>I know it's easy to ignore our appeals, and I'm glad that you didn't. From me, and from the tens of thousands of volunteers who write Wikipedia: thank you for helping us make the world a better place. We will use your money carefully, and I thank you for your trust in us.</p>

<p>Thanks,<br />
<br />
<b>Sue Gardner</b><br />
Wikimedia Foundation Executive Director</p>

<p>For your records: Your donation on {contribution.date} was {contribution.source}. This donation is part of a recurring subscription. Monthly payments will be debited by the Wikimedia Foundation until you notify us to stop. If you’d like to cancel the payments please see our <a href="https://wikimediafoundation.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_payments&basic=true&country={$country}language={$language}"> easy cancellation instructions.</a></p>

<p>This letter may serve as a record of your donation. No goods or services were provided, in whole or in part, for this contribution. The Wikimedia Foundation, Inc. is a non-profit charitable corporation with 501(c)(3) tax exempt status in the United States. Our address is 149 New Montgomery, 3rd Floor, San Francisco, CA, 94105. U.S. tax-exempt number: 20-0049703</p>

<p>Recurring monthly donations scheduled for the months of January, February, and March were not processed. Your scheduled donation subscription will resume recurring monthly as of this notification. We apologize for any confusion this may have caused. If you have any questions, please contact <a href="mailto:giving@wikimedia.org">giving@wikimedia.org.</a></p>

<div style="padding:0 10px 5px 10px; border:1px solid black;">
<p><i>Opt out option:</i></p>
<p>We'd like to keep you as a donor informed of our community activities and fundraisers.  If you prefer however not to receive such emails from us, please click below and we'll take you off the list:</p>
<a style="padding-left: 25px;" href="{unsubscribe_link}">Opt out</a>
</div>
EOD;


