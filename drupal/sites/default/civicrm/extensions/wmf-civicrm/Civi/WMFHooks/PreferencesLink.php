<?php

namespace Civi\WMFHooks;

class PreferencesLink {
  public static function contactSummaryBlocks(array &$blocks) {
    // Provide our own group for this block to visually distinguish it on the contact summary editor palette.
    $blocks += [
      'preferenceslink' => [
        'title' => ts('Email Prefs Link'),
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
        ts('Email Prefs Link') . ' ' . ts('(expires in %1 days)', [1 => 7]),
        'https://example.com/emailprefs'
      ],
      'edit' => FALSE,
      'system_default' => [3, 1], // Add to default layout under demographics block
      'contact_type' => 'Individual',
    ];
  }

  public static function pageRun(\CRM_Core_Page $page) {
    $contactID = $page->getVar('_contactId');
    if (
      $page instanceof \CRM_Contact_Page_View_Summary &&
      $contactID !== FALSE
    ) {
      $baseUrl = \Civi::settings()->get('wmf_email_preferences_url');
      $parsed = \CRM_Utils_Url::parseUrl($baseUrl);

      // Would be nice to have a Util that just let me add query bits and figured out if the URL already had a QS
      $queryParts = [];
      if ($parsed->getQuery() !== '') {
        $queryParts[] = $parsed->getQuery();
      }
      $queryParts[] = "contact_id=$contactID";
      $queryParts[] = 'checksum=' . \CRM_Contact_BAO_Contact_Utils::generateChecksum($contactID);
      $url = \CRM_Utils_Url::unparseUrl(
        $parsed->withQuery(implode('&', $queryParts))
      );

      $page->assign('preferencesLink', $url);
      $page->assign('expiryDays', \Civi::settings()->get('checksum_timeout'));

      // FIXME: should be enough to have this path in tpl_file in the contactSummaryBlocks hook
      \CRM_Core_Region::instance('contact-basic-info-right')->add(array(
        'template' => 'CRM/Wmf/Page/Inline/PreferencesLink.tpl',
      ));
    }
  }
}
