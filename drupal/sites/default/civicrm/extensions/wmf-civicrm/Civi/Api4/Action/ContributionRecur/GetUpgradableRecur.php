<?php
namespace Civi\Api4\Action\ContributionRecur;

use Civi\WMFHelpers\ContributionRecur;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * get all upgradable recurring contributions
 */
class GetUpgradableRecur extends AbstractAction {
  /**
   * @var int
   * @required
   */
  protected $contact_id;

  /**
   * @var string
   * @required
   */
  protected $checksum;

  public function _run( Result $result ) {
      $result[] = ContributionRecur::getUpgradeable($this->contact_id, $this->checksum);
  }
}
