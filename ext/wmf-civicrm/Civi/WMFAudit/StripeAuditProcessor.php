<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\Stripe\Audit\StripeAudit;

/**
 * Audit processor for Stripe reconciliation exports.
 *
 * Stripe reconciliation files are parsed by the SmashPig Stripe audit parser,
 * which is responsible for handling the supported Stripe CSV shapes.
 */
class StripeAuditProcessor extends BaseAuditProcessor {

	protected $name = 'stripe';

	protected function get_audit_parser() {
		return new StripeAudit();
	}

	protected function get_recon_file_sort_key( $file ) {
		if ( preg_match( '/(\d{4}-\d{2}-\d{2})/', $file, $matches ) ) {
			$parsed = \DateTime::createFromFormat( 'Y-m-d', $matches[1], new \DateTimeZone( 'UTC' ) );
			if ( $parsed !== FALSE ) {
				return $parsed->getTimestamp();
			}
		}

		$fullPath = $this->getIncomingFilesDirectory() . $file;
		if ( file_exists( $fullPath ) ) {
			return filemtime( $fullPath );
		}

		return $file;
	}

  /**
   * Match Stripe reconciliation CSV exports by filename prefix.
   *
   * Known filename shapes include:
   *
   * settlement-report-2026-02-03-WMF-online-po_1SwXd2JaRQOHTfEWu0pvlCMA.csv
   * payments-activity-2026-02-01-to-2026-02-28-WMF-online.csv
   *
   * The segment after the date or date range is not stable, so matching is
   * intentionally based on the leading report type only.
   */
	protected function regexForFilesToProcess(): string {
		return '/^(settlement-report|payments-activity)/i';
	}

  /**
   * Ignore files that are not valid reconciliation inputs.
   *
   * This excludes hidden files, temporary or partial files created during
   * transfer, and readme files that may be bundled with exports.
   */
	protected function regexForFilesToIgnore(): string {
		return '/(^\.|\.(tmp|part|swp)$|^readme.*\.txt$)/i';
	}
}
