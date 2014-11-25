-- Inserts initial data into wmf_donor
-- Tracks years in which a donor has given, lifetime totals, and latest donation
CREATE TEMPORARY TABLE temp_wmf_contributions(
  id int unsigned PRIMARY KEY,
  contact_id int unsigned,
  receive_date datetime,
  total_amount decimal(20,2),
  original_amount decimal(20,2),
  original_currency varchar(255),
  INDEX twc_contact_id (contact_id),
  INDEX twc_receive_date (receive_date)
);
-- Don't lock civicrm_contribution or wmf_contribution_extra
SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;
INSERT INTO temp_wmf_contributions (
  SELECT c.id, c.contact_id, c.receive_date, c.total_amount, x.original_amount, x.original_currency
  FROM civicrm_contribution c
  LEFT JOIN wmf_contribution_extra x ON x.entity_id = c.id
);
-- Add last donation date and lifetime total
INSERT INTO wmf_donor( entity_id, last_donation_date, lifetime_usd_total ) (
  SELECT contact_id, MAX(receive_date) AS last_donation_date, SUM(total_amount) AS lifetime_usd_total
  FROM temp_wmf_contributions t
  GROUP BY contact_id
) ON DUPLICATE KEY UPDATE
  last_donation_date = VALUES(last_donation_date),
  lifetime_usd_total = VALUES(lifetime_usd_total);
-- Add the rest of the last donation data
UPDATE wmf_donor w
INNER JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date = w.last_donation_date
SET w.last_donation_usd = t.total_amount,
  w.last_donation_amount = t.original_amount,
  w.last_donation_currency = t.original_currency;
-- Update years in which each contact has donated
UPDATE wmf_donor w
JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date >= '2006-07-01'
  AND t.receive_date < '2007-07-01'
SET w.is_2006_donor = 1;
UPDATE wmf_donor w
JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date >= '2007-07-01'
  AND t.receive_date < '2008-07-01'
SET w.is_2007_donor = 1;
UPDATE wmf_donor w
JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date >= '2008-07-01'
  AND t.receive_date < '2009-07-01'
SET w.is_2008_donor = 1;
UPDATE wmf_donor w
JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date >= '2009-07-01'
  AND t.receive_date < '2010-07-01'
SET w.is_2009_donor = 1;
UPDATE wmf_donor w
JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date >= '2010-07-01'
  AND t.receive_date < '2011-07-01'
SET w.is_2010_donor = 1;
UPDATE wmf_donor w
JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date >= '2011-07-01'
  AND t.receive_date < '2012-07-01'
SET w.is_2011_donor = 1;
UPDATE wmf_donor w
JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date >= '2012-07-01'
  AND t.receive_date < '2013-07-01'
SET w.is_2012_donor = 1;
UPDATE wmf_donor w
JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date >= '2013-07-01'
  AND t.receive_date < '2014-07-01'
SET w.is_2013_donor = 1;
UPDATE wmf_donor w
JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date >= '2014-07-01'
  AND t.receive_date < '2015-07-01'
SET w.is_2014_donor = 1;