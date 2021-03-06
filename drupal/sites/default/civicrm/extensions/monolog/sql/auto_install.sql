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

-- +--------------------------------------------------------------------+
-- | Copyright CiviCRM LLC. All rights reserved.                        |
-- |                                                                    |
-- | This work is published under the GNU AGPLv3 license with some      |
-- | permitted exceptions and without any warranty. For full license    |
-- | and copyright information, see https://civicrm.org/licensing       |
-- +--------------------------------------------------------------------+
--
-- Generated from drop.tpl
-- DO NOT EDIT.  Generated by CRM_Core_CodeGen
--
-- /*******************************************************
-- *
-- * Clean up the existing tables
-- *
-- *******************************************************/

SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `civicrm_monolog`;

SET FOREIGN_KEY_CHECKS=1;
-- /*******************************************************
-- *
-- * Create new tables
-- *
-- *******************************************************/

-- /*******************************************************
-- *
-- * civicrm_monolog
-- *
-- * Monolog log configuration
-- *
-- *******************************************************/
CREATE TABLE `civicrm_monolog` (


     `id` int unsigned NOT NULL AUTO_INCREMENT  COMMENT 'Unique Monolog ID',
     `name` varchar(16)    ,
     `channel` varchar(16)    ,
     `description` varchar(255)    ,
     `type` varchar(16)    ,
     `minimum_severity` varchar(16)    ,
     `weight` int    ,
     `is_active` tinyint    ,
     `is_final` tinyint    ,
     `is_default` tinyint    ,
     `configuration_options` text     
,
        PRIMARY KEY (`id`)
 
    ,     UNIQUE INDEX `UI_name`(
        name
  )
  
 
)    ;

 