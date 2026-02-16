<?php


namespace Civi\Api4\Action\Redislog;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use League\Csv\Reader;
use League\Csv\Writer;

/**
 * Class Parse.
 *
 * Get the content of an email for the given template text, rendering tokens.
 *
 * @method $this setLimit(int $limit) Set Limit
 * @method int getLimit() Get Limit
 * @method $this setFileName(string $fileName) Set File Name
 * @method string getFileName() Get File Name
 */
class Parse extends AbstractAction {

  /**
   * Limit of entities to process.
   *
   * @var int
   */
  protected $limit;

  /**
   * Name of log file to parse.
   *
   * @var string
   *
   * @required
   */
  protected $fileName;

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   * @throws \League\Csv\CannotInsertRecord
   * @throws \League\Csv\Exception
   */
  public function _run(Result $result) {
    $writer = Writer::createFromPath(dirname($this->getFileName()) . '/redis_log_parsed.csv', 'w+');
    $writer->insertOne(['Timestamp', 'Connection', 'Action', 'Detail', 'TTL', 'Data']);
    $writer->setDelimiter('`');
    $reader = Reader::from($this->getFileName());
    $reader->setDelimiter(' ');

    $patterns = [];
    $setStrings = [];

    foreach ($reader as $line) {
      if ($line[0] === 'OK') {
        // Not part of the output
        continue;
      }
      // line[1] is cruft ("[0")
      unset($line[1]);
      $line = array_values($line);

      $line[0] = date('Y-m-d H:i:s u', $line[0]);
      // line[2] looks like 127.0.0.1:456] - where we want the '456' as it is unique to the connection.
      $line[1] = substr(preg_replace("/^((25[0-5]|(2[0-4]|1\d|[1-9]|)\d)\.?\b){4}:/", '', $line[1]), 0, -1);

      if (empty($patterns[$line[1]])) {
        $patterns[$line[1]] = [];
      }
      $key = $line[2] . '-' . $line[3];
      if (!empty($line[4])) {
        $setStrings[$line[3]] = strlen($line[5]);
      }
      if (empty($patterns[$line[1]][$key])) {
        $patterns[$line[1]][$key] = 0;
      }
      $patterns[$line[1]][$key]++;
      if (!isset($line[4])) {
        $line[4] = '';
      }
      if (!isset($line[5])) {
        $line[5] = '';
      }
      $writer->insertOne($line);
    }

    $greatestHits = [];
    $countPerConnection = [];
    $connectionTypes = [];
    $stringsToLookFor = [
      'cividata-translate-message' => 'job:thank_you',
      'Contribute_Form_Cancel' => 'ui-usage',
      'js-strings' => 'ui-usage',
    ];
    foreach ($patterns as $connectionString => $pattern) {
      $countPerConnection[$connectionString] = $countPerConnection[$connectionString] ?? 0;
      foreach ($pattern as $key => $count) {
        $countPerConnection[$connectionString] +=$count;
        $indexString = $key . '-' . $connectionString;
        if ($count > 1) {
          $greatestHits[$indexString] = $count;
          if (strpos($key, 'SET') === 0) {
            $greatestSets[$indexString] = $count;
          }
        }
      }
    }
    $result[] = [
      'out_file' => dirname($this->getFileName()) . '/redis_log_parsed.csv',
      'number_of_connections' => count($patterns),
      'count_per_connection' => $countPerConnection,
      'duplicate_hits' => $greatestHits,
      'duplicate_sets' => $greatestSets,
      'set_strings' => $setStrings,
    ];

  }

}
