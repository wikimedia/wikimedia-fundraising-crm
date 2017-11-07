<?php namespace thank_you\generators;

class ThankYou extends RenderTranslatedPage {
	function __construct() {
		// FIXME: drupal var and settings UI
		$this->title = 'Fundraising/Translation/Thank_you_email_20171019';
		$this->proto_file = __DIR__ . '/../templates/html/thank_you.$1.html';

		$this->substitutions = array(
			// FIXME: The whitespace coming out of MediaWiki's parser is
			// unreliable and shouldn't be second-guessed.  We need to be more
			// robust here.
			"/\xc2\xa0/" => ' ', // no-break spaces confuse the rest of the replacements
			'/(<p>)?\[ifFirstnameAndLastname\]\s*/' => "{% if first_name and last_name %}\n\\1",
			'/(<p>)?\[elseifFirstnameAndLastname\]\s*/' => "{% else %}\n\\1",
			'/\s*\[endifFirstnameAndLastname\](<\/p>)?/' => "\\1\n{% endif %}",

			'/\[given name\]/' => '{{ first_name }}',
			'/\[first name\]/' => '{{ first_name }}',
			'/\[family name\]/' => '{{ last_name }}',
			'/\[last name\]/' => '{{ last_name }}',

			'/\[date\]/' => '{{ receive_date }}',

			'/\$date/' => '{{ receive_date }}',
			'/\[amount\]/' => '{{ (currency ~ " " ~ amount) | l10n_currency(locale) }}',
			'/\[contributionId\]/' => '{{ transaction_id }}',

			'/(<p>)?\[ifRecurringProblem\]/' => "{% if \"RecurringRestarted\" in contribution_tags %}\n\\1",
			'/\[endifRecurringProblem\]<\/p>/' => "</p>\n{% endif %}",
			'/(<p>)?\[ifRecurring\]\s*/' => "{% if recurring %}\n\\1",
			'/\s*\[endifRecurring\]\s*(<\/p>)?/' => "\\1\n{% endif %}",
			'/\[#?unsubscribe ((?:(?!\]).)*)\]/' => '<a href="{{ unsubscribe_link | raw }}">$1</a>',
			// All of the thank you letter's if...endif blocks should be outside p tags, not inside
			'/<p>\s*({%\s*if [^}]+})\s*/i' => "\\1\n<p>",
			'/\s*{%\s*endif\s*%}\s*<\/p>/i' => "</p>\n{% endif %}",
			// Delete paragraphs that just have a break tag
			'/\s*<p>\s*<br ?\/?>\s*<\/p>/i' => '',
			'/\[#recurringCancel ((?:(?!\]).)*)\]/' => '<a href="https://wikimediafoundation.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_payments&basic=true&language={{ locale }}">$1</a>',
		);
	}
}
