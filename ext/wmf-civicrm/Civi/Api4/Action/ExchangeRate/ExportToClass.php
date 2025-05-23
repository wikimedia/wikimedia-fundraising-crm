<?php

namespace Civi\Api4\Action\ExchangeRate;

use Civi\Api4\ExchangeRate;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;

/**
 * Writes CurrencyRates.php
 *
 * @method setOutputDirectory(string $outputDirectory)
 */
class ExportToClass extends AbstractAction {

  const TEMPLATE_PATH = 'templates/CRM/Wmf/CurrencyRates.php.tpl';

  /**
   * @var string Directory to output a file named CurrencyRates.php
   */
  protected string $outputDirectory = '/tmp/';

  public function _run( Result $result ) {
    $smarty = \CRM_Core_Smarty::singleton();
    $latestRates = ExchangeRate::getLatest(FALSE)->execute();
    $invertedRates = [];
    foreach ($latestRates as $rate) {
      $invertedRates[] = [
        'currency' => $rate['currency'],
        'unitsInOneDollar' => $this->invertAndRound((float)$rate['valueInUSD'])
      ];
    }
    $smarty->assign('templatePath', self::TEMPLATE_PATH);
    $date = new \DateTime('@' . \Civi::settings()->get('exchange_rates_last_update_timestamp'));
    $smarty->assign('lastUpdated', $date->format('Y-m-d'));
    $smarty->assign('rates', $invertedRates);
    file_put_contents(
      $this->outputDirectory . DIRECTORY_SEPARATOR . 'CurrencyRates.php',
      $smarty->fetch(__DIR__ . '/../../../../' . self::TEMPLATE_PATH)
    );
  }

  private function invertAndRound(float $valueInUsd) {
    if ($valueInUsd === 0) {
      return $valueInUsd;
    }
    $unitsInOneDollar = 1.0 / $valueInUsd;
    if ($unitsInOneDollar > 10) {
      return round($unitsInOneDollar);
    }
    elseif ($unitsInOneDollar > 1) {
      return round($unitsInOneDollar, 2);
    }
    return $unitsInOneDollar;
  }
}
