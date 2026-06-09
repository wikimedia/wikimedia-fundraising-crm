<?php

/**
 * DAOs provide an OOP-style facade for reading and writing database records.
 *
 * DAOs are a primary source for metadata in older versions of CiviCRM (<5.74)
 * and are required for some subsystems (such as APIv3).
 *
 * This stub provides compatibility. It is not intended to be modified in a
 * substantive way. Property annotations may be added, but are not required.
 * @property string $id
 * @property string $name
 * @property string $channel
 * @property string $description
 * @property string $type
 * @property string $minimum_severity
 * @property int|string $weight
 * @property bool|string $is_active
 * @property bool|string $is_final
 * @property bool|string $is_default
 * @property string $configuration_options
 */
class CRM_Monolog_DAO_Monolog extends CRM_Monolog_DAO_Base {

  /**
   * Required by older versions of CiviCRM (<5.74).
   * @var string
   */
  public static $_tableName = 'civicrm_monolog';

}
