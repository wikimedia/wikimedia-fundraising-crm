<?php
// phpcs:disable
use CRM_Wmf_ExtensionUtil as E;
// phpcs:enable
use SmashPig\Core\SequenceGenerators;

class CRM_Wmf_BAO_ContributionTracking extends CRM_Wmf_DAO_ContributionTracking {

  /**
   * This is an integer if we are doing an update & NULL otherwise.
   *
   * @var int|null
   */
  protected $contributionTrackingID;

  /**
   * @param int|null $contributionTrackingID
   */
  protected function setContributionTrackingID(?int $contributionTrackingID): void {
    $this->contributionTrackingID = $contributionTrackingID;
  }

  /**
   * Create or update a record from supplied params.
   *
   * Note that normally the presence of an id would mean the record exists
   * but for ContributionTracking we need to do a check.
   *
   * This is the same as the parent except for the mechanism to decide whether it
   * is an update or a create and some minor removals to hard-code things rather than
   * look them up where it feels a bit like needless lookups in a single class
   * (EntityName).
   *
   * @param array $record
   *
   * @return static
   * @throws \CRM_Core_Exception
   */
  public static function writeRecord(array $record): CRM_Core_DAO {
    if (empty($record['id'])){
      // Create a contribution tracking id
      \CRM_SmashPig_ContextWrapper::createContext('contribution-tracking');
      $generator = SequenceGenerators\Factory::getSequenceGenerator('contribution-tracking');
      $record['id'] = (string) $generator->getNext();
    }
    $exists = (bool) CRM_Core_DAO::singleValueQuery('SELECT id FROM civicrm_contribution_tracking WHERE id = %1', [1 => [$record['id'], 'Integer']]);
    $op = !$exists ? 'create' : 'edit';
    $entityName = 'ContributionTracking';
    \CRM_Utils_Hook::pre($op, $entityName, $record['id'] ?? NULL, $record);
    $fields = static::getSupportedFields();
    $instance = new static();
    // Ensure fields exist before attempting to write to them
    $values = array_intersect_key($record, $fields);
    if ($exists) {
      // This is checked by 'save' in conjunction with the getFirstPrimaryKey function.
      $instance->setContributionTrackingID((int) $record['id']);
    }
    // And of course... static caching in globals will fight our efforts to hack create vs update.
    global $_DB_DATAOBJECT;
    $_DB_DATAOBJECT['SEQUENCE'][$instance->_database][$instance->tableName()] = $instance->sequenceKey();

    $instance->copyValues($values);

    $instance->save();

    if (!empty($record['custom']) && is_array($record['custom'])) {
      CRM_Core_BAO_CustomValueTable::store($record['custom'], static::$_tableName, $instance->$idField, $op);
    }

    \CRM_Utils_Hook::post($op, $entityName, $instance->id, $instance, $record);
    return $instance;
  }

  /**
   * This function is called to differentiate an update from create.
   *
   * It is looking to find out the name of the unique id field - it will
   * then look in that field to see if it is populated to determine if it
   * is an update or a create. Since our unique id field is always populated
   * we give it a fake field to set it off the scent if we want it to
   * create.
   *
   * @return string
   */
  protected function getFirstPrimaryKey(): string {
    return $this->contributionTrackingID ? 'id' : 'contributionTrackingID';
  }

  /**
   * This hack allows us to tinker with the handling of id on edit.
   *
   * The parent function has static caching which we want to avoid
   * as we manage this dynamically to fool it's expectation the
   * primary key is only set up update.
   *
   * @return array
   */
  public function sequenceKey(): array {
    return [$this->getFirstPrimaryKey(), TRUE];
  }

}
