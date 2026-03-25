<?php

namespace Civi\WorkflowMessage;

/**
 * @method string getUrl()
 * @method $this setUrl(string $url)
 * @method string getTargetPage()
 * @method $this setTargetPage(string $targetPage)
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

  /**
   * Page the link is for
   *
   * @var string
   *
   * @scope tplParams
   */
  public $targetPage;
}
