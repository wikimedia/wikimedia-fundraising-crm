CREATE TABLE IF NOT EXISTS `public_reporting` (
  `contribution_id` int(10) unsigned NOT NULL,
  `contact_id` int(10) unsigned NOT NULL,
  `name` varchar(128) default NULL,
  `converted_amount` decimal(20,2) unsigned NOT NULL,
  `original_currency` varchar(3) NOT NULL,
  `original_amount` decimal(20,2) unsigned NOT NULL,
  `note` text NOT NULL,
  `received` int(10) unsigned NOT NULL,
  PRIMARY KEY  (`contribution_id`),
  KEY `contact_id` (`contact_id`),
  KEY `received` (`received`),
  KEY `with_notes` (`note`(1),`received`),
  KEY `with_currency` (`original_currency`,`received`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

ALTER TABLE `public_reporting`
  ADD CONSTRAINT `public_reporting_ibfk_1` FOREIGN KEY (`contribution_id`) REFERENCES `civicrm_contribution` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `public_reporting_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `civicrm_contact` (`id`) ON DELETE CASCADE;
