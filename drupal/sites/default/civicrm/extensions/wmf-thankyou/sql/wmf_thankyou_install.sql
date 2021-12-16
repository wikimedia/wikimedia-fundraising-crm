CREATE TABLE `wmf_eoy_receipt_donor`
(
  `year` INT(10) UNSIGNED DEFAULT NULL,
  `email` VARCHAR(254) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  KEY `email_year` (`email`, `year`),
  KEY `status` (`status`)
) ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci;

