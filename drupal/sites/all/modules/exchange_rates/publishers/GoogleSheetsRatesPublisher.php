<?php

namespace exchange_rates;

/**
 * Publisher class to publish all exchange rates from the last 12 months
 * to a designated google sheets doc via Google Sheets API integration
 *
 * @package exchange_rates
 */
class GoogleSheetsRatesPublisher {

  const GOOGLE_VALUE_INPUT_OPTION = 'USER_ENTERED';

  /**
   * @var string
   */
  public $serviceAccountFilePath;

  /**
   * @var string
   */
  public $spreadsheetId;

  /**
   * @var string
   */
  public $sheetName;

  /**
   * @var \Google_Client
   */
  protected $googleAPIClient;

  /**
   * @param string $serviceAccountFilePath file path to exported JSON service
   *   account bundle
   * @param string $spreadsheetId Id of sheets document (in url of sheet)
   * @param string $sheetName specific sheet/tab to be updated
   */
  public function __construct($serviceAccountFilePath, $spreadsheetId, $sheetName) {
    $this->serviceAccountFilePath = $serviceAccountFilePath;
    $this->spreadsheetId = $spreadsheetId;
    $this->sheetName = $sheetName;
    $this->setupGoogleClient();
  }

  /**
   * Retrieve latest exchange data from exchange_data mysql table, transform
   * data to google sheets friendly format and then publish  data via google
   * sheets API to google sheet document specified.
   *
   * @return bool
   */
  public function publish() {
    $data = $this->getLatestExchangeRates();
    $values = $this->transformExchangeRateDataToSheetFormat($data);

    $service = $this->getGoogleSheetsService();
    $sheetBody = new \Google_Service_Sheets_ValueRange([
      'values' => $values,
    ]);
    $sheetParams = [
      'valueInputOption' => self::GOOGLE_VALUE_INPUT_OPTION,
    ];
    $sheetRange = $this->sheetName . '!A1';

    $result = $service->spreadsheets_values->update(
      $this->spreadsheetId,
      $sheetRange,
      $sheetBody,
      $sheetParams
    );

    printf(
      "%d cells updated." . PHP_EOL,
      $result->getUpdatedCells()
    );

    return TRUE;
  }

  /**
   * Transform exchange rate data to Google sheets format with some array
   * rejiggery.
   *
   * @param $data
   *
   * @return array
   */
  protected function transformExchangeRateDataToSheetFormat($data) {
    $ratesMatrix = [];
    $currencies = [];

    // transform list format $data into arrays of currency rates grouped by date
    foreach ($data as $rate) {
      if (!array_key_exists($rate['date'], $ratesMatrix)) {
        $ratesMatrix[$rate['date']] = [
          $rate['currency'] => $rate['value_in_usd'],
        ];
      }
      else {
        $ratesMatrix[$rate['date']] += [$rate['currency'] => $rate['value_in_usd']];
      }

      // build a master array of all currencies present
      if (!in_array($rate['currency'], $currencies)) {
        $currencies[] = $rate['currency'];
      }
    }


    sort($currencies);

    // iterate over each date array and transform into a spreadsheet line item containing
    // rates for all currencies set on that $date, and leaving any empty currencies
    // as "" to preserve the structure of the spreadsheet
    foreach ($ratesMatrix as $date => $ratesRow) {

      // create line item and add any rates available to currency placeholders
      $lineItemCurrencyPlaceholders = array_fill_keys($currencies, "");
      $lineItem = array_replace($lineItemCurrencyPlaceholders,$ratesMatrix[$date]);

      // add new line item to array with numeric key
      $ratesMatrix[] = array_merge([$date], array_values($lineItem));

      // remove remnant
      unset($ratesMatrix[$date]);
    }

    // merge headers and values
    $spreadsheetHeaders = array_merge(['Date'], $currencies);
    $finalSpreadsheetOutput = array_merge([$spreadsheetHeaders], $ratesMatrix);

    return $finalSpreadsheetOutput;
  }

  /**
   * Wire up the Google API Client instance
   *
   * Uses a locally stored service account JSON key bundle for credentials
   */
  protected function setupGoogleClient() {
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $this->serviceAccountFilePath);
    $this->googleAPIClient = new \Google_Client();
    $this->googleAPIClient->useApplicationDefaultCredentials();
  }

  /**
   * Return an instance of the Google API Sheets Service
   *
   * @return \Google_Service_Sheets
   */
  protected function getGoogleSheetsService() {
    $this->googleAPIClient->addScope(\Google_Service_Sheets::SPREADSHEETS);
    return new \Google_Service_Sheets($this->googleAPIClient);
  }

  /**
   * Pull in all exchange rates in the last 12 months
   *
   * @return mixed
   */
  protected function getLatestExchangeRates() {
    $sql = <<<SQL
SELECT
    currency,
    value_in_usd,
    FROM_UNIXTIME(bank_update, '%Y-%m-%d') AS date
FROM
    {exchange_rates}
WHERE
    from_unixtime(bank_update) >= (CURDATE() - INTERVAL 12 MONTH )
ORDER BY date DESC
SQL;
    $exchangeRates = db_query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    return $exchangeRates;
  }
}