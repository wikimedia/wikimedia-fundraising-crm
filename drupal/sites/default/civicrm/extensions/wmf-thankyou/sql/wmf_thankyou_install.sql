CREATE TABLE `wmf_eoy_receipt_job`
(
  `job_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `start_time` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year` INT(11) DEFAULT NULL,
  PRIMARY KEY (`job_id`)
) ENGINE = InnoDB
AUTO_INCREMENT = 246
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

CREATE TABLE `wmf_eoy_receipt_donor`
(
  `job_id` INT(10) UNSIGNED DEFAULT NULL,
  `email` VARCHAR(254) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_language` VARCHAR(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contributions_rollup` MEDIUMTEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  UNIQUE KEY `wmf_eoy_receipt_donor_job_id_email` (`job_id`, `email`),
  KEY `job_id` (`job_id`),
  KEY `email` (`email`),
  KEY `status` (`status`)
) ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

