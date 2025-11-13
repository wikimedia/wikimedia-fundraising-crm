<?php

namespace Civi\Api4\MatchingGiftPolicies;

use Civi\Api4\Contact;
use Civi\Api4\MatchingGiftPolicies;
use Civi\BaseTestClass;

/**
 * @group MatchingGifts
 */
class VerifyEmployerFileTest extends BaseTestClass {

  /**
   * Ensure we detect new employer data.
   */
  public function testVerifyEmployerNotification(): void {
    $this->setUpMockResponse([
      $this->getResponseContents('searchResult01.json'),
      $this->getResponseContents('detail01.json'),
      $this->getResponseContents('detail02.json'),
    ]);
    $this->setEmployerDataFilePathToTmp();
    $result = MatchingGiftPolicies::verifyEmployerFile(FALSE)
      ->setLimit(0)
      ->execute()
      ->first()['is_update'];

    $this->assertTrue($result);
  }

  /**
   * If no updates exist, should return false for is_update.
   */
  public function testNewSyncContainsNoUpdatedData(): void {
    // set default employers file path to /tmp/employers.csv
    $this->setEmployerDataFilePathToTmp(FALSE);

    // generate a new employers.csv. this will be our baseline
    $this->setupBaselineTestEmployersFile();

    // queue up Mock API response stack with identical responses used to generate
    // the baseline file above
    $this->setUpMockResponse([
      $this->getResponseContents('searchResult01.json'),
      $this->getResponseContents('detail01.json'),
      $this->getResponseContents('detail02.json'),
    ]);

    // run matching gifts comparison job.
    // compares the latest employer data pulled from the API to the current version.
    // overwrites the current version if updates are present in the new export
    $result = MatchingGiftPolicies::verifyEmployerFile(FALSE)
      ->setLimit(0)
      ->execute()
      ->first();

    // should be false as the API responses should match the baseline data
    $this->assertFalse($result['is_update']);
  }

  /**
   * If updated data is found, should return true for is_update.
   */
  public function testNewSyncContainsUpdatedData(): void {
    // set default employers file path to /tmp/employers.csv
    $this->setEmployerDataFilePathToTmp(FALSE);

    // generate a new employers.csv. this will be our baseline
    $this->setupBaselineTestEmployersFile();

    // queue up Mock API response stack with new data
    $this->setUpMockResponse([
      $this->getResponseContents('searchResult02.json'),
      $this->getResponseContents('detail02.json'),
      $this->getResponseContents('detail03.json'),
    ]);

    // run matching gifts comparison job.
    // compares the latest employer data pulled from the API to the current version.
    // overwrites the current version if updates are present in the new export
    $result = MatchingGiftPolicies::verifyEmployerFile(FALSE)
      ->setLimit(0)
      ->execute()
      ->first();

    // should be true as the API responses should not match the baseline data
    $this->assertTrue($result['is_update']);
  }

  /**
   * Set up the baseline test employers file using mock responses.
   * Performs a sync and export of the matching gift results and writes it to
   * /tmp/employers.csv.
   */
  protected function setupBaselineTestEmployersFile(): void {
    $this->setUpMockResponse([
      $this->getResponseContents('searchResult01.json'),
      $this->getResponseContents('detail01.json'),
      $this->getResponseContents('detail02.json'),
    ]);

    civicrm_api3('MatchingGiftPolicies', 'Sync', [
      'batch' => 0,
    ]);

    civicrm_api3('MatchingGiftPolicies', 'Export', [
      'path' => \Civi::settings()->get('matching_gifts_employer_data_file_path'),
    ]);
  }

  /**
   * Remove any generated temp files after each test.
   */
  protected function cleanUpTmpEmployerFiles(): void {
    $files = array_merge(
      glob(sys_get_temp_dir() . '/employers*.csv'),
      [sys_get_temp_dir() . '/employers.csv.bk']
    );

    foreach ($files as $file) {
      if (is_file($file)) {
        unlink($file);
      }
    }
  }

  /**
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  protected function cleanUpTestMatchingGiftOrgs() : void {
    Contact::delete(FALSE)
      ->addWhere('contact_type', '=', 'Organization')
      ->addWhere('matching_gift_policies.matching_gifts_provider_id',
        'IS NOT NULL')
      ->setUseTrash(FALSE)
      ->execute();
  }

  protected function tearDown(): void {
    parent::tearDown();
    $this->cleanUpTmpEmployerFiles();
    $this->cleanUpTestMatchingGiftOrgs();
  }

}
