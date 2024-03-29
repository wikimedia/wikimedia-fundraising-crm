<?php

/**
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 *
 * Generated from damaged/xml/schema/CRM/Damaged/Damaged.xml
 * DO NOT EDIT.  Generated by CRM_Core_CodeGen
 * (GenCodeChecksum:4b881e02bad47bdc9e4458450ea8278b)
 */
use CRM_Damaged_ExtensionUtil as E;

/**
 * Database access object for the Damaged entity.
 */
class CRM_Damaged_DAO_Damaged extends CRM_Core_DAO {
  const EXT = E::LONG_NAME;
  const TABLE_ADDED = '';

  /**
   * Static instance to hold the table name.
   *
   * @var string
   */
  public static $_tableName = 'damaged_view';

  /**
   * Should CiviCRM log any modifications to this table in the civicrm_log table.
   *
   * @var bool
   */
  public static $_log = TRUE;

  /**
   * Unique Damaged Table Row ID
   *
   * @var int|string|null
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $id;

  /**
   * Original date
   *
   * @var string
   *   (SQL type: datetime)
   *   Note that values will be retrieved from the database as a string.
   */
  public $original_date;

  /**
   * Damage date
   *
   * @var string
   *   (SQL type: datetime)
   *   Note that values will be retrieved from the database as a string.
   */
  public $damaged_date;

  /**
   * Retry date
   *
   * @var string
   *   (SQL type: datetime)
   *   Note that values will be retrieved from the database as a string.
   */
  public $retry_date;

  /**
   * Original Queue
   *
   * @var string
   *   (SQL type: varchar(0))
   *   Note that values will be retrieved from the database as a string.
   */
  public $original_queue;

  /**
   * Gateway
   *
   * @var string
   *   (SQL type: varchar(0))
   *   Note that values will be retrieved from the database as a string.
   */
  public $gateway;

  /**
   * Order ID
   *
   * @var string
   *   (SQL type: varchar(0))
   *   Note that values will be retrieved from the database as a string.
   */
  public $order_id;

  /**
   * Gateway Transaction ID
   *
   * @var string
   *   (SQL type: varchar(0))
   *   Note that values will be retrieved from the database as a string.
   */
  public $gateway_txn_id;

  /**
   * Error
   *
   * @var string
   *   (SQL type: text)
   *   Note that values will be retrieved from the database as a string.
   */
  public $error;

  /**
   * Error
   *
   * @var string
   *   (SQL type: text)
   *   Note that values will be retrieved from the database as a string.
   */
  public $trace;

  /**
   * Error
   *
   * @var string
   *   (SQL type: text)
   *   Note that values will be retrieved from the database as a string.
   */
  public $message;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->__table = 'damaged_view';
    parent::__construct();
  }
  /**
   * Paths for accessing this entity in the UI.
   *
   * @var string[]
   */
  protected static $_paths = [
    'view' => 'civicrm/damaged/edit?action=view&id=[id]&reset=1',
    'update' => 'civicrm/damaged/edit?action=update&id=[id]&reset=1',
    'delete' => 'civicrm/damaged/edit?action=delete&id=[id]&reset=1',
  ];

  /**
   * Returns localized title of this entity.
   *
   * @param bool $plural
   *   Whether to return the plural version of the title.
   */
  public static function getEntityTitle($plural = FALSE) {
    return $plural ? E::ts('Damaged') : E::ts('Damaged');
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
          'description' => E::ts('Unique Damaged Table Row ID'),
          'required' => TRUE,
          'where' => 'damaged_view.id',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Number',
          ],
          'readonly' => TRUE,
          'add' => NULL,
        ],
        'original_date' => [
          'name' => 'original_date',
          'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
          'title' => E::ts('Original Date'),
          'description' => E::ts('Original date'),
          'required' => TRUE,
          'where' => 'damaged_view.original_date',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Datetime',
          ],
          'add' => NULL,
        ],
        'damaged_date' => [
          'name' => 'damaged_date',
          'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
          'title' => E::ts('Damaged Date'),
          'description' => E::ts('Damage date'),
          'required' => TRUE,
          'where' => 'damaged_view.damaged_date',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Datetime',
          ],
          'add' => NULL,
        ],
        'retry_date' => [
          'name' => 'retry_date',
          'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
          'title' => E::ts('Retry Date'),
          'description' => E::ts('Retry date'),
          'required' => FALSE,
          'where' => 'damaged_view.retry_date',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Datetime',
          ],
          'add' => NULL,
        ],
        'original_queue' => [
          'name' => 'original_queue',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Original Queue'),
          'description' => E::ts('Original Queue'),
          'required' => TRUE,
          'maxlength' => 0,
          'size' => CRM_Utils_Type::TWO,
          'where' => 'damaged_view.original_queue',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Character',
          ],
          'add' => NULL,
        ],
        'gateway' => [
          'name' => 'gateway',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Gateway'),
          'description' => E::ts('Gateway'),
          'required' => FALSE,
          'maxlength' => 0,
          'size' => CRM_Utils_Type::TWO,
          'where' => 'damaged_view.gateway',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Character',
          ],
          'add' => NULL,
        ],
        'order_id' => [
          'name' => 'order_id',
          'type' => CRM_Utils_Type::T_STRING,
          'description' => E::ts('Order ID'),
          'required' => FALSE,
          'maxlength' => 0,
          'size' => CRM_Utils_Type::TWO,
          'where' => 'damaged_view.order_id',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Character',
          ],
          'add' => NULL,
        ],
        'gateway_txn_id' => [
          'name' => 'gateway_txn_id',
          'type' => CRM_Utils_Type::T_STRING,
          'description' => E::ts('Gateway Transaction ID'),
          'required' => FALSE,
          'maxlength' => 0,
          'size' => CRM_Utils_Type::TWO,
          'where' => 'damaged_view.gateway_txn_id',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Character',
          ],
          'add' => NULL,
        ],
        'error' => [
          'name' => 'error',
          'type' => CRM_Utils_Type::T_TEXT,
          'title' => E::ts('Error'),
          'description' => E::ts('Error'),
          'required' => FALSE,
          'where' => 'damaged_view.error',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'trace' => [
          'name' => 'trace',
          'type' => CRM_Utils_Type::T_TEXT,
          'title' => E::ts('Trace'),
          'description' => E::ts('Error'),
          'required' => FALSE,
          'where' => 'damaged_view.trace',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'message' => [
          'name' => 'message',
          'type' => CRM_Utils_Type::T_TEXT,
          'title' => E::ts('Message'),
          'description' => E::ts('Error'),
          'required' => TRUE,
          'where' => 'damaged_view.message',
          'table_name' => 'damaged_view',
          'entity' => 'Damaged',
          'bao' => 'CRM_Damaged_DAO_Damaged',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
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
    $r = CRM_Core_DAO_AllCoreTables::getImports(__CLASS__, '_damaged', $prefix, []);
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
    $r = CRM_Core_DAO_AllCoreTables::getExports(__CLASS__, '_damaged', $prefix, []);
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
    $indices = [
      'index_original_date_retry_date_order_id_gateway_txn_id' => [
        'name' => 'index_original_date_retry_date_order_id_gateway_txn_id',
        'field' => [
          0 => 'original_date',
          1 => 'retry_date',
          2 => 'order_id',
          3 => 'gateway_txn_id',
        ],
        'localizable' => FALSE,
        'unique' => TRUE,
        'sig' => 'damaged_view::1::original_date::retry_date::order_id::gateway_txn_id',
      ],
    ];
    return ($localize && !empty($indices)) ? CRM_Core_DAO_AllCoreTables::multilingualize(__CLASS__, $indices) : $indices;
  }

}
