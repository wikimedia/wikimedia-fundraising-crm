<?php

namespace Civi\WorkflowMessage;

/**
 * @method string getUrl()
 * @method $this setUrl(string $url)
 * @method string getDate()
 * @method $this setDate(string $date)
 * @method string getTime()
 * @method $this setTime(string $time)
 * @method string getOldEmail()
 * @method $this setOldEmail(string $oldEmail)
 * @method string getNewEmail()
 * @method $this setNewEmail(string $newEmail)
 *
 */
class SetPrimaryEmailMessage extends GenericWorkflowMessage {
  public const WORKFLOW = 'set_primary_email';

  /**
   * Requested link
   *
   * @var string
   *
   * @scope tplParams
   */
  public $url;
  /**
   * @var string Date in YYYY-MM-DD format
   * @scope tplParams
   */
  public $date;
  /**
   * @var string Time in HH:MM format
   * @scope tplParams
   */
  public $time;
  /**
   * @var string
   * @scope tplParams
   */
  public $oldEmail;
  /**
   * @var string
   * @scope tplParams
   */
  public $newEmail;
}
