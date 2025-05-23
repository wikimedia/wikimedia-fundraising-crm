SELECT 1;

select 2;

Select * FROM hello_world where `name` = "foo" 
AND id in (select id FROM `other_ods`);

Select * FROM hello_world 
where `name` = "foo" 
AND id in (select id FROM `other_ods`);

SELECT * FROM `hello_world` where nAmE = "update";

SELECT
*
FROM `hello_world` where nAmE = "update";

SELECT * FROM `hello_world` where lastinsert(123) > updated;

show create table whiz;

show
create
table
whiz;

SHOW CREATE TABLE whiz;

DESC foobar;

DESCRIBE foobar;

SELECT "INSERT INTO foo";

SELECT "CREATE TEMPORARY TABLE foo";

SELECT "ALTER TABLE foo";

SELECT "DELETE FROM civicrm_tmp_e_foobar";

SELECT "@foo := 123";

select (udf_not_really_Get_Lock("foo", 123));

EXPLAIN SELECT * from foobar;

( SELECT * FROM civicrm_menu WHERE path in ( 'civicrm/admin/options/activity_type', 'civicrm/admin/options', 'civicrm/admin', 'civicrm' ) AND domain_id = 1 ORDER BY length(path) DESC LIMIT 1 ) UNION ( SELECT * FROM civicrm_menu WHERE path IN ( 'navigation' ) AND domain_id = 1 );

(SELECT 123) UNION (SELECT 456);

SELECT 123 UNION SELECT 456;