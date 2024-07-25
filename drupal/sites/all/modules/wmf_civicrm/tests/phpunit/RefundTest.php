<?php

/**
 * @group Pipeline
 * @group WmfCivicrm
 * @group Refund
 */
class RefundTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Check chargeback status exists.
   */
  public function testStatuses() {
    $options = $this->callAPISuccess('Contribution', 'getoptions', array('field' => 'contribution_status_id'));
    $this->assertTrue(in_array('Chargeback', $options['values']));
  }

}
