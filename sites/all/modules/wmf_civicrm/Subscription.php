<?php
namespace wmf_civicrm;

/**
 * Corresponds to a gateway subscription
 */
class Subscription {
    protected $gateway;

    protected $civicrm_contribution_recur_id;


    function find() {
        if ( $this->civicrm_contribution_recur_id ) {
            $where[] = "civicrm_contribution_recur.id = %{$param_index}";
            $params[$param_index++] = $this->civicrm_contribution_recur_id;
        }
        if ( $this->getGatewaySubscrId() ) {
            // TODO: unify formats
            $p2 = $param_index + 1;
            $where[] = "civicrm_contribution_recur_id.trxn_id in
                (%{$param_index}, %{$p2})";
            $params[$param_index++] = $this->getGatewaySubscrId;
            $params[$param_index++] = $this->getUniqueId();
        }
        $query = "
            SELECT
                civicrm_contribution_recur.*, civicrm_contact.display_name
            FROM civicrm_contribution_recur
            LEFT JOIN civicrm_contribution ON
                civicrm_contribution_recur.id = civicrm_contribution.contribution_recur_id
            LIMIT 1
        ";
    }

    function setInProgress() {
        $result = recurring_globalcollect_get_payment_by_id($id);
        $record = (array) $result;
        
        $working_statuses = array(
            civicrm_api_contribution_status( 'Completed' ),
            civicrm_api_contribution_status( 'Failed' ),
        );
        if ( !in_array( $record['contribution_status_id'], $working_statuses ) ) {
          throw new WmfException( 'INVALID_RECURRING', t( 'The subscription is supposed to be in a completed or failed state before it can be processed.' ), $record );
        }

        $dbs = wmf_civicrm_get_dbs();
        $dbs->push( 'civicrm' );

        $in_progress_id = civicrm_api_contribution_status('In Progress');
        $affected_rows = db_update( 'civicrm_contribution_recur' )
            ->fields( array(
                'contribution_status_id' => $in_progress_id,
            ) )
            ->condition( 'id', $id )
            ->execute();

        $dbs->pop();
        
        if ( !$affected_rows ) {
          throw new WmfException( 'INVALID_RECURRING', t( 'The subscription was not marked as in progress.' ), $record );
        }
    }

    function setSuccessful() {
        $result = recurring_globalcollect_get_payment_by_id($id);
        $record = (array) $result;
        
        // Make sure all of the proper fields are set to sane values.
        _recurring_globalcollect_validate_record_for_update($record);
        
        $next_sched_contribution = wmf_civicrm_get_next_sched_contribution_date_for_month($record);

        $dbs = wmf_civicrm_get_dbs();
        $dbs->push( 'civicrm' );

        $affected_rows = db_update( 'civicrm_contribution_recur' )
            ->fields( array(
                'failure_count' => 0,
                'failure_retry_date' => null,
                'contribution_status_id' => civicrm_api_contribution_status('Completed'),
                'next_sched_contribution' => $next_sched_contribution,
            ) )
            ->expression( 'processor_id', "processor_id + 1" )
            ->condition( 'id', $id )
            ->execute();

        return $affected_rows;
    }
}
