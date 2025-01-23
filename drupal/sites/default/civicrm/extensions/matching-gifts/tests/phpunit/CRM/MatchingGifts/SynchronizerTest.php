<?php

use Civi\Api4\Contact;
use Civi\BaseTestClass;

/**
 * @group MatchingGifts
 */
class CRM_MatchingGifts_SynchronizerTest extends BaseTestClass
  implements \Civi\Test\HeadlessInterface {

  /**
   * @var \CRM_MatchingGifts_Synchronizer
   */
  protected $synchronizer;

  /**
   * @var \PHPUnit\Framework\MockObject\MockObject
   */
  protected $provider;

  protected $mockDetails = [
    '12340000' => [
      'matching_gifts_provider_id' => '12340000',
      'name_from_matching_gift_db' => 'Yoyodyne Corporation',
      'matching_gifts_provider_info_url' => 'https://matchinggifts.com/wikimedia_iframe',
      'guide_url' => 'https://example.com/yoyodyne/matchingpolicy.pdf',
      'online_form_url' => 'https://yoyodyne.yourcause.com/auth',
      'minimum_gift_matched_usd' => 25,
      'match_policy_last_updated' => '2019-10-31',
      'subsidiaries' => '["Yoyodyne Aerospace Company","Yoyodyne Mortgage","Yoyodyne Defense Logistics"]',
    ],
    '56780404' => [
      'matching_gifts_provider_id' => '56780404',
      'name_from_matching_gift_db' => 'Advanced Idea Mechanics',
      'matching_gifts_provider_info_url' => 'https://matchinggifts.com/wikimedia_iframe',
      'guide_url' => 'https://example.com/advancedideamechanics/matchingpolicy.pdf',
      'online_form_url' => 'https://advideamech.benevity.com/',
      'minimum_gift_matched_usd' => 25,
      'match_policy_last_updated' => '2018-01-04',
      'subsidiaries' => '["Targo Corporation","International Data and Control","Cadenza Industries","Koenig and Strey","Pacific Vista Laboratories","Omnitech"]',
    ],
    '75751100' => [
      'matching_gifts_provider_id' => '75751100',
      'name_from_matching_gift_db' => 'Aperture Science, Inc.',
      'matching_gifts_provider_info_url' => 'https://matchinggifts.com/wikimedia_iframe',
      'guide_url' => 'https://example.com/aperturescience/matchingpolicy.pdf',
      'online_form_url' => 'https://aperture.benevity.com/',
      'minimum_gift_matched_usd' => 35,
      'match_policy_last_updated' => '2018-08-24',
      'subsidiaries' => '["Aperture Laboratories","Aperture Fixtures","Aperture Enrichment Centers"]',
    ]
  ];

  public function setUp(): void {
    parent::setUp();
    civicrm_initialize();
    $this->provider = $this->getMockBuilder(CRM_MatchingGifts_SsbinfoProvider::class)
      ->setConstructorArgs([['api_key' => 'blah']])
      ->getMock();
    $this->synchronizer = new CRM_MatchingGifts_Synchronizer($this->provider);
  }

  public function tearDown(): void {
    $providerCompanyIdFieldId = CRM_Core_BAO_CustomField::getCustomFieldID(
      'matching_gifts_provider_id', 'matching_gift_policies', TRUE
    );
    foreach ($this->mockDetails as $companyId => $details) {
      $contacts = civicrm_api3('Contact', 'get', [
        $providerCompanyIdFieldId => $companyId,
      ]);
      if ($contacts['count']) {
        foreach ($contacts['values'] as $id => $contact) {
          civicrm_api3('Contact', 'delete', [
            'id' => $id,
            'skip_undelete' => 1,
          ]);
        }
      }
    }
  }
  /**
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   *
   * See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
   *
   * @return \Civi\Test\CiviEnvBuilder
   * @throws \CRM_Extension_Exception_ParseException
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  protected function setUpMockSearch() {
    $this->provider->expects($this->once())
      ->method('getSearchResults')
      ->willReturn([
        '12340000' => [
          'matching_gifts_provider_id' => '12340000',
          'name_from_matching_gift_db' => 'Yoyodyne Corporation',
          'match_policy_last_updated' => '2019-10-31',
        ],
        '56780404' => [
          'matching_gifts_provider_id' => '56780404',
          'name_from_matching_gift_db' => 'Advanced Idea Mechanics',
          'match_policy_last_updated' => '2018-01-04',
        ],
        '75751100' => [
          'matching_gifts_provider_id' => '75751100',
          'name_from_matching_gift_db' => 'Aperture Science, Inc.',
          'match_policy_last_updated' => '2018-01-04',
        ],
    ]);
  }

  protected function setUpMockDetails() {
    $this->provider->expects($this->exactly(3))
      ->method('getPolicyDetails')
      ->willReturnCallback(function ($companyId) {
        return $this->mockDetails[$companyId];
      });
  }

  /**
   * @throws \CRM_Core_Exception
   */
  public function testSyncFromStart() {
    $this->setUpMockSearch();
    $this->setUpMockDetails();
    $contact = Contact::create()->setCheckPermissions(FALSE)->setValues([
      'contact_type' => 'Organization',
      'nick_name' => 'Advanced Idea Mechanics',
      'matching_gift_policies.match_policy_last_updated' => '2017-09-09',
    ])->execute()->first();

    $result = $this->synchronizer->synchronize([
      'matchedCategories' => [
        'educational_services',
        'zoos'
      ],
      'batch' => 10
    ]);
    $this->assertEquals(
      $this->mockDetails,
      $result
    );
    $fieldNames = array_keys($this->mockDetails['12340000']);
    $mappedFieldNames = [];
    foreach($fieldNames as $name) {
      $mappedFieldNames[$name] = CRM_Core_BAO_CustomField::getCustomFieldID(
        $name, 'matching_gift_policies', TRUE
      );
    }
    $companyIdField = $mappedFieldNames['matching_gifts_provider_id'];
    $result = civicrm_api3('Contact', 'get', [
      $companyIdField => ['IN' => array_keys($this->mockDetails)],
      'return' => array_values($mappedFieldNames),
    ])['values'];
    $this->assertArrayHasKey($contact['id'], $result);
    $this->assertCount(3, $result);
    foreach($result as $contactId => $contact) {
      $companyId = $contact[$companyIdField];
      $expected = $this->mockDetails[$companyId];
      foreach ($expected as $expectedFieldName => $expectedFieldValue) {
        $mappedFieldName = $mappedFieldNames[$expectedFieldName];
        if ($expectedFieldName === 'match_policy_last_updated') {
          $expectedFieldValue .= ' 00:00:00';
        }
        $this->assertEquals($expectedFieldValue, $contact[$mappedFieldName]);
      }
    }
  }

}
