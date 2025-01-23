<?php

namespace Civi\Api4\MatchingGift;

use Civi\Api4\MatchingGift;
use Civi\BaseTestClass;

/**
 * @group MatchingGifts
 */
class VerifyEmployerFileTest extends BaseTestClass {

  /**
   * @throws \CRM_Core_Exception
   */
  public function testVerifyEmployerNotification(): void {
    $this->setUpMockResponse([
      $this->getResponseContents('searchResult01.json'),
      $this->getResponseContents('detail01.json'),
      $this->getResponseContents('detail02.json'),
    ]);
    $this->setDataFilePath();
    $result = MatchingGift::verifyEmployerFile(FALSE)
      ->setLimit(0)
      ->execute()->first()['is_update'];
    $this->assertTrue($result);
  }

}
