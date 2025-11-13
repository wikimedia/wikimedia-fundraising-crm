<?php

namespace Civi\Api4\Action\MatchingGiftPolicies;

use Civi\Api4\Contact;
use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use League\Csv\Writer;

/**
 * @method string getPath()
 * @method $this setPath(string $path)
 */
class Export extends AbstractAction {

  /**
   * @required
   * @var string
   */
  protected string $path;

  /**
   * @throws \CRM_Core_Exception
   */
  public function _run(Result $result): void {
    if (
      (file_exists($this->path) && !is_writable($this->path)) ||
      !is_writable(dirname($this->path))
    ) {
      throw new \CRM_Core_Exception("Output path $this->path is not writeable.");
    }

    $contacts = Contact::get(FALSE)
      ->addSelect('matching_gift_policies.name_from_matching_gift_db', 'matching_gift_policies.subsidiaries')
      ->addWhere('contact_type', '=', 'Organization')
      ->addWhere('matching_gift_policies.name_from_matching_gift_db', 'IS NOT NULL')
      ->addWhere('matching_gift_policies.suppress_from_employer_field', '=', FALSE)
      ->execute();

    $writer = Writer::createFromPath($this->path, 'w');
    $rowsExported = 0;
    foreach ($contacts as $contact) {
      $parentCompanyName = trim($contact['matching_gift_policies.name_from_matching_gift_db']);
      $writer->insertOne([$contact['id'], $parentCompanyName]);
      $rowsExported++;
      $subsidiaries = json_decode($contact['matching_gift_policies.subsidiaries']);
      foreach ($subsidiaries as $subsidiary) {
        $trimmedSub = trim($subsidiary);
        // Skip if the subsidiary name is basically equal to the parent co name.
        if (
          $trimmedSub === $parentCompanyName ||
          $trimmedSub === 'The ' . $parentCompanyName ||
          'The ' . $trimmedSub === $parentCompanyName
        ) {
          continue;
        }
        $writer->insertOne([$contact['id'], $trimmedSub]);
        $rowsExported++;
      }
    }
    $result[] = [
      'companies' => $contacts->count(),
      'rows' => $rowsExported
    ];
  }
}
