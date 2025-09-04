<?php

namespace Civi\WorkflowMessage\AnnualRecurringPrenotification;

use Civi\Test as DemoData;
use Civi\WorkflowMessage\AnnualRecurringPrenotification;
use Civi\WorkflowMessage\WorkflowMessageExample;

class AnnualRecurringPrenotificationExample extends WorkflowMessageExample {

  public function getExamples(): iterable {
    yield [
      'name' => 'workflow/annual_recurring_prenotification/prenotification',
      'workflow' => 'annual_recurring_prenotification',
      'title' => ts( 'Annual Recurring Prenotification' ),
      'tags' => [ 'preview' ],
    ];
  }

  public function build( array &$example ): void {
    $message = new AnnualRecurringPrenotification();
    $message->setContact( DemoData::example( 'entity/Contact/Alex' ) );
    $message->setEmail( 'testy@example.com' );
    $message->setContributionRecur( $this->getContributionRecur() );
    $example['data'] = $this->toArray( $message );
  }

  /**
   * @return array[]
   */
  protected function getContributionRecur(): array {
    return [
      'id' => 0,
      'amount' => '12.30',
      'frequency_unit' => 'year',
      'next_sched_contribution_date' => '2025-10-12 00:00:00',
      'currency' => 'USD',
    ];
  }
}
