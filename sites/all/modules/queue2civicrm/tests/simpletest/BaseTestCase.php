<?php

class BaseTestCase extends DrupalWebTestCase {
    public function setUp() {
        global $db_url;
        $main_db = $db_url;
        $db_url['civicrm'] = $db_url['default'] = $main_db;

        parent::setUp( 'exchange_rates', 'dblog', 'queue2civicrm', 'wmf_common', 'wmf_civicrm', 'contribution_tracking', 'civicrm' );
    }
}
