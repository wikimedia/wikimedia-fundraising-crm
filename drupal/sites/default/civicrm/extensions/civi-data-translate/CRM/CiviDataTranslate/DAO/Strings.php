<?php

/**
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 *
 * Generated from /Users/eileenmcnaughton/buildkit/build/wmff/sites/default/civicrm/extensions/civi-data-translate/xml/schema/CRM/CiviDataTranslate/Strings.xml
 * DO NOT EDIT.  Generated by CRM_Core_CodeGen
 * (GenCodeChecksum:1940b70151dca051fefe019bb7950217)
 */

/**
 * Database access object for the Strings entity.
 */
class CRM_CiviDataTranslate_DAO_Strings extends CRM_Core_DAO {

  /**
   * Static instance to hold the table name.
   *
   * @var string
   */
  public static $_tableName = 'civicrm_strings';

  /**
   * Should CiviCRM log any modifications to this table in the civicrm_log table.
   *
   * @var bool
   */
  public static $_log = TRUE;

  /**
   * Unique Strings ID
   *
   * @var int
   */
  public $id;

  /**
   * Table where referenced item is stored
   *
   * @var string
   */
  public $entity_table;

  /**
   * Field where referenced item is stored
   *
   * @var string
   */
  public $entity_field;

  /**
   * ID of the relevant entity.
   *
   * @var int
   */
  public $entity_id;

  /**
   * Translated strinng
   *
   * @var longtext
   */
  public $string;

  /**
   * Relevant language
   *
   * @var string
   */
  public $language;

  /**
   * Is this string active?
   *
   * @var bool
   */
  public $is_active;

  /**
   * Is this the default string for the given locale?
   *
   * @var bool
   */
  public $is_default;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->__table = 'civicrm_strings';
    parent::__construct();
  }

  /**
   * Returns localized title of this entity.
   */
  public static function getEntityTitle() {
    return ts('Stringses');
  }

  /**
   * Returns all the column names of this table
   *
   * @return array
   */
  public static function &fields() {
    if (!isset(Civi::$statics[__CLASS__]['fields'])) {
      Civi::$statics[__CLASS__]['fields'] = [
        'id' => [
          'name' => 'id',
          'type' => CRM_Utils_Type::T_INT,
          'description' => CRM_CiviDataTranslate_ExtensionUtil::ts('Unique Strings ID'),
          'required' => TRUE,
          'where' => 'civicrm_strings.id',
          'table_name' => 'civicrm_strings',
          'entity' => 'Strings',
          'bao' => 'CRM_CiviDataTranslate_DAO_Strings',
          'localizable' => 0,
          'add' => NULL,
        ],
        'entity_table' => [
          'name' => 'entity_table',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => CRM_CiviDataTranslate_ExtensionUtil::ts('Entity Table'),
          'description' => CRM_CiviDataTranslate_ExtensionUtil::ts('Table where referenced item is stored'),
          'required' => TRUE,
          'maxlength' => 64,
          'size' => CRM_Utils_Type::BIG,
          'where' => 'civicrm_strings.entity_table',
          'table_name' => 'civicrm_strings',
          'entity' => 'Strings',
          'bao' => 'CRM_CiviDataTranslate_DAO_Strings',
          'localizable' => 0,
          'add' => NULL,
        ],
        'entity_field' => [
          'name' => 'entity_field',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => CRM_CiviDataTranslate_ExtensionUtil::ts('Entity Field'),
          'description' => CRM_CiviDataTranslate_ExtensionUtil::ts('Field where referenced item is stored'),
          'required' => TRUE,
          'maxlength' => 64,
          'size' => CRM_Utils_Type::BIG,
          'where' => 'civicrm_strings.entity_field',
          'table_name' => 'civicrm_strings',
          'entity' => 'Strings',
          'bao' => 'CRM_CiviDataTranslate_DAO_Strings',
          'localizable' => 0,
          'add' => NULL,
        ],
        'entity_id' => [
          'name' => 'entity_id',
          'type' => CRM_Utils_Type::T_INT,
          'description' => CRM_CiviDataTranslate_ExtensionUtil::ts('ID of the relevant entity.'),
          'required' => TRUE,
          'where' => 'civicrm_strings.entity_id',
          'table_name' => 'civicrm_strings',
          'entity' => 'Strings',
          'bao' => 'CRM_CiviDataTranslate_DAO_Strings',
          'localizable' => 0,
          'add' => NULL,
        ],
        'string' => [
          'name' => 'string',
          'type' => CRM_Utils_Type::T_LONGTEXT,
          'title' => CRM_CiviDataTranslate_ExtensionUtil::ts('String'),
          'description' => CRM_CiviDataTranslate_ExtensionUtil::ts('Translated strinng'),
          'required' => TRUE,
          'where' => 'civicrm_strings.string',
          'table_name' => 'civicrm_strings',
          'entity' => 'Strings',
          'bao' => 'CRM_CiviDataTranslate_DAO_Strings',
          'localizable' => 0,
          'add' => NULL,
        ],
        'language' => [
          'name' => 'language',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => CRM_CiviDataTranslate_ExtensionUtil::ts('Language'),
          'description' => CRM_CiviDataTranslate_ExtensionUtil::ts('Relevant language'),
          'required' => TRUE,
          'maxlength' => 16,
          'size' => CRM_Utils_Type::TWELVE,
          'where' => 'civicrm_strings.language',
          'table_name' => 'civicrm_strings',
          'entity' => 'Strings',
          'bao' => 'CRM_CiviDataTranslate_DAO_Strings',
          'localizable' => 0,
          'html' => [
            'type' => 'Select',
          ],
          'pseudoconstant' => [
            'optionGroupName' => 'languages',
            'keyColumn' => 'name',
            'optionEditPath' => 'civicrm/admin/options/languages',
          ],
          'add' => NULL,
        ],
        'is_active' => [
          'name' => 'is_active',
          'type' => CRM_Utils_Type::T_BOOLEAN,
          'description' => CRM_CiviDataTranslate_ExtensionUtil::ts('Is this string active?'),
          'where' => 'civicrm_strings.is_active',
          'table_name' => 'civicrm_strings',
          'entity' => 'Strings',
          'bao' => 'CRM_CiviDataTranslate_DAO_Strings',
          'localizable' => 0,
          'add' => NULL,
        ],
        'is_default' => [
          'name' => 'is_default',
          'type' => CRM_Utils_Type::T_BOOLEAN,
          'description' => CRM_CiviDataTranslate_ExtensionUtil::ts('Is this the default string for the given locale?'),
          'where' => 'civicrm_strings.is_default',
          'table_name' => 'civicrm_strings',
          'entity' => 'Strings',
          'bao' => 'CRM_CiviDataTranslate_DAO_Strings',
          'localizable' => 0,
          'add' => NULL,
        ],
      ];
      CRM_Core_DAO_AllCoreTables::invoke(__CLASS__, 'fields_callback', Civi::$statics[__CLASS__]['fields']);
    }
    return Civi::$statics[__CLASS__]['fields'];
  }

  /**
   * Return a mapping from field-name to the corresponding key (as used in fields()).
   *
   * @return array
   *   Array(string $name => string $uniqueName).
   */
  public static function &fieldKeys() {
    if (!isset(Civi::$statics[__CLASS__]['fieldKeys'])) {
      Civi::$statics[__CLASS__]['fieldKeys'] = array_flip(CRM_Utils_Array::collect('name', self::fields()));
    }
    return Civi::$statics[__CLASS__]['fieldKeys'];
  }

  /**
   * Returns the names of this table
   *
   * @return string
   */
  public static function getTableName() {
    return self::$_tableName;
  }

  /**
   * Returns if this table needs to be logged
   *
   * @return bool
   */
  public function getLog() {
    return self::$_log;
  }

  /**
   * Returns the list of fields that can be imported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &import($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getImports(__CLASS__, 'strings', $prefix, []);
    return $r;
  }

  /**
   * Returns the list of fields that can be exported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &export($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getExports(__CLASS__, 'strings', $prefix, []);
    return $r;
  }

  /**
   * Returns the list of indices
   *
   * @param bool $localize
   *
   * @return array
   */
  public static function indices($localize = TRUE) {
    $indices = [];
    return ($localize && !empty($indices)) ? CRM_Core_DAO_AllCoreTables::multilingualize(__CLASS__, $indices) : $indices;
  }

}
