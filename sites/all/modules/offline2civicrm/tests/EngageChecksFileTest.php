<?php

/**
 * @group Import
 * @group Offline2Civicrm
 */
class EngageChecksFileTest extends BaseChecksFileTest {

    protected $sourceFileUri = '';
    function setUp() {
        parent::setUp();

        require_once __DIR__ . "/includes/EngageChecksFileProbe.php";
    }

    function testParseRow_Individual() {
        $data = array(
            'Batch' => '1234',
            'Contribution Type' => 'Engage',
            'Total Amount' => '50',
            'Source' => 'USD 50.00',
            'Postmark Date' => '',
            'Received Date' => '4/1/14',
            'Payment Instrument' => 'Check',
            'Check Number' => '2020',
            'Restrictions' => 'Unrestricted - General',
            'Gift Source' => 'Community Gift',
            'Direct Mail Appeal' => 'White Mail',
            'Prefix' => 'Mrs.',
            'First Name' => 'Sub',
            'Last Name' => 'Tell',
            'Suffix' => '',
            'Street Address' => '1000 Markdown Markov',
            'Additional Address 1' => '',
            'Additional Address 2' => '',
            'City' => 'Best St. Louis',
            'State' => 'MA',
            'Postal Code' => '2468',
            'Country' => '',
            'Phone' => '(123) 456-0000',
            'Email' => '',
            'Thank You Letter Date' => '5/1/14',
            'AC Flag' => 'Y',
        );
        $expected_normal = array(
            'check_number' => '2020',
            'city' => 'Best St. Louis',
            'contact_source' => 'check',
            'contact_type' => 'Individual',
            'contribution_source' => 'USD 50.00',
            'contribution_type' => 'engage',
            'country' => 'US',
            'currency' => 'USD',
            'date' => 1396310400,
            'direct_mail_appeal' => 'White Mail',
            'first_name' => 'Sub',
            'gateway' => 'engage',
            'gateway_txn_id' => 'e59ed825ea04516fb2abf1c130d47525',
            'gift_source' => 'Community Gift',
            'gross' => '50',
            'import_batch_number' => '1234',
            'last_name' => 'Tell',
            'name_prefix' => 'Mrs.',
            'payment_method' => 'Check',
            'postal_code' => '02468',
            'raw_contribution_type' => 'Engage',
            'restrictions' => 'Unrestricted - General',
            'state_province' => 'MA',
            'street_address' => '1000 Markdown Markov',
            'thankyou_date' => 1398902400,
        );

        $importer = new EngageChecksFileProbe( "null URI" );
        $output = $importer->_parseRow( $data );

        $this->stripSourceData( $output );
        $this->assertEquals( $expected_normal, $output );
    }

    function testParseRow_Organization() {
        $data = array(
            'Batch' => '1235',
            'Contribution Type' => 'Engage',
            'Total Amount' => '51.23',
            'Source' => 'USD 51.23',
            'Postmark Date' => '',
            'Received Date' => '4/1/14',
            'Payment Instrument' => 'Check',
            'Check Number' => '202000001',
            'Restrictions' => 'Restricted-Foundation',
            'Gift Source' => 'Foundation Gift',
            'Direct Mail Appeal' => 'White Mail',
            'Organization Name' => 'One Pacific Entitlement',
            'Street Address' => '1000 Markdown Markov',
            'Additional Address 1' => '',
            'Additional Address 2' => '',
            'City' => 'Best St. Louis',
            'State' => 'MA',
            'Postal Code' => '123-LAX',
            'Country' => 'FR',
            'Phone' => '+357 (123) 456-0000',
            'Email' => '',
            'Thank You Letter Date' => '5/1/14',
            'AC Flag' => '',
        );
        $expected_normal = array(
            'check_number' => '202000001',
            'city' => 'Best St. Louis',
            'contact_source' => 'check',
            'contact_type' => 'Organization',
            'contribution_source' => 'USD 51.23',
            'contribution_type' => 'engage',
            'country' => 'FR',
            'currency' => 'USD',
            'date' => 1396310400,
            'direct_mail_appeal' => 'White Mail',
            'gateway' => 'engage',
            'gateway_txn_id' => '6dbb8d844c7509076e2a275fb76d0130',
            'gift_source' => 'Foundation Gift',
            'gross' => 51.23,
            'import_batch_number' => '1235',
            'organization_name' => 'One Pacific Entitlement',
            'payment_method' => 'Check',
            'postal_code' => '123-LAX',
            'raw_contribution_type' => 'Engage',
            'restrictions' => 'Restricted-Foundation',
            'state_province' => 'MA',
            'street_address' => '1000 Markdown Markov',
            'thankyou_date' => 1398902400,
        );

        $importer = new EngageChecksFileProbe( "null URI" );
        $output = $importer->_parseRow( $data );

        $this->stripSourceData( $output );
        $this->assertEquals( $expected_normal, $output );
    }

  public function testImporterFormatsPostal() {
    civicrm_initialize();
    $fileUri = $this->setupFile('engage_postal.csv');

    $importer = new EngageChecksFile($fileUri);
    $importer->import();
    $contact = $this->callAPISuccess('Contact', 'get', array('email' => 'rsimpson4@unblog.fr', 'sequential' => 1));
    $this->assertEquals('07065', $contact['values'][0]['postal_code']);
    $this->assertEquals(5, strlen($contact['values'][0]['postal_code']));
  }

  /**
   * Test valid output files are created when an error streak is encountered.
   *
   * An error streak is 10 or more invalid rows in a row.
   */
  public function testImporterErrorStreak() {
    civicrm_initialize();
    $fileUri = $this->setupFile('engage_multiple_errors.csv');

    try {
      $importer = new EngageChecksFile($fileUri);
      $importer->import();
    }
    catch (Exception $e) {
      $this->assertTrue(strpos($e->getMessage(), 'Import aborted due to 10 consecutive errors, last error was at row 12: \'Invalid Name\'') === 0, 'Actual error was ' . $e->getMessage());
      return;
    }
    $this->fail('An exception should have been thrown');
  }

    public function testImporterCreatesOutputFiles() {
      civicrm_initialize();
      $this->sourceFileUri = __DIR__ . '/../tests/data/engage_reduced.csv';
      $fileUri = $this->setupFile('engage_reduced.csv');

      $importer = new EngageChecksFile($fileUri);
      $messages = $importer->import();
      global $user;
      $this->assertEquals(
        array (
          0 => 'Successful import!',
          'Result' => '14 out of 18 rows were imported.',
          'not imported' => '4 not imported rows logged to <a href=\'/import_output/' . substr(str_replace('.csv', '_all_missed.' . $user->uid, $fileUri), 12) ."'> file</a>.",
          'Duplicate' => '1 Duplicate row logged to <a href=\'/import_output/' . substr(str_replace('.csv', '_skipped.' . $user->uid, $fileUri), 12) ."'> file</a>.",
          'Error' => '3 Error rows logged to <a href=\'/import_output/'.  substr(str_replace('.csv', '_errors.' . $user->uid, $fileUri), 12) ."'> file</a>.",
        )
       , $messages);

      $errorsURI = str_replace('.csv', '_errors.' . $user->uid . '.csv', $fileUri);
      $this->assertTrue(file_exists($errorsURI));
      $errors = file($errorsURI);

      // Header row
      $this->assertEquals('Error,Banner,Campaign,Medium,Batch,"Contribution Type","Total Amount",Source,"Postmark Date","Received Date","Payment Instrument","Check Number",Restrictions,"Gift Source","Direct Mail Appeal","Organization Name","Street Address",City,Country,"Postal Code",Email,State,"Thank You Letter Date","AC Flag",Notes,"Do Not Email","Do Not Phone","Do Not Mail","Do Not SMS","Is Opt Out"', trim($errors[0]));
      unset($errors[0]);

      $this->assertEquals(3, count($errors));
      $this->assertEquals('"\'Unrstricted - General\' is not a valid option for field ' . wmf_civicrm_get_custom_field_name('Fund') . '",B15_0601_enlvroskLVROSK_dsk_lg_nag_sd.no-LP.cc,C15_mlWW_mob_lw_FR,sitenotice,10563,Engage,24,"USD 24.00",5/9/2015,5/9/2015,Cash,1,"Unrstricted - General","Corporate Gift","Carl TEST Perry",Roombo,"53 International Circle",Nowe,Poland,,cperry0@salon.com,,12/21/2014,,,,,,,
', $errors[1]);

      $skippedURI = str_replace('.csv', '_skipped.' . $user->uid . '.csv', $fileUri);
      $this->assertTrue(file_exists($skippedURI));
      $skipped = file($skippedURI);
      // 1 + 1 header row
      $this->assertEquals(2, count($skipped));

      $allURI = str_replace('.csv', '_all_missed.' . $user->uid . '.csv', $fileUri);
      $this->assertTrue(file_exists($allURI));
      $all = file($allURI);
      // 1 header row, 1 skipped, 3 errors.
      $this->assertEquals(5, count($all));

    }

  /**
   * Clean up transactions from previous test runs.
   *
   * If you run this several times locally it will fail on duplicate transactions
   * if we don't clean them up first.
   */
  public function purgePreviousData() {
    $this->callAPISuccess('Contribution', 'get', array(
      'api.Contribution.delete' => 1,
      wmf_civicrm_get_custom_field_name('gateway_txn_id') => array('IN' => $this->getGatewayIDs()),
      'api.contact.delete' => array('skip_undelete' => 1),
    ));
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_contact WHERE organization_name = "Jaloo"');
  }

   /**
    * Get the gateway IDS from the source file.
    */
    public function getGatewayIDs() {
      $gatewayIDs = array();
      $data = $this->getParsedData();
      foreach ($data as $record) {
        $gatewayIDs[] = $record['gateway_txn_id'];
      }
      return $gatewayIDs;
    }

   /**
    * Get parsed data from the source file.
    *
    * @return array
    */
    public function getParsedData() {
      $file = fopen($this->sourceFileUri, 'r');
      $result = array();
      $importer = new EngageChecksFileProbe( "null URI" );
      while(($row = fgetcsv( $file, 0, ',', '"', '\\')) !== FALSE) {
        if ($row[0] == 'Banner') {
          // Header row.
          $headers = _load_headers($row);
          continue;
        }
        $data = array_combine(array_keys($headers), array_slice($row, 0, count($headers)));
        $result[] = $importer->_parseRow($data);

      }
      return $result;
    }

  /**
   * Set up the file for import.
   *
   * @param string $inputFileName
   *
   * @return string
   */
  public function setupFile($inputFileName) {
    $this->sourceFileUri = __DIR__ . '/../tests/data/' . $inputFileName;
    $this->purgePreviousData();

    // copy the file to a temp dir so copies are made in the temp dir.
    // This is where it would be in an import.
    $fileUri = tempnam(sys_get_temp_dir(), 'Engage') . '.csv';
    copy($this->sourceFileUri, $fileUri);
    return $fileUri;
  }
}
