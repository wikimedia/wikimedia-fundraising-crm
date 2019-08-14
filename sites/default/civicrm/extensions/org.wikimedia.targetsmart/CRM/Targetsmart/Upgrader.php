<?php
use CRM_Targetsmart_ExtensionUtil as E;
use Civi\Api4\Mapping;
use Civi\Api4\MappingField;
use Civi\Api4\LocationType;

/**
 * Collection of upgrade steps.
 */
class CRM_Targetsmart_Upgrader extends CRM_Targetsmart_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Create 2019_targetsmart_bulkimport.
   *
   * For simplicity just delete it if it already exists & recreate.
   *
   * @throws \API_Exception
   */
  public function install() {

    /* @var Civi\Api4\Generic\Result $location */
    $location = LocationType::get()
      ->addWhere('name', '=', 'Old_2019')
      ->execute();
    if (!$location->count()) {
      $location = LocationType::create()
        ->addValue('name','Old_2019')
        ->addValue('display_name', 'Old 2019')
        ->addValue('description', 'Address on file in 2019 before TargetSmart update  ')
        ->execute();
    }
    $locationTypeID = $location->first()['id'];

    // Delete pre-existing mappings.
    Mapping::delete()
      ->addWhere('name', '=', '2019_targetsmart_bulkimport')->execute();

    $mappingFields = [
      /*[
        // contact_id
        'name' => 'Contact ID',
        'column_number' => 0,
      ],*/
      [
        // first_name
        'name' => 'First Name',
        'column_number' => 1,
      ],
      [
        // last_name
        'name' => 'Last Name',
        'column_number' => 2,
      ],
      [
        // nick_name
        'name' => 'Nickname',
        'column_number' => 3,
      ],
      [
        // street_address
        'name' => 'Street Address',
        'column_number' => 4,
        'location_type_id' => 'Old 2019',
      ],
      [
        // supplemental_address_1
        'name' => 'Supplemental Address 1',
        'column_number' => 5,
        'location_type_id' => 'Old 2019',
      ],
      [
        // supplemental_address_1
        'name' => 'Supplemental Address 2',
        'column_number' => 6,
        'location_type_id' => 'Old 2019',
      ],
      [
        // city
        'name' => 'City',
        'column_number' => 7,
        'location_type_id' => 'Old 2019',
      ],
      [
        // state_province
        'name' => 'State',
        'column_number' => 8,
        'location_type_id' => 'Old 2019',
      ],
      [
        // postal code
        'name' => 'Postal Code',
        'column_number' => 9,
        'location_type_id' => 'Old 2019',
      ],
      [
        // country
        'name' => 'Country',
        'column_number' => 10,
        'location_type_id' => 'Old 2019',
      ],
      [
        // voterbase_id
        'name' => '- do not import -',
        'column_number' => 11,
      ],
      [
        // tsmart_sample_id
        'name' => '- do not import -',
        'column_number' => 12,
      ],
      [
        // tsmart_full_address
        'name' => 'Street Address',
        'column_number' => 13,
      ],
      [
        // tsmart_city
        'name' => 'City',
        'column_number' => 14,
      ],
      [
        // tsmart_state
        'name' => 'State',
        'column_number' => 15,
      ],
      [
        // tsmart_zip
        'name' => 'Postal Code',
        'column_number' => 16,
      ],
      [
        // tsmart_zip4
        'name' => 'Postal Code Suffix',
        'column_number' => 17,
      ],
      [
        //tb.new_mover_flg
        'name' => '- do not import -',
        'column_number' => 18,
      ],
      [
        // voterbase_dob
        'name' => 'Birth Date',
        'column_number' => 19,
      ],
      [
        // deceased_flag_date_of_death
        'name' => 'Deceased Date',
        'column_number' => 20,
      ],
      [
        // Household Income Range
        'name' => 'Income Range :: Prospect',
        'column_number' => 21,
      ],
      [
        // Household Income Range_key
        'name' => '- do not import -',
        'column_number' => 22,
      ],
      [
        // Household Net Worth
        'name' => 'Estimated Net Worth :: Prospect',
        'column_number' => 23,
      ],
      [
        // Household Net Worth Key
        'name' => '- do not import -',
        'column_number' => 24,
      ],
      [
        //xpg.donor_contributes_to_charities
        'name' => '- do not import -',
        'column_number' => 25,
      ],
      [
        // tb.charitable_contrib_decile
        'name' => 'Charitable Contributions Decile :: Prospect',
        'column_number' => 26,
      ],
      [
        // Discretionary Income Amount
        'name' => '- do not import -',
        'column_number' => 27,
      ],
      [
        // Discretionary Income Decile
        'name' => 'Disc Income Decile :: Prospect',
        'column_number' => 28,
      ],
      [
        // Family Composition Code - use next field.
        'name' => '- do not import -',
        'column_number' => 29,
      ],
      [
        // Family Composition.
        'name' => 'Family Composition :: Prospect',
        'column_number' => 30,
      ],
      [
        // vf_party.
        'name' => 'Voter Party :: Prospect',
        'column_number' => 31,
      ],
      [
        //party_score_rollup. (how does this related to vf_party?)
        'name' => '- do not import -',
        'column_number' => 32,
      ],
      [
        // Occupation.
        'name' => 'Occupation :: Prospect',
        'column_number' => 33,
      ],
      [
        // Occupation_key - assume same data as ^^ in diff format
        'name' => '- do not import -',
        'column_number' => 34,
      ],
      [
        // voterbase_gender.
        'name' => 'Gender',
        'column_number' => 35,
      ],
    ];
    $mapping = Mapping::create()
      ->addValue('name', '2019_targetsmart_bulkimport')
      ->addValue('mapping_type_id', CRM_Core_PseudoConstant::getKey('CRM_Core_BAO_Mapping', 'mapping_type_id', 'Import Contact'))
      ->execute();

    foreach ($mappingFields as $index => $mappingField) {
      $mappingFieldAPI = MappingField::create()
        ->addValue('mapping_id', $mapping->first()['id'])
        ->addValue('contact_type', 'Individual')
        ->addValue('name', $mappingField['name'])
        ->addValue('column_number', $mappingField['column_number']);
      if (!empty($mappingField['location_type_id'])) {
         $mappingFieldAPI->addValue('location_type_id', $locationTypeID);
      }
      $mappingFieldAPI->execute();
    }

    CRM_Core_BAO_OptionValue::ensureOptionValueExists(['option_group_id' => 'Gender', 'label' => 'Unknown', 'name' => 'Unknown']);
  }

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   *
  public function postInstall() {
    $customFieldId = civicrm_api3('CustomField', 'getvalue', array(
      'return' => array("id"),
      'name' => "customFieldCreatedViaManagedHook",
    ));
    civicrm_api3('Setting', 'create', array(
      'myWeirdFieldSetting' => array('id' => $customFieldId, 'weirdness' => 1),
    ));
  }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
  public function uninstall() {
   $this->executeSqlFile('sql/myuninstall.sql');
  }

  /**
   * Example: Run a simple query when a module is enabled.
   *
  public function enable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a simple query when a module is disabled.
   *
  public function disable() {
    CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  }

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws Exception
   *
  public function upgrade_4200() {
    $this->ctx->log->info('Applying update 4200');
    CRM_Core_DAO::executeQuery('UPDATE foo SET bar = "whiz"');
    CRM_Core_DAO::executeQuery('DELETE FROM bang WHERE willy = wonka(2)');
    return TRUE;
  } // */


  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4201() {
    $this->ctx->log->info('Applying update 4201');
    // this path is relative to the extension base dir
    $this->executeSqlFile('sql/upgrade_4201.sql');
    return TRUE;
  } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4202() {
    $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

    $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
    $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
    $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
    return TRUE;
  }
  public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  public function processPart3($arg5) { sleep(10); return TRUE; }
  // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
  public function upgrade_4203() {
    $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

    $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
    $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
    for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
      $endId = $startId + self::BATCH_SIZE - 1;
      $title = E::ts('Upgrade Batch (%1 => %2)', array(
        1 => $startId,
        2 => $endId,
      ));
      $sql = '
        UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
        WHERE id BETWEEN %1 and %2
      ';
      $params = array(
        1 => array($startId, 'Integer'),
        2 => array($endId, 'Integer'),
      );
      $this->addTask($title, 'executeSql', $sql, $params);
    }
    return TRUE;
  } // */

}
