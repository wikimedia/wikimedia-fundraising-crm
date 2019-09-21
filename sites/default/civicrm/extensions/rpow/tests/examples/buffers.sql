SET foo = "bar";

SET @foo = 123;

SET @@foo = "bar";

SET time_zone = '-8:00';

/*!40101 SET NAMES utf8 */;

select @last_id := id from civicrm_contact;

select @lastID2:=id From civicrm_contact;

create temporary table foo;

CREATE TEMPORARY TABLE foo (whiz bang);

CREATE TEMPORARY TABLE IF NOT EXISTS foo (whiz bang);

CREATE   TEMPORARY	TABLE	IF NOT EXISTS foo (whiz bang);

CREATE
TEMPORARY
TABLE
IF NOT
EXISTS
foo (whiz bang);

DROP TEMPORARY TABLE foo;

drop temporary table if exists foo;

Select * FROM hello_world where `name` = "foo" AND id in (select @bar := id FROM `other_ods`);

SELECT id, data INTO @x, @y FROM test.t1 LIMIT 1;

INSERT IGNORE INTO civicrm_tmp_e_foobar (col1, col2) values (1, 2);

INSERT IGNORE INTO `civicrm_tmp_e_foobar` (col1, col2) values (1, 2);

INSERT INTO civicrm_tmp_e_foobar (col1, col2) values (1, 2);

UPDATE civicrm_tmp_e_foobar SET foo = bar;

UPDATE `civicrm_tmp_e_foobar` SET foo = bar;

UPDATE `civicrm_tmp_e_foo`, civicrm_tmp_e_bar SET foo = bar;

DELETE FROM civicrm_tmp_e_foobar;

DELETE FROM `civicrm_tmp_e_foobar` WHERE foo = bar;

BEGIN;

START TRANSACTION;

BEGIN WORK;

START TRANSACTION WITH CONSISTENT SNAPSHOT;

savepoint foo;
