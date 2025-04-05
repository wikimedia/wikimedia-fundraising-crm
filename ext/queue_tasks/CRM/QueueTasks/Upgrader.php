<?php

use Civi\Api4\Queue;
use CRM_QueueTasks_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_QueueTasks_Upgrader extends \CRM_Extension_Upgrader_Base {

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   */
  public function postInstall(): void {
    Queue::addDedupeTask(FALSE)->execute();
  }

}
