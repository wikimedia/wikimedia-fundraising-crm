<?php

use SmashPig\Core\Context;
use SmashPig\Core\DataStores\DamagedDatabase;
use SmashPig\Core\DataStores\PendingDatabase;
use SmashPig\Tests\TestingContext;
use SmashPig\Tests\TestingGlobalConfiguration;

/**
 * @group OrphanSlayer
 */

class OrphanSlayerTest extends PHPUnit_Framework_TestCase {

    public function setUp() {
        parent::setUp();

        // Initialize SmashPig with a fake context object
        $config = TestingGlobalConfiguration::create();
        TestingContext::init( $config );

        if ( !defined( 'DRUPAL_ROOT' ) ) {
            throw new Exception( "Define DRUPAL_ROOT somewhere before running unit tests." );
        }

        global $user, $_exchange_rate_cache;
        $GLOBALS['_PEAR_default_error_mode'] = NULL;
        $GLOBALS['_PEAR_default_error_options'] = NULL;
        $_exchange_rate_cache = array();

        $user = new stdClass();
        $user->name = "foo_who";
        $user->uid = "321";
        $user->roles = array( DRUPAL_AUTHENTICATED_RID => 'authenticated user' );
        module_load_include('php', 'orphan_slayer', 'OrphanSlayer');

    }

    public function tearDown() {
        Context::set( null ); // Nullify any SmashPig context for the next run
        parent::tearDown();
    }


    public function testGetOldest(){
        $slayer = new OrphanSlayer('paypal_ec');
        $orphan = $this->createTestOrphan('paypal_ec');
        $result = $slayer->get_oldest();
        $this->assertEquals($orphan['contribution_tracking_id'], $result['contribution_tracking_id'], "Cannot get orphan");
        PendingDatabase::get()->deleteMessage([$orphan]);
    }

    public function testRectify() {
        $slayer = new OrphanSlayer('paypal_ec');
        $orphan = $this->createTestOrphan($slayer->gateway);
        TestingPaypalExpressAdapter::setDummyGatewayResponseCode('OK');
        $result = $slayer->rectify($orphan);
        $this->assertEquals(array(), $result->getErrors(), "rectify_orphan returned errors: " . print_r($result->getErrors(), true));
        $result = PendingDatabase::get()->fetchMessageByGatewayOrderId( 'paypal_ec', $orphan['order_id'] );
        $this->assertEquals($result, null, "Orphan was not deleted");
    }

    public function testError() {
        $slayer = new OrphanSlayer('paypal_ec');
        $orphan = $this->createTestOrphan($slayer->gateway);
        //email won't validate -> error
        $orphan['email'] = '12345';
        TestingPaypalExpressAdapter::setDummyGatewayResponseCode('OK');
        $slayer->rectify($orphan);
        $expected = $orphan;
        $expected['damaged_id'] = '1';
        $expected['original_queue'] = 'pending';
        $expected['amount'] = '123';
        $result = DamagedDatabase::get()->fetchMessageByGatewayOrderId( 'paypal_ec', $orphan['order_id'] );
        $this->assertEquals($expected, $result, "Orphan was not stored in damaged queue");
        $result = PendingDatabase::get()->fetchMessageByGatewayOrderId( 'paypal_ec', $orphan['order_id'] );
        $this->assertEquals($result, null, "Orphan was not deleted");
    }

    protected function createTestOrphan($gateway = 'test'){
        $uniq = mt_rand();
        $message = array(
            'contribution_tracking_id' => $uniq,
            'country' => 'US',
            'first_name' => 'Flighty',
            'last_name' => 'Dono',
            'email' => 'test+wmf@eff.org',
            'gateway' => $gateway,
            'gateway_txn_id' => "txn-{$uniq}",
            'gateway_session_id' => mt_rand(),
            'order_id' => "order-{$uniq}",
            'gateway_account' => 'default',
            'payment_method' => 'paypal',
            'payment_submethod' => 'mc',
            // Defaults to a magic 25 minutes ago, within the process window.
            'date' => time() - 25 * 60,
            'gross' => 123,
            'currency' => 'EUR',
        );

        PendingDatabase::get()->storeMessage($message);
        return $message;
    }
}
