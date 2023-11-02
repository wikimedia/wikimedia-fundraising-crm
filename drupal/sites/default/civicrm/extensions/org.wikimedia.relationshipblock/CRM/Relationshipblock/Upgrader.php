<?php
use CRM_Relationshipblock_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Relationshipblock_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   */
  public function install() {
    $optionValues = civicrm_api3('OptionValue', 'get', [
      'option_group_id' => 'cg_extend_objects',
      'name' => 'civicrm_relationship_type'
    ]);
    if (!$optionValues['count']) {
      civicrm_api3('OptionValue', 'create', [
        'option_group_id' => 'cg_extend_objects',
        'name' => 'civicrm_relationship_type',
        'label' => ts('Relationship Type'),
        'value' => 'RelationshipType',
      ]);
    }
    $customGroups = civicrm_api3('CustomGroup', 'get', [
      'extends' => 'RelationshipType',
      'name' => 'relationship_block',
    ]);
    if (!$customGroups['count']) {
      $customGroups = civicrm_api3('CustomGroup', 'create', [
        'extends' => 'RelationshipType',
        'name' => 'relationship_block',
        'title' => E::ts('Relationship Block Settings'),
      ]);
    }
    $customFields = civicrm_api3('CustomField', 'get', [
      'custom_group_id' => $customGroups['id'],
    ]);
    if (!$customFields['count']) {
      $customField = civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $customGroups['id'],
        'name' => 'is_relationship_block_on_summary',
        'label' => E::ts('Display block on contact summary'),
        'data_type' => 'Boolean',
        'default_value' => 0,
        'html_type' => 'Radio',
        'required' => 1,
      ]);
      $customField = civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $customGroups['id'],
        'name' => 'relationship_block_exclude_expired',
        'label' => E::ts('Exclude expired relationships'),
        'data_type' => 'Boolean',
        'default_value' => 1,
        'html_type' => 'Radio',
        'required' => 1,
      ]);
      $customField = civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $customGroups['id'],
        'name' => 'relationship_block_exclude_pending',
        'label' => E::ts('Exclude pending relationships'),
        'data_type' => 'Boolean',
        'default_value' => 0,
        'html_type' => 'Radio',
        'required' => 1,
      ]);
    }
  }

  /**
   * Add custom fields for configuring whether to exclude expired and pending
   * relationships.
   *
   * @return TRUE on success
   * @throws Exception
   */
  public function upgrade_0140() {
    $this->ctx->log->info('Applying update 0140');

    $customGroups = civicrm_api3('CustomGroup', 'getsingle', [
      'extends' => 'RelationshipType',
      'name' => 'relationship_block',
    ]);
    $customField = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => $customGroups['id'],
      'name' => 'relationship_block_exclude_expired',
      'label' => E::ts('Exclude expired relationships'),
      'data_type' => 'Boolean',
      'default_value' => 1,
      'html_type' => 'Radio',
      'required' => 1,
    ]);
    $customField = civicrm_api3('CustomField', 'create', [
      'custom_group_id' => $customGroups['id'],
      'name' => 'relationship_block_exclude_pending',
      'label' => E::ts('Exclude pending relationships'),
      'data_type' => 'Boolean',
      'default_value' => 0,
      'html_type' => 'Radio',
      'required' => 1,
    ]);

    return TRUE;
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
