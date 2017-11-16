CREATE TABLE `civicrm_merge_conflict` (
  `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique ID',
  `contact_1` int(10) unsigned NOT NULL COMMENT 'FK to entity table specified in entity_table column.',
  `contact_2` int(10) unsigned NOT NULL COMMENT 'FK to entity table specified in entity_table column.',
  `group_id` int(10) NOT NULL DEFAULT '0',
  conflicted_field varchar(255) NOT NULL DEFAULT '',
  `value_1` varchar(255) NOT NULL DEFAULT '',
  `value_2` varchar(255) NOT NULL DEFAULT '',
  `analysis` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `contact_1` (`contact_1`,`contact_2`),
  KEY `contact_2` (`contact_2`,`contact_1`),
  KEY `value_1` (`value_1`,`value_2`),
  KEY `value_2` (`value_2`,`value_1`),
  KEY `analysis` (`analysis`,`conflicted_field`),
  KEY `conflicted_field` (`conflicted_field`),
  KEY `group_id` ( `group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
