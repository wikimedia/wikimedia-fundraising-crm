<?php

use League\Csv\Writer;

/**
 * @param array $params
 */
function _civicrm_api3_matching_gift_policies_export_spec(&$params) {
  $params['path'] = [
    'title' => 'Output path',
    'api.required' => TRUE
  ];
}

/**
 * @param array $params
 * @return array API result descriptor
 */
function civicrm_api3_matching_gift_policies_export($params) {
  $mgNameFieldId = CRM_Core_BAO_CustomField::getCustomFieldID(
    'name_from_matching_gift_db', 'matching_gift_policies', TRUE
  );
  $subsidiariesFieldId = CRM_Core_BAO_CustomField::getCustomFieldID(
    'subsidiaries', 'matching_gift_policies', TRUE
  );
  $suppressionFieldId = CRM_Core_BAO_CustomField::getCustomFieldID(
    'suppress_from_employer_field', 'matching_gift_policies', TRUE
  );
  $orgContacts = civicrm_api3(
    'Contact', 'get', [
      'contact_type' => 'Organization',
      'return' => [$mgNameFieldId, $subsidiariesFieldId],
      'sequential' => 1,
      $mgNameFieldId => ['IS NOT NULL' => 1],
      $suppressionFieldId => 0,
      'options' => [
        'limit' => 0,
      ],
    ]
  );
  $outputPath = $params['path'];
  if (
    (file_exists($outputPath) && !is_writable($outputPath)) ||
    !is_writable(dirname($outputPath))
  ) {
    return civicrm_api3_create_error("Output path $outputPath is not writeable.");
  }

  $writer = Writer::from($outputPath, 'w');
  foreach ($orgContacts['values'] as $contact) {
    $parentCompanyName = trim($contact[$mgNameFieldId]);
    $writer->insertOne([$contact['contact_id'], $parentCompanyName]);
    $subsidiaries = json_decode($contact[$subsidiariesFieldId]);
    foreach ($subsidiaries as $subsidiary) {
      $trimmedSub = trim($subsidiary);
      // Skip if the subsidiary name is basically equal to the parent co name.
      if (
        $trimmedSub === $parentCompanyName ||
        $trimmedSub === 'The ' . $parentCompanyName ||
        'The ' . $trimmedSub === $parentCompanyName
      ) {
        continue;
      }
      $writer->insertOne([$contact['contact_id'], $trimmedSub]);
    }
  }
  return civicrm_api3_create_success();
}
