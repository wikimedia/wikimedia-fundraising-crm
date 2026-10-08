<?php

use Civi\Api4\Activity;
use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\ContributionSoft;
use Civi\Api4\Email;
use Civi\Api4\Relationship;
use Civi\Api4\WMFContact;
use Civi\AuthenticatedIframe\IframePage;
use Civi\WMFHook\PreferencesLink;

/**
 * Email-lookup donor snapshot to be embedded in an iframe in a Zendesk
 * sidebar app.
 *
 * Auth is handled by authenticatediframe extension cookie.
 */
class CRM_Wmf_Page_DonorIframe extends IframePage {

  /**
   * @var array<int, array<int, string>> [contact ID => getDAFs() result]
   */
  private array $dafs = [];

  protected function getHandshakeParams(): array {
    return ['email'];
  }

  protected function handlePost(): string {
    $this->buildTemplateVars();
    $donor = $this->getTemplateVars('donor');

    $snoozeDate = $this->getPostParam('snoozeDate', 'String');
    if ($snoozeDate && $donor) {
      $this->updateSnoozeDate((int) $donor['id'], $snoozeDate);
      $this->buildTemplateVars();
    }

    return self::getTemplate()->fetch($this->getTemplateFileName());
  }

  public function updateSnoozeDate(int $contactID, string $snoozeDate): void {
    Email::update(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('is_primary', '=', TRUE)
      ->addValue('email_settings.snooze_date', $snoozeDate)
      ->execute();
  }

  /**
   * Look up the donor and assign the template variables.
   *
   * Split out from run() for testing.
   */
  public function buildTemplateVars(): void {
    $email = $this->getPostParam('email', 'String');

    $primaryContacts = Email::get(FALSE)
      ->addWhere('email', '=', $email)
      ->addWhere('contact_id.is_deleted', '=', FALSE)
      ->addWhere('is_primary', '=', TRUE)
      ->addSelect('contact_id', 'contact_id.contact_type')
      ->addGroupBy('contact_id')
      ->execute()->column('contact_id.contact_type', 'contact_id');
    $primaryContactIDs = array_keys($primaryContacts);

    if (count($primaryContactIDs) === 1) {
      $this->assign('donor', $this->getDonorSnapshot($primaryContactIDs[0]));
      return;
    }

    if (count($primaryContactIDs) === 2) {
      $dafHolderID = $this->getDAFHolderID($primaryContacts);
      if ($dafHolderID) {
        $donor = $this->getDonorSnapshot($dafHolderID);
        $donor['shares_email_with_daf'] = TRUE;
        $this->assign('donor', $donor);
        return;
      }
    }

    if (count($primaryContactIDs) === 0) {
      $contactIDs = Email::get(FALSE)
        ->addWhere('email', '=', $email)
        ->addWhere('contact_id.is_deleted', '=', FALSE)
        ->addSelect('contact_id')
        ->addGroupBy('contact_id')
        ->execute()->column('contact_id');

      if (count($contactIDs) === 0) {
        $this->assign('message', 'No contact found for this email.');
        return;
      }

      if (count($contactIDs) === 1) {
        $donor = $this->getDonorSnapshot($contactIDs[0]);
        $donor['is_secondary_email'] = TRUE;
        $this->assign('donor', $donor);
        return;
      }
    }

    $this->assign('email', $email);
  }

  /**
   * @param array<int, string> $contacts [contact ID => contact type]
   *
   * @return int|null
   *   The individual, if the contacts are an individual and an organization
   *   that is one of their DAFs.
   */
  private function getDAFHolderID(array $contacts): ?int {
    $individualID = array_search('Individual', $contacts);
    $organizationID = array_search('Organization', $contacts);

    if ($individualID && $organizationID && isset($this->getDAFs($individualID)[$organizationID])) {
      return $individualID;
    }
    return NULL;
  }

  private function getDonorSnapshot(int $contactID): array {
    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect(
        'display_name',
        'address_primary.country_id:label',
        'email_primary.email',
        'email_primary.email_settings.snooze_date',
        'wmf_donor.donor_segment_overall:label',
        'wmf_donor.donor_status_otg:label',
        'wmf_donor.donor_status_recur_month',
        'wmf_donor.donor_status_recur_month:label',
        'wmf_donor.donor_status_recur_year',
        'wmf_donor.donor_status_recur_year:label',
        'wmf_donor.donor_status_recur_overall',
        'wmf_donor.donor_status_recur_overall:label',
        'Communication.last_donor_portal_login',
        'Prospect.Relationship_Manager:label'
      )
      ->execute()->first();
    $activeRecurCount = $this->getActiveRecurCount($contactID);

    return [
      'id' => $contactID,
      'display_name' => $contact['display_name'],
      'country' => $contact['address_primary.country_id:label'],
      'segment' => $contact['wmf_donor.donor_segment_overall:label'],
      'opt_in' => $this->getOptInStatus($contact['email_primary.email'], $contact['email_primary.email_settings.snooze_date']),
      'otg_status' => $contact['wmf_donor.donor_status_otg:label'] ?? 'Never',
      'last_otg' => $this->getLastDonation($contactID, FALSE),
      'recur_status' => $this->getRecurStatusSummary($contact),
      'last_recur' => $this->getLastDonation($contactID, TRUE),
      'employer' => $this->getEmployers($contactID),
      'daf' => $this->getDAFs($contactID),
      'is_secondary_email' => FALSE,
      'shares_email_with_daf' => FALSE,
      'donor_portal_login' => $this->getRecentDonorPortalLogin($contact['Communication.last_donor_portal_login']),
      'relationship_manager' => $contact['Prospect.Relationship_Manager:label'],
      'is_legacy_society' => $this->isLegacySocietyMember($contactID),
      'is_portal_eligible' => $activeRecurCount && PreferencesLink::isDonorPortalEligible($contactID),
      'active_recur_count' => $activeRecurCount,
    ];
  }

  /**
   * @return string|null
   *   Formatted date, or NULL if there's no login or it's more than a week old.
   */
  private function getRecentDonorPortalLogin(?string $lastLogin): ?string {
    if (!$lastLogin || strtotime($lastLogin) < strtotime('-1 week')) {
      return NULL;
    }
    return date('M j, Y', strtotime($lastLogin));
  }

  private function getActiveRecurCount(int $contactID): int {
    return ContributionRecur::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('contribution_status_id:name', 'IN', ['In Progress', 'Pending', 'Processing'])
      ->selectRowCount()
      ->execute()->count();
  }

  private function getOptInStatus(string $primaryEmail, ?string $snoozeDate): string {
    $bulkEmailable = WMFContact::bulkEmailable(FALSE)
      ->setEmail($primaryEmail)
      ->setCheckSnooze(FALSE)
      ->execute()->first();

    if (!$bulkEmailable) {
      return 'Opted out';
    }

    if ($snoozeDate && $snoozeDate > gmdate('Y-m-d')) {
      return 'Snoozed until ' . $snoozeDate;
    }

    return 'Opted in';
  }

  public function getRecurStatusSummary(array $contact): array {
    $month = $contact['wmf_donor.donor_status_recur_month'];
    $year = $contact['wmf_donor.donor_status_recur_year'];

    // All three status fields share the same option values
    $recurStatusLabels = CRM_Core_OptionGroup::values('WMF_Donor_Donor_Status_Overall_Recurring', FALSE, FALSE, FALSE, NULL, 'label');
    // Default to Never if they have no value
    $overallLabel = $contact['wmf_donor.donor_status_recur_overall:label'] ?? $recurStatusLabels[95];

    if ($month < 50 && $year < 50) {
      // Both active, new, paused or failing so show both
      $label = $overallLabel;
      $suffix = 'both';
    }
    // Otherwise show the lowest id one, i.e. the most active
    elseif ($month < $year) {
      $label = $contact['wmf_donor.donor_status_recur_month:label'];
      $suffix = 'monthly';
    }
    elseif ($year < $month) {
      $label = $contact['wmf_donor.donor_status_recur_year:label'];
      $suffix = 'annual';
    }
    else {
      // Both are equal
      $label = $overallLabel;
      $suffix = 'both';
    }

    $text = $label === $recurStatusLabels[95] ? $label : "$label $suffix";

    return [
      'text' => $text,
      'is_failing' => $label === $recurStatusLabels[45],
    ];
  }

  /**
   * Most recent contribution for a contact, either one-time or recurring,
   * including non-Completed status.
   */
  private function getLastDonation(int $contactID, bool $recurring): ?array {
    $get = Contribution::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addOrderBy('receive_date', 'DESC')
      ->addSelect('receive_date', 'total_amount', 'currency', 'contribution_extra.original_currency', 'contribution_extra.original_amount', 'contribution_status_id:name')
      ->setLimit(1);
    if ($recurring) {
      $get->addWhere('contribution_recur_id', 'IS NOT NULL')
        ->addSelect('contribution_recur_id.frequency_unit');
    }
    else {
      $get->addWhere('contribution_recur_id', 'IS NULL');
    }

    $contribution = $get->execute()->first();
    if (!$contribution) {
      return NULL;
    }
    $status = $contribution['contribution_status_id:name'];
    $frequencyLabels = ['month' => 'monthly', 'year' => 'annual'];
    $frequencyUnit = $recurring ? $contribution['contribution_recur_id.frequency_unit'] : NULL;
    return [
      'date' => date('M j, Y', strtotime($contribution['receive_date'])),
      'amount' => CRM_Utils_Money::format(
        $contribution['contribution_extra.original_amount'] ?? $contribution['total_amount'],
        $contribution['contribution_extra.original_currency'] ?? $contribution['currency'],
        '%a %C'
      ),
      'frequency_unit' => $frequencyUnit ? '(' . ($frequencyLabels[$frequencyUnit] ?? $frequencyUnit) . ')' : NULL,
      'status' => $status,
      'is_bad' => $status !== 'Completed',
    ];
  }

  /**
   * @return array<int, string> [employer contact ID => display name]
   */
  private function getEmployers(int $contactID): array {
    $employers = [];

    $relationships = Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $contactID)
      ->addWhere('relationship_type_id:name', '=', 'Employee of')
      ->addWhere('is_active', '=', TRUE)
      ->addSelect('contact_id_b', 'contact_id_b.display_name')
      ->execute();
    foreach ($relationships as $relationship) {
      $employers[$relationship['contact_id_b']] = $relationship['contact_id_b.display_name'];
    }

    return $employers;
  }

  /**
   * Get DAFs related to the given contact, either via a "Holds a Donor
   * Advised Fund of" relationship or a DAF soft credit.
   *
   * @return array<int, string> [DAF contact ID => display name]
   */
  private function getDAFs(int $contactID): array {
    if (isset($this->dafs[$contactID])) {
      return $this->dafs[$contactID];
    }
    $dafs = [];

    $relationships = Relationship::get(FALSE)
      ->addWhere('contact_id_b', '=', $contactID)
      ->addWhere('relationship_type_id:name', '=', 'Holds a Donor Advised Fund of')
      ->addWhere('is_active', '=', TRUE)
      ->addSelect('contact_id_a', 'contact_id_a.display_name')
      ->execute();
    foreach ($relationships as $relationship) {
      $dafs[$relationship['contact_id_a']] = $relationship['contact_id_a.display_name'];
    }

    $softCredits = ContributionSoft::get(FALSE)
      ->addWhere('contact_id', '=', $contactID)
      ->addWhere('soft_credit_type_id:name', '=', 'donor-advised_fund')
      ->addSelect('contribution_id.contact_id', 'contribution_id.contact_id.display_name')
      ->execute();
    foreach ($softCredits as $softCredit) {
      $dafs[$softCredit['contribution_id.contact_id']] = $softCredit['contribution_id.contact_id.display_name'];
    }

    return $this->dafs[$contactID] = $dafs;
  }

  private function isLegacySocietyMember(int $contactID): bool {
    return (bool) Activity::get(FALSE)
      ->addWhere('target_contact_id', 'CONTAINS', $contactID)
      ->addWhere('activity_type_id:name', '=', 'PG - Pledge Confirmed')
      ->addWhere('PG_Commitment_Activity.Commitment_Confirmation_Date', 'IS NOT NULL')
      ->addWhere('PG_Commitment_Activity.Commitment_Confirmed_', '=', TRUE)
      ->addSelect('id')
      ->execute()->count();
  }

}
