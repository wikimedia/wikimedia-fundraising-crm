<?php

namespace Civi\WorkflowMessage\ThankYou;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\WorkflowMessageExample;
use Civi\Api4\WorkflowMessage;
use Civi\WorkflowMessage\GenericWorkflowMessage;

class ThankYouExample extends WorkflowMessageExample {

  /**
   * Get the examples this class is able to deliver.
   */
  public function getExamples(): iterable {
    $workflows = [
      'thank_you' => 'Thank You',
      'endowment_thank_you' => 'Endowment Thank You',
      'monthly_convert' => 'Monthly Convert',
    ];
    foreach ($workflows as $workflow => $label) {
      yield [
        'name' => 'workflow/' . $workflow . '/' . $this->getExampleName(),
        'title' => $label . ' (USD)',
        'tags' => ['preview'],
        'workflow' => $workflow,
      ];
      yield [
        'name' => 'workflow/' . $workflow . '/EUR',
        'title' => $label . ' (EUR)',
        'tags' => ['preview'],
        'workflow' => $workflow,
        'example' => 'EUR',
      ];
      yield [
        'name' => 'workflow/' . $workflow . '/CAD',
        'title' => $label . ' (CAD)',
        'tags' => ['preview'],
        'workflow' => $workflow,
        'example' => 'CAD',
      ];
      yield [
        'name' => 'workflow/' . $workflow . '/organization',
        'title' => $label . ' (Organization)',
        'tags' => ['preview'],
        'workflow' => $workflow,
        'example' => 'organization',
      ];
    }
    yield [
      'name' => 'workflow/thank_you/stock',
      'title' => 'Thank you for stock',
      'tags' => ['preview'],
      'workflow' => 'thank_you',
      'example' => 'stock',
    ];
    yield [
      'name' => 'workflow/endowment_thank_you/stock',
      'title' => 'Thank you for stock',
      'tags' => ['preview'],
      'workflow' => 'endowment_thank_you',
      'example' => 'stock',
    ];
    yield [
      'name' => 'workflow/thank_you/delayed',
      'title' => 'Thank you (delayed)',
      'tags' => ['preview'],
      'workflow' => 'thank_you',
      'example' => 'delayed',
    ];
    yield [
      'name' => 'workflow/thank_you/recurring',
      'title' => 'Thank you (recurring)',
      'tags' => ['preview'],
      'workflow' => 'thank_you',
      'example' => 'recurring',
    ];
    yield [
      'name' => 'workflow/thank_you/recurring_annual',
      'title' => 'Thank you (annual recurring)',
      'tags' => ['preview'],
      'workflow' => 'thank_you',
      'example' => 'recurring_annual',
    ];
    yield [
      'name' => 'workflow/thank_you/restarted',
      'title' => 'Thank you (restarted recurring)',
      'tags' => ['preview'],
      'workflow' => 'thank_you',
      'example' => 'restarted',
    ];
    yield [
      'name' => 'workflow/endowment_thank_you/retirement',
      'title' => 'Endowment Thank you (retirement gift source)',
      'tags' => ['preview'],
      'workflow' => 'endowment_thank_you',
      'example' => 'retirement',
    ];
  }

  /**
   * Get the name of the workflow this is used in.
   *
   * (wrapper for confusing property name)
   *
   * @return string
   */
  protected function getWorkflowName(): string {
    return $this->wfName;
  }

  /**
   * Build an example to use when rendering the workflow.
   *
   * @param array $example
   *
   * @throws \CRM_Core_Exception
   */
  public function build(array &$example): void {
    $workFlow = WorkflowMessage::get(TRUE)->addWhere('name', '=', $example['workflow'])->execute()->first();
    $this->setWorkflowName($workFlow['name']);
    $messageTemplate = new $workFlow['class']();
    $example = explode('/', $example['name']);
    $this->addExampleData($messageTemplate, $example[2]);
    $example['data'] = $this->toArray($messageTemplate);
  }

  /**
   * Add relevant example data.
   *
   * @param \Civi\WorkflowMessage\ThankYou|\Civi\WorkflowMessage\EndowmentThankYou|\Civi\WorkflowMessage\MonthlyConvert $messageTemplate
   * @param $example
   *
   * @throws \CRM_Core_Exception
   */
  private function addExampleData(GenericWorkflowMessage $messageTemplate, $example): void {
    if ($example === 'organization') {
      $organization = DemoData::example('entity/Contact/TheDailyBugle');
      $messageTemplate->setContact($organization);
    }
    else {
      $messageTemplate->setContact(DemoData::example('entity/Contact/Barb'));
    }
    $messageTemplate->setCurrency(in_array($example, ['CAD', 'EUR'], TRUE) ? $example : 'USD');
    $messageTemplate->setAmount(4000.99);
    $messageTemplate->setTransactionID('CNTCT-567');
    $messageTemplate->setGateway('braintree');
    $messageTemplate->setPaymentInstrumentID(107);
    $messageTemplate->setVenmoUserName('venmojoe');
    $messageTemplate->setReceiveDate(date('Y-m-d'), strtotime('One month ago'));
    if ($example === 'stock') {
      $messageTemplate->setStockValue(5200);
      $messageTemplate->setStockQuantity(10);
      $messageTemplate->setStockTicker('XXX');
      $messageTemplate->setDescriptionOfStock('Index fund stock');
    }
    if ($example === 'recurring') {
      $messageTemplate->setIsRecurring(TRUE);
      $messageTemplate->setFrequencyUnit('month');
    }
    if ($example === 'recurring_annual') {
      $messageTemplate->setIsRecurring(TRUE);
      $messageTemplate->setFrequencyUnit('year');
    }
    if ($example === 'restarted') {
      $messageTemplate->setIsRecurring(TRUE);
      $messageTemplate->setIsRecurringRestarted(TRUE);
    }
    if ($example === 'delayed') {
      $messageTemplate->setIsDelayed(TRUE);
    }
    if ($example === 'retirement') {
      $messageTemplate->setGiftSource('Retirement Fund');
    }
  }

}
