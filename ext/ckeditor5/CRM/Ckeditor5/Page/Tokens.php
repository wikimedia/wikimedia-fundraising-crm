<?php

/**
 * Serve the token catalogue used by the CKEditor token picker.
 */
class CRM_Ckeditor5_Page_Tokens extends CRM_Core_Page {

  /**
   * Output a context-aware token catalogue as JSON.
   */
  public function run() {
    $preset = CRM_Utils_Request::retrieve('preset', 'String', $this, FALSE, 'default', 'GET');
    CRM_Utils_JSON::output([
      'groups' => CRM_Ckeditor5_Tokens::getForPreset($preset),
    ]);
  }

}
