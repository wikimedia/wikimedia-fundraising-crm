<?php namespace thank_you\generators;

class ThankYou extends RenderTranslatedPage {
	function __construct() {
		$this->title = 'Fundraising/Translation/Thank_you_email_20161128';
		$this->proto_file = __DIR__ . '/../templates/html/thank_you.$1.html';

		$this->substitutions = array(
			// FIXME: The whitespace coming out of MediaWiki's parser is
			// unreliable and shouldn't be second-guessed.  We need to be more
			// robust here.
            '/(<p>)?\[ifFirstnameAndLastname\]\s*/' => "{% if first_name and last_name %}\n\\1",
            '/(<p>)?\[elseifFirstnameAndLastname\]\s*/' => "{% else %}\n",
            '/\s*\[endifFirstnameAndLastname\](<\/p>)?/' => "\\1\n{% endif %}",

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

			'/\[#recurringCancel ((?:(?!\]).)*)\]/' => '<a href="https://wikimediafoundation.org/wiki/Special:LandingCheck?landing_page=Cancel_or_change_recurring_payments&basic=true&language={{ locale }}">$1</a>',
		);
	}
}
