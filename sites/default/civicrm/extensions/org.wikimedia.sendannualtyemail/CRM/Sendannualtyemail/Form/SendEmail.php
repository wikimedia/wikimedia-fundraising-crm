<?php

use CRM_Sendannualtyemail_ExtensionUtil as E;
use CRM_Sendannualtyemail_AnnualThankYou as AnnualThankYou;

/**
 * Form controller class
 *
 * @see https://wiki.civicrm.org/confluence/display/CRMDOC/QuickForm+Reference
 */
class CRM_Sendannualtyemail_Form_SendEmail extends CRM_Core_Form {

  public function preProcess() {
    parent::preProcess();

    //check to see if
  }


  public function buildQuickForm() {

    // add form elements
    $this->add(
      'select', // field type
      'year', // field name
      'Year', // field label
      $this->getYearOptions(), // list of options
      TRUE // is required
    );
    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'class' => 'email-send-submit',
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();
    if ($cid = CRM_Utils_Request::retrieveValue('cid', 'Integer')) {
      // send_email
      AnnualThankYou::send($cid, $values['year']);
      CRM_Core_Session::setStatus("An email with all contributions from {$values['year']} will be sent to this contact if they contributed that year.");
    }
    else {
      // FIXME: better message
      CRM_Core_Form::errorMessage('Email not sent. Contact ID missing?!?!');
    }
    parent::postProcess();
  }

  public function getYearOptions() {
    $options = array(
      '' => E::ts('- select -'),
    );
    $current_year = date('Y');
    $last_seven_years = array_reverse(range($current_year - 7, $current_year));
    foreach ($last_seven_years as $year) {
        $options[$year] = $year;
    }
    return $options;
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
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
