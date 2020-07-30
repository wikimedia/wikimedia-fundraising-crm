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
   */
  public function resolveConflicts() {
    foreach (array_keys($this->getAllAddressConflicts()) as $blockNumber) {
      $mainBlock = $this->getAddressBlock(TRUE, $blockNumber);
      $otherBlock = $this->getAddressBlock(FALSE, $blockNumber);
      if ($this->hasSamePostalCodeButOneSuffixIsEmpty($mainBlock, $otherBlock)) {
        $postalCodeSuffix = $mainBlock['postal_code_suffix'] ?? $otherBlock['postal_code_suffix'];
        $this->setResolvedAddressValue('postal_code_suffix', 'address', $blockNumber, $postalCodeSuffix);
      }
    }
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
