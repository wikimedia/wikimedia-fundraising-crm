<?php

/**
 * Update the receipt to match the version in the templates folder.
 */
function _wmf_civicrm_update_offline_receipt() {
    civicrm_initialize();
    $msg_html = file_get_contents(__DIR__ . '/templates/offline_receipt.html');
    $msg_text = file_get_contents(__DIR__ . '/templates/offline_receipt.txt');
    CRM_Core_DAO::executeQuery("
  UPDATE civicrm_msg_template
  SET msg_html = '$msg_html'
  , msg_text = '$msg_text'
  WHERE msg_title = 'Contributions - Receipt (off-line)' AND is_default = 1

  ");
}
