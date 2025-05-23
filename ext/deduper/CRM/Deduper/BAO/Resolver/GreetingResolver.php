<?php

use Civi\Api4\Generic\Result;
use Civi\Api4\OptionValue;

/**
 * Class CRM_Deduper_BAO_Resolver_InitialResolver
 */
class CRM_Deduper_BAO_Resolver_GreetingResolver extends CRM_Deduper_BAO_Resolver {

  /**
   * Resolve conflicts if possible.
   *
   * @throws \CRM_Core_Exception
   */
  public function resolveConflicts() {
    if (!$this->isFieldInConflict('email_greeting_id')
      && !$this->isFieldInConflict('postal_greeting_id')
      && !$this->isFieldInConflict('addressee_id')
    ) {
      return;
    }

    foreach (['email_greeting_id', 'postal_greeting_id', 'addressee_id'] as $field) {
      $contact1Value = $this->getValueForField($field, TRUE);
      $contact2Value = $this->getValueForField($field, FALSE);
      if ($this->isCustomised($field, $contact1Value) && $this->isDefault($field, $contact2Value)) {
        $this->setResolvedValue($field, $contact1Value);
      }
      if ($this->isCustomised($field, $contact2Value) && $this->isDefault($field, $contact1Value)) {
        $this->setResolvedValue($field, $contact2Value);
      }
    }
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function isCustomised($fieldName, $value): bool {
    $greetings = $this->getGreetings($fieldName);
    return ((int) $greetings['Customized']['value']) === $value;
  }

  /**
   * @throws \CRM_Core_Exception
   */
  protected function isDefault($fieldName, int $value): bool {
    $greetings = $this->getGreetings($fieldName);
    return ((int) $greetings->first()['value']) === $value;
  }

  /**
   * @param $fieldName
   *
   * @return \Civi\Api4\Generic\Result
   * @throws \CRM_Core_Exception
   */
  protected function getGreetings($fieldName): Result {
    if (!isset(\Civi::$statics['deduper_greeting_resolver'][$fieldName])) {
      \Civi::$statics['deduper_greeting_resolver'][$fieldName] = OptionValue::get(FALSE)
        ->addWhere('option_group_id:name', '=', str_replace('_id', '', $fieldName))
        ->addSelect('id', 'name', 'is_default', 'value')
        ->addOrderBy('is_default', 'DESC')
        ->execute()->indexBy('name');
    }
    return \Civi::$statics['deduper_greeting_resolver'][$fieldName];
  }

}
