<?php

use wmf_eoy_receipt\EoySummary;

require_once __DIR__ . '/../../EoySummary.php';

/**
 * Tests for EOY Summary
 *
 * @group EOYSummary
 */
class EoySummaryTest extends BaseWmfDrupalPhpUnitTestCase {

  /**
   * Test the render function.
   *
   * @throws \Exception
   */
  public function testRender() {
    variable_set('thank_you_from_address', 'bobita@example.org');
    variable_set('thank_you_from_name', 'Bobita');
    $eoyClass = new EoySummary(['year' => 2018]);
    $email = $eoyClass->render_letter((object) [
      'job_id' => '132',
      'email' => 'bob@example.com',
      'preferred_language' => NULL,
      'name' => 'Bob',
      'status' => 'queued',
      'contributions_rollup' => explode(',', '%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 100.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 1200.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 100.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 800.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 50.00 USD,%Y-%m-%d 100.00 USD,%Y-%m-%d 1200.00 USD,%Y-%m-%d 100.00 USD,%Y-%m-%d 50.00 USD'),
    ]);
    $this->assertEquals([
      'from_name' => 'Bobita',
      'from_address' => 'bobita@example.org',
      'to_name' => 'Bob',
      'to_address' => 'bob@example.com',
      'subject' => 'Your 2018 contributions to Wikipedia
',
      'plaintext' => 'Dear Bob,
Thank you for your donations during 2018.

For your records, your contributions were as follows:

Date        Amount
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  100 USD
%Y-%m-%d  1200 USD
%Y-%m-%d  100 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  100 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  800 USD
%Y-%m-%d  800 USD
%Y-%m-%d  1200 USD
%Y-%m-%d  800 USD
%Y-%m-%d  50 USD
%Y-%m-%d  800 USD
%Y-%m-%d  800 USD
%Y-%m-%d  100 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD
%Y-%m-%d  50 USD

Total USD:  12400
',
      'html' => '<p>
Dear Bob,
</p>

<p>
Thank you for your donations during 2018.
</p>

<p>
For your records, your contributions were as follows:
</p>

<table>
<thead>
<tr>
<th>Date</th><th>Amount</th>
</tr>
</thead>
<tbody>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">1200 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">1200 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">800 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">100 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
<td>%Y-%m-%d</td><td width="100%">50 USD</td>
</tr>
<tr>
  <td>Total USD</td><td width="100%">12400</td>
</tr>
<tbody>
</table>
',
    ], $email);
  }

}
