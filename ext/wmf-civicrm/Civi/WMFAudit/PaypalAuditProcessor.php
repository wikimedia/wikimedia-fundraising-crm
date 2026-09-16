<?php

namespace Civi\WMFAudit;

use SmashPig\PaymentProviders\PayPal\Audit\PayPalAudit;

class PaypalAuditProcessor extends BaseAuditProcessor {

  protected $name = 'paypal';
  /**
   * There are two different files we use for the audit SettlementDetailReport is weekly
   * and PaymentsAccountingReport is nightly
   */
  protected function get_audit_parser() {
    return new PayPalAudit();
  }

  /**
   * Note: the output is only used to sort files in chronological order
   * The settlement detail report is named with sequential batch numbers
   * while the payments detail report has the date at the end of the name
   */
  protected function get_recon_file_sort_key($file) {
    $timeString = substr($file, 4, -4);
    $timeParts = explode('.', $timeString);
    $try = gmdate('Y-m-d H:i:s', strtotime($timeParts[0] . ' ' . $timeParts[1] . ':00'));
    if (substr($try, 0, 4) !== '1970') {
      return strtotime($try);
    }
    // sort by the modified date to get the most recent files
    $directory = $this->getIncomingFilesDirectory();
    $fullpath = $directory . '/' . $file;
    return filemtime($fullpath);
  }

  protected function regexForFilesToProcess(): string {
    return '/TRR|STL-/';
  }

  protected function regexForFilesToIgnore(): string {
    return '/(DDR-|PPA-|RPP-|WIkimedia_)/';
  }

  /**
   * A PayPal donation with no order id (e.g. an unsolicited 'Send Money'
   * donation) never came through checkout, so there's nothing to search the
   * payments logs for. Also require:
   * - an email: the STL file doesn't carry one, so such rows are left for
   *   the TRR file (which does) to create instead of creating an anonymous
   *   contribution.
   * - no backend_processor_txn_id (PayPal Reference ID): real checkout
   *   donations carry this even when their Invoice ID/order id is missing or
   *   unparseable, so its presence means this is a flaky-order-id donation
   *   to leave for makemissing/manual review, not a genuine unsolicited one.
   * - gateway is not gravy: gravy transactions are orchestrated (a real
   *   checkout happened at another processor), so they should never be
   *   treated as an unsolicited direct-to-PayPal donation, even on the rare
   *   chance one has no backend_processor_txn_id either.
   * - no grant_provider: a PayPal DAF grant ('X has sent you money') is
   *   already recorded as a GrantTransaction elsewhere in the audit flow;
   *   without this check one with an empty order id and an email would also
   *   get auto-created here as a duplicate, unrelated Cash contribution.
   *
   * @see https://phabricator.wikimedia.org/T437215
   */
  protected function isUnrebuildableDonation(array $auditRecord): bool {
    $message = $auditRecord['message'];
    return !$auditRecord['is_negative']
      && empty($message['order_id'])
      && !empty($message['email'])
      && empty($message['backend_processor_txn_id'])
      && ($message['gateway'] ?? '') !== 'gravy'
      && empty($message['grant_provider']);
  }

}
