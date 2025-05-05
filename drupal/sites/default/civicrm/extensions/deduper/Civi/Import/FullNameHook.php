<?php

namespace Civi\Import;

use Civi\Api4\Name;
use CRM_Deduper_ExtensionUtil as E;

class FullNameHook {

  /**
   * Implements hook_civicrm_importAlterMappedRow().
   *
   * Supports unpacking full_name in the import context.
   */
  public static function alterMappedImportRow($event): void {
    $mappedRow = $event->mappedRow;
    $contact = $mappedRow['Contact'] ?? [];
    if (!empty($contact['full_name'])) {
      self::addFullNameDetails($mappedRow['Contact']);
    }
    $softCreditContacts = $mappedRow['SoftCreditContact'] ?? [];
    foreach ($softCreditContacts as $key => $softCreditContact) {
      if (!empty($softCreditContact['Contact']['full_name'])) {
        self::addFullNameDetails($mappedRow['SoftCreditContact'][$key]['Contact']);
      }
    }
    $event->mappedRow = $mappedRow;
  }

  /**
   * Add the details derived from parsing the full name.
   *
   * @param array $contact
   *
   * @return void
   * @throws \CRM_Core_Exception
   */
  private static function addFullNameDetails(&$contact): void {
    if (!empty($contact['first_name']) || !empty($contact['last_name'])) {
      throw new \CRM_Core_Exception(E::ts('data cannot be provided for last name or first name if full name is provided'));
    }
    $contact = array_filter(Name::parse(FALSE)
      ->setNames([$contact['full_name']])
      ->execute()->first()) + $contact;
    $contact['addressee_custom'] = $contact['addressee_display'] = $contact['full_name'];
    unset($contact['full_name']);
  }

}
