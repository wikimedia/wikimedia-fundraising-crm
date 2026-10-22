<?php

use Civi\Api4\CustomField;
use SmashPig\Core\ConfigurationKeyException;
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
 *
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_contribution_forgetme(array $params): array {
  $customFieldMap = _civicrm_api3_contribution_forgetme_getcustomfields();
  $getParams = [
    'contact_id' => $params['contact_id'],
    'return' => ['contact_id', $customFieldMap['gateway'], $customFieldMap['gateway_txn_id']],
    'options' => ['limit' => 0],
  ];

  $contributions = civicrm_api3('Contribution', 'get', $getParams)['values'];

  $deleted = [];
  $failures = [];
  foreach ($contributions as $contribution) {
    $gatewayTxnId = $contribution[$customFieldMap['gateway_txn_id']];
    try {
      $provider = _civicrm_api3_contribution_forgetme_getproviderobject($contribution);
      if ($provider instanceof IDeleteDataProvider) {
        $response = $provider->deleteDataForPayment($gatewayTxnId);
        if ($response->isSuccessful()) {
          $deleted[] = $contribution;
        }
        else {
          $errors = [];
          foreach ($response->getErrors() as $error) {
            $errors[] = $error->getDebugMessage();
          }
          $failures[] = "contribution {$contribution['id']} (contact {$contribution['contact_id']}, $gatewayTxnId): " . implode(', ', $errors);
        }
      }
    }
    catch (Exception $ex) {
      $failures[] = "contribution {$contribution['id']} (contact {$contribution['contact_id']}, $gatewayTxnId): " . $ex->getMessage();
    }
  }

  if ($failures) {
    \Civi::log('wmf')->alert("Forgetme: payment processor data deletion failed for:\n" . implode("\n", $failures), [
      'subject' => 'Forgetme payment processor data deletion failed',
    ]);
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

function _civicrm_api3_contribution_forgetme_getproviderobject(array $contrbution): ?IPaymentProvider {
  $customFieldMap = _civicrm_api3_contribution_forgetme_getcustomfields();
  if (empty($contrbution[$customFieldMap['gateway']])) {
    return null;
  }
  try {
    // Gateways like 'engage' which don't correspond to anything known to SmashPig
    // have no provider config.
    \CRM_SmashPig_ContextWrapper::createContext('forgetme', $contrbution[$customFieldMap['gateway']]);
    // The GDPR method is defined on the base class, so any method's provider will do.
    return PaymentProviderFactory::getDefaultProvider();
  }
  catch (ConfigurationKeyException $ex) {
    return null;
  }
}

