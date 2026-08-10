<?php
namespace Civi\Api4\Action;

use GuzzleHttp\Client;

/**
 * Provides a Guzzle client setter/getter that is not a real api4 param.
 *
 * The property is prefixed with an underscore so it's excluded from
 * AbstractAction::getParamInfo() - core's ValidateFieldsSubscriber
 * rejects objects as API4 params.
 */
trait HasClientTrait {

  protected ?Client $_client = NULL;

  public function setClient(?Client $client): self {
    $this->_client = $client;
    return $this;
  }

  public function getClient(): ?Client {
    return $this->_client;
  }

}
