<?php

namespace Civi\WMFHook;

use Civi\Api4\WMFLink;

class PreferencesLink {

  public static function contactSummaryBlocks(array &$blocks) {
    // Provide our own group for this block to visually distinguish it on the contact summary editor palette.
    $blocks += [
      'preferenceslink' => [
        'title' => ts('Donor Prefs Links'),
        'icon' => 'fa-at',
        'blocks' => [],
      ]
    ];
    $blocks['preferenceslink']['blocks']['PreferencesLink'] = [
      'id' => 'PreferencesLink',
      'icon' => 'crm-i fa-at',
      'title' => ts('Email Prefs Link'),
      'tpl_file' => 'CRM/Wmf/Page/Inline/PreferencesLink.tpl',
      'sample' => [
        ts('Donor Prefs Links') . ' ' . ts(' (expire in %1 days)', [1 => 7]),
        'https://example.com/emailpreferences'
      ],
      'edit' => FALSE,
      'system_default' => [3, 1], // Add to default layout under demographics block
      'contact_type' => ['Individual'],
    ];
  }

  public static function pageRun(\CRM_Core_Page $page) {
    $contactID = intval($page->getVar('_contactId'));
    if (
      $page instanceof \CRM_Contact_Page_View_Summary &&
      $contactID !== 0
    ) {
      $page->assign('expiryDays', \Civi::settings()->get('checksum_timeout'));
      $preferencesLink = self::getPreferenceUrl($contactID);
      $page->assign('preferencesLink', $preferencesLink);
      $checksum = \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);
      $upgradeableRecur = \Civi\WMFHelper\ContributionRecur::getUpgradeableWithoutChecksum($contactID);
      if ($upgradeableRecur) {
        $recurringUpgradeBaseUrl = (string) \Civi::settings()->get('wmf_recurring_upgrade_url');
        $recurringUpgradeUrl = self::addContactAndChecksumToUrl($recurringUpgradeBaseUrl, $contactID, $checksum);
      }
      else {
        $recurringUpgradeUrl = FALSE;
      }
      $page->assign('recurringUpgradeLink', $recurringUpgradeUrl);
      $donorPortalBaseUrl = (string) \Civi::settings()->get('wmf_donor_portal_url');
      $donorPortalUrl = self::addContactAndChecksumToUrl($donorPortalBaseUrl, $contactID, $checksum);
      $page->assign('donorPortalLink', $donorPortalUrl);
      // FIXME: should be enough to have this path in tpl_file in the contactSummaryBlocks hook
      \CRM_Core_Region::instance('contact-basic-info-right')->add(array(
        'template' => 'CRM/Wmf/Page/Inline/PreferencesLink.tpl',
      ));
    }
  }

  public static function addContactAndChecksumToUrl(string $url, int $contactID, string $checksum): string {
    $parsed = \CRM_Utils_Url::parseUrl($url);

    // Would be nice to have a Util that just let me add query bits and figured out if the URL already had a QS
    $queryParts = [];
    if ($parsed->getQuery() !== '') {
      $queryParts[] = $parsed->getQuery();
    }
    $queryParts[] = "contact_id=$contactID";
    $queryParts[] = "checksum=$checksum";
    return \CRM_Utils_Url::unparseUrl(
      $parsed->withQuery(implode('&', $queryParts))
    );
  }

  /**
   * Get the url to link to the preference centre.
   *
   * @todo figure out where this should live. We have an api in ThankYou extension
   * called WMFLink with getUnsubscribeUrl which makes sense except that the
   * preference code kinda 'belongs' to this extension. We could move that api class
   * into this extension maybe?
   *
   * @param int $contactID
   * @return string
   * @throws \CRM_Core_Exception
   */
  public static function getPreferenceUrl(int $contactID): string {
    $email = \Civi\Api4\Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect('email_primary.email')
      ->execute()->single()['email_primary.email'];
    return WMFLink::getUnsubscribeURL(FALSE)
      ->setContactID($contactID)
      ->setEmail($email)
      ->execute()->first()['unsubscribe_url'];
  }

}
