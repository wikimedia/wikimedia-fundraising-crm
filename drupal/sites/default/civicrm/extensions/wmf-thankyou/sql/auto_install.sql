-- +--------------------------------------------------------------------+
-- | Copyright CiviCRM LLC. All rights reserved.                        |
-- |                                                                    |
-- | This work is published under the GNU AGPLv3 license with some      |
-- | permitted exceptions and without any warranty. For full license    |
-- | and copyright information, see https://civicrm.org/licensing       |
-- +--------------------------------------------------------------------+
--
-- Generated from schema.tpl
-- DO NOT EDIT.  Generated by CRM_Core_CodeGen
--
-- /*******************************************************
-- *
-- * Clean up the existing tables - this section generated from drop.tpl
-- *
-- *******************************************************/

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `wmf_eoy_receipt_donor`;

SET FOREIGN_KEY_CHECKS=1;
-- /*******************************************************
-- *
-- * Create new tables
-- *
-- *******************************************************/

-- /*******************************************************
-- *
-- * wmf_eoy_receipt_donor
-- *
-- * Tracking for EOY emails
-- *
-- *******************************************************/
CREATE TABLE `wmf_eoy_receipt_donor` (
  `id` int unsigned NOT NULL AUTO_INCREMENT COMMENT 'EOY email job ID',
  `email` varchar(254) COMMENT 'Email address',
  `status` varchar(254) COMMENT 'queued|failed|sent',
  `year` int,
  PRIMARY KEY (`id`),
  INDEX `email_year`(email, year),
  INDEX `status`(status)
)
ENGINE=InnoDB;