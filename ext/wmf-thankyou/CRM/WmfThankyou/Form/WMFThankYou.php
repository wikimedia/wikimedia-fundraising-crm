<?php

use Civi\Api4\Contribution;
use Civi\Api4\ThankYou;
use CRM_WmfThankyou_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_WmfThankyou_Form_WMFThankYou extends CRM_Core_Form {

  /**
   * Build basic form.
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(): void {
    $contributionRecurID = $this->getContributionRecurID();
    if ($contributionRecurID) {
      $contributionID = $this->getContributionIDForMonthlyConvert($contributionRecurID);
      if (!$contributionID) {
        $this->assign('no_go_reason', E::ts('Selected recurring contribution was not started via monthly convert'));
        return;
      }
    } else {
      $contributionID = $this->getContributionID();
      if (!$this->isValidContribution()) {
        $this->assign('no_go_reason', E::ts('A valid contribution ID, WITH contribution_extra data is required'));
        return;
      }
    }

    try {
      $contribution = civicrm_api3('Contribution', 'getsingle', ['id' => $contributionID, 'contribution_status_id' => 'Completed']);
      $contact = civicrm_api3('Contact', 'getsingle', ['id' => $contribution['contact_id']]);
    }
    catch (CRM_Core_Exception $e) {
      $this->assign('no_go_reason', E::ts('A valid contribution ID is required'));
      return;
    }

    if ($contact['on_hold'] || !$contact['email']) {
      $this->assign('no_go_reason', E::ts('A usable email is required'));
      return;
    }
    $preferredLanguage = $contact['preferred_language'] ?: 'en_US';
    $preferredLanguageString = CRM_Core_PseudoConstant::getLabel('CRM_Contact_BAO_Contact', 'preferred_language', $preferredLanguage);
    $this->assign('language', $preferredLanguageString ?? $contact['preferred_language']);
    $this->assign('contact', $contact);
    $this->assign('contribution', $contribution);
    $this->setMessage($preferredLanguage, $contributionID, $contribution['contact_id'], $contributionRecurID);
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Send'),
        'isDefault' => TRUE,
      ],
    ]);
    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());

    parent::buildQuickForm();
  }

  /**
   * Set the rendered message property, if possible.
   */
  protected function setMessage(string $preferredLanguage, int $contributionID, int $contactID, ?int $contributionRecurID): void {
    try {
      $render = ThankYou::render()
        ->setContributionID($contributionID)
        ->setLanguage($preferredLanguage);

      if ($contributionRecurID) {
         $render
           ->setTemplateName('monthly_convert')
           ->setContributionRecurID($contributionRecurID);
      }
      else {
        $render->setTemplateName($this->getTemplateName());
      }

      $message = $render->execute()->first();
      $this->assign('subject', $message['subject']);
      $this->assign('message', $message['html']);
    }
    catch (CRM_Core_Exception $e) {
      // No valid contributions - probably our local dev doesn't have wmf donor data
      // for the contribution.
      \Civi::log('wmf')->error('WMFThankyouForm:: Thank you not rendered {error}', ['error' => $e->getMessage(), 'exception' => $e]);
    }
  }

  /**
   * Get the relevant template name.
   *
   * @return string
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getTemplateName() : string {
    return $this->isEndowment() ? 'endowment_thank_you' : 'thank_you';
  }

  protected function isMonthlyConvert(int $contributionRecurID): bool {
    return (bool)$this->getContributionRecurID();
  }

  /**
   * Is this an endowment gift.
   *
   * @return bool
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function isEndowment(): bool {
    return $this->getFinancialType() === 'Endowment Gift';
  }

  /**
   * Submit form.
   */
  public function postProcess(): void {
    try {
      if ($this->getContributionRecurID()) {
        $contributionRecur = \Civi\Api4\ContributionRecur::get(FALSE)
          ->addWhere('id', '=', $this->getContributionRecurID())
          ->execute()->first();
        $contributionID = $this->getContributionIDForMonthlyConvert($this->getContributionRecurID());
        Civi\WMFHelper\ContributionRecur::sendSuccessThankYouMail($contributionRecur, $contributionID);
      } else {
        ThankYou::send(FALSE)
          ->setContributionID(CRM_Utils_Request::retrieve('contribution_id', 'Integer', $this))
          ->setTemplateName($this->getTemplateName())
          ->execute();
      }
      CRM_Core_Session::setStatus('Message sent', E::ts('Thank you Sent'), 'success');
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Session::setStatus('Message failed with error ' . $e->getMessage());
    }
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames(): array {
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
   * @return int
   * @throws \CRM_Core_Exception
   */
  protected function getContributionID(): int {
    return (int) CRM_Utils_Request::retrieve('contribution_id', 'Integer', $this);
  }

  /**
   * @return int
   * @throws \CRM_Core_Exception
   */
  protected function getContributionRecurID(): int {
    return (int) CRM_Utils_Request::retrieve('contribution_recur_id', 'Integer', $this);
  }

  /**
   * @return string
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function getFinancialType(): string {
    return (string) Contribution::get()
      ->addWhere('id', '=', $this->getContributionID())
      ->addSelect('financial_type_id:name')
      ->execute()
      ->first()['financial_type_id:name'];
  }

  /**
   * Is the contribution valid for thanking.
   *
   * @throws \CRM_Core_Exception
   */
  protected function isValidContribution(): bool {
    return (bool) CRM_Core_DAO::singleValueQuery('SELECT id FROM wmf_contribution_extra WHERE entity_id = ' . $this->getContributionID());
  }

  protected function getContributionIDForMonthlyConvert(int $contributionRecurID): int {
    return (int) CRM_Core_DAO::singleValueQuery('
        SELECT c.id FROM civicrm_contribution c
        INNER JOIN civicrm_contribution_recur r ON c.invoice_id = r.invoice_id
        WHERE r.id = ' . $contributionRecurID);
  }

}
