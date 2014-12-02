-- Updates wmf_donor for 1k+ donors with minimal lockage
-- Fixes stats for WMF LYBUNT if merges or other db manipulation has gotten them out of date
-- Tracks years in which a donor has given, lifetime totals, and latest donation
CREATE TEMPORARY TABLE temp_wmf_donor(
  contact_id int unsigned PRIMARY KEY,
  last_donation_date datetime,
  lifetime_usd_total decimal(20,2)
);
-- Don't lock civicrm_contribution or wmf_contribution_extra
SELECT 'Populating big donor temp table';
SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;
INSERT INTO temp_wmf_donor (contact_id)
  SELECT DISTINCT contact_id
  FROM civicrm_contribution
  WHERE total_amount >= 1000
    AND contribution_status_id = 1;
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
SELECT 'Populating contribution temp table';
INSERT INTO temp_wmf_contributions (
  SELECT c.id, c.contact_id, c.receive_date, c.total_amount, x.original_amount, x.original_currency
  FROM civicrm_contribution c
  JOIN temp_wmf_donor w ON c.contact_id = w.contact_id
  LEFT JOIN wmf_contribution_extra x ON x.entity_id = c.id
);
INSERT INTO temp_wmf_donor( contact_id, last_donation_date, lifetime_usd_total ) (
  SELECT contact_id, MAX(receive_date) AS last_donation_date, SUM(total_amount) AS lifetime_usd_total
  FROM temp_wmf_contributions t
  GROUP BY contact_id
) ON DUPLICATE KEY UPDATE
  last_donation_date = VALUES(last_donation_date),
  lifetime_usd_total = VALUES(lifetime_usd_total);

SELECT 'Updating last donation date and lifetime total';
-- Add last donation date and lifetime total
INSERT INTO wmf_donor( entity_id, last_donation_date, lifetime_usd_total ) (
  SELECT contact_id, last_donation_date, lifetime_usd_total
  FROM temp_wmf_donor t
  GROUP BY contact_id
) ON DUPLICATE KEY UPDATE
  last_donation_date = VALUES(last_donation_date),
  lifetime_usd_total = VALUES(lifetime_usd_total);
SELECT 'Updating last donation amounts and currency';
-- Add the rest of the last donation data
UPDATE wmf_donor w
INNER JOIN temp_wmf_contributions t
  ON t.contact_id = w.entity_id
  AND t.receive_date = w.last_donation_date
SET w.last_donation_usd = t.total_amount,
  w.last_donation_amount = t.original_amount,
  w.last_donation_currency = t.original_currency;
SELECT 'Updating donation years';
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
