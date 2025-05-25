<?php

namespace Civi\WorkflowMessage;

/**
 * @method string getUrl()
 * @method $this setUrl(string $url)
 */
class NewChecksumLinkMessage extends GenericWorkflowMessage {
  public const WORKFLOW = 'new_checksum_link';

  /**
   * Requested link
   *
   * @var string
   *
   * @scope tplParams
   */
  public $url;
}
