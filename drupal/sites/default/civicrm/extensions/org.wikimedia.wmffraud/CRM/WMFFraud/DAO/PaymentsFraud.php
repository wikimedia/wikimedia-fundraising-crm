<?php

/**
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 *
 * Generated from org.wikimedia.wmffraud/xml/schema/CRM/WMFFraud/PaymentsFraud.xml
 * DO NOT EDIT.  Generated by CRM_Core_CodeGen
 * (GenCodeChecksum:5b2898357597ce4e0dbaf94caffb5b4b)
 */
use CRM_WMFFraud_ExtensionUtil as E;

/**
 * Database access object for the PaymentsFraud entity.
 */
class CRM_WMFFraud_DAO_PaymentsFraud extends CRM_Core_DAO {
  const EXT = E::LONG_NAME;
  const TABLE_ADDED = '';

  /**
   * Static instance to hold the table name.
   *
   * @var string
   */
  public static $_tableName = 'payments_fraud';

  /**
   * Should CiviCRM log any modifications to this table in the civicrm_log table.
   *
   * @var bool
   */
  public static $_log = FALSE;

  /**
   * Unique PaymentsFraud ID
   *
   * @var int|string|null
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $id;

  /**
   * Contact Contribution Tracking
   *
   * @var int|string
   *   (SQL type: int unsigned)
   *   Note that values will be retrieved from the database as a string.
   */
  public $contribution_tracking_id;

  /**
   * Gateway
   *
   * @var string
   *   (SQL type: varchar(255))
   *   Note that values will be retrieved from the database as a string.
   */
  public $gateway;

  /**
   * Order ID
   *
   * @var string
   *   (SQL type: varchar(255))
   *   Note that values will be retrieved from the database as a string.
   */
  public $order_id;

  /**
   * Validation Action
   *
   * @var string
   *   (SQL type: varchar(16))
   *   Note that values will be retrieved from the database as a string.
   */
  public $validation_action;

  /**
   * User IP Address. The actual field type is varbinary but not sure if
   * this is supported here
   *
   *
   * @var string
   *   (SQL type: varchar(0))
   *   Note that values will be retrieved from the database as a string.
   */
  public $user_ip;

  /**
   * Payment Method
   *
   * @var string
   *   (SQL type: varchar(16))
   *   Note that values will be retrieved from the database as a string.
   */
  public $payment_method;

  /**
   * Risk Score
   *
   * @var float|string
   *   (SQL type: decimal(20,2))
   *   Note that values will be retrieved from the database as a string.
   */
  public $risk_score;

  /**
   * Server
   *
   * @var string
   *   (SQL type: varchar(64))
   *   Note that values will be retrieved from the database as a string.
   */
  public $server;

  /**
   * Date
   *
   * @var string
   *   (SQL type: datetime)
   *   Note that values will be retrieved from the database as a string.
   */
  public $date;

  /**
   * Class constructor.
   */
  public function __construct() {
    $this->__table = 'payments_fraud';
    parent::__construct();
  }

  /**
   * Returns localized title of this entity.
   *
   * @param bool $plural
   *   Whether to return the plural version of the title.
   */
  public static function getEntityTitle($plural = FALSE) {
    return $plural ? E::ts('Payments Frauds') : E::ts('Payments Fraud');
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
          'title' => E::ts('ID'),
          'description' => E::ts('Unique PaymentsFraud ID'),
          'required' => TRUE,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.id',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
          'localizable' => 0,
          'html' => [
            'type' => 'Number',
          ],
          'readonly' => TRUE,
          'add' => NULL,
        ],
        'contribution_tracking_id' => [
          'name' => 'contribution_tracking_id',
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Contact Tracking ID'),
          'description' => E::ts('Contact Contribution Tracking'),
          'required' => TRUE,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.contribution_tracking_id',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
          'localizable' => 0,
          'FKClassName' => 'CRM_Wmf_DAO_ContributionTracking',
          'FKColumnName' => 'id',
          'html' => [
            'type' => 'EntityRef',
            'label' => E::ts("Contribution Tracking"),
          ],
          'add' => NULL,
        ],
        'gateway' => [
          'name' => 'gateway',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Gateway'),
          'description' => E::ts('Gateway'),
          'required' => TRUE,
          'maxlength' => 255,
          'size' => CRM_Utils_Type::HUGE,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.gateway',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'order_id' => [
          'name' => 'order_id',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Order ID'),
          'description' => E::ts('Order ID'),
          'required' => TRUE,
          'maxlength' => 255,
          'size' => CRM_Utils_Type::HUGE,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.order_id',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'validation_action' => [
          'name' => 'validation_action',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Validation Action'),
          'description' => E::ts('Validation Action'),
          'required' => TRUE,
          'maxlength' => 16,
          'size' => CRM_Utils_Type::TWELVE,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.validation_action',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'user_ip' => [
          'name' => 'user_ip',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('User Ip'),
          'description' => E::ts('User IP Address. The actual field type is varbinary but not sure if
      this is supported here
    '),
          'required' => TRUE,
          'maxlength' => 0,
          'size' => CRM_Utils_Type::TWO,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.user_ip',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'payment_method' => [
          'name' => 'payment_method',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Payment Method'),
          'description' => E::ts('Payment Method'),
          'required' => TRUE,
          'maxlength' => 16,
          'size' => CRM_Utils_Type::TWELVE,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.payment_method',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'risk_score' => [
          'name' => 'risk_score',
          'type' => CRM_Utils_Type::T_MONEY,
          'title' => E::ts('Risk Score'),
          'description' => E::ts('Risk Score'),
          'required' => TRUE,
          'precision' => [
            20,
            2,
          ],
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.risk_score',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'server' => [
          'name' => 'server',
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Server'),
          'description' => E::ts('Server'),
          'required' => TRUE,
          'maxlength' => 64,
          'size' => CRM_Utils_Type::BIG,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.server',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
          'localizable' => 0,
          'html' => [
            'type' => 'Text',
          ],
          'add' => NULL,
        ],
        'date' => [
          'name' => 'date',
          'type' => CRM_Utils_Type::T_DATE + CRM_Utils_Type::T_TIME,
          'title' => E::ts('Date'),
          'description' => E::ts('Date'),
          'required' => TRUE,
          'usage' => [
            'import' => FALSE,
            'export' => FALSE,
            'duplicate_matching' => FALSE,
            'token' => FALSE,
          ],
          'where' => 'payments_fraud.date',
          'table_name' => 'payments_fraud',
          'entity' => 'PaymentsFraud',
          'bao' => 'CRM_WMFFraud_DAO_PaymentsFraud',
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
   * Returns the list of fields that can be imported
   *
   * @param bool $prefix
   *
   * @return array
   */
  public static function &import($prefix = FALSE) {
    $r = CRM_Core_DAO_AllCoreTables::getImports(__CLASS__, '_fraud', $prefix, []);
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
    $r = CRM_Core_DAO_AllCoreTables::getExports(__CLASS__, '_fraud', $prefix, []);
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