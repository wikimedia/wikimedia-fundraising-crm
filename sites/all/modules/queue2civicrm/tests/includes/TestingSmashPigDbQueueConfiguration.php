<?php

use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Tests\TestingConfiguration;

class TestingSmashPigDbQueueConfiguration {
    public static function instance() {
		$config = TestingConfiguration::loadConfigWithFileOverrides( array(
			__DIR__ . '/../data/config_queue_and_db.yaml',
		) );
        return $config;
    }
}
