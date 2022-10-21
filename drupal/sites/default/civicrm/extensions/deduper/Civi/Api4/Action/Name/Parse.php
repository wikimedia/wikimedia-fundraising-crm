<?php


namespace Civi\Api4\Action\Name;

use Civi;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use TheIconic\NameParser\Parser;

/**
 * Class Parse.
 *
 * Parse a name into component parts.
 *
 * @method $this setNames(array $names) Set names to parse.
 * @method array getNames() Get names to parse.
 */
class Parse extends AbstractAction {

  /**
   * Names to parse.
   *
   * @var array
   */
  protected $names = [];

  /**
   * @inheritDoc
   *
   * @param \Civi\Api4\Generic\Result $result
   *
   */
  public function _run(Result $result): void {
    foreach ($this->getNames() as $name) {
      $splitNames = explode(' & ', $name);
      $doubleSplitNames = [];
      foreach ($splitNames as $splitName) {
        $extraSplit = explode(' and ', $splitName);
        foreach ($extraSplit as $toUse) {
          $doubleSplitNames[] = trim($toUse);
        }
      }
      $result[$name] = $this->parseName($doubleSplitNames[0]);
      if (!empty($doubleSplitNames[1])) {
        $result[$name]['Partner.Partner'] = $doubleSplitNames[1];
        $sharedLastName = $this->parseName($doubleSplitNames[1])['last_name'];
        if ($sharedLastName) {
          if (empty($result[$name]['first_name']) && !empty($result[$name]['last_name'])) {
            // We seem to hit this for 'Mr. Andrew and Mrs Sally Smith'
            $result[$name]['first_name'] = $result[$name]['last_name'];
            $result[$name]['last_name'] = $sharedLastName;
          }
          elseif (empty($result[$name]['last_name'])) {
            // We seem to hit this for 'Andrew and Sally Smith'
            $result[$name]['last_name'] = $sharedLastName;
          }
        }
      }
    }
  }

  /**
   * Parse the name into component parts.
   *
   * @param string $name
   *
   * @return array
   */
  protected function parseName(string $name): array {
    // Detect multibyte initials, which currently break TheIconic parser
    // Delete this when fix is merged upstream: https://github.com/theiconic/name-parser/pull/39
    if (mb_ereg_match('.*\b[^\x00-\x7F]\b',$name)) {
      $parts = explode(' ', $name, 2);
      return [
        'prefix_id:label' => '',
        'first_name' => $parts[0],
        'last_name' => $parts[1] ?? '',
        'middle_name' => '',
        'nick_name' => '',
        'suffix_id:label' => ''
      ];
    }

    $parser = new Parser();
    $nameParser = $parser->parse($name);
    return [
      'prefix_id:label' => $nameParser->getSalutation(),
      'first_name' => $nameParser->getFirstname(),
      'last_name' => $nameParser->getLastname(),
      'middle_name' => strlen($nameParser->getMiddlename()) ? $nameParser->getMiddlename() : $nameParser->getInitials(),
      'nick_name' => $nameParser->getNickName(),
      'suffix_id:label' => $nameParser->getSuffix(),
    ];
  }

}
