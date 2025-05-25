<?php

use CRM_Deduper_ExtensionUtil as E;

/**
 * Class CRM_Deduper_BAO_Resolver_EquivalentAddressResolver
 *
 * Resolve false address conflicts - notable the presence of postal_code_suffix on one and not the other.
 */
class CRM_Deduper_BAO_Resolver_EquivalentAddressResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   *
   * @throws \CRM_Core_Exception
   */
  public function resolveConflicts() {
    foreach ($this->getAllAddressConflicts() as $blockNumber => $conflicts) {
      $mainBlock = $this->getAddressBlock(TRUE, $blockNumber);
      $otherBlock = $this->getAddressBlock(FALSE, $blockNumber);
      if ($this->hasSamePostalCodeButOneSuffixIsEmpty($mainBlock, $otherBlock)) {
        $postalCodeSuffix = $mainBlock['postal_code_suffix'] ?? $otherBlock['postal_code_suffix'];
        $postalCode = str_replace('-' . $postalCodeSuffix, '', $mainBlock['postal_code']);
        $this->setResolvedAddressValue('postal_code_suffix', 'address', $blockNumber, $postalCodeSuffix);
        $this->setResolvedAddressValue('postal_code', 'address', $blockNumber, $postalCode);
      }
      // Check if one address is only a country, and is a subset of the other.
      // This could be the case if only 'display' is in conflict and both have countries.
      // We could extend this to check state+country but as we expand it we might hit some edge cases.
      if (!empty($mainBlock['country_id']) && !empty($otherBlock['country_id']) && array_keys($conflicts) === ['display']) {
        if ($this->isDisplayCountryOnly((int) $mainBlock['country_id'], $mainBlock['display'])) {
          // ditch this address & use the other.
          $this->setResolvedAddressValue('display', 'address', $blockNumber, $otherBlock['display']);
        }
        elseif ($this->isDisplayCountryOnly((int) $otherBlock['country_id'], $otherBlock['display'])) {
          // ditch this address & use the other.
          $this->setResolvedAddressValue('display', 'address', $blockNumber, $mainBlock['display']);
        }
      }
    }
  }

  /**
   * Is the display field just the country?
   *
   * If display is 'Mexico\n' and the country id matches Mexico this will return true.
   *
   * @param int $countryID
   * @param string $display
   *
   * @return bool
   * @throws \CRM_Core_Exception
   */
  public function isDisplayCountryOnly(int $countryID, $display): bool {
    static $countries = [];
    if (empty($countries)) {
      $countries = civicrm_api3('Address', 'getoptions', ['field' => 'country_id'])['values'];
    }
    $country = $countries[$countryID] ?? NULL;
    return $country . "\n" === $display;
  }

  /**
   * Is this a cases where both addresses have the same (non-empty) postal code but only one has a suffix.
   *
   * Potentially the suffix might be appended to the main field so we break up postal_code
   * around any '-' and compare any part after the '-' to the postal code suffix, if any.
   *
   * @param array $mainBlock
   * @param array $otherBlock
   *
   * @return bool
   */
  protected function hasSamePostalCodeButOneSuffixIsEmpty(array $mainBlock, array $otherBlock): bool {
    $mainPostalCode = explode('-', $mainBlock['postal_code'] ?? '');
    $otherPostalCode = explode('-', $otherBlock['postal_code'] ?? '');
    $mainPostalCodeSuffix = $mainBlock['postal_code_suffix'] ?? '';
    $otherPostalCodeSuffix = $otherBlock['postal_code_suffix'] ?? '';

    // Return early if we don't potentially have 2 matching postal codes
    // where only one has a suffix
    if (
      !$mainPostalCode[0]
      || !$otherPostalCode[0]
      || $mainPostalCode[0] !== $otherPostalCode[0]
      // neither have a suffix
      || (!$mainPostalCodeSuffix && !$otherPostalCodeSuffix)
      // Both have a suffix
      || ($mainPostalCodeSuffix && $otherPostalCodeSuffix)
    ) {
      return FALSE;
    }
    $otherPostalCodeSuffixInLine = $otherPostalCode[1] ?? '';
    if ($mainPostalCodeSuffix && $otherPostalCodeSuffixInLine && $mainPostalCodeSuffix !== $otherPostalCodeSuffixInLine) {
      // The suffix extracted from the other postal_code exists and differs from the main
      // postal code suffix, no match.
      return FALSE;
    }

    $mainPostalCodeSuffixInLine = $mainPostalCode[1] ?? '';
    if ($otherPostalCodeSuffix && $mainPostalCodeSuffixInLine && $otherPostalCodeSuffix !== $mainPostalCodeSuffixInLine) {
      // The suffix extracted from the main postal_code exists and differs from the other
      // postal code suffix, no match.
      return FALSE;
    }

    if ($otherPostalCodeSuffix && !empty($mainPostalCode[1]) && $otherPostalCodeSuffix !== $otherPostalCode[1]) {
      return FALSE;
    }
    return TRUE;
  }

}
