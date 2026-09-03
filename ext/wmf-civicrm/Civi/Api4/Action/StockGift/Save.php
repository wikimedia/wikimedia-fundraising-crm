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

      $individualID = $this->getOrCreateIndividual($record, NULL, NULL);
      $result[] = $this->saveContribution($record, [
        'financial_type_id:name' => $this->isEndowmentAccount($record) ? 'Endowment Gift' : 'Stock',
        'Stock_Information.Stock Ticker' => $record['stock_ticker'] ?? NULL,
        'Stock_Information.Stock Quantity' => $record['stock_quantity'] ?? NULL,
        // Stock Value is the net (post-fee) value, same as settled_net_amount.
        'Stock_Information.Stock Value' => $record['settled_net_amount'] ?? NULL,
      ], $individualID, 1);
    }
  }

}
