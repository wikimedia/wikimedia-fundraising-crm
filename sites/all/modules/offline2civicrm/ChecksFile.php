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

        $num_ignored = 0;
        $num_successful = 0;
        $num_duplicates = 0;
        $this->row_index = -1 + $this->numSkippedRows;

        while( ( $row = fgetcsv( $file, 0, ',', '"', '\\')) !== FALSE) {
            // Reset the PHP timeout for each row.
            set_time_limit( 10 );

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
                    $skipped = $this->handleDuplicate( $existing );
                    if ( $skipped ) {
                        $num_duplicates++;
                    } else {
                        $num_successful++;
                    }
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
            } catch ( IgnoredRowException $ex ) {
                $num_ignored++;
                continue;
            } catch ( WmfException $ex ) {
                $rowNum = $this->row_index + 2;
                $errorMsg = "Import aborted due to error at row {$rowNum}: {$ex->getMessage()}, after {$num_successful} records were stored successfully, {$num_ignored} were ignored, and {$num_duplicates} duplicates encountered.";
                throw new Exception($errorMsg);
            }
        }

       // Unset time limit.
       set_time_limit( 0 );

        $message = t( "Checks import complete. @successful imported, @ignored ignored, not including @duplicates duplicates.", array( '@successful' => $num_successful, '@ignored' => $num_ignored, '@duplicates' => $num_duplicates ) );
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

    protected function handleDuplicate ( $duplicate ) {
        watchdog( 'offline2civicrm', 'Contribution matches existing contribution (id: @id), skipping it.', array( '@id' => $duplicate[0]['id'] ), WATCHDOG_INFO );
        return true; // true means this was a duplicate and i skipped it
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
                    $msg['contribution_type'] = "engage";
                    break;

                case "Cash":
                    $msg['contribution_type'] = "cash";
                    break;

                default:
                    $msg['contribution_type'] = $msg['raw_contribution_type'];
            }
        }

        if ( isset( $msg['organization_name'] ) ) {
            $msg['contact_type'] = "Organization";
        } else {
            // If this is not an Organization contact, freak out if Name or Title are filled.
            if ( !empty( $msg['org_contact_name'] )
                || !empty( $msg['org_contact_title'] )
            ) {
                throw new WmfException( 'INVALID_MESSAGE', "Don't give a Name or Title unless this is an Organization contact." );
            }
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

        // Expand soft credit short names.
        if ( !empty( $msg['soft_credit_to'] ) ) {
            $nickname_mapping = array(
                'Fidelity' => 'Fidelity Charitable Gift Fund',
                'Vanguard' => 'Vanguard Charitable Endowment Program',
                'Schwab' => 'Schwab Charitable Fund',
            );
            if ( array_key_exists( $msg['soft_credit_to'], $nickname_mapping ) ) {
                $msg['soft_credit_to'] = $nickname_mapping[$msg['soft_credit_to']];
            }
        }

        if ( empty( $msg['gateway'] ) ) {
            $msg['gateway'] = 'generic_import';
        }

        foreach ( $this->getDatetimeFields() as $field ) {
            if ( !empty( $msg[$field] ) && !is_numeric( $msg[$field] ) ) {
                $msg[$field] = wmf_common_date_parse_string( $msg[$field] );
            }
        }

        // Allow yes or true as inputs for opt-out fields
        $optOutFields = array(
            'do_not_email',
            'do_not_mail',
            'do_not_phone',
            'do_not_sms',
            'do_not_solicit',
            'is_opt_out',
            );

        $trueValues = array(
            'yes',
            'y',
            'true',
            't',
            '1',
        );

        foreach( $optOutFields as $field ) {
            if ( isset( $msg[$field] ) ) {
                if ( in_array( strtolower( $msg[$field] ), $trueValues ) ) {
                    $msg[$field] = 1;
                }
                else {
                    $msg[$field] = 0;
                }
            }
        }

    }

    /**
     * Do fancy stuff with the contribution we just created
     *
     * FIXME: We need to wrap each loop iteration in a transaction to
     * make this safe.  Otherwise we can easily die before adding the
     * second message, and skip it when resuming the import.
     *
     * @param array $contribution
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
            'Batch' => 'import_batch_number', # deprecated, use External Batch Number instead.
            'Banner' => 'utm_source',
            'Batch' => 'import_batch_number',
            'Campaign' => 'utm_campaign',
            'Check Number' => 'check_number',
            'City' => 'city',
            'Contribution Type' => 'raw_contribution_type',
            'Country' => 'country',
            'Description of Stock' => 'stock_description',
            'Direct Mail Appeal' => 'direct_mail_appeal',
            'Do Not Email' => 'do_not_email',
            'Do Not Mail' => 'do_not_mail',
            'Do Not Phone' => 'do_not_phone',
            'Do Not SMS' => 'do_not_sms',
            'Do Not Solicit' => 'do_not_solicit',
            'Email' => 'email',
            'External Batch Number' => 'import_batch_number',
            'First Name' => 'first_name',
            'Gift Source' => 'gift_source',
            'Groups' => 'contact_groups',
            'Is Opt Out' => 'is_opt_out',
            'Last Name' => 'last_name',
            'Letter Code' => 'letter_code',
            'Medium' => 'utm_medium',
            'Middle Name' => 'middle_name',
            'Name' => 'org_contact_name',
            'No Thank You' => 'no_thank_you',
            'Notes' => 'notes',
            'Organization Name' => 'organization_name',
            'Original Amount' => 'gross',
            'Original Currency' => 'currency',
            'Payment Instrument' => 'payment_method',
            'Postal Code' => 'postal_code',
            'Postmark Date' => 'postmark_date',
            'Prefix' => 'name_prefix',
            'Received Date' => 'date',
            'Relationship Type' => 'relationship_type',
            'Restrictions' => 'restrictions',
            'Soft Credit To' => 'soft_credit_to',
            'Source' => 'contribution_source',
            'State' => 'state_province',
            'Street Address' => 'street_address',
            'Suffix' => 'name_suffix',
            'Tags' => 'contact_tags',
            'Target Contact ID' => 'relationship_target_contact_id',
            'Thank You Letter Date' => 'thankyou_date',
            'Title' => 'org_contact_title',
            'Total Amount' => 'gross', # deprecated, use Original Amount
            'Transaction ID' => 'gateway_txn_id',
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
    protected function getRequiredData() {
		return array(
			'currency',
			'date',
			'gross',
		);
	}
}
