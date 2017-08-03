<?php

use SmashPig\PaymentProviders\Ingenico\Audit\IngenicoAudit;
use SmashPig\PaymentProviders\Ingenico\ReferenceData;

class IngenicoAuditProcessor extends BaseAuditProcessor {
	protected $name = 'ingenico';

	protected function get_audit_parser() {
		return new IngenicoAudit();
	}

	// TODO: wx2 files should supersede wx1 files of the same name
	protected function get_recon_file_sort_key( $file ) {
		// Example: wx1.000000123420160423.010211.xml.gz
		// For that, we'd want to return 20160423
		return substr( $file, 15, 8 );
	}

	// TODO: Switch to json log parsing when caught up to 20170727
	protected function get_log_distilling_grep_string() {
		return 'RETURNED FROM CURL.*<ACTION>INSERT_ORDERWITHPAYMENT</ACTION>';
	}

	protected function get_log_line_grep_string( $order_id ) {
		return "<ORDERID>$order_id</ORDERID>";
	}

	protected function parse_log_line( $logLine ) {
		// get the xml, and the contribution_id... and while we're at it, parse the xml.
		$xml = null;
		$full_xml = false;
		$contribution_id = null;

		// look for the raw xml.
		$xmlstart = strpos( $logLine, '<XML>' );
		$xmlend = strpos( $logLine, '</XML>' );
		if ( $xmlend ){
			$full_xml = true;
			$xmlend += 6;
			$xml = substr($logLine, $xmlstart, $xmlend - $xmlstart);
		} else {
			// this is a broken line, and it won't load... but we can still parse what's left of the thing, the slow way.
			$xml = substr($logLine, $xmlstart);
		}
		// get the contribution tracking id.
		$ctid_end = strpos( $logLine, 'RETURNED FROM CURL' );
		$ctid_start = strpos( $logLine, '_gateway:' ) + 9;
		$ctid = substr($logLine, $ctid_start, $ctid_end - $ctid_start);
		$parts = explode( ':', $ctid );
		$contribution_id = trim( $parts[0], ' :' );
		// go on with your bad self
		// now parse the xml...

		$donor_data = array();

		if ( $full_xml ){
			$xmlobj = new DomDocument;
			$xmlobj->loadXML($xml);

			$parent_nodes = array(
				'ORDER',
				'PAYMENT'
			);

			foreach ( $parent_nodes as $parent_node ){
				foreach ( $xmlobj->getElementsByTagName( $parent_node ) as $node ) {
					foreach ( $node->childNodes as $childnode ) {
						if ( trim( $childnode->nodeValue ) != '' ) {
							$donor_data[$childnode->nodeName] = $childnode->nodeValue;
						}
					}
				}
			}
		} else {
			$search_for_nodes = array(
				'ORDERID' => true,
				'AMOUNT' => true,
				'CURRENCYCODE' => true,
				'PAYMENTPRODUCTID' => true,
				'ORDERTYPE' => false,
				'EMAIL' => true,
				'FIRSTNAME' => false,
				'SURNAME' => false,
				'STREET' => false,
				'CITY' => false,
				'STATE' => false,
				'COUNTRYCODE' => true,
				'ZIP' => false,
			);

			foreach ( $search_for_nodes as $node => $mandatory ){
				$tmp = $this->getPartialXmlNodeValue( $node, $xml );
				if ( !is_null( $tmp ) ){
					$donor_data[$node] = $tmp;
				} else {
					if ( $mandatory ){
						throw new WmfException(
							'MISSING_MANDATORY_DATA',
							"Mandatory field $node missing for $contribution_id."
						);
					} else {
						$donor_data[$node] = '';
					}
				}
			}
		}

		$return['contribution_tracking_id'] = $contribution_id;
		// FIXME: move all staging/unstaging to SmashPig lib
		$xmlMap = array(
			'ORDERID' => 'gateway_txn_id',
			'CURRENCYCODE' => 'currency',
			'EMAIL' => 'email',
			'FIRSTNAME' => 'first_name',
			'SURNAME' => 'last_name',
			'STREET' => 'street_address',
			'CITY' => 'city',
			'STATE' => 'state_province',
			'COUNTRYCODE' => 'country',
			'ZIP' => 'postal_code',
			'AMOUNT' => 'gross',
		);
		foreach ( $xmlMap as $theirs => $ours ) {
			if ( isset( $donor_data[$theirs] ) ) {
				$return[$ours] = $donor_data[$theirs];
			}
		}
		$return['gross'] = $return['gross'] / 100;
		$normalizedMethod = ReferenceData::decodePaymentMethod(
			$donor_data['PAYMENTPRODUCTID']
		);
		if ( !empty( $donor_data['ORDERTYPE'] ) && $donor_data['ORDERTYPE'] === '4' ) {
			$return['recurring'] = 1;
		}
		$return = array_merge( $return, $normalizedMethod );
		return $return;
	}

	function getPartialXmlNodeValue( $node, $xml ){
		$node1 = "<$node>";
		$node2 = "</$node>";

		$valstart = strpos( $xml, $node1 ) + strlen( $node1 );
		if ( !$valstart ){
			return null;
		}

		$valend = strpos( $xml, $node2 );
		if ( !$valend ){ //it cut off in that node. This next thing is therefore safe(ish).
			$valend = strpos( $xml, '</' );
		}

		if ( !$valend ){
			return null;
		}

		$value = substr( $xml, $valstart, $valend - $valstart );
		return $value;

	}

	protected function regex_for_recon() {
		return '/wx\d\.\d{18}\.\d{6}.xml.gz/';
	}

	/**
	 * Initial logs for the Ingenico Connect API have no gateway transaction id,
	 * just our contribution tracking id and the hosted checkout session ID.
	 *
	 * @param array $transaction possibly incomplete set of transaction data
	 * @return string|false the order_id, or false if we can't figure it out
	 */
	protected function get_order_id( $transaction ) {
		if ( is_array( $transaction ) ) {
			if ( array_key_exists( 'order_id', $transaction ) ) {
				return $transaction['order_id'];
			}
			if ( array_key_exists( 'gateway_parent_id' , $transaction ) ) {
				return $transaction['gateway_parent_id'];
			}
		}
		return false;
	}

	/**
	 * Get the name of a compressed log file based on the supplied date.
	 * TODO: transition from 'globalcollect' to 'ingenico' and stop
	 * overriding these three functions
	 *
	 * @param string $date date in YYYYMMDD format
	 * @return string Name of the file we're looking for
	 */
	protected function get_compressed_log_file_name( $date ) {
		return "payments-globalcollect-{$date}.gz";
	}

	/**
	 * Get the name of an uncompressed log file based on the supplied date.
	 * @param string $date date in YYYYMMDD format
	 * @return string Name of the file we're looking for
	 */
	protected function get_uncompressed_log_file_name( $date ) {
		return "payments-globalcollect-{$date}";
	}

	/**
	 * The regex to use to determine if a file is an uncompressed log for this
	 * gateway.
	 * @return string regular expression
	 */
	protected function regex_for_uncompressed_log() {
		return "/globalcollect_\d{8}/";
	}
}
