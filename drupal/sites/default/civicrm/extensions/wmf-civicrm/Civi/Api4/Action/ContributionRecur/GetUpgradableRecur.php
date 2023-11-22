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
    if (!\CRM_Core_Permission::check('access CiviContribute') && !\CRM_Contact_BAO_Contact_Utils::validChecksum($this->contact_id,  $this->checksum)) {
      throw new \CRM_Core_Exception('Authorization failed');
    }

    $result[] = ContributionRecur::getUpgradeable($this->contact_id, $this->checksum);
  }

  /**
   * @return array
   */
  public function getPermissions() {
    $permissions = parent::getPermissions();
    $permissions[] = TRUE;
    return $permissions;
  }
}
