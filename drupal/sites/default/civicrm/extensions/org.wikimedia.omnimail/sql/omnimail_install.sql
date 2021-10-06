CREATE TABLE IF NOT EXISTS `civicrm_mailing_provider_data` (
  `contact_identifier` varchar(255) NOT NULL DEFAULT '',
   `mailing_identifier` varchar(255) DEFAULT NULL,
   `email` varchar(255) DEFAULT NULL,
   `event_type` varchar(255) NOT NULL DEFAULT '',
  `recipient_action_datetime` timestamp NOT NULL,
   `contact_id` varchar(255) DEFAULT NULL,
   `is_civicrm_updated`  TINYINT(4) DEFAULT '0',
 PRIMARY KEY (`contact_identifier`,`recipient_action_datetime`,`event_type`),
   KEY `contact_identifier` (`contact_identifier`),
   KEY `mailing_identifier` (`mailing_identifier`),
   KEY `contact_id` (`contact_id`),
   KEY `email` (`email`),
   KEY `event_type` (`event_type`),
   KEY `recipient_action_datetime` (`recipient_action_datetime`)
) ENGINE=InnoDB;

CREATE TABLE civicrm_omnimail_job_progress (
 `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
 `mailing_provider` VARCHAR(32) NOT NULL,
 `job` VARCHAR(32) NULL,
 `job_identifier` VARCHAR(512) NULL,
 `last_timestamp` timestamp NULL,
 `progress_end_timestamp` timestamp NULL,
 `retrieval_parameters` VARCHAR(255) NULL,
 `offset` INT(10) unsigned,
 `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
   PRIMARY KEY (`id`)
) ENGINE=InnoDB;
