<?php

/**
 * Description of a one-way link between two entities for search purposes only.
 *
 * This overrides the basic reference to not return references for 'getRefCount'
 * in order to ensure that is not considered in the forgetme routine.
 */
class CRM_Core_Reference_SearchOnly extends CRM_Core_Reference_Basic {

  /**
   * @param CRM_Core_DAO $targetDao
   *
   * @return array
   */
  public function getReferenceCount($targetDao) {
    return [
      'name' => implode(':', ['sql', $this->getReferenceTable(), $this->getReferenceKey()]),
      'type' => get_class($this),
      'table' => $this->getReferenceTable(),
      'key' => $this->getReferenceKey(),
      'count' => 0,
    ];
  }

}
