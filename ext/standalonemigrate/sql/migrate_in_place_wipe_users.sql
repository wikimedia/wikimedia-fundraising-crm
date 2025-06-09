
-- delete all cms users
DELETE FROM `civicrm_uf_match`;

-- TODO: domain_id is set to 1 - is that ok?
INSERT INTO `civicrm_uf_match` (`id`, `uf_id`, `domain_id`, `is_active`,`username`, `uf_name`, `hashed_password`, `contact_id`)
VALUES (1, 1, 1, 1, 'admin', 'admin@example.org', @HASHED_ADMIN_PASS, @ADMIN_CONTACT_ID);

-- create stock roles
INSERT INTO `civicrm_role` (`id`, `name`, `label`, `permissions`, `is_active`)
VALUES
  (1, "everyone", "Everyone, including anonymous users", CONCAT(@VALUE_SEP, "authenticate with password", @VALUE_SEP, "access password resets", @VALUE_SEP), 1),
  (2, "admin", "Administrator", CONCAT(@VALUE_SEP, "all CiviCRM permissions and ACLs", @VALUE_SEP, "access password resets", @VALUE_SEP), 1)
;

-- assign admin role
INSERT INTO `civicrm_user_role` (`user_id`, `role_id`)
VALUES (1, 2);
