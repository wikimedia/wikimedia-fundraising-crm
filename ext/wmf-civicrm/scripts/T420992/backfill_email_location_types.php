<?php

/**
 * Backfill email location types for existing contacts based on latest donation payment method.
 */

use Civi\Api4\Email;

/**
 * Perform the backfill of email location types using optimized SQL queries.
 * @param int $minId
 * @param int $maxId
 * @param int $paymentInstrumentGroupId
 * @param string $locationTypes
 * @return null
 * @throws \Civi\Core\Exception\DBQueryException
 */
function backfillEmailRange(int $minId, int $maxId, int $paymentInstrumentGroupId, string $locationTypes)
{
  echo "Starting backfill of ach email location types for cid range " . $minId . " to " . $maxId . "\n";

  $sql = "
SELECT
  e.id AS email_id,
  e.contact_id,
  ov.name AS payment_method
FROM civicrm_email e
INNER JOIN civicrm_contact c ON c.id = e.contact_id
INNER JOIN civicrm_contribution contrib ON contrib.contact_id = e.contact_id
LEFT JOIN civicrm_contribution contrib_newer ON contrib_newer.contact_id = contrib.contact_id AND contrib_newer.id > contrib.id
INNER JOIN civicrm_option_value ov ON ov.value = contrib.payment_instrument_id AND ov.option_group_id = {$paymentInstrumentGroupId}
WHERE e.contact_id BETWEEN {$minId} AND {$maxId}
  AND e.is_primary = 1
  AND e.location_type_id IN ({$locationTypes})
  AND c.is_deleted = 0
  AND contrib_newer.id IS NULL
  AND LOWER(SUBSTRING_INDEX(ov.name, ' ', 1)) = 'ach'
";

  $dao = \CRM_Core_DAO::executeQuery($sql);

  $lastProcessedId = null;

  $updatedCount = 0;
  $errorCount = 0;
  while ($dao->fetch()) {
    $tx = new \CRM_Core_Transaction();

    try {
        // Update primary email to achForm
        Email::update(FALSE)
          ->addWhere('id', '=', $dao->email_id)
          ->setValues(['location_type_id:name' => 'achForm'])
          ->execute();

        // Check if ach billing email already exists
        $billingEmail = Email::get(FALSE)
          ->addWhere('contact_id', '=', $dao->contact_id)
          ->addWhere('location_type_id:name', '=', 'Billing')
          ->addSelect('id')
          ->execute()
          ->first();

        if ($billingEmail) {
          Email::update(FALSE)
            ->addWhere('id', '=', $billingEmail['id'])
            ->setValues(['location_type_id:name' => 'ach'])
            ->execute();
        }
      $tx->commit();
      $updatedCount++;
    } catch (\Exception $e) {
      $tx->rollback();
      $errorCount++;
      \Civi::log()->error("Backfill error for contact {$dao->contact_id}: " . $e->getMessage());
    }

    $lastProcessedId = $dao->contact_id;
  }
  echo "Backfill completed. Updated: $updatedCount, Errors: $errorCount\n";
  return $lastProcessedId;
}

// Run the backfill if this script is executed directly
if (basename(__FILE__) == basename($_SERVER['PHP_SELF'])) {
  backfillEmailLocationTypes();
}
