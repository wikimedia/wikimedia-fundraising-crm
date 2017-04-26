CREATE TABLE `civicrm_mailing_stats` (
  `id` INT(10) unsigned NOT NULL AUTO_INCREMENT,
  `mailing_id` INT(10) UNSIGNED NOT NULL,
  `mailing_name` VARCHAR(128) NULL DEFAULT NULL COLLATE 'utf8_unicode_ci',
  `is_completed` TINYINT(4) NULL DEFAULT NULL,
  `created_date` TIMESTAMP NULL DEFAULT NULL,
  `start` TIMESTAMP NULL DEFAULT NULL,
  `finish` TIMESTAMP NULL DEFAULT NULL,
  `recipients` INT(10) UNSIGNED NULL DEFAULT NULL,
  `delivered` INT(10) UNSIGNED NULL DEFAULT NULL,
  `send_rate` FLOAT UNSIGNED NULL DEFAULT NULL,
  `bounced` INT(10) UNSIGNED NULL DEFAULT NULL,
  `blocked` INT(10) UNSIGNED NULL DEFAULT NULL,
  `suppressed` INT(10) UNSIGNED NULL DEFAULT NULL,
  `abuse_complaints` INT(10) UNSIGNED NULL DEFAULT NULL,
  `opened_total` INT(10) UNSIGNED NULL DEFAULT NULL,
  `opened_unique` INT(10) UNSIGNED NULL DEFAULT NULL,
  `unsubscribed` INT(10) UNSIGNED NULL DEFAULT NULL,
  `forwarded` INT(10) UNSIGNED NULL DEFAULT NULL,
  `clicked_total` INT(10) UNSIGNED NULL DEFAULT NULL,
  `clicked_unique` INT(10) UNSIGNED NULL DEFAULT NULL,
  `trackable_urls` INT(10) UNSIGNED NULL DEFAULT NULL,
  `clicked_contribution_page` INT(10) UNSIGNED NULL DEFAULT NULL,
  `contribution_count` INT(10) UNSIGNED NULL DEFAULT NULL,
  `contribution_total` FLOAT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `start` (`start`),
  INDEX `finish` (`start`)
)
COLLATE='utf8_unicode_ci'
ENGINE=InnoDB
;

CREATE TABLE `civicrm_mailing_stats_performance` (
  `id` INT(10) unsigned NOT NULL AUTO_INCREMENT,
  `time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `doing` VARCHAR(64) NOT NULL COLLATE 'utf8_unicode_ci',
  PRIMARY KEY (`id`)
)
COLLATE='utf8_unicode_ci'
ENGINE=InnoDB
;

