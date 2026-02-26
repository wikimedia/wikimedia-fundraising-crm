<?php

namespace Civi\WorkflowMessage\DonorPortalRecurring;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\DonorPortalRecurring;
use Civi\WorkflowMessage\WorkflowMessageExample;

class DonorPortalRecurringExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => 'workflow/donor_portal_recurring/downgrade',
      'title' => ts('Recurring Downgrade'),
      'tags' => ['preview'],
      'workflow' => 'donor_portal_recurring',
    ];
    yield [
      'name' => 'workflow/donor_portal_recurring/pause',
      'title' => ts('Recurring Paused'),
      'tags' => ['preview'],
      'workflow' => 'donor_portal_recurring',
    ];
    yield [
      'name' => 'workflow/donor_portal_recurring/annual_conversion',
      'title' => ts('Recurring Annual Conversion'),
      'tags' => ['preview'],
      'workflow' => 'donor_portal_recurring',
    ];
    yield [
      'name' => 'workflow/donor_portal_recurring/cancel',
      'title' => ts('Cancel Recurring Contribution (monthly)'),
      'tags' => ['preview'],
      'workflow' => 'donor_portal_recurring',
    ];
    yield [
      'name' => 'workflow/donor_portal_recurring/cancel_annual',
      'title' => ts('Cancel Recurring Contribution (annual)'),
      'tags' => ['preview'],
      'workflow' => 'donor_portal_recurring',
    ];
  }

  public function build(array &$example): void {
    $message = new DonorPortalRecurring();
    $oldContributionRecur = $newContributionRecur = [
      'id' => 50,
      'contact_id' => 100,
      'is_email_receipt' => 1,
      'start_date' => '2026-02-23 15:39:20',
      'next_sched_contribution_date' => '2026-03-23 15:39:20',
      'end_date' => NULL,
      'amount' => 25.99,
      'currency' => 'EUR',
      'frequency_unit' => 'month',
      'frequency_interval' => 1,
      'installments' => 0,
      'payment_instrument_id:label' => 'Credit Card',
      'financial_type_id:label' => 'Cash',
      'processor_id' => 'abc_xyz',
      'payment_processor_id' => 2,
      'trxn_id' => 123,
      'invoice_id' => 'inv123',
      'failure_retry_date' => NULL,
      'auto_renew' => 1,
      'cycle_day' => '15',
      'is_test' => TRUE,
      'payment_token_id' => 4,
      'contribution_status_id' => 5,
      'contribution_status_id:name' => 'In Progress',
      'cancel_date' => NULL,
      'cancel_reason' => NULL,
    ];
    switch (basename($example['name'])) {
      case 'downgrade':
        $newContributionRecur['amount'] -= 5;
        $message->setAction('Recurring Downgrade');
        break;
      case 'pause':
        $newContributionRecur['next_sched_contribution_date'] = '2026-06-23 15:39:20';
        $message->setAction('Recurring Paused');
        break;
      case 'annual_conversion':
        $message->setAction('Recurring Annual Conversion');
        $newContributionRecur['frequency_unit'] = 'year';
        $newContributionRecur['next_sched_contribution_date'] = '2027-02-23 15:39:20';
        break;
      case 'cancel':
        $message->setAction('Cancel Recurring Contribution');
        $newContributionRecur['contribution_status_id'] = 3;
        $newContributionRecur['contribution_status_id:name'] = 'Cancelled';
        $newContributionRecur['cancel_date'] = '2026-02-23 15:39:20';
        $newContributionRecur['cancel_reason'] = 'Financial Reasons';
        break;
      case 'cancel_annual':
        $message->setAction('Cancel Recurring Contribution');
        $oldContributionRecur['frequency_unit'] = 'year';
        $newContributionRecur['frequency_unit'] = 'year';
        $newContributionRecur['contribution_status_id'] = 3;
        $newContributionRecur['contribution_status_id:name'] = 'Cancelled';
        $newContributionRecur['cancel_date'] = '2026-02-23 15:39:20';
        $newContributionRecur['cancel_reason'] = 'Financial Reasons';
        break;
    }
    $message->setContact(DemoData::example('entity/Contact/Alex'));
    $message->setNewContributionRecur($newContributionRecur);
    $message->setOldContributionRecur($oldContributionRecur);
    $this->setWorkflowName('donor_portal_recurring');
    $example['data'] = $this->toArray($message);
  }

}
