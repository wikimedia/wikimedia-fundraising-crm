<?php
namespace wmf_communication;

use \CsvBatchFile;
use \Engage;
use \Net_SFTP;
use \SimpleXMLElement;
use \ZipArchive;

/**
 * Import mailing records from Silverpop into CiviMail
 */
class SilverpopImporter {

	/**
	 * @var Engage instance of Engage to talk to the Silverpop API
	 */
	protected $engage;
	/**
	 * @var string username of Silverpop account
	 */
	protected $username;
	/**
	 * @var string password of Silverpop account
	 * TODO: uname/pass authentication is deprecated in favor of oauth, but
	 * their lib still only works that way
	 */
	protected $password;
	/**
	 * @var ICiviMailBulkStore instance to talk to the CiviMail db
	 */
	protected $civimailstore;
	/**
	 * @var Net_SFTP instance for connecting to Silverpop's SFTP site.
	 */
	protected $sftp;
	/**
	 * @var ZipArchive instance to unzip downloaded exports
	 */
	protected $zipper;

	const DATE_FORMAT = 'm/d/Y H:i:s';

	function __construct( $options ) {
		$this->engage = $options['engage'];
		$this->username = $options['username'];
		$this->password = $options['password'];
		$this->civimailstore = $options['civimailstore'];
		$this->sftp = $options['sftp'];
		$this->zipper = $options['zipper'];
	}

	function import( $days ) {
		$this->engage->login( $this->username, $this->password );
		$sentMailings = $this->getSentMailings( $days );
		$mailings = array();
		foreach ( $sentMailings->Mailing as $mailing ) {
			$alreadyImported = false;
			try {
				$civiMailing = $this->civimailstore->getMailing( 'Silverpop', $mailing->MailingId );
				// If it exists, check the status to see if we're trying to re-import one that failed
				$alreadyImported = ( $civiMailing->getJobStatus() === 'Complete' );
			} catch ( CiviMailingMissingException $ex ) {
				// If the mailing record is missing, we definitely still need to import it
			}
			if ( $alreadyImported ) {
				continue;
			}
			$mailingId = (string)$mailing->MailingId;
			$mailings[$mailingId] = array(
				'id' => $mailing->MailingId,
				'sent' => $mailing->SentTS,
				'count' => $mailing->NumSent,
				'subject' => $mailing->Subject,
				'body' => $this->screenScrapeMailing( $mailingId ),
				'export' => $this->requestRawExport( $mailing ),
				'imported' => false,
			);
		}
		$tempDir = file_directory_temp();
		$this->downloadExports( $mailings, $tempDir );
		$this->processExports( $mailings, $tempDir );
		return $mailings;
	}

	function makeXmlRequest( $parentElement, $childElements ) {
		$xml = new SimpleXMLElement('<Envelope />');
		$body = $xml->addChild('Body');
		$parent = $body->addChild($parentElement);
		$this->addXmlChildren( $childElements, $parent );
		return $xml;
	}

	/**
	 * @param array $childElements
	 * @param SimpleXMLElement $xml
	 */
	function addXmlChildren( $childElements, $xml ) {
		foreach( $childElements as $nodeName => $value ) {
			if ( is_array ( $value ) ) {
				if ( is_numeric( $nodeName ) ) {
					$this->addXmlChildren( $value, $xml );
				} else {
					$newNode = $xml->addChild( $nodeName );
					$this->addXmlChildren ( $value, $newNode );
				}
			} else {
				$xml->addChild( $nodeName, $value );
			}
		}
	}

	function getSentMailings( $days ) {
		$startDate = gmdate( self::DATE_FORMAT, strtotime( "-$days days" ) );
		$endDate = gmdate( self::DATE_FORMAT );
		$xml = $this->makeXmlRequest(
			'GetSentMailingsForOrg',
			array(
				'DATE_START' => $startDate,
				'DATE_END' => $endDate,
				'EXCLUDE_ZERO_SENT' => '',
			)
		);
		watchdog(
			'silverpop_import',
			"Getting sent mailings for the last $days days",
			array(),
			WATCHDOG_INFO
		);
		$result = $this->engage->execute( $xml );
		if ( !$result || !$result->RESULT ) {
			throw new Exception( "Tried to GetSentMailingsForOrg for the past $days and failed" );
		}
		return $result->RESULT;
	}

	function screenScrapeMailing( $id ) {
		$url = "https://engage4.silverpop.com/mailingsSummary.do?action=displayHtmlBody&mailingId=$id";
		//TODO: Get a web UI session cookie, and maybe load balancer cookies.
		return "Silverpop needs a better API.  View the mailing <a href='$url'>here</a>.";
	}

	function requestRawExport( $mailing ) {
		$childNodes = array(
			'MAILING' => array(
				'MAILING_ID' => $mailing['id'],
			),
			'MOVE_TO_FTP' => '',
			'SENT' => '',
			'SUPPRESSED' => '',
			'HARD_BOUNCES' => '',
			'SOFT_BOUNCES' => '',
			'REPLY_ABUSE' => '',
			'REPLY_COA' => '',
			'MAIL_BLOCKS' => '',
			'MAIL_RESTRICTIONS' => '',
			'EXCLUDE_DELETED' => '',
			'COLUMNS' => array(
				array( 'COLUMN' => 'Mailing ID'),
				array( 'COLUMN' => 'Suppression Reason'),
			)
		);
		$xml = $this->makeXmlRequest(
			'RawRecipientDataExport',
			$childNodes
		);
		watchdog(
			'silverpop_import',
			"Requesting export of mailing with Silverpop ID {$mailing['id']}",
			array(),
			WATCHDOG_INFO
		);
		$result = $this->engage->execute( $xml );
		if ( !$result || !$result->RESULT || !$result->RESULT->MAILING ) {
			throw new Exception( "Failed requesting export for mailing {$mailing['id']}" );
		}
		return $result->RESULT->MAILING;
	}

	function downloadExports( $mailings, $path ) {
		$loggedIn = false;
		$requests = array_map( function( $thing ) { return $thing['export']; }, $mailings );
		while( !empty( $requests ) ) {
			foreach( $requests as $index => $request ) {
				sleep( 2 );
				if ( $this->checkJob( $request->JOB_ID ) ) {
					if ( !$loggedIn ) {
						if ( !$this->sftp->login( $this->username, $this->password ) ) {
							throw new Exception( "Could not SFTP in to $this->sftppath as $this->username." );
						}
						$loggedIn = true;
					}
					watchdog(
						'silverpop_import',
						"Downloading file {$request->FILE_PATH} via SFTP",
						array(),
						WATCHDOG_INFO
					);
					if ( $this->sftp->get( "download/$request->FILE_PATH", "$path/$request->FILE_PATH" ) ) {
						$request->success = true;
					} else {
						$msg = "Failed downloading file {$request->FILE_PATH}: " . $this->sftp->getLastSFTPError();
						watchdog(
							'silverpop_import',
							$msg,
							array(),
							WATCHDOG_WARNING
						);
						$request->success = false;
					}
					unset( $requests[$index] );
				}
			}
		}
	}

	function checkJob( $id ) {
		$xml = $this->makeXmlRequest( 'GetJobStatus', array(
			'JOB_ID' => $id
		) );
		watchdog(
			'silverpop_import',
			"Checking status of export $id",
			array(),
			WATCHDOG_INFO
		);
		$response = $this->engage->execute( $xml );
		return $response && $response->RESULT->JOB_STATUS->__toString() === 'COMPLETE';
	}

	function processExports( $mailings, $path ) {
		foreach ( $mailings as $mailing ) {
			if ( !$mailing['export']->success ) {
				watchdog(
					'silverpop_import',
					"Skipping processing unsuccessful mailing export {$mailing['id']}.",
					array(),
					WATCHDOG_WARNING
				);
				continue;
			}
			$civiMailing = $this->civimailstore->addMailing(
				'Silverpop',
				$mailing['id'],
				$mailing['body'],
				$mailing['subject'],
				0,
				'RUNNING'
			);
			watchdog(
				'silverpop_import',
				"Created CiviMail mailing record {$civiMailing->getMailingName()}",
				array(),
				WATCHDOG_DEBUG
			);
			$filePath = "$path/{$mailing['export']->FILE_PATH}";

			watchdog(
				'silverpop_import',
				"Unzipping downloaded export $filePath",
				array(),
				WATCHDOG_DEBUG
			);

			$this->zipper->open( $filePath );
			$this->zipper->extractTo( $path );

			$csvPath = str_replace('.zip', '.csv', $filePath );

			watchdog(
				'silverpop_import',
				"Opening export $csvPath as CSV",
				array(),
				WATCHDOG_DEBUG
			);
			$export = new CsvBatchFile( $csvPath );
			$events = array();
			while ( $record = $export->read_line() ) {
				$type = $record['Event Type'];
				if ( !array_key_exists( $type, $events ) ) {
					$events[$type] = array();
				}
				$events[$type][] = $record['Email'];
			}
			watchdog(
				'silverpop_import',
				"Importing {$mailing['count']} sent records into CiviMail",
				array(),
				WATCHDOG_DEBUG
			);
			$this->civimailstore->addSentBulk( $civiMailing, $events['Sent'] );
			$mailing['imported'] = true;
		}
	}
}
