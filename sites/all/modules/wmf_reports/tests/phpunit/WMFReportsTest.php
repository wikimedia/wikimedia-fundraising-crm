<?php

/**
 * @group WMFReports
 */
class WMFReportsTest extends BaseWmfDrupalPhpUnitTestCase {

   public function testGatewayReconciliationReport() {
     $params = [
       'report_id' => 'contribute/reconciliation',
       'fields' => [
         'total_amount' => '1',
         'is_negative' => '1',
         'financial_trxn_payment_instrument_id' => '1',
         'original_currency' => '1',
         'gateway' => '1',
         'gateway_account' => '1',
       ],
       'group_bys' =>
         [
           'is_negative' => '1',
           'original_currency' => '1',
           'gateway' => '1',
         ],
     ];
     $this->callAPISuccess('report_template', 'getrows', $params);
   }
  /**
   * Tet api to get rows from reports.
   *
   * This test doesn't check what is retrieved - just that the reports can be
   * run without error.
   *
   * @dataProvider getReportTemplates
   *
   * @param $reportID
   *
   * @throws \PHPUnit_Framework_IncompleteTestError
   */
  public function testReportTemplateGetRowsAllReports($reportID) {
    civicrm_initialize();

    $this->_sethtmlGlobals();
    if (stristr($reportID, 'has existing issues')) {
      $this->markTestIncomplete($reportID);
    }
    $this->callAPISuccess('report_template', 'getrows', array(
      'report_id' => $reportID,
    ));
  }

  /**
   * Data provider function for getting report templates to test.
   */
  public static function getReportTemplates() {
    return array(
      array('contribute/wmf_lybunt'),
      array('contribute/reconciliation'),
      array('contribute/trends'),
      array('contribute/recur'),
      array('contribute/detail'),
    );
  }

  /**
   * Ported function from core that prevents enotices & allows tests to
   * complete.
   *
   * NB - it's really annoying not to use the core function directly but we
   * would need to do restructuring in core, which, while desirable is not
   * short term.
   *
   * FIXME: something NULLs $GLOBALS['_HTML_QuickForm_registered_rules'] when
   * the tests are ran all together
   * (NB unclear if this is still required)
   */
  public function _sethtmlGlobals() {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $GLOBALS['_HTML_QuickForm_registered_rules'] = array(
      'required' => array(
        'html_quickform_rule_required',
        'HTML/QuickForm/Rule/Required.php',
      ),
      'maxlength' => array(
        'html_quickform_rule_range',
        'HTML/QuickForm/Rule/Range.php',
      ),
      'minlength' => array(
        'html_quickform_rule_range',
        'HTML/QuickForm/Rule/Range.php',
      ),
      'rangelength' => array(
        'html_quickform_rule_range',
        'HTML/QuickForm/Rule/Range.php',
      ),
      'email' => array(
        'html_quickform_rule_email',
        'HTML/QuickForm/Rule/Email.php',
      ),
      'regex' => array(
        'html_quickform_rule_regex',
        'HTML/QuickForm/Rule/Regex.php',
      ),
      'lettersonly' => array(
        'html_quickform_rule_regex',
        'HTML/QuickForm/Rule/Regex.php',
      ),
      'alphanumeric' => array(
        'html_quickform_rule_regex',
        'HTML/QuickForm/Rule/Regex.php',
      ),
      'numeric' => array(
        'html_quickform_rule_regex',
        'HTML/QuickForm/Rule/Regex.php',
      ),
      'nopunctuation' => array(
        'html_quickform_rule_regex',
        'HTML/QuickForm/Rule/Regex.php',
      ),
      'nonzero' => array(
        'html_quickform_rule_regex',
        'HTML/QuickForm/Rule/Regex.php',
      ),
      'callback' => array(
        'html_quickform_rule_callback',
        'HTML/QuickForm/Rule/Callback.php',
      ),
      'compare' => array(
        'html_quickform_rule_compare',
        'HTML/QuickForm/Rule/Compare.php',
      ),
    );
    // FIXME: …ditto for $GLOBALS['HTML_QUICKFORM_ELEMENT_TYPES']
    $GLOBALS['HTML_QUICKFORM_ELEMENT_TYPES'] = array(
      'group' => array(
        'HTML/QuickForm/group.php',
        'HTML_QuickForm_group',
      ),
      'hidden' => array(
        'HTML/QuickForm/hidden.php',
        'HTML_QuickForm_hidden',
      ),
      'reset' => array(
        'HTML/QuickForm/reset.php',
        'HTML_QuickForm_reset',
      ),
      'checkbox' => array(
        'HTML/QuickForm/checkbox.php',
        'HTML_QuickForm_checkbox',
      ),
      'file' => array(
        'HTML/QuickForm/file.php',
        'HTML_QuickForm_file',
      ),
      'image' => array(
        'HTML/QuickForm/image.php',
        'HTML_QuickForm_image',
      ),
      'password' => array(
        'HTML/QuickForm/password.php',
        'HTML_QuickForm_password',
      ),
      'radio' => array(
        'HTML/QuickForm/radio.php',
        'HTML_QuickForm_radio',
      ),
      'button' => array(
        'HTML/QuickForm/button.php',
        'HTML_QuickForm_button',
      ),
      'submit' => array(
        'HTML/QuickForm/submit.php',
        'HTML_QuickForm_submit',
      ),
      'select' => array(
        'HTML/QuickForm/select.php',
        'HTML_QuickForm_select',
      ),
      'hiddenselect' => array(
        'HTML/QuickForm/hiddenselect.php',
        'HTML_QuickForm_hiddenselect',
      ),
      'text' => array(
        'HTML/QuickForm/text.php',
        'HTML_QuickForm_text',
      ),
      'textarea' => array(
        'HTML/QuickForm/textarea.php',
        'HTML_QuickForm_textarea',
      ),
      'fckeditor' => array(
        'HTML/QuickForm/fckeditor.php',
        'HTML_QuickForm_FCKEditor',
      ),
      'tinymce' => array(
        'HTML/QuickForm/tinymce.php',
        'HTML_QuickForm_TinyMCE',
      ),
      'dojoeditor' => array(
        'HTML/QuickForm/dojoeditor.php',
        'HTML_QuickForm_dojoeditor',
      ),
      'link' => array(
        'HTML/QuickForm/link.php',
        'HTML_QuickForm_link',
      ),
      'advcheckbox' => array(
        'HTML/QuickForm/advcheckbox.php',
        'HTML_QuickForm_advcheckbox',
      ),
      'date' => array(
        'HTML/QuickForm/date.php',
        'HTML_QuickForm_date',
      ),
      'static' => array(
        'HTML/QuickForm/static.php',
        'HTML_QuickForm_static',
      ),
      'header' => array(
        'HTML/QuickForm/header.php',
        'HTML_QuickForm_header',
      ),
      'html' => array(
        'HTML/QuickForm/html.php',
        'HTML_QuickForm_html',
      ),
      'hierselect' => array(
        'HTML/QuickForm/hierselect.php',
        'HTML_QuickForm_hierselect',
      ),
      'autocomplete' => array(
        'HTML/QuickForm/autocomplete.php',
        'HTML_QuickForm_autocomplete',
      ),
      'xbutton' => array(
        'HTML/QuickForm/xbutton.php',
        'HTML_QuickForm_xbutton',
      ),
      'advmultiselect' => array(
        'HTML/QuickForm/advmultiselect.php',
        'HTML_QuickForm_advmultiselect',
      ),
    );
  }

}
