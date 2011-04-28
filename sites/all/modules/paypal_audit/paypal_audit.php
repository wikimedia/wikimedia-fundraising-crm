<?php 
/**
 * A class to facilitate interaction with Paypal Audit scripts
 * @author arthur
 */
class Paypal_Audit {
  
  /**
   * Run custom reports from PayPal audit scripts
   * @param string $start_date
   * @param string $end_date
   * @param array $options
   */
  public function getCustomReport( $start_date, $end_date, $options=array() ) {
    require_once( variable_get( 'paypal_audit_dir', CONTRIBUTION_AUDIT_PAYFLOW_AUDIT_DIR ) . "PayflowReportTypes.php");
    
    $time_bounds = array( $this->fixTime( $start_date ) . " 00:00:00", $this->fixTime( $end_date ) . " 23:59:59");
    
    $report = new CustomReport( $time_bounds );
    $report_options = $report->getOptions( true );
    $report_options = array_merge( $report_options, $options );
    $report->setOptions( $report_options );
    $report->runReport();
    
    return $report->getResults();
  }
  
  /**
   * Format time correctly for reports
   * @param string Representation of date/time
   */
  public function fixTime( $time ) {
    return date( 'Y-m-d', strtotime( $time ));
  }
}
