<?php

use Civi\Api4\ExchangeRate;
use CRM_ExchangeRates_ExtensionUtil as E;

class CRM_ExchangeRates_Page_LatestRates extends CRM_Core_Page {

  public function run() {
    CRM_Utils_System::setTitle(E::ts('Latest Exchange Rates'));

    $latestRates = ExchangeRate::getLatest(FALSE)->execute();
    $this->assign('rates', $latestRates);
    $updatedTo = \Civi::settings()->get('exchange_rates_last_update_timestamp');
    $this->assign('lastUpdated', $updatedTo ? date('Y-m-d', $updatedTo) : 'never');

    parent::run();
  }

}
