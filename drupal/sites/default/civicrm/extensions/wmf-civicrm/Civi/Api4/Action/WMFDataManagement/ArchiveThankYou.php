<?php
namespace Civi\Api4\Action\WMFDataManagement;

use Civi\Api4\Activity;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Class ArchiveThankYou.
 *
 * Delete details from old thank you emails.
 *
 * @method setLimit(int $limit) Set the number of activities to hit in the run.
 * @method getLimit(): int Get the number of activities
 * @method setEndTimeStamp(string $endTimeStamp) Set the time to purge up to.
 * @method getEndTimeStamp(): string Get the time to purge up to.
 * @method getBatchSize(): int get the number to do each query.
 * @method setBatchSize(int $batchSize) set the number to run each query.
 *
 * @package Civi\Api4
 */
class ArchiveThankYou extends AbstractAction {

  /**
   * Limit for run.
   *
   * @var int
   */
  protected $limit = 10000;

  /**
   * Number to run per iteration.
   *
   * @var int
   */
  protected $batchSize = 1000;

  /**
   * Date to finish at - a strtotime-able value.
   *
   * @var string
   */
  protected $endTimeStamp = '1 year ago';

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \API_Exception
   */
  public function _run(Result $result): void {
    // We might need a temp table - but testing without first.
    // $tempTable = \CRM_Utils_SQL_TempTable::build()->createWithColumns('id int unsigned NOT NULL  KEY ( id )');
    $rows = 0;
    while ($rows < $this->getLimit()) {
      $ids = array_keys((array) Activity::get($this->getCheckPermissions())
        ->addSelect('id')
        ->addWhere('activity_type_id:name', '=', 'Email')
        ->addWhere('details', '<>', '')
        ->addWhere('activity_date_time', '<', $this->getEndTimeStamp())
        ->addClause('OR',
          ['subject', 'IN', $this->getThankYouSubjects()],
          ['subject', 'LIKE', 'Your % gift = free knowledge for billions']
        )
        ->setLimit($this->getBatchSize())
        ->execute()->indexBy('id'));
       if (empty($ids)) {
         break;
       }
      \CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_activity SET details = NULL
        WHERE id IN(" . implode(',', $ids) . ")"
      );
      \CRM_Core_DAO::executeQuery(
        "UPDATE log_civicrm_activity SET details = NULL
        WHERE id IN(" . implode(',', $ids) . ")"
      );
      $rows += count($ids);
    }
  }

  public function getThankYouSubjects() {
    return [
      'Thank you from the Wikimedia Foundation',
      'We’re here for you. Thanks for being here for us',
      'Your donation. Your curiosity. Your Wikipedia.',
      'Your gift = free knowledge for billions',
      'Merci à vous de la part de la Wikimedia Foundation',
      'Merci à vous de la part de la Fondation Wikimédia',
      'Din donation. Din nyfikenhet. Ditt Wikipedia.',
      'Uw donatie. Uw nieuwsgierigheid. Uw Wikipedia.',
      'Grazie dalla Wikimedia Foundation',
      'Gracias desde la Fundación Wikimedia',
      'Dank u wel van de Wikimedia Foundation',
      'Die Wikimedia Foundation sagt Danke',
      'Podziękowanie od Wikimedia Foundation',
      'Votre cadeau = le savoir gratuit pour des milliards de personnes',
      'La tua donazione. La tua curiosità. La tua Wikipedia.',
      'ウィキメディア財団からの感謝',
      'あなたから世界への贈り物：無料でオープンな百科事典',
      'Tack från Wikimedia Foundation',
    ];
  }
}
