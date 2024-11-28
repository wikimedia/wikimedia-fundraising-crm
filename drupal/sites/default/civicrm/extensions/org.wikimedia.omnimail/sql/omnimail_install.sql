-- remote the foreign key constraint. We have to declare it
-- in the entityType file to
-- get the entity reference goodness but live does not have
-- the constraint & probably it's good for forensics to leave orphans
-- when they are created.
ALTER TABLE `civicrm_mailing_provider_data`
  DROP CONSTRAINT FK_civicrm_mailing_provider_data_contact_id;

