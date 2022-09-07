-- Generated with 'SHOW CREATE TABLE' to get the current state of the table on prod
-- Table had originally been created by the ContributionTracking extension via
-- https://phabricator.wikimedia.org/diffusion/ECNT/browse/master/patches/patch-contribution_source_table.sql;22cb54fc457b7b7d7aaaa38d86393b65ff5ae809
CREATE TABLE IF NOT EXISTS `contribution_source` (
  `contribution_tracking_id` int(10) unsigned NOT NULL,
  `banner` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `landing_page` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_method` varchar(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`contribution_tracking_id`),
  KEY `banner` (`banner`),
  KEY `landing_page` (`landing_page`),
  KEY `payment_method` (`payment_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
