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
   * @throws \CiviCRM_API3_Exception
   */
  public function resolveConflicts() {
    foreach ($this->getAllAddressConflicts() as $blockNumber => $conflicts) {
      $mainBlock = $this->getAddressBlock(TRUE, $blockNumber);
      $otherBlock = $this->getAddressBlock(FALSE, $blockNumber);
      if ($this->hasSamePostalCodeButOneSuffixIsEmpty($mainBlock, $otherBlock)) {
        $postalCodeSuffix = $mainBlock['postal_code_suffix'] ?? $otherBlock['postal_code_suffix'];
        $this->setResolvedAddressValue('postal_code_suffix', 'address', $blockNumber, $postalCodeSuffix);
      }
      // Check if one address is only a country, and is a subset of the other.
      // This could be the case if only 'display' is in conflict and both have countries.
      // We could extend this to check state+country but as we expand it we might hit some edge cases.
      if (!empty($mainBlock['country_id']) && !empty($otherBlock['country_id']) && array_keys($conflicts) === ['display'] ) {
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
   * @throws \CiviCRM_API3_Exception
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
   * @param array $mainBlock
   * @param array $otherBlock
   *
   * @return bool
   */
  protected function hasSamePostalCodeButOneSuffixIsEmpty(array $mainBlock, array $otherBlock): bool {
    if (empty($mainBlock['postal_code']) || empty($otherBlock['postal_code'])
      || $mainBlock['postal_code'] !== $otherBlock['postal_code']
    ) {
      return FALSE;
    }
    // If both are empty this does not apply.
    if (empty($mainBlock['postal_code_suffix']) && empty($otherBlock['postal_code_suffix'])) {
      return FALSE;
    }
    // If neither are empty this does not apply.
    if (!empty($mainBlock['postal_code_suffix']) && !empty($otherBlock['postal_code_suffix'])) {
      return FALSE;
    }
    return TRUE;
  }

}
