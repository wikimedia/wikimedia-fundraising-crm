<?php

namespace wmf_eoy_receipt;

use wmf_communication\Mailer;
use wmf_communication\Templating;
use wmf_communication\Translation;

class EoySummary
{
    static protected $templates_dir;
    static protected $template_name;
    static protected $option_keys = array(
        'year',
        'test',
        'batch',
        'job_id',
    );

    protected $batch_max = 100;

    function __construct( $options = array() )
    {
        $this->year = variable_get( 'wmf_eoy_target_year', null );
        $this->batch_max = variable_get( 'wmf_eoy_batch_max', 100 );
        $this->test = variable_get( 'wmf_eoy_test_mode', TRUE );

        foreach ( self::$option_keys as $key ) {
            if ( array_key_exists( $key, $options ) ) {
                $this->$key = $options[ $key ];
            }
        }

        $this->from_address = variable_get( 'thank_you_from_address', null );
        $this->from_name = variable_get( 'thank_you_from_name', null );
        if ( !$this->from_address || !$this->from_name ) {
            throw new \Exception( "Must configure a valid return address in the Thank-you module" );
        }

        // FIXME: this is not required on the production configuration.
        // However, it will require code changes if the databases are
        // actually hosted on separate servers.  You will need to specify
        // the database name: 'wmf_civi.' if you are isolating for dev.
        $this->civi_prefix = '';

        self::$templates_dir = __DIR__ . '/templates';
        self::$template_name = 'eoy_thank_you';
    }

    //FIXME rename
    function calculate_year_totals()
    {
        $job_timestamp = date( "YmdHis" );
        $year_start = strtotime( "{$this->year}-01-01 00:00:01" );
        $year_end = strtotime( "{$this->year}-12-31 23:59:59" );

        $select_query = <<<EOS
SELECT
    {$this->job_id} AS job_id,
    COALESCE( billing_email.email, primary_email.email ) AS email,
    contact.first_name,
    contact.preferred_language,
    'queued',
    GROUP_CONCAT( CONCAT(
        DATE_FORMAT( contribution.receive_date, '%%Y-%%m-%%d' ),
        ' ',
        contribution.total_amount,
        ' ',
        contribution.currency
    ) )
FROM {$this->civi_prefix}civicrm_contribution contribution
LEFT JOIN {$this->civi_prefix}civicrm_email billing_email
    ON billing_email.contact_id = contribution.contact_id AND billing_email.is_billing
LEFT JOIN {$this->civi_prefix}civicrm_email primary_email
    ON primary_email.contact_id = contribution.contact_id AND primary_email.is_primary
JOIN {$this->civi_prefix}civicrm_contact contact
    ON contribution.contact_id = contact.id
WHERE
    UNIX_TIMESTAMP( receive_date ) BETWEEN '{$year_start}' AND '{$year_end}'
GROUP BY
    email
EOS;

        db_insert( 'wmf_eoy_receipt_job' )->fields( array(
            'start_time' => $job_timestamp,
            'year' => $this->year,
        ) )->execute();

        $sql = <<<EOS
SELECT job_id FROM {wmf_eoy_receipt_job}
    WHERE start_time = :start
EOS;
        $result = db_query( $sql, array( ':start' => $job_timestamp ) );
        $row = $result->fetch();
        $this->job_id = $row->job_id;

        $sql = <<<EOS
INSERT INTO {wmf_eoy_receipt_donor}
  ( job_id, email, name, preferred_language, status, contributions_rollup )
  {$select_query}
EOS;
        $result = db_query( $sql );

        $num_rows = $result->rowCount();
        watchdog( 'wmf_eoy_receipt',
            t( "Compiled summaries for !num donors giving during !year",
                array(
                    "!num" => $num_rows,
                    "!year" => $this->year,
                )
            )
        );
    }

    function send_letters()
    {
        $mailer = Mailer::getDefault();

        $sql = <<<EOS
SELECT *
FROM {wmf_eoy_receipt_donor}
WHERE
    status = 'queued'
    AND job_id = :id
LIMIT {$this->batch_max}
EOS;
        $result = db_query( $sql, array( ':id' => $this->job_id ) );
        $succeeded = 0;
        $failed = 0;

        foreach ( $result as $row )
        {
            $email = $this->render_letter( $row );

            if ( $this->test ) {
                $email[ 'to_address' ] = variable_get( 'wmf_eoy_test_email', null );
            }

            $success = $mailer->send( $email );

            if ( $success ) {
                $status = 'sent';
                $succeeded += 1;
            } else {
                $status = 'failed';
                $failed += 1;
            }

            db_update( 'wmf_eoy_receipt_donor' )->fields( array(
                'status' => $status,
            ) )->condition( 'email', $row->email )->execute();
        }

        watchdog( 'wmf_eoy_receipt',
            t( "Successfully sent !succeeded messages, failed to send !failed messages.",
                array(
                    "!succeeded" => $succeeded,
                    "!failed" => $failed,
                )
            )
        );
    }

    function render_letter( $row ) {
        $language = Translation::normalize_language_code( $row->preferred_language );
        $subject = Translation::get_translated_message( 'donate_interface-email-subject', $language );
        $contributions = array_map(
            function( $contribution ) {
                $terms = explode( ' ', $contribution );
                return array(
                    'date' => $terms[0],
                    'amount' => round( $terms[1], 2 ),
                    'currency' => $terms[2],
                );
            },
            explode( ',', $row->contributions_rollup )
        );
        $total = array_reduce( $contributions,
            function( $sum, $contribution ) {
                return $sum + $contribution[ 'amount' ];
            },
            0
        );

        $template_params = array(
            'name' => 'name',
            'contributions' => $contributions,
            'total' => $total,
        );
        $template = $this->get_template( $language, $template_params );
        $email = array(
            'from_name' => $this->from_name,
            'from_address' => $this->from_address,
            'to_name' => $row->name,
            'to_address' => $row->email,
            'subject' => $subject,
            'plaintext' => $template->render( 'txt' ),
            'html' => $template->render( 'html' ),
        );

        return $email;
    }

    function get_template( $language, $template_params ) {
        return new Templating(
            self::$templates_dir,
            self::$template_name,
            $language,
            $template_params
        );
    }
}
