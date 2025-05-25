<?php

use CRM_WMFFraud_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_WMFFraud_Form_Fredge extends CRM_Core_Form {

  public function buildQuickForm() {
    $this->add('File', 'file', ts('OrderId CSV File'),
      ['size' => 30, 'maxlength' => 255], TRUE);

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    //file input form element
    $element = $this->getElement("file");

    // check that the element is an uploaded file
    if ('file' == $element->getType() && $element->isUploadedFile()) {
      // check we have a csv file
      if ($this->isValidCsvFileType($element->getValue()['type']) !== TRUE) {
        $this->validationFailFlashMessage();
        return;
      }

      // extract order ids from csv file
      $csvPath = $element->getValue()['tmp_name'];
      $orderIds = $this->extractOrderIdsFromCsv($csvPath);

      //validate order ID's as we'll be sending them as part of a query string
      $cleanOrderIds = [];
      foreach ($orderIds as $orderId) {
        if ($this->isValidOrderID($orderId)) {
          $cleanOrderIds[] = CRM_Utils_Type::escape($orderId, "String", FALSE);
        }
      }

      if (empty($cleanOrderIds) === TRUE) {
        $this->validationFailFlashMessage();
        return;
      }

      // build a string of concatenated ids to use as a filter
      $orderIdsFilter = implode(',', $cleanOrderIds);

      // build the "deeplink" to the fraud report with appropriate filters
      $fraudReportRedirectURL = $this->buildWmfFraudReportUrlWithOrderIdFilter($orderIdsFilter);

      //redirect
      CRM_Utils_System::redirect($fraudReportRedirectURL);
    }
    else {
      $this->validationFailFlashMessage();
    }

    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  /**
   * This builds a report URL with static parameter values to force
   * the desired output. The only necessary dynamic value is orderIds.
   *
   * @param $filterOrderIds
   *
   * @return string
   */
  protected function buildWmfFraudReportUrlWithOrderIdFilter($filterOrderIds) {
    return CRM_Utils_System::url('civicrm/report/wmffraud/fredge', [
      'reset' => 1,
      'order_id_op' => 'in',
      'order_id_value' => $filterOrderIds,
      'output' => 'html',
      'force' => 1,
    ]);
  }

  /**
   * @param string $csvFilePath
   *
   * @return array
   */
  protected function extractOrderIdsFromCsv($csvFilePath) {
    if (file_exists($csvFilePath)) {
      $csvToArray = array_map('str_getcsv', file($csvFilePath));
      return array_column($csvToArray, 0);
    }
  }

  /**
   * Tell the user what we need
   */
  protected function validationFailFlashMessage() {
    CRM_Core_Session::setStatus(
      "Please submit a CSV file containing a list of valid Order Id's in the first column",
      "Required input missing"
    );
  }

  /**
   * @param string $type
   *
   * @return bool
   */
  private function isValidCsvFileType($type) {
    $mimes = [
      'application/vnd.ms-excel',
      'text/plain',
      'text/csv',
      'text/tsv',
    ];
    return in_array($type, $mimes);
  }

  /**
   * Allow for order ID's such as 12345.679, 12345-33, 12345_442
   *
   * @param string $orderId
   *
   * @return bool
   */
  private function isValidOrderID($orderId) {
    $pattern = '/[0-9A-Z_\-\.]+/i';
    return (preg_match($pattern, $orderId) === 1);
  }

}
