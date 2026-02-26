<?php

namespace Civi\WorkflowMessage;

/**
 * @method array getNewContributionRecur()
 * @method $this setNewContributionRecur(array $newContributionRecur)
 * @method array getOldContributionRecur()
 * @method $this setOldContributionRecur(array $oldContributionRecur)
 * @method string getAction()
 * @method $this setAction(string $action)
 *
 * @support template-only
 */
class DonorPortalRecurring extends GenericWorkflowMessage {
  public const WORKFLOW = 'donor_portal_recurring';

  /**
   * @var array
   * @scope tplParams as contribution_recur_new
   */
  public $newContributionRecur;

  /**
   * @var array
   * @scope tplParams as contribution_recur_old
   */
  public $oldContributionRecur;

  /**
   * An action corresponding to the name of one of the activities that can be sent
   * from the Donor Portal, e.g. 'Recurring Paused'
   *
   * @var string
   * @scope tplParams
   */
  public $action;

}
