<?php

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Service\Spec\CustomFieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * Offers view-only custom fields in the SearchKit Update dialog to permitted users,
 * so they can update them.
 *
 * @service
 * @internal
 */
class ReadOnlyCustomFieldSpecProvider implements Generic\SpecProviderInterface {

  public function modifySpec(RequestSpec $spec): void {
    if (!\CRM_Core_Permission::check('update read-only fields in SearchKit')) {
      return;
    }
    foreach ($spec->getFields() as $field) {
      if ($field instanceof CustomFieldSpec && $field->getReadonly()) {
        $field->setReadonly(FALSE)
          ->setLabel(ts('%1 (read-only)', [1 => $field->getLabel()]));
      }
    }
  }

  public function applies(string $entity, string $action): bool {
    // In practice only changes SearchKit Update
    return $action === 'update';
  }

}
