<?php

use CRM_Monolog_ExtensionUtil as E;

class CRM_Monolog_BAO_Monolog extends CRM_Monolog_DAO_Monolog {

  /**
   * @param array $params
   *
   * @return \CRM_Monolog_DAO_Monolog
   */
  public static function create($params) {
    if (isset($params['type']) && !empty($params['configuration_options'])) {
      $validOptions = self::getOptionsForTypes()[$params['type']];
      foreach ($params['configuration_options'] as $key => $values) {
        if (isset($validOptions[$key]['type'])
        ) {
          // https://lab.civicrm.org/dev/core/-/issues/2551 would ideally formalist / extend validation.
          if ($validOptions[$key]['type'] === CRM_Utils_Type::T_INT && !is_int($values)) {
            unset($params['configuration_options'][$key]);
          }
        }
        else {
          unset($params['configuration_options'][$key]);
        }
      }
    }
    return self::writeRecord($params);
  }

  /**
   * @return array
   */
  public static function getTypes(): array {
    return [
      'log_file' => E::ts('Basic log file'),
      'daily_log_file' => E::ts('File per day'),
      'syslog' => 'Syslog',
      'phpmailer' => E::ts('Email'),
      'firephp' => 'FirePhp',
      'std_out' => E::ts('Command line Standard Out (where in use)'),
      'std_err' => E::ts('Command line Standard Err (where in use)'),
    ];
  }

  /**
   * @return array
   */
  public static function getSeverities(): array {
    return [
      'emergency' => E::ts('Emergency'),
      'alert' => E::ts('Alert'),
      'critical' => E::ts('Critical'),
      'error' => E::ts('Error'),
      'warning' => E::ts('Warning'),
      'notice' => E::ts('Notice'),
      'info' => E::ts('Info'),
      'debug' => E::ts('Debug'),
    ];
  }

  /**
   * @return array[]
   */
  public static function getOptionsForTypes(): array {
    return [
      'daily_log_file' => [
        'max_files' => [
          'description' => E::ts('Number of days before files are deleted, or 0 for never'),
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Maximum days'),
          'default' => 30,
          'required' => FALSE,
        ],
      ],
      'log_file' => [
        'max_files' => [
          'description' => E::ts('Number of files to keep before starting deleting, or 0 for unlimited'),
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Maximum files'),
          'default' => 10,
          'required' => FALSE,
        ],
        'max_file_size' => [
          'description' => E::ts('Maximum file size, after this a new file will be started.'),
          'type' => CRM_Utils_Type::T_INT,
          'title' => E::ts('Maximum file size (in MB)'),
          'default' => 250,
          'required' => TRUE,
        ],
      ],
      'syslog' => [],
      'firephp' => [],
      'mail' => [
        'to' => [
          'description' => E::ts('Address to email'),
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('To email'),
          'required' => TRUE,
        ],
        'from' => [
          'description' => E::ts('Address from email'),
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('From email'),
          'required' => FALSE,
        ],
        'subject' => [
          'description' => E::ts('Subject of email'),
          'type' => CRM_Utils_Type::T_STRING,
          'title' => E::ts('Subject to email'),
          'default' => E::ts('CiviCRM notification'),
          'required' => TRUE,
        ],
      ],
    ];
  }

}
