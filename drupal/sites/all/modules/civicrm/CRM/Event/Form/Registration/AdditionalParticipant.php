<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */

/**
 * This class generates form components for processing Event.
 */
class CRM_Event_Form_Registration_AdditionalParticipant extends CRM_Event_Form_Registration {

  /**
   * Additional participant id.
   *
   * @var int
   * @internal
   */
  public $_additionalParticipantId = NULL;

  /**
   * Tracks whether we are at the last participant.
   *
   * @var bool
   * @internal
   */
  public $_lastParticipant = FALSE;

  /**
   * @var bool
   * @internal
   */
  public $_resetAllowWaitlist = FALSE;

  /**
   * @var int
   * @internal
   */
  public $_contactId;

  /**
   * Used within CRM_Event_Form_EventFees
   *
   * @var int
   * @internal
   */
  public $_discountId;

  /**
   * Alias of $this->_additionalParticipantId,
   * Used within CRM_Event_Form_EventFees
   *
   * @var int
   * @internal
   */
  public $_pId;

  /**
   * Get the active UFGroups (profiles) on this form
   * Many forms load one or more UFGroups (profiles).
   * This provides a standard function to retrieve the IDs of those profiles from the form
   * so that you can implement things such as "is is_captcha field set on any of the active profiles on this form?"
   *
   * NOT SUPPORTED FOR USE OUTSIDE CORE EXTENSIONS - Added for reCAPTCHA core extension.
   *
   * @return array
   */
  public function getUFGroupIDs() {
    $ufGroupIDs = [];
    foreach (['pre', 'post'] as $keys) {
      if (isset($this->_values['additional_custom_' . $keys . '_id'])) {
        $ufGroupIDs[] = $this->_values['additional_custom_' . $keys . '_id'];
      }
    }
    return $ufGroupIDs;
  }

  /**
   * Set variables up before form is built.
   *
   * @return void
   * @throws CRM_Core_Exception
   */
  public function preProcess() {
    parent::preProcess();
    $this->addExpectedSmartyVariable('additionalCustomPost');
    $participantNo = substr($this->_name, 12);

    //lets process in-queue participants.
    if ($this->_participantId && $this->_additionalParticipantIds) {
      $this->_additionalParticipantId = $this->_additionalParticipantIds[$participantNo] ?? NULL;
    }

    $participantCnt = $participantNo + 1;
    $this->assign('formId', $participantNo);
    $this->_params = $this->get('params');

    $participantTot = $this->_params[0]['additional_participants'] + 1;
    $skipCount = count(array_keys($this->_params, "skip"));
    $this->assign('skipCount', $skipCount);
    $this->setTitle(ts('Register Participant %1 of %2', [1 => $participantCnt, 2 => $participantTot]));

    //CRM-4320, hack to check last participant.
    $this->_lastParticipant = FALSE;
    if ($participantTot == $participantCnt) {
      $this->_lastParticipant = TRUE;
    }
    $this->assign('lastParticipant', $this->_lastParticipant);
  }

  /**
   * Set default values for the form. For edit/view mode
   * the default values are retrieved from the database
   *
   *
   * @return void
   */
  public function setDefaultValues() {
    $defaults = $unsetSubmittedOptions = [];
    $discountId = NULL;
    //fix for CRM-3088, default value for discount set.
    if (!empty($this->_values['discount'])) {
      $discountId = CRM_Core_BAO_Discount::findSet($this->_eventId, 'civicrm_event');
      if ($discountId && !empty($this->_values['event']['default_discount_fee_id'])) {
        $discountKey = CRM_Core_DAO::getFieldValue("CRM_Core_DAO_OptionValue", $this->_values['event']['default_discount_fee_id'], 'weight', 'id'
        );
        $defaults['amount'] = key(array_slice($this->_values['discount'][$discountId], $discountKey - 1, $discountKey, TRUE));
      }
    }
    if ($this->_priceSetId) {
      foreach ($this->getPriceFieldMetaData() as $key => $val) {
        if (empty($val['options'])) {
          continue;
        }

        $optionsFull = $this->getOptionFullPriceFieldValues($val);
        foreach ($val['options'] as $keys => $values) {
          if ($values['is_default'] && !in_array($keys, $optionsFull)) {
            if ($val['html_type'] === 'CheckBox') {
              $defaults["price_{$key}"][$keys] = 1;
            }
            else {
              $defaults["price_{$key}"] = $keys;
            }
          }
        }
        if (!empty($optionsFull)) {
          $unsetSubmittedOptions[$val['id']] = $optionsFull;
        }
      }
    }

    //CRM-4320, setdefault additional participant values.
    if ($this->_allowConfirmation && $this->_additionalParticipantId) {
      //hack to get set default from eventFees.php
      $this->_discountId = $discountId;
      $this->_pId = $this->_additionalParticipantId;
      $this->_contactId = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant', $this->_additionalParticipantId, 'contact_id');

      CRM_Event_BAO_Participant::formatFieldsAndSetProfileDefaults($this->_contactId, $this);
      $participantDefaults = CRM_Event_Form_EventFees::setDefaultValues($this);
      $participantDefaults = array_merge($this->_defaults, $participantDefaults);
      // use primary email address if billing email address is empty
      if (empty($this->_defaults["email-{$this->_bltID}"]) &&
        !empty($this->_defaults["email-Primary"])
      ) {
        $participantDefaults["email-{$this->_bltID}"] = $this->_defaults["email-Primary"];
      }
      $defaults = array_merge($defaults, $participantDefaults);
    }

    $defaults = array_merge($this->_defaults, $defaults);

    //reset values for all options those are full.
    CRM_Event_Form_Registration::resetElementValue($unsetSubmittedOptions, $this);

    //load default campaign from page.
    if (array_key_exists('participant_campaign_id', $this->_fields)) {
      $defaults['participant_campaign_id'] = $this->_values['event']['campaign_id'] ?? NULL;
    }

    //CRM-17865 set custom field defaults
    if (!empty($this->_fields)) {
      foreach ($this->_fields as $name => $field) {
        if ($customFieldID = CRM_Core_BAO_CustomField::getKeyID($name)) {
          if (!isset($defaults[$name])) {
            CRM_Core_BAO_CustomField::setProfileDefaults($customFieldID, $name, $defaults,
              NULL, CRM_Profile_Form::MODE_REGISTER
            );
          }
        }
      }
    }

    return $defaults;
  }

  /**
   * Build the form object.
   *
   * @return void
   */
  public function buildQuickForm() {

    $button = substr($this->controller->getButtonName(), -4);

    if ($this->_values['event']['is_monetary']) {
      $this->buildAmount(TRUE, NULL, $this->_priceSetId);
    }
    $this->assign('priceSet', $this->_priceSet);

    //Add pre and post profiles on the form.
    foreach (['pre', 'post'] as $keys) {
      if (isset($this->_values['additional_custom_' . $keys . '_id'])) {
        $this->buildCustom($this->_values['additional_custom_' . $keys . '_id'], 'additionalCustom' . ucfirst($keys));
      }
    }

    //add buttons
    if ($this->isLastParticipant(TRUE) && empty($this->_values['event']['is_monetary'])) {
      $this->submitOnce = TRUE;
    }

    //handle case where user might sart with waiting by group
    //registration and skip some people and now group fit to
    //become registered so need to take payment from user.
    //this case only occurs at dynamic waiting status, CRM-4320
    $statusMessage = NULL;
    $allowToProceed = TRUE;
    $includeSkipButton = TRUE;
    $this->_resetAllowWaitlist = FALSE;

    $pricesetFieldsCount = CRM_Price_BAO_PriceSet::getPricesetCount($this->_priceSetId);

    if ($this->_lastParticipant || $pricesetFieldsCount) {
      //get the participant total.
      $processedCnt = $this->getParticipantCount($this->_params, TRUE);
    }

    if (!$this->_allowConfirmation && !empty($this->_params[0]['bypass_payment']) &&
      $this->_lastParticipant
    ) {

      //get the event spaces.
      $spaces = $this->_availableRegistrations;

      $currentPageMaxCount = 1;
      if ($pricesetFieldsCount) {
        $currentPageMaxCount = $pricesetFieldsCount;
      }

      //we might did reset allow waiting in case of dynamic calculation
      // @TODO - does this bypass_payment still exist?
      if (!empty($this->_params[0]['bypass_payment']) &&
        is_numeric($spaces) &&
        $processedCnt > $spaces
      ) {
        $this->_allowWaitlist = TRUE;
        $this->set('allowWaitlist', TRUE);
      }

      //lets allow to become a part of runtime waiting list, if primary selected pay later.
      $realPayLater = FALSE;
      if (!empty($this->_values['event']['is_monetary']) && !empty($this->_values['event']['is_pay_later'])) {
        $realPayLater = $this->_params[0]['is_pay_later'] ?? NULL;
      }

      //truly spaces are less than required.
      if (is_numeric($spaces) && $spaces <= ($processedCnt + $currentPageMaxCount)) {
        if (($this->_params[0]['amount'] ?? 0) == 0 || $this->_requireApproval) {
          $this->_allowWaitlist = FALSE;
          $this->set('allowWaitlist', $this->_allowWaitlist);
          if ($this->_requireApproval) {
            $statusMessage = ts("It looks like you are now registering a group of %1 participants. The event has %2 available spaces (you will not be wait listed). Registration for this event requires approval. You will receive an email once your registration has been reviewed.", [
              1 => ++$processedCnt,
              2 => $spaces,
            ]);
          }
          else {
            $statusMessage = ts("It looks like you are now registering a group of %1 participants. The event has %2 available spaces (you will not be wait listed).", [
              1 => ++$processedCnt,
              2 => $spaces,
            ]);
          }
        }
        else {
          $statusMessage = ts("It looks like you are now registering a group of %1 participants. The event has %2 available spaces (you will not be wait listed). Please go back to the main registration page and reduce the number of additional people. You will also need to complete payment information.", [
            1 => ++$processedCnt,
            2 => $spaces,
          ]);
          $allowToProceed = FALSE;
        }
        CRM_Core_Session::setStatus($statusMessage, ts('Registration Error'), 'error');
      }
      elseif ($processedCnt == $spaces) {
        if (($this->_params[0]['amount'] ?? 0) == 0
          || $realPayLater || $this->_requireApproval
        ) {
          $this->_resetAllowWaitlist = TRUE;
          if ($this->_requireApproval) {
            $statusMessage = ts("If you skip this participant there will be enough spaces in the event for your group (you will not be wait listed). Registration for this event requires approval. You will receive an email once your registration has been reviewed.");
          }
          else {
            $statusMessage = ts("If you skip this participant there will be enough spaces in the event for your group (you will not be wait listed).");
          }
        }
        else {
          //hey there is enough space and we require payment.
          $statusMessage = ts("If you skip this participant there will be enough spaces in the event for your group (you will not be wait listed). Please go back to the main registration page and reduce the number of additional people. You will also need to complete payment information.");
          $includeSkipButton = FALSE;
        }
      }
    }

    // Assign false & maybe overwrite with TRUE below.
    $this->assign('allowGroupOnWaitlist', FALSE);
    // for priceset with count
    if ($pricesetFieldsCount && $this->getEventValue('has_waitlist') &&
      !$this->_allowConfirmation
    ) {

      if ($this->_isEventFull) {
        $statusMessage = ts('This event is currently full. You are registering for the waiting list. You will be notified if spaces become available.');
      }
      elseif ($this->_allowWaitlist ||
        (!$this->_allowWaitlist && ($processedCnt + $pricesetFieldsCount) > $this->_availableRegistrations)
      ) {

        $waitingMsg = ts('It looks like you are registering more participants then there are spaces available. All participants will be added to the waiting list. You will be notified if spaces become available.');
        $confirmedMsg = ts('It looks like there are enough spaces in this event for your group (you will not be wait listed).');
        if ($this->_requireApproval) {
          $waitingMsg = ts('It looks like you are now registering a group of %1 participants. The event has %2 available spaces (you will not be wait listed). Registration for this event requires approval. You will receive an email once your registration has been reviewed.');
          $confirmedMsg = ts('It looks there are enough spaces in this event for your group (you will not be wait listed). Registration for this event requires approval. You will receive an email once your registration has been reviewed.');
        }

        $this->assign('waitingMsg', $waitingMsg);
        $this->assign('confirmedMsg', $confirmedMsg);

        $this->assign('availableRegistrations', $this->_availableRegistrations);
        $this->assign('currentParticipantCount', $processedCnt);
        $this->assign('allowGroupOnWaitlist', TRUE);

        $paymentBypassed = NULL;
        if (!empty($this->_params[0]['bypass_payment']) &&
          !$this->_allowWaitlist &&
          !$realPayLater &&
          !$this->_requireApproval &&
          !(($this->_params[0]['amount'] ?? 0) == 0)
        ) {
          $paymentBypassed = ts('Please go back to the main registration page, to complete payment information.');
        }
        $this->assign('paymentBypassed', $paymentBypassed);
      }
    }

    $this->assign('statusMessage', $statusMessage);

    $buttons = [
      [
        'type' => 'back',
        'name' => ts('Go Back'),
        'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp',
      ],
    ];

    //CRM-4320
    if ($allowToProceed) {
      if ($includeSkipButton) {
        $buttons[] =
          [
            'type' => 'next',
            'name' => ts('Skip Participant'),
            'subName' => 'skip',
            'icon' => 'fa-fast-forward',
          ];
      }
      $buttonParams = [
        'type' => 'upload',
        'spacing' => '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;',
        'isDefault' => TRUE,
      ];
      if ($this->isLastParticipant(TRUE)) {
        if ($this->_values['event']['is_confirm_enabled'] || $this->_values['event']['is_monetary']) {
          $buttonParams['name'] = ts('Review');
          $buttonParams['icon'] = 'fa-chevron-right';
        }
        else {
          $buttonParams['name'] = ts('Register');
          $buttonParams['icon'] = 'fa-check';
        }
      }
      else {
        $buttonParams['name'] = ts('Continue');
        $buttonParams['icon'] = 'fa-chevron-right';
      }
      $buttons[] = $buttonParams;
    }

    $this->addButtons($buttons);
    $this->addFormRule(['CRM_Event_Form_Registration_AdditionalParticipant', 'formRule'], $this);
    $this->unsavedChangesWarn = TRUE;
  }

  /**
   * Global form rule.
   *
   * @param array $fields
   *   The input form values.
   * @param array $files
   *   The uploaded files if any.
   * @param self $self
   *
   *
   * @return bool|array
   *   true if no errors, else array of errors
   */
  public static function formRule($fields, $files, $self) {
    $errors = [];
    //get the button name.
    $button = substr($self->controller->getButtonName(), -4);

    $realPayLater = FALSE;
    if (!empty($self->_values['event']['is_monetary']) && !empty($self->_values['event']['is_pay_later'])) {
      $realPayLater = $self->_params[0]['is_pay_later'] ?? NULL;
    }

    if ($button !== 'skip') {
      //Check that either an email or firstname+lastname is included in the form(CRM-9587)
      CRM_Event_Form_Registration_Register::checkProfileComplete($fields, $errors, $self->_eventId);

      //Additional Participant can also register for an event only once
      if (!$self->_values['event']['allow_same_participant_emails']) {
        $isRegistered = CRM_Event_Form_Registration_Register::checkRegistration($fields, $self, TRUE);
        if ($isRegistered) {
          $errors["email-{$self->_bltID}"] = ts('A person with this email address is already registered for this event.');
        }
      }

      //get the complete params.
      $params = $self->get('params');

      //take the participant instance.
      $addParticipantNum = substr($self->_name, 12);

      if (is_array($params)) {
        foreach ($params as $key => $value) {
          if ($key != $addParticipantNum) {
            if (!$self->_values['event']['allow_same_participant_emails']) {
              //collect all email fields
              $existingEmails = [];
              $additionalParticipantEmails = [];
              if (is_array($value)) {
                foreach ($value as $key => $val) {
                  if (substr($key, 0, 6) == 'email-' && $val) {
                    $existingEmails[] = $val;
                  }
                }
              }
              foreach ($fields as $key => $val) {
                if (substr($key, 0, 6) == 'email-' && $val) {
                  $additionalParticipantEmails[] = $val;
                  $mailKey = $key;
                }
              }
              //check if any emails are common to both arrays
              if (count(array_intersect($existingEmails, $additionalParticipantEmails))) {
                $errors[$mailKey] = ts('The email address must be unique for each participant.');
                break;
              }
            }
            else {
              // check with first_name and last_name for additional participants
              if (!empty($value['first_name']) && ($value['first_name'] == ($fields['first_name'] ?? NULL)) &&
                (($value['last_name'] ?? NULL) == ($fields['last_name'] ?? NULL))
              ) {
                $errors['first_name'] = ts('The first name and last name must be unique for each participant.');
                break;
              }
            }
          }
        }
      }

      //check for atleast one pricefields should be selected
      if (!empty($fields['priceSetId'])) {
        $allParticipantParams = $params;

        //format current participant params.
        $allParticipantParams[$addParticipantNum] = self::formatPriceSetParams($self, $fields);
        $totalParticipants = $self->getParticipantCount($allParticipantParams);

        //validate price field params.
        $priceSetErrors = $self->validatePriceSet($allParticipantParams, $self->get('priceSetId'), $self->get('priceSet'));
        $errors = array_merge($errors, $priceSetErrors[$addParticipantNum] ?? []);

        if (!$self->_allowConfirmation &&
          is_numeric($self->_availableRegistrations)
        ) {
          if (!empty($self->_params[0]['bypass_payment']) &&
            !$self->_allowWaitlist &&
            !$realPayLater &&
            !$self->_requireApproval &&
            !(($self->_params[0]['amount'] ?? 0) == 0) &&
            $totalParticipants < $self->_availableRegistrations
          ) {
            $errors['_qf_default'] = ts("Your event registration will be confirmed. Please go back to the main registration page, to complete payment information.");
          }
          //check for availability of registrations.
          if (!$self->_allowConfirmation && empty($self->_values['event']['has_waitlist']) &&
            $totalParticipants > $self->_availableRegistrations
          ) {
            $errors['_qf_default'] = ts('Sorry, it looks like this event only has %2 spaces available, and you are trying to register %1 participants. Please change your selections accordingly.', [
              1 => $totalParticipants,
              2 => $self->_availableRegistrations,
            ]);
          }
        }
      }
    }

    if ($button == 'skip' && $self->_lastParticipant && !empty($fields['priceSetId'])) {
      $pricesetFieldsCount = CRM_Price_BAO_PriceSet::getPricesetCount($fields['priceSetId']);
      if (($pricesetFieldsCount < 1) || $self->_allowConfirmation) {
        return $errors;
      }

      if (!empty($self->_values['event']['has_waitlist']) && !empty($self->_params[0]['bypass_payment']) &&
        !$self->_allowWaitlist &&
        !$realPayLater &&
        !$self->_requireApproval &&
        !(($self->_params[0]['amount'] ?? 0) == 0)
      ) {
        $errors['_qf_default'] = ts("You are going to skip the last participant, your event registration will be confirmed. Please go back to the main registration page, to complete payment information.");
      }
    }

    if ($button != 'skip' &&
      $self->_values['event']['is_monetary'] &&
      !isset($errors['_qf_default']) &&
      !$self->validatePaymentValues($self, $fields)
    ) {
      $errors['_qf_default'] = ts("Your payment information looks incomplete. Please go back to the main registration page, to complete payment information.");
      $self->set('forcePayement', TRUE);
    }
    elseif ($button == 'skip') {
      $self->set('forcePayement', TRUE);
    }

    return $errors;
  }

  /**
   * @param self $self
   * @param $fields
   *
   * @return bool
   */
  public function validatePaymentValues($self, $fields) {

    if (!empty($self->_params[0]['bypass_payment']) ||
      $self->_allowWaitlist ||
      empty($self->_fields) ||
      ($self->_params[0]['amount'] ?? 0) > 0
    ) {
      return TRUE;
    }

    $validatePayement = FALSE;
    if (!empty($fields['priceSetId'])) {
      $lineItem = [];
      CRM_Price_BAO_PriceSet::processAmount($self->_values['fee'], $fields, $lineItem);
      if ($fields['amount'] > 0) {
        $validatePayement = TRUE;
        // return false;
      }
    }
    elseif (!empty($fields['amount']) &&
      (($self->_values['fee'][$fields['amount']]['value'] ?? 0) > 0)
    ) {
      $validatePayement = TRUE;
    }

    if (!$validatePayement) {
      return TRUE;
    }

    $errors = [];

    CRM_Core_Form::validateMandatoryFields($self->_fields, $fields, $errors);

    if (isset($self->_params[0]['payment_processor'])) {
      // validate supplied payment instrument values (e.g. credit card number and cvv)
      $payment_processor_id = $self->_params[0]['payment_processor'];
      CRM_Core_Payment_Form::validatePaymentInstrument($payment_processor_id, $self->_params[0], $errors, (!$self->_isBillingAddressRequiredForPayLater ? NULL : 'billing'));
    }
    if (!empty($errors)) {
      return FALSE;
    }

    foreach (CRM_Contact_BAO_Contact::$_greetingTypes as $greeting) {
      $greetingType = $self->_params[0][$greeting] ?? NULL;
      if ($greetingType) {
        $customizedValue = CRM_Core_PseudoConstant::getKey('CRM_Contact_BAO_Contact', $greeting . '_id', 'Customized');
        if ($customizedValue == $greetingType && empty($self->_params[0][$greeting . '_custom'])) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  /**
   * Process the form submission.
   *
   *
   * @return void
   */
  public function postProcess() {
    //get the button name.
    $button = substr($this->controller->getButtonName(), -4);

    //take the participant instance.
    $addParticipantNum = substr($this->_name, 12);

    //user submitted params.
    $params = $this->controller->exportValues($this->_name);

    if (!$this->_allowConfirmation) {
      // check if the participant is already registered
      $params['contact_id'] = CRM_Event_Form_Registration_Register::getRegistrationContactID($params, $this, TRUE);
    }

    if (!empty($params['image_URL'])) {
      CRM_Contact_BAO_Contact::processImageParams($params);
    }
    //carry campaign to partcipants.
    if (array_key_exists('participant_campaign_id', $params)) {
      $params['campaign_id'] = $params['participant_campaign_id'];
    }
    else {
      $params['campaign_id'] = $this->_values['event']['campaign_id'] ?? NULL;
    }

    // if waiting is enabled
    if (!$this->_allowConfirmation &&
      is_numeric($this->_availableRegistrations)
    ) {
      $this->_allowWaitlist = FALSE;
      //get the current page count.
      $currentCount = $this->getParticipantCount($params);
      if ($button === 'skip') {
        $currentCount = 'skip';
      }

      //get the total count.
      $previousCount = $this->getParticipantCount($this->_params, TRUE);
      $totalParticipants = $previousCount;
      if (is_numeric($currentCount)) {
        $totalParticipants += $currentCount;
      }
      if (!empty($this->_values['event']['has_waitlist']) &&
        $totalParticipants > $this->_availableRegistrations
      ) {
        $this->_allowWaitlist = TRUE;
      }
      $this->set('allowWaitlist', $this->_allowWaitlist);
      $this->_lineItemParticipantsCount[$addParticipantNum] = $currentCount;
    }

    if ($button == 'skip') {
      //hack for free/zero amount event.
      if ($this->_resetAllowWaitlist) {
        $this->_allowWaitlist = FALSE;
        $this->set('allowWaitlist', FALSE);
        if ($this->_requireApproval) {
          $status = ts("You have skipped last participant and which result into event having enough spaces, but your registration require approval, Once your registration has been reviewed, you will receive an email with a link to a web page where you can complete the registration process.");
        }
        else {
          $status = ts("You have skipped last participant and which result into event having enough spaces, hence your group become as register participants though you selected on wait list.");
        }
        CRM_Core_Session::setStatus($status);
      }

      $this->_params[$addParticipantNum] = 'skip';
      if (isset($this->_lineItem)) {
        $this->_lineItem[$addParticipantNum] = 'skip';
        $this->_lineItemParticipantsCount[$addParticipantNum] = 'skip';
      }
    }
    else {

      $config = CRM_Core_Config::singleton();
      $params['currencyID'] = $config->defaultCurrency;

      if ($this->_values['event']['is_monetary']) {

        //added for discount
        $discountId = CRM_Core_BAO_Discount::findSet($this->_eventId, 'civicrm_event');
        $params['amount_level'] = $this->getAmountLevel($params, $discountId);
        if (!empty($this->_values['discount'][$discountId])) {
          $params['discount_id'] = $discountId;
          $params['amount'] = $this->_values['discount'][$discountId][$params['amount']]['value'];
        }
        elseif (empty($params['priceSetId'])) {
          CRM_Core_Error::deprecatedWarning('unreachable code, prices set always passed as hidden field for monetary events');
          $params['amount'] = $this->_values['fee'][$params['amount']]['value'];
        }
        else {
          $lineItem = [];
          CRM_Price_BAO_PriceSet::processAmount($this->_values['fee'], $params, $lineItem);

          //build line item array..
          //if requireApproval/waitlist is enabled we hide fees for primary participant
          // (and not for additional participant which might be is a bug)
          //lineItem are not correctly build for primary participant
          //this results in redundancy since now lineItems for additional participant will be build against primary participantNum
          //therefore lineItems must always be build against current participant No
          $this->_lineItem[$addParticipantNum] = $lineItem;
        }
      }

      if (array_key_exists('participant_role', $params)) {
        $params['participant_role_id'] = $params['participant_role'];
      }

      if (empty($params['participant_role_id']) && $this->_values['event']['default_role_id']) {
        $params['participant_role_id'] = $this->_values['event']['default_role_id'];
      }

      if (!empty($this->_params[0]['is_pay_later'])) {
        $params['is_pay_later'] = 1;
      }

      //carry additional participant id, contact id if pre-registered.
      if ($this->_allowConfirmation && $this->_additionalParticipantId) {
        $params['contact_id'] = $this->_contactId;
        $params['participant_id'] = $this->_additionalParticipantId;
      }

      //build the params array.
      $this->_params[$addParticipantNum] = $params;
    }

    //finally set the params.
    $this->set('params', $this->_params);
    //set the line item.
    if ($this->_lineItem) {
      $this->set('lineItem', $this->_lineItem);
      $this->set('lineItemParticipantsCount', $this->_lineItemParticipantsCount);
    }

    $participantNo = count($this->_params);
    if ($button !== 'skip') {
      $statusMsg = ts('Registration information for participant %1 has been saved.', [1 => $participantNo]);
      CRM_Core_Session::setStatus($statusMsg, ts('Registration Saved'), 'success');
    }

    // Check whether to process the registration now, calling processRegistration()
    if (
    // CRM-11182 - Optional confirmation screen
      !$this->_values['event']['is_confirm_enabled']
      && !$this->_values['event']['is_monetary']
      && !empty($this->_params[0]['additional_participants'])
      && $this->isLastParticipant()
    ) {
      $this->processRegistration($this->_params);
    }
  }

  /**
   * @param $additionalParticipant
   *
   * @return array
   */
  public static function &getPages($additionalParticipant) {
    $details = [];
    for ($i = 1; $i <= $additionalParticipant; $i++) {
      $details["Participant_{$i}"] = [
        'className' => 'CRM_Event_Form_Registration_AdditionalParticipant',
        'title' => "Register Additional Participant {$i}",
      ];
    }
    return $details;
  }

  /**
   * Check whether call current participant is last one.
   *
   * @param bool $isButtonJs
   *
   * @return bool
   *   true on success.
   */
  public function isLastParticipant($isButtonJs = FALSE) {
    $participant = $isButtonJs ? $this->_params[0]['additional_participants'] : $this->_params[0]['additional_participants'] + 1;
    if (count($this->_params) == $participant) {
      return TRUE;
    }
    return FALSE;
  }

}
