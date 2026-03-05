<?php

namespace Civi\Api4\Action\MatchingGiftPolicies;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use CRM_MatchingGifts_ProviderFactory;

/**
 * @method string getLastUpdated()
 * @method $this setLastUpdated(string $lastUpdated)
 * @method array getMatchedCategories()
 * @method $this setMatchedCategories(array $matchedCategories)
 * @method string getProvider()
 * @method $this setProvider(string $provider)
 */
class Fetch extends AbstractAction {

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
    $providerObject = CRM_MatchingGifts_ProviderFactory::getProvider($this->provider);
    $params = [];
    if ($this->lastUpdated !== '') {
      $params['lastUpdated'] = $this->lastUpdated;
    }
    if ($this->matchedCategories !== []) {
      $params['matchedCategories'] = $this->matchedCategories;
    }
    $fetchParams = $params + CRM_MatchingGifts_ProviderFactory::getFetchDefaults(
        $this->provider
    );
    $result[] = $providerObject->fetchMatchingGiftPolicies($fetchParams);
  }
}
