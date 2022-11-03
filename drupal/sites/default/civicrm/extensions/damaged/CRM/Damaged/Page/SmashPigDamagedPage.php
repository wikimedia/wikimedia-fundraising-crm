<?php
use CRM_Damaged_ExtensionUtil as E;

class CRM_Damaged_Page_SmashPigDamagedPage extends CRM_Core_Page {
  /**
   * The action links that we need to display for the browse screen.
   *
   * @var array
   */
  public static $_links = NULL;

  /**
   * Get BAO Name.
   *
   * @return string
   *   Classname of BAO.
   */
  public function getBAOName() {
    return 'CRM_Damaged_BAO_Damaged';
  }

  /**
   * Get action Links.
   *
   * @return array
   *   (reference) of action links
   */
  public function &links() {
    if (!(self::$_links)) {
      self::$_links = [
        CRM_Core_Action::UPDATE => [
          'name' => ts('Edit'),
          'url' => 'civicrm/damaged/edit',
          'qs' => 'action=update&id=%%id%%&reset=1',
          'title' => ts('Edit Damaged'),
        ],
        CRM_Core_Action::DELETE => [
          'name' => ts('Delete'),
          'url' => 'civicrm/damaged/edit',
          'qs' => 'action=delete&id=%%id%%',
          'title' => ts('Delete Damaged'),
        ],
      ];
    }
    return self::$_links;
  }


}
