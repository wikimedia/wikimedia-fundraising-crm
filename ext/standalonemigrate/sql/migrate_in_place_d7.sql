--- USERS ---

-- copy user columns from Drupal users table
UPDATE `civicrm_uf_match` JOIN `users` ON `civicrm_uf_match`.`uf_id` = `users`.`uid` SET `username` = `users`.`name`;
UPDATE `civicrm_uf_match` JOIN `users` ON `civicrm_uf_match`.`uf_id` = `users`.`uid` SET `hashed_password` = `users`.`pass`;
UPDATE `civicrm_uf_match` JOIN `users` ON `civicrm_uf_match`.`uf_id` = `users`.`uid` SET `when_created` = FROM_UNIXTIME(`users`.`created`) WHERE `users`.`access` > 0;
UPDATE `civicrm_uf_match` JOIN `users` ON `civicrm_uf_match`.`uf_id` = `users`.`uid` SET `when_last_accessed` = FROM_UNIXTIME(`users`.`access`) WHERE `users`.`access` > 0;
UPDATE `civicrm_uf_match` JOIN `users` ON `civicrm_uf_match`.`uf_id` = `users`.`uid` SET `when_updated` = FROM_UNIXTIME(`users`.`changed`) WHERE `users`.`access` > 0;

-- TODO: how to map timezones
-- UPDATE `civicrm_uf_match` JOIN `users` ON `civicrm_uf_match`.`uf_id` = `users`.`uid` SET `civicrm_uf_match`.`timezone` = `users`.`timezone`;

UPDATE `civicrm_uf_match` JOIN `users` ON `civicrm_uf_match`.`uf_id` = `users`.`uid` SET `is_active` = `users`.`status`;

-- Standaloneusers expects uf_id to match id. Prefer the uf_id value to simplify assigning roles
UPDATE `civicrm_uf_match` SET `id` = `uf_id`;


--- ROLES ---

-- concatenating the role permissions field can lead to some looong strings
SET	group_concat_max_len = 10000;

-- create roles matching d7 roles
INSERT INTO `civicrm_role` (`id`, `name`, `label`, `permissions`, `is_active`)
SELECT `role`.`rid`, `name`, `name`, REPLACE(GROUP_CONCAT(DISTINCT `permission` SEPARATOR ','), ',', @VALUE_SEP), 1 FROM `role` JOIN `role_permission` ON `role_permission`.`rid` = `role`.`rid` GROUP BY `rid`;

-- bookend serialized field
UPDATE `civicrm_role` SET `permissions` = CONCAT(@VALUE_SEP, `permissions`, @VALUE_SEP);


-- update anonymous user role to "everyone"
UPDATE `civicrm_role` SET `name` = "everyone" WHERE `name` = "anonymous user";
-- enable password login and reset
UPDATE `civicrm_role` SET `permissions` = CONCAT(`permissions`, @VALUE_SEP, 'authenticate with password', @VALUE_SEP, 'access password resets') WHERE `name` = "everyone";


-- assign roles
INSERT INTO `civicrm_user_role` (`user_id`, `role_id`) SELECT `uid`, `rid` FROM `users_roles`;


-- create superuser role
INSERT INTO `civicrm_role` (`name`, `label`, `permissions`, `is_active`)
VALUES ("superuser", "Superuser", CONCAT(@VALUE_SEP, "all CiviCRM permissions and ACLs", @VALUE_SEP, "access password resets", @VALUE_SEP), 1);

-- assign superuser role to User ID 1
INSERT INTO `civicrm_user_role` (`user_id`, `role_id`)
SELECT 1, `id` FROM `civicrm_role` WHERE `name` = "superuser";

