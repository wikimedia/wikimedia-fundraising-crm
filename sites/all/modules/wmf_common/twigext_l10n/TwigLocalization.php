<?php

require_once( 'CldrData.php' );

/**
 * Implements various localization filters:
 *  - Number format (1234.43 -> 1 234,43 .. 1,234.43)
 *  - Currency format (EUR 1234.43 -> €1,234.43 .. 1 234,43 €)
 */
class TwigLocalization extends Twig_Extension  {
	/** @var array Currency symbols keyed on ISO currency code, source http://www.xe.com/symbols.php */
	protected static $currency_symbols = array(
		'AFN' => '؋',
		'ANG' => 'ƒ',
		'ARS' => '$',
		'AUD' => '$',
		'AWG' => 'ƒ',
		'AZN' => 'ман',
		'BAM' => 'KM',
		'BBD' => '$',
		'BGN' => 'лв',
		'BMD' => '$',
		'BND' => '$',
		'BOB' => '$b',
		'BRL' => 'R$',
		'BSD' => '$',
		'BWP' => 'P',
		'BYR' => 'p.',
		'BZD' => 'BZ$',
		'CAD' => '$',
		'CHF' => 'CHF',
		'CLP' => '$',
		'CNY' => '¥',
		'COP' => '$',
		'CRC' => '₡',
		'CUP' => '₱',
		'CZK' => 'Kč',
		'DKK' => 'kr',
		'DOP' => 'RD$',
		'EEK' => 'kr',
		'EGP' => '£',
		'EUR' => '€',
		'FJD' => '$',
		'FKP' => '£',
		'GBP' => '£',
		'GGP' => '£',
		'GHC' => '¢',
		'GIP' => '£',
		'GTQ' => 'Q',
		'GYD' => '$',
		'HKD' => '$',
		'HNL' => 'L',
		'HRK' => 'kn',
		'HUF' => 'Ft',
		'IDR' => 'Rp',
		'ILS' => '₪',
		'IMP' => '£',
		'IRR' => '﷼',
		'ISK' => 'kr',
		'JEP' => '£',
		'JMD' => 'J$',
		'JPY' => '¥',
		'KGS' => 'лв',
		'KHR' => '៛',
		'KPW' => '₩',
		'KRW' => '₩',
		'KYD' => '$',
		'KZT' => 'лв',
		'LAK' => '₭',
		'LBP' => '£',
		'LKR' => '₨',
		'LRD' => '$',
		'LTL' => 'Lt',
		'LVL' => 'Ls',
		'MKD' => 'ден',
		'MNT' => '₮',
		'MUR' => '₨',
		'MXN' => '$',
		'MYR' => 'RM',
		'MZN' => 'MT',
		'NAD' => '$',
		'NGN' => '₦',
		'NIO' => 'C$',
		'NOK' => 'kr',
		'NPR' => '₨',
		'NZD' => '$',
		'OMR' => '﷼',
		'PAB' => 'B/.',
		'PEN' => 'S/.',
		'PHP' => '₱',
		'PKR' => '₨',
		'PLN' => 'zł',
		'PYG' => 'Gs',
		'QAR' => '﷼',
		'RON' => 'lei',
		'RSD' => 'Дин.',
		'RUB' => 'руб',
		'SAR' => '﷼',
		'SBD' => '$',
		'SCR' => '₨',
		'SEK' => 'kr',
		'SGD' => '$',
		'SHP' => '£',
		'SOS' => 'S',
		'SRD' => '$',
		'SVC' => '$',
		'SYP' => '£',
		'THB' => '฿',
		'TRL' => '₤',
		'TRY' => 'info',
		'TTD' => 'TT$',
		'TVD' => '$',
		'TWD' => 'NT$',
		'UAH' => '₴',
		'USD' => '$',
		'UYU' => '$U',
		'UZS' => 'лв',
		'VEF' => 'Bs',
		'VND' => '₫',
		'XCD' => '$',
		'YER' => '﷼',
		'ZAR' => 'R',
		'ZWD' => 'Z$'
	);

	/**
	 * Returns the name of the extension.
	 *
	 * @return string The extension name
	 */
	public function getName() {
		return "Wikimedia Foundation -- Twig Localization";
	}

	/**
	 * Returns a list of filters to add to the existing list.
	 *
	 * @return array An array of filters
	 */
	public function getFilters() {
		return array(
			new Twig_SimpleFilter( 'l10n_currency', array( $this, 'l10n_currency' ) ),
			new Twig_SimpleFilter( 'l10n_number', array( $this, 'l10n_number' ) ),
		);
	}

	/**
	 * Localize a currency string, string is expected to be <ISO Code> <Non formatted number>
	 * Will transform the string by replacing the currency symbol, if available,
	 * formatting the number and re arranging the symbol/number as appropriate to the language.
	 *
	 * @param string $string	<ISO Code> <Non formatted number [0-9]+[\.]?[0-9]*>
	 * @param string $locale	Language to translate into, may be a locale (e.g. en_GB)
	 *
	 * @return string Transformed $string
	 */
	public static function l10n_currency( $string, $locale = '*' ) {
		list( $currency, $amount ) = explode( ' ', $string, 2 );
		$amount = floatval( $amount );

		$decimals = static::getFromCldrArray( CldrData::$currencyData, $currency, 0 );

		if ( array_key_exists( $currency, static::$currency_symbols ) ) {
			$currency = static::$currency_symbols[$currency];
		}

		if ( $amount > 0 ) {
			$retval = static::getFromCldrArray( CldrData::$localeNumberFormat, $locale, 1 );
		} else {
			$retval = static::getFromCldrArray( CldrData::$localeNumberFormat, $locale, 2 );
			$amount *= -1;	// The format will take care of annunciation of +/-
		}

		$retval = str_replace( '$1', $currency, $retval );
		$retval = str_replace( '$2', static::l10n_number( $amount, $decimals, $locale ), $retval );
		return $retval;
	}

	/**
	 * Localize a number as defined by the given locale.
	 *
	 * @param string $string   Number to format
	 * @param int    $decimals Round to number of decimals
	 * @param string $locale   Locale to format into
	 *
	 * @return string Transformed $string
	 */
	public static function l10n_number( $string, $decimals = 2, $locale = '*' ) {
		list( $decPoint, $groupPoint ) = static::getFromCldrArray( CldrData::$localCharacters, $locale );
		$grouping = static::getFromCldrArray( CldrData::$localeNumberFormat, $locale, 0 );

		// We don't want to use this function to actually subst in the characters because
		// it's not multibyte safe.
		// https://bugs.php.net/bug.php?id=64424
		$string = number_format( floatval( $string ), $decimals, '.', '' );

		$count = 0;
		if ( $decimals > 0 ) {
			$i = strpos( $string, '.' ) - 1;
			$newstring = $decPoint . substr( $string, $i + 2 );
		} else {
			$i = strlen( $string ) - 1;
			$newstring = '';
		}

		// Now add the group separators
		if ( count( $grouping ) > 0 ) {
			$groupVal = array_pop( $grouping );
			while ( $i >= 0 ) {
				if ( ( $count != 0 ) && ( $count == $groupVal ) ) {
					$newstring = $string[$i] . $groupPoint . $newstring;
					$count = 0;
					if ( count( $grouping ) > 0 ) {
						$groupVal = array_pop( $grouping );
					}
				} else {
					$newstring = $string[$i] . $newstring;
				}
				$i--;
				$count++;
			}
		} else {
			$newstring = substr( $string, 0, $i + 1 ) . $newstring;
		}

		return $newstring;
	}

	/**
	 * Gets the nearest locale value from CLDR
	 *
	 * @param array  $ary    The array to operate on
	 * @param string $locale Locale string, like en or en_US
	 * @param int    $index  If a specific sub array index is required; set this to it
	 *
	 * @return mixed Contents of array at key $locale
	 */
	protected static function getFromCldrArray( &$ary, $locale, $index = null ) {
		$split = explode( '_', $locale );

		if ( array_key_exists( $locale, $ary ) ) {
			$retval = $ary[$locale];
		} elseif ( ( count( $split ) == 2 ) && ( array_key_exists( $split[0], $ary ) ) ) {
			$retval = $ary[$split[0]];
		} else {
			$retval = $ary['*'];
		}

		if ( $index !== null ) {
			return $retval[$index];
		} else {
			return $retval;
		}
	}
}
