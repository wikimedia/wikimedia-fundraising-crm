insert into foo (col1, col2) values (1, 2);

Insert into foo (col1, col2) values ("1", 'two');

INSERT into foo values ("one", "two");

INSERT into foo values ("INSERT INTO civicrm_tmp_e_foobar (col1, col2) values (1, 2)");

INSERT into foo values ("DELETE FROM civicrm_tmp_e_foobar");

INSERT into foo values ("DELETE FROM `civicrm_tmp_e_foobar`");

INSERT INTO civicrm_foobar_123 (id, civicrm_tmp_e_foobar) values (1, 2);

insert into foo select * from bar 
on duplicate key update whiz = b(ang);

update foo set bar = 1 where whiz = b(ang);

Update Ignore foo Set bar = 1 Limit 10;

UPDATE `civicrm_tmp_e_foo`, civicrm_contact SET foo = bar;

UPDATE civicrm_foobar_123 SET civicrm_tmp_e_foo = 123;

DELETE FROM foo;

delete from foo where whiz = b(ang);

delete from foo where id in (SELECT foo_id FROM bar);

TRUNCATE foo;

ALTER TABLE foo;

create TABLE foo (whiz bang);

create TABLE if Not Exists foo (whiz bang);

CREATE VIEW foo AS SELECT bar;

GRANT ALL on *.* TO `foo`@`bar`;

REVOKE foo;

SELECT a,b,a+b INTO OUTFILE '/tmp/result.txt'
  FIELDS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '"'
  LINES TERMINATED BY '\n'
  FROM test_table;

SELECT * FROM t1 WHERE c1 = (SELECT c1 FROM t2) FOR UPDATE;

SELECT * FROM t1 WHERE c1 = (SELECT c1 FROM t2 FOR UPDATE) FOR UPDATE;

SELECT * FROM parent WHERE NAME = 'Jones' FOR SHARE;

SELECT
*
FROM t1 WHERE c1 = (SELECT c1 FROM t2 FOR UPDATE) FOR UPDATE;

select
get_lock
("foo", 123);

select (Get_Lock("foo", 123));

select "foo",is_FREE_lock("bar");

commit;

Commit Work;

rollback;

ROLLBACK AND NO CHAIN;

flush privileges;

rollback to foo;

UNRECOGNIZED ACTION;

unrecognized action;

SELECT 'civirpow-force-write';

SELECT "civirpow-force-write";

DESCR is not an action;

(SELECT 123) UNION (SELECT GET_LOCK("foo", 456));

SELECT 123 UNION SELECT GET_LOCK("foo", 456);

CALL storedproc;

CALL storedproc();

CALL storedproc("foo bar");

PREPARE s FROM 'CALL p(?, ?)';

EXECUTE s USING @version, @increment;
