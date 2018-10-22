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
      $events[] = CheckImportLogEvent::loadFromRow( $row );
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

class CheckImportLogEvent {
  static function loadFromRow( $data ) {
    $event = new CheckImportLogEvent();
    $event->time = $data['time'];
    $event->who = $data['who'];
    $event->done = filter_xss($data['done'], ['a']);
    return $event;
  }
}
