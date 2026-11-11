<?php
namespace Civi\Api4\Action\FinanceIntegration;

use Civi\Api4\Generic\BasicGetFieldsAction;
use Civi\FinanceIntegration\Connection;
use GuzzleHttp\Client;

/**
 * Intacct dimensions (plus GL account) as fields, with their values as options.
 *
 * @method $this setIsEndowment(bool $isEndowment)
 * @method $this setIsStaging(bool $isStaging)
 */
class GetFields extends BasicGetFieldsAction {

  /**
   * Use the Endowment (rather than WMF) Sage instance.
   *
   * @var bool
   */
  protected bool $isEndowment = FALSE;

  /**
   * Are we connecting to the staging instance?
   *
   * @var bool
   */
  protected bool $isStaging = TRUE;

  protected function getRecords(): array {
    $client = $this->getConnection()->getApiClient();
    $response = $client->get('services/company-config/dimensions/list');
    $dimensions = json_decode((string) $response->getBody(), TRUE)['ia::result'] ?? [];
    // GL account (ACCT_NO) isn't a dimension but is needed alongside them.
    $dimensions[] = ['dimensionName' => 'ACCOUNT', 'dimensionLabel' => 'GL Account', 'termName' => 'GL Account', 'dimensionEndpoint' => '/objects/general-ledger/account'];
    $names = $this->_itemsToGet('name');
    $fields = [];
    foreach ($dimensions as $dimension) {
      $name = strtolower($dimension['dimensionName']);
      if ($names !== NULL && !in_array($name, $names, TRUE)) {
        continue;
      }
      $fields[] = [
        'name' => $name,
        'title' => $dimension['dimensionLabel'],
        'label' => $dimension['termName'],
        'data_type' => 'String',
        'input_type' => 'Select',
        'options' => $this->loadOptions ? $this->getOptions($client, $dimension['dimensionEndpoint'], $name === 'account') : TRUE,
        'dimension_key' => $dimension['dimensionKey'] ?? NULL,
        'dimension_endpoint' => $dimension['dimensionEndpoint'],
        'is_user_defined' => $dimension['isUserDefinedDimension'] ?? FALSE,
      ];
    }
    return $fields;
  }

  /**
   * Fetch all records of a dimension endpoint as [id, name, label] options.
   *
   * Sorted by label, or by id if $sortById (e.g. account numbers).
   */
  protected function getOptions(Client $client, string $endpoint, bool $sortById): array {
    $object = preg_replace('#^/?objects/#', '', $endpoint);
    $labels = [];
    $start = 1;
    do {
      $response = $client->post('services/core/query', [
        'json' => ['object' => $object, 'fields' => ['id', 'name'], 'start' => $start, 'size' => 4000],
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      foreach ((array) ($data['ia::result'] ?? []) as $record) {
        $labels[$record['id']] = $record['name'] ?? $record['id'];
      }
      $start = $data['ia::meta']['next'] ?? NULL;
    } while ($start);
    $sortById ? ksort($labels, SORT_NATURAL) : asort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    $options = [];
    foreach ($labels as $id => $label) {
      $options[] = ['id' => (string) $id, 'name' => (string) $id, 'label' => $label];
    }
    return $options;
  }

  public function fields(): array {
    $fields = parent::fields();
    $fields[] = ['name' => 'dimension_key', 'data_type' => 'Integer', 'description' => 'Intacct dimension key'];
    $fields[] = ['name' => 'dimension_endpoint', 'data_type' => 'String', 'description' => 'Intacct REST endpoint for the dimension values'];
    $fields[] = ['name' => 'is_user_defined', 'data_type' => 'Boolean', 'default_value' => FALSE];
    return $fields;
  }

  /**
   * Protected to allow unit tests to substitute a test connection.
   */
  protected function getConnection(): Connection {
    return new Connection($this->isEndowment ? 'endowment' : 'wmf', $this->isStaging);
  }

}
