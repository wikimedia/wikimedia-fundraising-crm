<?php

class ChecksImportLog {
  static function recentEvents( $pageLength = 20 ) {
    $result = db_select( 'offline2civicrm_log' )
      ->fields( 'offline2civicrm_log' )
      ->orderBy( 'id', 'DESC' )
      ->extend( 'PagerDefault' )
      ->limit( $pageLength )
      ->execute();

    $events = array();
    while ( $row = $result->fetchAssoc() ) {
      $row['done'] = filter_xss($row['done'], ['a']);
      $events[] = $row;
    }
    return $events;
  }

  static function record( $description ) {
    global $user;
    db_insert( 'offline2civicrm_log' )->fields( array(
      'who' => $user->name,
      'done' => $description,
    ) )->execute();
  }
}
