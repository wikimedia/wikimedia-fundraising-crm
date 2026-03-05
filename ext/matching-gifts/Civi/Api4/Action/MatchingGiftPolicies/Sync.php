<?php

namespace Civi\Api4\Action\MatchingGiftPolicies;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_MatchingGifts_ProviderFactory;
use CRM_MatchingGifts_Synchronizer;

/**
 * @method string getBatch()
 * @method $this setBatch(int $batch)
 * @method string getLastUpdated()
 * @method $this setLastUpdated(string $lastUpdated)
 * @method array getMatchedCategories()
 * @method $this setMatchedCategories(array $matchedCategories)
 * @method string getProvider()
 * @method $this setProvider(string $provider)
 */
class Sync extends AbstractAction {

  /**
   * How many records to synchronize each run
   * @var int
   */
  protected int $batch = 250;

  /**
   * Check for updates after this date
   * @var string
   */
  protected string $lastUpdated = '';

  /**
   * Categories of charities that the company matches
   * @var array
   */
  protected array $matchedCategories = [];

  /**
   * @var string
   */
  protected string $provider = 'ssbinfo';

  public function _run(Result $result): void {
    $params = [
      'batch' => $this->batch,
    ];
    if ($this->lastUpdated !== '') {
      $params['lastUpdated'] = $this->lastUpdated;
    }
    if ($this->matchedCategories !== []) {
      $params['matchedCategories'] = $this->matchedCategories;
    }
    $syncParams = $params + CRM_MatchingGifts_ProviderFactory::getFetchDefaults(
        $this->provider
    );
    $providerObject = CRM_MatchingGifts_ProviderFactory::getProvider($this->provider);
    $synchronizer = new CRM_MatchingGifts_Synchronizer($providerObject);
    $result[] = $synchronizer->synchronize($syncParams);
  }
}
