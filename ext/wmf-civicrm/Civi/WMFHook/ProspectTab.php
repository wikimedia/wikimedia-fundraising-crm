<?php

namespace Civi\WMFHook;

use CRM_Wmf_ExtensionUtil as E;

class ProspectTab {

  public static function pageRun(\CRM_Core_Page $page): void {
    if (!$page instanceof \CRM_Contact_Page_View_CustomData) {
      return;
    }
    if (\CRM_Core_DAO::getFieldValue('CRM_Core_DAO_CustomGroup', $page->_groupId, 'name') !== 'Prospect') {
      return;
    }
    \Civi::service('angularjs.loader')->addModules('afsearchMgStageHistory');
    \CRM_Core_Region::instance('page-body')->add([
      'markup' => '<div class="action-link">
        <a class="button crm-popup" href="' . \CRM_Utils_System::url('civicrm/mg-engagement/add', '', FALSE, '?target_contact_id=' . (int) $page->_contactId) . '">
          <span><i class="crm-i fa-handshake-o" role="img" aria-hidden="true"></i> ' . E::ts('Add MG Engagement') . '</span>
        </a>
      </div>',
    ]);
    \CRM_Core_Region::instance('page-body')->add([
      'markup' => '<crm-angular-js modules="afsearchMgStageHistory">
        <h3>' . E::ts('MG Stage History') . '</h3>
        <afsearch-mg-stage-history options="{contact_id: ' . (int) $page->_contactId . '}"></afsearch-mg-stage-history>
      </crm-angular-js>',
    ]);
  }

}
