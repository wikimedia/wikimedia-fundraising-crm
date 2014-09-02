<?php
namespace wmf_communication;
use \BaseWmfDrupalPhpUnitTestCase;

class SilverpopImporterTest extends BaseWmfDrupalPhpUnitTestCase {
	function testImport() {
		$sftp = $this->getMockBuilder( 'Net_SFTP' )
			->disableOriginalConstructor()
			->setMethods( array( 'login', 'get' ) )
			->getMock();
		$mailStore = $this->getMock( 'CiviMailBulkStore', array( 'getMailing', 'addMailing', 'addSentBulk' ) );
		$zipper = $this->getMock( 'ZipArchive', array( 'open', 'extractTo' ) );
		$mailing = $this->getMock( 'ICiviMailingRecord', array( 'getMailingName' ) );

		$tempDir = file_directory_temp();

		$sftp->expects( $this->atLeastOnce() )
			->method( 'login' )
			->with( 'TestUser', 'TestPass' )
			->will( $this->returnValue( true ) );

		$sftp->expects( $this->once() )
			->method( 'get' )
			->with( 'download/Raw Recipient Data Export Sep 02 2014 18-45-05 PM 1200.zip',
					"$tempDir/Raw Recipient Data Export Sep 02 2014 18-45-05 PM 1200.zip" )
			->will( $this->returnValue( true ) );

		$zipper->expects( $this->once() )
			->method( 'open' )
			->with( "$tempDir/Raw Recipient Data Export Sep 02 2014 18-45-05 PM 1200.zip" )
			->will( $this->returnValue( true ) );

		$zipper->expects( $this->once() )
			->method( 'extractTo' )
			->with( $tempDir )
			->will( $this->returnValue( true ) );

		$mailStore->expects( $this->once() )
			->method( 'getMailing' )
			->with( 'Silverpop', '9876543' )
			->will( $this->ThrowException( new CiviMailingMissingException() ) );

		$mailStore->expects( $this->once() )
			->method( 'addMailing' )
			->with( 'Silverpop', '9876543', $this->anything(), 'Test Subject', 0, 'RUNNING' )
			->will( $this->returnValue( $mailing ) );

		$emails = array();
		$fileContents = "Recipient Id,Recipient Type,Mailing Id,Report Id,Campaign Id,Email,Event Type,Event Timestamp,Body Type,Content Id,Click Name,URL,Conversion Action,Conversion Detail,Conversion Amount,Suppression Reason,,\n";
		for( $i = 0; $i < 10; $i++ ) {
			$email = "test.user.$i@example.com";
			$emails[] = $email;
			$fileContents .= mt_rand();
			$fileContents .= ',Normal,9876543,503612902,"",';
			$fileContents .= $email;
			$fileContents .= ',Sent,08/29/2014 12:00:08,"","","","","","","","","",""';
			$fileContents .= "\n";
		}
		//add a suppressed record to the end to see that we don't insert it along with the sent
		$fileContents .= '105817151078,Normal,9876543,503612902,"",test.user.99@example.com,Suppressed,08/29/2014 12:00:08,"","","","","","","",Organization Suppression List,"",""' . "\n";
		// Writing a real file since we're not mocking CsvBatchFile
		file_put_contents( "$tempDir/Raw Recipient Data Export Sep 02 2014 18-45-05 PM 1200.csv", $fileContents );

		$mailStore->expects( $this->once() )
			->method( 'addSentBulk' )
			->with( $mailing, $emails );

		$options = array(
			'engage' => new FakeEngage(),
			'username' => 'TestUser',
			'password' => 'TestPass',
			'sftp' => $sftp,
			'civimailstore' => $mailStore,
			'zipper' => $zipper,
		);

		$silverpopImporter = new SilverpopImporter( $options );

		$silverpopImporter->import( 1 );

		//TODO: assert some things about $engage->executeArgs;
	}
}

class FakeEngage {
	public $executeResponses = array(
		'GetSentMailingsForOrg' => '<Mailing>
<MailingId>9876543</MailingId>
<ReportId>135791113</ReportId>
<ScheduledTS>2014-09-02 13:24:23.0</ScheduledTS>
<MailingName><![CDATA[Test Mailing]]></MailingName>
<ListName><![CDATA[Test List]]></ListName>
<ListId>1234567</ListId>
<UserName>Mailing Sender</UserName>
<SentTS>2014-09-02 13:25:12.0</SentTS>
<NumSent>3</NumSent>
<Subject><![CDATA[Test Subject]]></Subject>
<Visibility>Shared</Visibility>
</Mailing>',
		'RawRecipientDataExport' => '<MAILING>
<JOB_ID>77665544</JOB_ID>
<FILE_PATH>Raw Recipient Data Export Sep 02 2014 18-45-05 PM 1200.zip</FILE_PATH>
</MAILING>',
		'GetJobStatus' => '<JOB_ID>77665544</JOB_ID>
<JOB_STATUS>COMPLETE</JOB_STATUS>
<JOB_DESCRIPTION>Export raw recipient data.</JOB_DESCRIPTION>
<PARAMETERS>
</PARAMETERS>'
	 );

	public function login() {
		return true;
	}

	public $executeArgs = array();
	/**
	 * @param SimpleXMLElement $simplexml
	 */
	public function execute( $simplexml ) {
		$kids = $simplexml->Body->children();
		$nodeName = $kids[0]->getName();
		$this->executeArgs[$nodeName] = $simplexml;
		return simplexml_load_string("<Body>
<RESULT>
<SUCCESS>TRUE</SUCCESS>
" . $this->executeResponses[$nodeName] . "
</RESULT>
</Body>
");
	}
}
