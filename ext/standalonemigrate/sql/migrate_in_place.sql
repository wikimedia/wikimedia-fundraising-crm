--- create additional tables for Standaloneusers entities
--- (derived from `CRM_Standaloneusers_ExtensionUtil::schema()->generateInstallSql();`)
CREATE TABLE `civicrm_role` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique Role ID',
  `name` varchar(60) NOT NULL COMMENT 'Machine name for this role',
  `label` varchar(128) NOT NULL COMMENT 'Human friendly name for this role',
  `permissions` text NOT NULL COMMENT 'List of permissions granted by this role',
  `is_active` boolean NOT NULL DEFAULT TRUE COMMENT 'Only active roles grant permissions',
  PRIMARY KEY (`id`)
)
ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE `civicrm_session` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Unique Session ID',
  `session_id` char(64) NOT NULL COMMENT 'Hexadecimal Session Identifier',
  `data` longtext NULL COMMENT 'Session Data',
  `last_accessed` datetime NULL COMMENT 'Timestamp of the last session access',
  PRIMARY KEY (`id`),
  UNIQUE INDEX `index_session_id`(`session_id`)
)
ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE `civicrm_totp` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique TOTP ID',
  `user_id` int(10) unsigned NOT NULL COMMENT 'Reference to User (UFMatch) ID',
  `seed` varchar(512) NOT NULL,
  `hash` varchar(20) NOT NULL DEFAULT '\"sha1\"',
  `period` INT(1) UNSIGNED NOT NULL DEFAULT '30',
  `length` INT(1) UNSIGNED NOT NULL DEFAULT '6',
  PRIMARY KEY (`id`)
)
ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE `civicrm_user_role` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique UserRole ID',
  `user_id` int unsigned NULL COMMENT 'FK to User',
  `role_id` int unsigned NULL COMMENT 'FK to Role',
  PRIMARY KEY (`id`)
)
ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

--- alter core civicrm_uf_match to Standaloneusers version
--- this should reflect schema_version 5692 as set below
ALTER TABLE `civicrm_uf_match` ADD `username` varchar(60);
UPDATE `civicrm_uf_match` SET `username` = `uf_name`;
ALTER TABLE `civicrm_uf_match` MODIFY COLUMN `username` varchar(60) NOT NULL;
CREATE UNIQUE INDEX `UI_username` ON `civicrm_uf_match` (`username`);

ALTER TABLE `civicrm_uf_match` ADD `hashed_password` varchar(128) NOT NULL DEFAULT '' COMMENT 'Hashed, not plaintext password';

ALTER TABLE `civicrm_uf_match` ADD `when_created` timestamp NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE `civicrm_uf_match` ADD `when_last_accessed` timestamp NULL;
ALTER TABLE `civicrm_uf_match` ADD `when_updated` timestamp NULL;
ALTER TABLE `civicrm_uf_match` ADD `is_active` boolean NOT NULL DEFAULT TRUE;
ALTER TABLE `civicrm_uf_match` ADD `timezone` varchar(32) NULL COMMENT 'Users timezone';
ALTER TABLE `civicrm_uf_match` ADD `password_reset_token` varchar(255) NULL COMMENT 'The unspent token';

ALTER TABLE `civicrm_totp`
  ADD CONSTRAINT `FK_civicrm_totp_user_id` FOREIGN KEY (`user_id`) REFERENCES `civicrm_uf_match`(`id`) ON DELETE CASCADE;
ALTER TABLE `civicrm_user_role`
  ADD CONSTRAINT `FK_civicrm_user_role_user_id` FOREIGN KEY (`user_id`) REFERENCES `civicrm_uf_match`(`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `FK_civicrm_user_role_role_id` FOREIGN KEY (`role_id`) REFERENCES `civicrm_role`(`id`) ON DELETE CASCADE;

--- set standaloneusers to enabled so the standalone site works.
--- NOTES:
---  - this may cause strange behaviour on the source site
---  - the schema version is hard coded here, but this reflects the state of the standaloneuser tables after the transformations above
INSERT INTO `civicrm_extension` (`type`, `full_name`, `name`, `label`, `file`, `schema_version`, `is_active`)
VALUES ('module', 'standaloneusers', 'Standalone Users', 'Standalone Users', 'standaloneusers', 5692, 1)
ON DUPLICATE KEY UPDATE `is_active` = 1, `schema_version` = 5692;

--- empty database cache
TRUNCATE `civicrm_cache`;

