<?php
namespace Civi\Api4\Action\StockGift;

use Civi\Api4\Generic\Result;

/**
 * Create a Contribution from an Overflow stock-gift audit row.
 */
class Save extends \Civi\Api4\Action\OfflineGift\Save {

  protected $_entityName = 'StockGift';

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    foreach ($this->records as $record) {
      $record += $this->defaults;
      $this->formatWriteValues($record);

      if (($record['payment_method'] ?? NULL) === 'DAFpay') {
        // Overflow also carries DAF-routed gifts through the same deposit
        // endpoint - OverflowAuditProcessor should have routed those to
        // DAFGift instead, so this would indicate a routing bug.
        throw new \CRM_Core_Exception('StockGift.save does not support DAFpay - use DAFGift.save instead');
      }

      // Multi-stock gifts have no single quantity & Overflow only gives the
      // combined value, so staff split these by hand from the email.
      $holdings = $record['stock_holdings'] ?? [];
      $tickers = $descriptions = [];
      foreach ($holdings as $holding) {
        $tickers[] = $holding['ticker'];
        $descriptions[] = $holding['ticker'] . ': ' . $holding['quantity'] . ' shares';
      }
      $quantity = count($holdings) === 1 ? $holdings[0]['quantity'] : NULL;
      $holdingsDescription = count($holdings) > 1 ? 'Split required - ' . implode(', ', $descriptions) : NULL;

      $individualID = $this->getOrCreateIndividual($record, NULL, NULL);
      $contribution = $this->saveContribution($record, [
        'financial_type_id:name' => $this->isEndowmentAccount($record) ? 'Endowment Gift' : 'Stock',
        'Stock_Information.Stock Ticker' => $tickers ? implode(',', $tickers) : NULL,
        'Stock_Information.Stock Quantity' => $quantity,
        // Stock Value is the net (post-fee) value, same as settled_net_amount.
        'Stock_Information.Stock Value' => $record['settled_net_amount'] ?? NULL,
        'contribution_extra.no_thank_you' => 'pending manual confirmation',
      ], $individualID, 1);
      $result[] = $contribution;
      $message = 'A Stock contribution has been created that needs manual_review';
      $subject = 'Stock Gift requiring review';
      if ($holdingsDescription) {
        $message = 'A Stock contribution with multiple stocks has been created - it needs to be split into one contribution per stock. ' . $holdingsDescription;
        $subject = 'Multiple Stock Gift - ' . $holdingsDescription;
      }
      $message .= "\nNo thank you has been sent - once reviewed, clear the 'No Thank You' field (currently 'pending manual confirmation') so the thank you goes out.";
      \Civi::log('offline_gifts')->info($message, [
        'subject' => $subject,
        'id' => $contribution['id'],
        'ticker' => implode(',', $tickers),
        'quantity' => $quantity,
        'value' => $record['settled_net_amount'],
        'url' => \CRM_Utils_System::url('civicrm/contact/view/contribution', [
          'id' => $contribution['id'],
          'action' => 'view',
          'reset' => 1,
        ], TRUE, NULL, FALSE)
      ]);
    }
  }

}
