<?php

use Civi\Api4\Email;
use Civi\AuthenticatedIframe\AuthenticatedIframeTestTrait;
use Civi\AuthenticatedIframe\IframeCookieAuth;
use Civi\AuthenticatedIframe\LoginCookieSubscriber;
use Civi\Test;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use Civi\WMFEnvironmentTrait;
use PHPUnit\Framework\TestCase;

/**
 * @group headless
 */
class CRM_Wmf_Page_DonorIframeTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  use WMFEnvironmentTrait;
  use AuthenticatedIframeTestTrait;
  use Test\EntityTrait;

  public function testNoContactFound(): void {
    $vars = $this->runPage('nobody@example.org');
    $this->assertSame('No contact found for this email.', $vars['message']);
    $this->assertNull($vars['donor']);
  }

  public function testSinglePrimaryMatch(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'donor@example.org']);
    $donor = $this->runPage('donor@example.org')['donor'];
    $this->assertSame($contactID, $donor['id']);
    $this->assertFalse($donor['is_secondary_email']);
  }

  public function testSingleSecondaryMatchIsFlagged(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'primary@example.org']);
    $this->addSecondaryEmail($contactID, 'secondary@example.org');

    $donor = $this->runPage('secondary@example.org')['donor'];
    $this->assertSame($contactID, $donor['id']);
    $this->assertTrue($donor['is_secondary_email']);
  }

  public function testMultiplePrimaryMatchesRedirect(): void {
    $this->createIndividual(['email_primary.email' => 'shared@example.org'], 'a');
    $this->createIndividual(['email_primary.email' => 'shared@example.org'], 'b');

    $vars = $this->runPage('shared@example.org');
    $this->assertSame('shared@example.org', $vars['email']);
  }

  public function testMultipleSecondaryMatchesRedirect(): void {
    $contactA = $this->createIndividual(['email_primary.email' => 'other-a@example.org'], 'a');
    $contactB = $this->createIndividual(['email_primary.email' => 'other-b@example.org'], 'b');
    $this->addSecondaryEmail($contactA, 'shared-secondary@example.org');
    $this->addSecondaryEmail($contactB, 'shared-secondary@example.org');

    $vars = $this->runPage('shared-secondary@example.org');
    $this->assertSame('shared-secondary@example.org', $vars['email']);
  }

  public function testOptInNotBulkEmailable(): void {
    $this->createIndividual([
      'email_primary.email' => 'notemailable@example.org',
      'Communication.opt_in' => FALSE,
    ]);
    $donor = $this->runPage('notemailable@example.org')['donor'];
    $this->assertSame('Opted out', $donor['opt_in']);
  }

  public function testOptInBulkEmailableNoSnooze(): void {
    $this->createIndividual(['email_primary.email' => 'emailable@example.org']);
    $donor = $this->runPage('emailable@example.org')['donor'];
    $this->assertSame('Opted in', $donor['opt_in']);
  }

  public function testOptInSnoozedInFuture(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'snoozed@example.org']);
    $this->setSnoozeDate($contactID, date('Y-m-d', strtotime('+30 days')));
    $donor = $this->runPage('snoozed@example.org')['donor'];
    $this->assertStringStartsWith('Snoozed until', $donor['opt_in']);
  }

  public function testOptInSnoozeDateInPast(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'pastsnooze@example.org']);
    $this->setSnoozeDate($contactID, date('Y-m-d', strtotime('-30 days')));
    $donor = $this->runPage('pastsnooze@example.org')['donor'];
    $this->assertSame('Opted in', $donor['opt_in']);
  }

  /**
   * Not bulk emailable for another reason (e.g. do_not_email) should show N,
   * not the snooze date, even though the contact is also snoozed.
   */
  public function testOptInNotBulkEmailableButSnoozedReturnsN(): void {
    $contactID = $this->createIndividual([
      'email_primary.email' => 'both@example.org',
      'Communication.opt_in' => FALSE,
    ]);
    $this->setSnoozeDate($contactID, date('Y-m-d', strtotime('+30 days')));
    $donor = $this->runPage('both@example.org')['donor'];
    $this->assertSame('Opted out', $donor['opt_in']);
  }

  public function testDonorPortalLoginWithinLastWeek(): void {
    $this->createIndividual([
      'email_primary.email' => 'recentlogin@example.org',
      'Communication.last_donor_portal_login' => date('Y-m-d H:i:s', strtotime('-2 days')),
    ]);
    $donor = $this->runPage('recentlogin@example.org')['donor'];
    $this->assertSame(date('M j, Y', strtotime('-2 days')), $donor['donor_portal_login']);
  }

  public function testDonorPortalLoginOlderThanAWeekIsHidden(): void {
    $this->createIndividual([
      'email_primary.email' => 'oldlogin@example.org',
      'Communication.last_donor_portal_login' => date('Y-m-d H:i:s', strtotime('-8 days')),
    ]);
    $donor = $this->runPage('oldlogin@example.org')['donor'];
    $this->assertNull($donor['donor_portal_login']);
  }

  public function testNoDonorPortalLogin(): void {
    $this->createIndividual(['email_primary.email' => 'neverlogin@example.org']);
    $donor = $this->runPage('neverlogin@example.org')['donor'];
    $this->assertNull($donor['donor_portal_login']);
  }

  public function testNoOneTimeGift(): void {
    $this->createIndividual(['email_primary.email' => 'nogift@example.org']);
    $donor = $this->runPage('nogift@example.org')['donor'];
    $this->assertNull($donor['last_otg']);
  }

  public function testOneTimeGiftDetails(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'otg@example.org']);
    $this->createContribution([
      'contact_id' => $contactID,
      'total_amount' => 25,
      'receive_date' => '2024-05-01',
      'contribution_extra.original_currency' => 'USD',
      'contribution_extra.original_amount' => 25,
    ]);

    $lastOTG = $this->runPage('otg@example.org')['donor']['last_otg'];
    $this->assertSame('May 1, 2024', $lastOTG['date']);
    $this->assertSame('25.00 USD', $lastOTG['amount']);
    $this->assertSame('Completed', $lastOTG['status']);
  }

  public function testPicksMostRecentOneTimeGift(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'multi@example.org']);
    $this->createContribution([
      'contact_id' => $contactID,
      'total_amount' => 10,
      'receive_date' => '2023-01-01',
      'contribution_extra.original_currency' => 'USD',
      'contribution_extra.original_amount' => 10,
    ], 'earlier');
    $this->createContribution([
      'contact_id' => $contactID,
      'total_amount' => 20,
      'receive_date' => '2024-01-01',
      'contribution_extra.original_currency' => 'USD',
      'contribution_extra.original_amount' => 20,
    ], 'later');

    $lastOTG = $this->runPage('multi@example.org')['donor']['last_otg'];
    $this->assertSame('Jan 1, 2024', $lastOTG['date']);
  }

  public function testRecurringContributionOnlyInLastRecur(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'recur@example.org']);
    $recurID = $this->createTestEntity('ContributionRecur', [
      'contact_id' => $contactID,
      'payment_processor_id:name' => 'adyen',
      'amount' => 15,
      'currency' => 'USD',
      'frequency_unit' => 'month',
      'contribution_status_id:name' => 'In Progress',
    ], 'recur')['id'];
    $this->createContribution([
      'contact_id' => $contactID,
      'contribution_recur_id' => $recurID,
      'total_amount' => 15,
      'receive_date' => '2024-06-01',
      'contribution_extra.original_currency' => 'USD',
      'contribution_extra.original_amount' => 15,
    ]);

    $donor = $this->runPage('recur@example.org')['donor'];
    $this->assertNull($donor['last_otg']);
    $this->assertSame('Jun 1, 2024', $donor['last_recur']['date']);
    $this->assertSame('(monthly)', $donor['last_recur']['frequency_unit']);
  }

  public function testMultipleEmployers(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'twojobs@example.org']);
    $orgA = $this->createOrganization(['organization_name' => 'Employer A'], 'orgA');
    $orgB = $this->createOrganization(['organization_name' => 'Employer B'], 'orgB');
    foreach ([$orgA, $orgB] as $orgID) {
      $this->createTestEntity('Relationship', [
        'contact_id_a' => $contactID,
        'contact_id_b' => $orgID,
        'relationship_type_id:name' => 'Employee of',
      ]);
    }

    $donor = $this->runPage('twojobs@example.org')['donor'];
    $this->assertEqualsCanonicalizing(['Employer A', 'Employer B'], array_values($donor['employer']));
  }

  public function testNoActiveRecurringHasNoCancelLink(): void {
    $this->createIndividual(['email_primary.email' => 'norecur@example.org']);
    $donor = $this->runPage('norecur@example.org')['donor'];
    $this->assertNull($donor['active_recur_id']);
    $this->assertSame(0, $donor['active_recur_count']);
  }

  public function testSoleActiveRecurringIsLinkable(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'onerecur@example.org']);
    $recurID = $this->createTestEntity('ContributionRecur', [
      'contact_id' => $contactID,
      'payment_processor_id:name' => 'adyen',
      'amount' => 15,
      'currency' => 'USD',
      'frequency_unit' => 'month',
      'contribution_status_id:name' => 'In Progress',
    ])['id'];

    $donor = $this->runPage('onerecur@example.org')['donor'];
    $this->assertSame($recurID, $donor['active_recur_id']);
    $this->assertSame(1, $donor['active_recur_count']);
  }

  public function testCancelledRecurringIsNotLinkable(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'cancelledrecur@example.org']);
    $this->createTestEntity('ContributionRecur', [
      'contact_id' => $contactID,
      'payment_processor_id:name' => 'adyen',
      'amount' => 15,
      'currency' => 'USD',
      'frequency_unit' => 'month',
      'contribution_status_id:name' => 'Cancelled',
    ]);

    $donor = $this->runPage('cancelledrecur@example.org')['donor'];
    $this->assertNull($donor['active_recur_id']);
  }

  public function testMultipleActiveRecurringsAreAmbiguousSoNotLinkable(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'tworecurs@example.org']);
    foreach (['a', 'b'] as $suffix) {
      $this->createTestEntity('ContributionRecur', [
        'contact_id' => $contactID,
        'payment_processor_id:name' => 'adyen',
        'amount' => 15,
        'currency' => 'USD',
        'frequency_unit' => 'month',
        'contribution_status_id:name' => 'In Progress',
      ], $suffix);
    }

    $donor = $this->runPage('tworecurs@example.org')['donor'];
    $this->assertNull($donor['active_recur_id']);
    $this->assertSame(2, $donor['active_recur_count']);
  }

  public function testNoDAF(): void {
    $this->createIndividual(['email_primary.email' => 'nodaf@example.org']);
    $donor = $this->runPage('nodaf@example.org')['donor'];
    $this->assertSame([], $donor['daf']);
  }

  public function testDAFViaRelationship(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'hasdaf@example.org']);
    $orgID = $this->createOrganization(['organization_name' => 'Some DAF Sponsor']);
    $this->createTestEntity('Relationship', [
      'contact_id_a' => $orgID,
      'contact_id_b' => $contactID,
      'relationship_type_id:name' => 'Holds a Donor Advised Fund of',
    ]);

    $donor = $this->runPage('hasdaf@example.org')['donor'];
    $this->assertSame(['Some DAF Sponsor'], array_values($donor['daf']));
  }

  public function testMultipleDAFRelationships(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'twodafs@example.org']);
    $orgA = $this->createOrganization(['organization_name' => 'DAF A'], 'orgA');
    $orgB = $this->createOrganization(['organization_name' => 'DAF B'], 'orgB');
    foreach ([$orgA, $orgB] as $orgID) {
      $this->createTestEntity('Relationship', [
        'contact_id_a' => $orgID,
        'contact_id_b' => $contactID,
        'relationship_type_id:name' => 'Holds a Donor Advised Fund of',
      ]);
    }

    $donor = $this->runPage('twodafs@example.org')['donor'];
    $this->assertEqualsCanonicalizing(['DAF A', 'DAF B'], array_values($donor['daf']));
  }

  public function testDAFViaSoftCreditFallback(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'softdaf@example.org']);
    $orgID = $this->createOrganization(['organization_name' => 'Soft Credit DAF']);
    $contributionID = $this->createContribution([
      'contact_id' => $orgID,
      'total_amount' => 100,
    ])['id'];
    $this->createTestEntity('ContributionSoft', [
      'contact_id' => $contactID,
      'contribution_id' => $contributionID,
      'amount' => 100,
      'soft_credit_type_id:name' => 'donor-advised_fund',
    ]);

    $donor = $this->runPage('softdaf@example.org')['donor'];
    $this->assertSame(['Soft Credit DAF'], array_values($donor['daf']));
  }

  public function testDAFCombinesRelationshipOnlyAndSoftCreditOnlyOrgs(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'mixeddaf@example.org']);

    $relOrgID = $this->createOrganization(['organization_name' => 'Relationship Only DAF'], 'relOrg');
    $this->createTestEntity('Relationship', [
      'contact_id_a' => $relOrgID,
      'contact_id_b' => $contactID,
      'relationship_type_id:name' => 'Holds a Donor Advised Fund of',
    ]);

    $softOrgID = $this->createOrganization(['organization_name' => 'Soft Credit Only DAF'], 'softOrg');
    $contributionID = $this->createContribution([
      'contact_id' => $softOrgID,
      'total_amount' => 100,
      'receive_date' => date('Y-m-d', strtotime('-6 months')),
    ])['id'];
    $this->createTestEntity('ContributionSoft', [
      'contact_id' => $contactID,
      'contribution_id' => $contributionID,
      'amount' => 100,
      'soft_credit_type_id:name' => 'donor-advised_fund',
    ]);

    $donor = $this->runPage('mixeddaf@example.org')['donor'];
    $this->assertEqualsCanonicalizing(
      ['Relationship Only DAF', 'Soft Credit Only DAF'],
      array_values($donor['daf'])
    );
  }

  public function testRelationshipManager(): void {
    $this->createIndividual([
      'email_primary.email' => 'hasrm@example.org',
      'Prospect.Relationship_Manager:label' => 'Jimmy Wales',
    ]);
    $donor = $this->runPage('hasrm@example.org')['donor'];
    $this->assertSame('Jimmy Wales', $donor['relationship_manager']);
  }

  public function testLegacySocietyMember(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'wls@example.org']);
    $this->createTestEntity('Activity', [
      'activity_type_id:name' => 'PG - Pledge Confirmed',
      'source_contact_id' => $contactID,
      'target_contact_id' => $contactID,
      'PG_Commitment_Activity.Commitment_Confirmation_Date' => '2026-01-15',
      'PG_Commitment_Activity.Commitment_Confirmed_' => TRUE,
    ]);

    $donor = $this->runPage('wls@example.org')['donor'];
    $this->assertTrue($donor['is_legacy_society']);
  }

  public function testUpdateSnoozeDateSetsPrimaryEmailSnoozeDate(): void {
    $contactID = $this->createIndividual(['email_primary.email' => 'snoozebutton@example.org']);
    (new CRM_Wmf_Page_DonorIframe())->updateSnoozeDate($contactID, '2030-06-15');

    $donor = $this->runPage('snoozebutton@example.org')['donor'];
    $this->assertSame('Snoozed until 2030-06-15', $donor['opt_in']);
  }

  public function testSnoozeButtonAndFormAreRendered(): void {
    $this->createIndividual(['email_primary.email' => 'snoozeform@example.org']);
    $_POST['email'] = 'snoozeform@example.org';
    $page = new CRM_Wmf_Page_DonorIframe();
    CRM_Wmf_Page_DonorIframe::getTemplate()->clearAllAssign();
    $page->buildTemplateVars();
    $html = CRM_Wmf_Page_DonorIframe::getTemplate()->fetch($page->getTemplateFileName());
    $this->assertStringContainsString('>Snooze<', $html);
    $this->assertStringContainsString('name="snoozeDate"', $html);
  }

  public function testSendLinkButtonsAreRendered(): void {
    $this->createIndividual(['email_primary.email' => 'buttons@example.org']);
    $_POST['email'] = 'buttons@example.org';
    $page = new CRM_Wmf_Page_DonorIframe();
    // buildTemplateVars() only ever assigns one of message/email/donor, so
    // clear any left over from an earlier test sharing the same Smarty
    // template instance.
    CRM_Wmf_Page_DonorIframe::getTemplate()->clearAllAssign();
    $page->buildTemplateVars();
    $html = CRM_Wmf_Page_DonorIframe::getTemplate()->fetch($page->getTemplateFileName());
    $this->assertStringContainsString('name="sendLink" value="DonorPortal"', $html);
    $this->assertStringContainsString('name="sendLink" value="EmailPreferences"', $html);
  }

  public function testSendLinkButtonsDisabledOnceSent(): void {
    $this->createIndividual(['email_primary.email' => 'sent@example.org']);
    $_POST['email'] = 'sent@example.org';
    $page = new CRM_Wmf_Page_DonorIframe();
    CRM_Wmf_Page_DonorIframe::getTemplate()->clearAllAssign();
    $page->buildTemplateVars();
    $page->assign('linkSent', 'DonorPortal');
    $html = CRM_Wmf_Page_DonorIframe::getTemplate()->fetch($page->getTemplateFileName());
    $this->assertSame(2, substr_count($html, '<button type="submit" disabled>'));
  }

  /**
   * Via AccessGuard, so the log tables record the agent.
   */
  public function testSnoozeIsLoggedAgainstAgent(): void {
    $donorID = $this->createIndividual(['email_primary.email' => 'snoozelog@example.org']);
    $agentID = $this->createFixtureUser();
    $this->grantPermissions($agentID, ['access Zendesk iframe']);
    Civi::settings()->loadMandatory(['authenticatediframe_routes' => [
      'civicrm/donoriframe' => ['permissions' => 'access Zendesk iframe', 'frame_ancestors' => 'https://example.com', 'ttl_seconds' => 3600],
    ]]);
    $_COOKIE[LoginCookieSubscriber::cookieName('civicrm/donoriframe')] = IframeCookieAuth::buildToken($agentID, CRM_Utils_Time::time() + 3600, 'civicrm/donoriframe');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ORIGIN'] = CRM_Utils_System::baseURL();
    $_POST = ['email' => 'snoozelog@example.org', 'snoozeDate' => '2030-06-15'];

    try {
      $this->invokeRoute('civicrm/donoriframe');
    }
    finally {
      $this->resetIframeAuth();
      $_POST = [];
    }

    $logUserID = CRM_Core_DAO::singleValueQuery(
      'SELECT log_user_id FROM log_civicrm_value_email l INNER JOIN civicrm_email e ON e.id = l.entity_id WHERE e.contact_id = %1 ORDER BY log_date DESC LIMIT 1',
      [1 => [$donorID, 'Integer']]
    );
    $this->assertSame($agentID, (int) $logUserID);
  }

  /**
   * sendLink/snoozeDate are only be honored from POST.
   */
  public function testSendLinkViaGetQueryStringIsIgnored(): void {
    $this->createIndividual(['email_primary.email' => 'getsendlink@example.org']);
    $_POST['email'] = 'getsendlink@example.org';
    $_GET['sendLink'] = 'DonorPortal';
    $page = new CRM_Wmf_Page_DonorIframe();
    CRM_Wmf_Page_DonorIframe::getTemplate()->clearAllAssign();

    $this->runPageAction($page);

    $this->assertNull($page->getTemplateVars('linkSent'));
    unset($_GET['sendLink']);
  }

  public function testSnoozeDateViaGetQueryStringIsIgnored(): void {
    $this->createIndividual(['email_primary.email' => 'getsnooze@example.org']);
    $_POST['email'] = 'getsnooze@example.org';
    $_GET['snoozeDate'] = '2030-06-15';
    $page = new CRM_Wmf_Page_DonorIframe();
    CRM_Wmf_Page_DonorIframe::getTemplate()->clearAllAssign();

    $this->runPageAction($page);

    $donor = $page->getTemplateVars('donor');
    $this->assertStringNotContainsString('Snoozed', $donor['opt_in']);
    unset($_GET['snoozeDate']);
  }

  /**
   * run() ends with civiExit(), which would kill the whole test process;
   * make it throw instead so it can be caught below.
   */
  private function runPageAction(CRM_Wmf_Page_DonorIframe $page): void {
    CRM_Wmf_Page_DonorIframe::$exitFn = function (): void {
      throw new CRM_Core_Exception_PrematureExitException('CRM_Wmf_Page_DonorIframe::run() called (test)', []);
    };
    // GETs only render the handshake.
    $_SERVER['REQUEST_METHOD'] = 'POST';
    ob_start();
    try {
      $page->run();
    }
    catch (CRM_Core_Exception_PrematureExitException $e) {
      // Expected.
    }
    finally {
      ob_end_clean();
      unset($_SERVER['REQUEST_METHOD']);
      CRM_Wmf_Page_DonorIframe::$exitFn = ['CRM_Utils_System', 'civiExit'];
    }
  }

  public function testRecurStatusBothBelowThreshold(): void {
    $text = $this->callGetRecurStatusSummary(15, 25, 15)['text'];
    $this->assertSame('Active (both)', $text);
  }

  public function testRecurStatusMonthWins(): void {
    $text = $this->callGetRecurStatusSummary(15, 65, 15)['text'];
    $this->assertSame('Active (monthly)', $text);
  }

  public function testRecurStatusYearWins(): void {
    $text = $this->callGetRecurStatusSummary(65, 15, 15)['text'];
    $this->assertSame('Active (annual)', $text);
  }

  public function testRecurStatusNever(): void {
    $text = $this->callGetRecurStatusSummary(95, 95, 95)['text'];
    $this->assertSame('Never', $text);
  }

  public function testRecurStatusEqual(): void {
    $text = $this->callGetRecurStatusSummary(55, 55, 55)['text'];
    $this->assertSame('Failed (both)', $text);
  }

  /**
   * A contact with no wmf_donor row at all has no
   * overall status; it should default to "Never".
   */
  public function testRecurStatusDefaultsToNeverWithNoOverallStatus(): void {
    $contact = [
      'wmf_donor.donor_status_recur_month' => NULL,
      'wmf_donor.donor_status_recur_month:label' => NULL,
      'wmf_donor.donor_status_recur_year' => NULL,
      'wmf_donor.donor_status_recur_year:label' => NULL,
      'wmf_donor.donor_status_recur_overall:label' => NULL,
    ];
    $summary = (new CRM_Wmf_Page_DonorIframe())->getRecurStatusSummary($contact);
    $this->assertSame('Never', $summary['text']);
    $this->assertFalse($summary['is_failing']);
  }

  /**
   * Calls getRecurStatusSummary() with synthetic status IDs, since the real
   * wmf_donor.donor_status_recur_* fields are trigger-computed.
   */
  private function callGetRecurStatusSummary(int $month, int $year, int $overall): array {
    $labels = CRM_Core_OptionGroup::values('WMF_Donor_Donor_Status_Overall_Recurring', FALSE, FALSE, FALSE, NULL, 'label');
    $contact = [
      'wmf_donor.donor_status_recur_month' => $month,
      'wmf_donor.donor_status_recur_month:label' => $labels[$month],
      'wmf_donor.donor_status_recur_year' => $year,
      'wmf_donor.donor_status_recur_year:label' => $labels[$year],
      'wmf_donor.donor_status_recur_overall' => $overall,
      'wmf_donor.donor_status_recur_overall:label' => $labels[$overall],
    ];
    return (new CRM_Wmf_Page_DonorIframe())->getRecurStatusSummary($contact);
  }

  /**
   * Runs the donor lookup for the given email and returns the assigned
   * template vars. Calls buildTemplateVars() directly rather than run(),
   * since these tests don't care about the POST-handling logic in run()
   * (runPageAction() for tests that do).
   */
  private function runPage(string $email): array {
    $_POST['email'] = $email;
    $page = new CRM_Wmf_Page_DonorIframe();
    $page->buildTemplateVars();
    return [
      'message' => $page->getTemplateVars('message'),
      'email' => $page->getTemplateVars('email'),
      'donor' => $page->getTemplateVars('donor'),
    ];
  }

  private function addSecondaryEmail(int $contactID, string $email): void {
    $this->createTestEntity('Email', [
      'contact_id' => $contactID,
      'email' => $email,
      'is_primary' => FALSE,
    ]);
  }

  private function setSnoozeDate(int $contactID, string $date): void {
    Email::update(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('is_primary', '=', TRUE)
      ->addValue('email_settings.snooze_date', $date)
      ->execute();
  }

}
