<?php

use CRM_Rpow_StateMachine as StateMachine;

class CRM_Rpow_StateMachineTest extends \PHPUnit\Framework\TestCase {

  public function testNormalLifecycle() {
    $s = new StateMachine();

    // Start out directing requests to the read-only slave. Any replayable statements are stored in the buffer.

    $this->assertEquals(StateMachine::READ_ONLY, $s->handle('SELECT contact_id FROM civicrm_uf_match WHERE uf_id = 100'));
    $this->assertEquals(StateMachine::READ_ONLY, $s->handle('SET @contact_id = 123'));
    $this->assertEquals(StateMachine::READ_ONLY, $s->handle('SELECT id, name FROM civicrm_option_group'));
    $this->assertEquals(StateMachine::READ_ONLY, $s->handle('SELECT value, label FROM civicrm_option_value WHERE option_group_id = 123'));
    $this->assertEquals(StateMachine::READ_ONLY, $s->handle('CREATE TEMPORARY TABLE foobar AS SELECT id FROM whizbang'));

    // When we hit the first write statement, we need to replay the buffer.

    $this->assertEquals(StateMachine::REPLAY, $s->handle('UPDATE civicrm_contact SET do_not_phone = 1 WHERE id IN (SELECT id FROM foobar)'));
    $this->assertEquals(
      [
        'SET @contact_id = 123',
        'CREATE TEMPORARY TABLE foobar AS SELECT id FROM whizbang',
        'UPDATE civicrm_contact SET do_not_phone = 1 WHERE id IN (SELECT id FROM foobar)',
      ],
      $s->getBuffer()
    );
    $this->assertEquals($s->getBuffer(), $s->clearBuffer());
    $this->assertEquals([], $s->getBuffer());

    // To ensure read-write consistency, all subsequent queries go to the read-write master.

    $this->assertEquals(StateMachine::READ_WRITE, $s->handle('SELECT value, label FROM civicrm_option_value WHERE option_group_id = 123'));
    $this->assertEquals(StateMachine::READ_WRITE, $s->handle('UPDATE civicrm_contact SET do_not_phone = 1 WHERE id IN (SELECT id FROM foobar)'));
  }

  public function testForce() {
    $s = new StateMachine();
    $s->forceWriteMode();

    // Start out directing requests to the read-only slave. Any replayable statements are stored in the buffer.

    $this->assertEquals(StateMachine::READ_WRITE, $s->handle('SELECT contact_id FROM civicrm_uf_match WHERE uf_id = 100'));
    $this->assertEquals(StateMachine::READ_WRITE, $s->handle('SET @contact_id = 123'));
    $this->assertEquals(StateMachine::READ_WRITE, $s->handle('SELECT id, name FROM civicrm_option_group'));
    $this->assertEquals(StateMachine::READ_WRITE, $s->handle('SELECT value, label FROM civicrm_option_value WHERE option_group_id = 123'));
    $this->assertEquals(StateMachine::READ_WRITE, $s->handle('CREATE TEMPORARY TABLE foobar AS SELECT id FROM whizbang'));
    $this->assertEquals(StateMachine::READ_WRITE, $s->handle('UPDATE civicrm_contact SET do_not_phone = 1 WHERE id IN (SELECT id FROM foobar)'));

  }

}
