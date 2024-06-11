create table T365519 (
  id int auto_increment primary key,
  gateway_txn_id varchar(16),
  contribution_id int(10)
);

source inserts.sql;

select count(*) from T365519;
-- 92023 failed authorizations while the bug was live

UPDATE T365519 t
INNER JOIN civicrm.wmf_contribution_extra x on x.gateway_txn_id = t.gateway_txn_id
SET t.contribution_id = x.entity_id
WHERE x.gateway='adyen';
-- Query OK, 32523 rows affected (0.860 sec)
-- 32523 actually made it into Civi

create table T365519_contacts (
  contact_id int(10) unsigned,
  failed_count int,
  total_count int
);

insert into T365519_contacts (contact_id, failed_count)
select contact_id, count(*)
from T365519 t
  inner join civicrm.civicrm_contribution c on c.id = t.contribution_id group by c.contact_id;
-- 9464 distinct contact IDs

update T365519_contacts t
set total_count=(
  select count(*)
  from civicrm.civicrm_contribution c
  where c.contact_id = t.contact_id
  group by c.contact_id
);

select * from T365519_contacts where failed_count = total_count;
-- 68 rows = 68 contacts with only failed donations
-- many seem to be in CZK. This is because onlinebanking_CZ is one of the methods that
-- we record as complete as soon as we get a 'successful' authorization.
