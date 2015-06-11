<?php

/**
 * CSV batch format for manually-keyed donation checks
 *
 * FIXME: This currently includes stuff specific to Wikimedia Foundation fundraising.
 */
abstract class ChecksFile {
    protected $numSkippedRows = 0;

    /**
     * @param string $file_uri path to the file
     */
    function __construct( $file_uri ) {
        $this->file_uri = $file_uri;
    }

    /**
     * Read checks from a file and save to the database.
     */
    function import() {
        ChecksImportLog::record( "Beginning import of checks file {$this->file_uri}..." );
        //TODO: $db->begin();

        ini_set( 'auto_detect_line_endings', true );
        if( ( $file = fopen( $this->file_uri, 'r' )) === FALSE ){
            throw new WmfException( 'FILE_NOT_FOUND', 'Import checks: Could not open file for reading: ' . $this->file_uri );
        }

        if ( $this->numSkippedRows ) {
            foreach ( range( 1, $this->numSkippedRows ) as $i ) {
                fgets( $file );
            }
        }

        $headers = _load_headers( fgetcsv( $file, 0, ',', '"', '\\') );

        $failed = array();
        foreach ( $this->getRequiredColumns() as $name ) {
            if ( !array_key_exists( $name, $headers ) ) {
                $failed[] = $name;
            }
        }
        if ( $failed ) {
            throw new WmfException( 'INVALID_FILE_FORMAT', "This file is missing column headers: " . implode( ", ", $failed ) );
        }

        $num_successful = 0;
        $num_duplicates = 0;
        $this->row_index = -1 + $this->numSkippedRows;

        while( ( $row = fgetcsv( $file, 0, ',', '"', '\\')) !== FALSE) {
            $this->row_index++;

            // Zip headers and row into a dict
            $data = array_combine( array_keys( $headers ), array_slice( $row, 0, count( $headers ) ) );

            // Strip whitespaces
            foreach ( $data as $key => &$value ) {
                $value = trim( $value );
            }

            try {
                $msg = $this->parseRow( $data );

                // check to see if we have already processed this check
                if ( $existing = wmf_civicrm_get_contributions_from_gateway_id( $msg['gateway'], $msg['gateway_txn_id'] ) ){
                    // if so, move on
                    watchdog( 'offline2civicrm', 'Contribution matches existing contribution (id: @id), skipping it.', array( '@id' => $existing[0]['id'] ), WATCHDOG_INFO );
                    $num_duplicates++;
                    continue;
                }

                // tha business.
                $contribution = wmf_civicrm_contribution_message_import( $msg );
                $this->mungeContribution( $contribution );

                watchdog( 'offline2civicrm',
                    'Import checks: Contribution imported successfully (@id): !msg', array(
                        '@id' => $contribution['id'],
                        '!msg' => print_r( $msg, true ),
                    ), WATCHDOG_INFO
                );
                $num_successful++;
            } catch ( EmptyRowException $ex ) {
                continue;
            } catch ( WmfException $ex ) {
                $rowNum = $this->row_index + 2;
                $errorMsg = "Import aborted due to error at row {$rowNum}: {$ex->getMessage()}, after {$num_successful} records were stored successfully and {$num_duplicates} duplicates encountered.";
                throw new Exception($errorMsg);
            }
        }

        $message = t( "Checks import complete. @successful imported, not including @duplicates duplicates.", array( '@successful' => $num_successful, '@duplicates' => $num_duplicates ) );
        ChecksImportLog::record( $message );
        watchdog( 'offline2civicrm', $message, array(), WATCHDOG_INFO );
    }

    /**
     * Read a row and transform into normalized queue message form
     *
     * @param array $row native format for this upload file, usually a dict
     *
     * @return array queue message format
     */
    protected function parseRow( $data ) {
        $msg = array();

        foreach ( $this->getFieldMapping() as $header => $normal ) {
            if ( !empty( $data[$header] ) ) {
                $msg[$normal] = $data[$header];
            }
        }

        if ( !$msg ) {
            throw new EmptyRowException();
        }

        foreach ( $this->getDatetimeFields() as $field ) {
            if ( !empty( $msg[$field] ) ) {
                $msg[$field] = wmf_common_date_parse_string( $msg[$field] );
            }
        }

        $this->setDefaults( $msg );

        $this->mungeMessage( $msg );

        $failed = array();
        foreach ( $this->getRequiredData() as $key ) {
            if ( !array_key_exists( $key, $msg ) or empty( $msg[$key] ) ) {
                $failed[] = $key;
            }
        }
        if ( $failed ) {
            throw new WmfException( 'CIVI_REQ_FIELD', t( "Missing required fields @keys during check import", array( "@keys" => implode( ", ", $failed ) ) ) );
        }

        wmf_common_set_message_source( $msg, 'direct', 'Offline importer: ' . get_class( $this ) );

        return $msg;
    }

    protected function setDefaults( &$msg ) {
        foreach ( $this->getDefaultValues() as $key => $defaultValue ) {
            if ( empty( $msg[$key] ) ) {
                $msg[$key] = $defaultValue;
            }
        }
    }

    /**
     * Do any final transformation on a normalized and default-laden queue
     * message.  Overrides are specific to each upload source.
     */
    protected function mungeMessage( &$msg ) {
        if ( isset( $msg['raw_contribution_type'] ) ) {
            $contype = $msg['raw_contribution_type'];
            switch ( $contype ) {
                case "Merkle":
                    $msg['gateway'] = "merkle";
                    break;

                case "Engage":
                case "Engage Direct Mail":
                    $msg['gateway'] = "engage";
                    break;

                case "Cash":
                    $msg['contribution_type'] = "cash";
                    break;

                default:
                    throw new WmfException( 'INVALID_MESSAGE', "Contribution Type '$contype' is unknown whilst importing checks!" );
            }
        }

        if ( !empty( $msg['organization_name'] ) ) {
            $msg['contact_type'] = "Organization";
        }

        $msg['gross'] = trim( $msg['gross'], '$' );

        if ( isset( $msg['contribution_source'] ) ) {
            // Check that the message amounts match
            list($currency, $source_amount) = explode( ' ', $msg['contribution_source'] );

            if ( abs( $source_amount - $msg['gross'] ) > .01 ) {
                $pretty_msg = json_encode( $msg );
                watchdog( 'offline2civicrm', "Amount mismatch in row: " . $pretty_msg, NULL, WATCHDOG_ERROR );
                throw new WmfException( 'INVALID_MESSAGE', "Amount mismatch during checks import" );
            }

            $msg['currency'] = $currency;
        }

        // left-pad the zipcode
        if ( $msg['country'] === 'US' && !empty( $msg['postal_code'] ) ) {
            if ( preg_match( '/^(\d{1,4})(-\d+)?$/', $msg['postal_code'], $matches ) ) {
                $msg['postal_code'] = str_pad( $matches[1], 5, "0", STR_PAD_LEFT );
                if ( !empty( $matches[2] ) ) {
                    $msg['postal_code'] .= $matches[2];
                }
            }
        }

        // Generate a transaction ID so that we don't import the same rows multiple times
        if ( empty( $msg['gateway_txn_id'] ) ) {
            if ( $msg['contact_type'] === 'Individual' ) {
                $name_salt = $msg['first_name'] . $msg['last_name'];
            } else {
                $name_salt = $msg['organization_name'];
            }

            if ( !empty( $msg['check_number'] ) ) {
                $msg['gateway_txn_id'] = md5( $msg['check_number'] . $name_salt );
            } else {
                $msg['gateway_txn_id'] = md5( $msg['date'] . $name_salt . $this->row_index );
            }
        }
    }

    /**
     * Do fancy stuff with the contribution we just created
     *
     * FIXME: We need to wrap each loop iteration in a transaction to
     * make this safe.  Otherwise we can easily die before adding the
     * second message, and skip it when resuming the import.
     */
    protected function mungeContribution( $contribution ) {
    }

    protected function getDefaultValues() {
        return array(
            'contact_source' => 'check',
            'contact_type' => 'Individual',
            'country' => 'US',
        );
    }

    /**
     * Return column mappings
     *
     * @return array of {spreadsheet column title} => {normalized field name}
     */
    protected function getFieldMapping() {
        return array(
            'Additional Address 1' => 'supplemental_address_1',
            'Additional Address 2' => 'supplemental_address_2',
            'Batch' => 'import_batch_number',
            'Check Number' => 'check_number',
            'City' => 'city',
            'Contribution Type' => 'raw_contribution_type',
            'Country' => 'country',
            'Direct Mail Appeal' => 'direct_mail_appeal',
            'Do Not Email' => 'do_not_email',
            'Do Not Mail' => 'do_not_mail',
            'Do Not Phone' => 'do_not_phone',
            'Do Not SMS' => 'do_not_sms',
            'Do Not Solicit' => 'do_not_solicit',
            'Email' => 'email',
            'First Name' => 'first_name',
            'Gift Source' => 'gift_source',
            'Is Opt Out' => 'is_opt_out',
            'Last Name' => 'last_name',
            'Letter Code' => 'letter_code',
            'Middle Name' => 'middle_name',
            'No Thank You' => 'no_thank_you',
            'Notes' => 'notes',
            'Organization Name' => 'organization_name',
            'Original Amount' => 'gross',
            'Original Currency' => 'currency',
            'Payment Instrument' => 'payment_method',
            'Postal Code' => 'postal_code',
            'Postmark Date' => 'postmark_date',
            'Received Date' => 'date',
            'Restrictions' => 'restrictions',
            'Source' => 'contribution_source',
            'State' => 'state_province',
            'Street Address' => 'street_address',
            'Thank You Letter Date' => 'thankyou_date',
            'Total Amount' => 'gross',
        );
    }

    /**
     * Date fields which must be converted to unix timestamps
     *
     * @return array of field names
     */
    protected function getDatetimeFields() {
        return array(
            'date',
            'thankyou_date',
            'postmark_date',
        );
    }

    /**
     * Columns which must exist in the spreadsheet
     *
     * This is just a "schema" check.  We don't require that the fields contain data.
     *
     * @return array of column header titles
     */
    abstract protected function getRequiredColumns();

    /**
     * Fields that must not be empty in the normalized message
     *
     * @return array of normalized message field names
     */
    abstract protected function getRequiredData();
}
