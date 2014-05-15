-- FIXME:
-- * i18n schema

insert ignore into civicrm_tag
    (name, used_for, is_reserved)
values
    ('Manually reviewed - Perform action', 'civicrm_contact', 1),
    ('Manually reviewed - Revert email', 'civicrm_contact', 1),
    ('Manually reviewed - Revert name', 'civicrm_contact', 1),
    ('Manually reviewed - Revert address', 'civicrm_contact', 1),
    ('Manually reviewed - Revert language', 'civicrm_contact', 1),
    ('Manually rejected action', 'civicrm_contact', 1),
    ('Needs rereview', 'civicrm_contact', 1);

create table dedupe_review_action (
  id int(10) unsigned not null auto_increment,
  name text,
  primary key (id)
);
insert into dedupe_review_action
    (name)
values
    ('Autoreview - Recommend keep'),
    ('Autoreview - Recommend spamblock'),
    ('Autoreview - Recommend is duplicate'),
    ('Autoreview - Act on is duplicate'),
    ('Autoreview - Recommend update contact'),
    ('Autoreview - Recommend conflict resolution');

create table dedupe_review_batch (
    id int unsigned not null auto_increment,
    assigned_to int unsigned not null
        comment 'Admin user assigned to this batch. Foreign key to user.uid',
    title varchar(255)
        comment 'Text which describes this batch',
    notes text
        comment 'Free text attached to this batch',
    primary key (id)
);

create table dedupe_review_queue (
    id int unsigned not null auto_increment,
    job_id int unsigned default null
        comment 'Autoreview job sequence number',
    old_id int unsigned not null
        comment 'Suspected original matching record. Foreign key to civicrm_contact.id',
    new_id int unsigned not null
        comment 'Suspected related record. Foreign key to civicrm_contact.id',
    match_description text not null
        comment 'Description of match (JSON)',
    action_id int unsigned
        comment 'Auto-recommended action',
    assigned_to_batch int unsigned null
        comment 'Assigned to resolution batch. Foreign key to dedupe_review_batch.id',
    PRIMARY KEY (id),
    KEY old_id (old_id),
    KEY new_id (new_id),
    KEY action_id (action_id)
);
