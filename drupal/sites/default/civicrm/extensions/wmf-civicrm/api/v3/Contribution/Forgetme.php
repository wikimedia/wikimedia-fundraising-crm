<?php

use Civi\Api4\CustomField;
use SmashPig\PaymentProviders\IDeleteDataProvider;
use SmashPig\PaymentProviders\IPaymentProvider;
use SmashPig\PaymentProviders\PaymentProviderFactory;

function _civicrm_api3_contribution_forgetme_spec(array &$spec): void {
  $spec['contact_id']['api.required'] = 1;
  $spec['contact_id']['type'] = CRM_Utils_Type::T_INT;
}

/**
 * contribution.forgetme API
 *
 * Delete personal data about contributions that may be stored at the payment processor,
 * for processors which support automated data deletion requests. This uses our SmashPig
 * library and should ideally go in the org.wikimedia.smashpig Civi extension, but for
 * now we rely on the wmf_contribution_extra table to determine which processor was used
 * to charge each donation. Until we remove that dependency, this lives in wmf-civicrm.
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_contribution_forgetme(array $params): array {
  $customFieldMap = _civicrm_api3_contribution_forgetme_getcustomfields();
  $getParams = [
    'contact_id' => $params['contact_id'],
    'return' => [$customFieldMap['gateway'], $customFieldMap['gateway_txn_id']]
  ];

  $contributions = civicrm_api3('Contribution', 'get', $getParams)['values'];

  $deleted = [];
  foreach ($contributions as $contribution) {
    // Get the SmashPig payment processor for the given contribution
    $provider = _civicrm_api3_contribution_forgetme_getproviderobject($contribution[$customFieldMap['gateway']]);
    if ($provider instanceof IDeleteDataProvider) {
      $provider->deleteDataForPayment($contribution[$customFieldMap['gateway_txn_id']]);
      $deleted[] = $contribution;
    }
  }

  return civicrm_api3_create_success($deleted, $params);
}

function _civicrm_api3_contribution_forgetme_getcustomfields(): array {
  $customFields = Civi::$statics['civicrm_api3_contribution_forgetme_customfields'] ?? [];
  if (empty($customFields)) {
    $queryResult = CustomField::get(FALSE)
      ->addSelect('name')
      ->addWhere('custom_group_id:name', '=', 'contribution_extra')
      ->addWhere('name', 'IN', ['gateway', 'gateway_txn_id', 'original_amount', 'original_currency'])
      ->execute();
    foreach ($queryResult as $customField) {
      $customFields[$customField['name']] = 'custom_' . $customField['id'];
    }
    Civi::$statics['civicrm_api3_contribution_forgetme_customfields'] = $customFields;
  }
  return $customFields;
}

function _civicrm_api3_contribution_forgetme_getproviderobject(string $gateway): IPaymentProvider {
  wmf_common_create_smashpig_context('forgetme', $gateway);
  // Just use 'cc' as the payment method - it doesn't matter for the data deletion API call
  // TODO: PaymentProviderFactory::getProviderForDefaultMethod() might be nice to have
  return PaymentProviderFactory::getProviderForMethod('cc');
}

