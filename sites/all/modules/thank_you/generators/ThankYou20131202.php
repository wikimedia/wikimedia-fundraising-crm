<?php namespace thank_you\generators;

class ThankYou20131202 extends RenderTranslatedPage {
	function __construct() {
		$this->title = 'Fundraising/Translation/Thank_you_email_20131202';
		$this->proto_file = __DIR__ . '/../templates/html/thank_you.$1.html';

		$this->substitutions = array(
            '/<p>\[ifFirstnameAndLastname\]\s*/' => "{% if first_name and last_name %}\n<p>",
            '/<p>\[elseifFirstnameAndLastname\]\s*/' => "{% else %}\n<p>",
            '/\s*\[endifFirstnameAndLastname\]<\/p>/' => "</p>\n{% endif %}",

			'/\[given name\]/' => '{{ first_name }}',
			'/\[first name\]/' => '{{ first_name }}',
			'/\[family name\]/' => '{{ last_name }}',
			'/\[last name\]/' => '{{ last_name }}',

			'/\[date\]/' => '{{ receive_date }}',
			'/\[amount\]/' => '{{ (currency ~ " " ~ amount) | l10n_currency(locale) }}',
			'/\[contributionId\]/' => '{{ transaction_id }}',

            '/<p>\[ifRecurringProblem\]/' => '{% if "RecurringRestarted" in contribution_tags %}<p>',
            '/\[endifRecurringProblem\]<\/p>/' => '</p>{% endif %}',
			'/<p>\[ifRecurring\]/' => '{% if recurring %}<p>',
			'/\[endifRecurring\]<\/p>/' => '</p>{% endif %}',

			'/\[#twitter ((?:(?!\]).)*)\]/' => '<a href="https://twitter.com/Wikipedia">$1</a>',
			'/\[#identica ((?:(?!\]).)*)\]/' => '<a href="https://identi.ca/wikipedia">$1</a>',
			'/\[#google ((?:(?!\]).)*)\]/' => '<a href="https://plus.google.com/+Wikipedia/posts">$1</a>',
			'/\[#facebook ((?:(?!\]).)*)\]/' => '<a href="https://www.facebook.com/wikipedia">$1</a>',
			'/\[#blog ((?:(?!\]).)*)\]/' => '<a href="https://blog.wikimedia.org">$1</a>',
			// TODO: DO WE HAVE TRANSLATIONS FOR THE ANNUAL REPORT
			'/\[#annual ((?:(?!\]).)*)\]/' => '<a href="https://meta.wikimedia.org/wiki/Special:MyLanguage/Wikimedia_Foundation/Annual_Report/2012-2013/Front">$1</a>',
			// TODO: DO WE HAVE TRANSLATIONS FOR THE ANNUAL PLAN
			'/\[#plan ((?:(?!\]).)*)\]/' => '<a href="http://wikimediafoundation.org/wiki/2013-2014_Annual_Plan_Questions_and_Answers">$1</a>',
			// TODO: DO WE HAVE TRANSLATIONS FOR THE 5-YEAR, STRATEGIC PLAN
			'/\[#strategic ((?:(?!\]).)*)\]/' => '<a href="https://wikimediafoundation.org/wiki/Wikimedia_Movement_Strategic_Plan_Summary">$1</a>',
			'/\[#shop ((?:(?!\]).)*)\]/' => '<a href="https://shop.wikimedia.org">$1</a>',
			'/\[#unsubscribe ((?:(?!\]).)*)\]/' => '<a href="{{ unsubscribe_link | raw }}">$1</a>',
			'/\[#recurringCancel ((?:(?!\]).)*)\]/' => '<a href="https://wikimediafoundation.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_payments&basic=true&language={{ locale }}">$1</a>',
			'/\[#translate ((?:(?!\]).)*)\]/' => '<a href="https://meta.wikimedia.org/w/index.php?title=Special:Translate&group=page-Fundraising%2FTranslation%2FThank_you_email_20131202">$1</a>',
			'/\[#donate ((?:(?!\]).)*)\]/' => '<a href="https://donate.wikimedia.org/">$1</a>',
		);
	}
}
