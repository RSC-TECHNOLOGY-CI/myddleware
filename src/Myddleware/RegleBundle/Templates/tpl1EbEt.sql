INSERT INTO Template VALUES ('54ab1802ca90b','8','14');

INSERT INTO TemplateLang (`tpll_lang`, `tplt_id`, `tpll_name`, `tpll_description`) VALUES ('FR','54ab1802ca90b','tpl1EbEt','Envoi des participants Eventbrite vers Exacttarget.');
INSERT INTO TemplateLang (`tpll_lang`, `tplt_id`, `tpll_name`, `tpll_description`) VALUES ('EN','54ab1802ca90b','tpl1EbEt','Sending Eventbrite attendees to Exacttarget.');

INSERT INTO `TemplateQuery` (`tplt_id`, `tplq_query`) VALUES ('54ab1802ca90b', "INSERT INTO `Rule` (`rule_id`,`conn_id_source`,`conn_id_target`,`rule_date_created`,`rule_date_modified`,`rule_created_by`,`rule_modified_by`,`rule_module_source`,`rule_module_target`,`rule_active`,`rule_deleted`,`rule_name`,`rule_name_slug`,`rule_version`) VALUES ('idRule','idConnectorSource','idConnectorTarget',NOW(),NOW(),'idUser','idUser','Attendees','Subscriber','0','0','prefixRuleName_ebet_attendees','prefixRuleName_ebet_attendees','001') ");

INSERT INTO `TemplateQuery` (`tplt_id`, `tplq_query`) VALUES ('54ab1802ca90b', "INSERT INTO `RuleParams` (`rule_id`,`rulep_name`,`rulep_value`) VALUES ('idRule','rate','5'),
('idRule','delete','60'),
('idRule','mode','0'),
('idRule','datereference',CONCAT( CURDATE( ),' 00:00:00' )),
('idRule','duplicate_fields','EmailAddress') ");

INSERT INTO `TemplateQuery` (`tplt_id`, `tplq_query`) VALUES ('54ab1802ca90b', "INSERT INTO `RuleFilters` (`rule_id`,`rfi_target`,`rfi_type`,`rfi_value`) VALUES ('idRule','email','content','@') ");

INSERT INTO `TemplateQuery` (`tplt_id`, `tplq_query`) VALUES ('54ab1802ca90b', "INSERT INTO `RuleFields` (`rule_id`,`rulef_target_field_name`,`rulef_source_field_name`,`rulef_formula`) VALUES ('idRule','EmailAddress','email',''),
('idRule','SubscriberKey','email',''),
('idRule','Full_mydblank_Name','last_name',''),
('idRule','User_mydblank_Defined','first_name','') ");

INSERT INTO `TemplateQuery` (`tplt_id`, `tplq_query`) VALUES ('54ab1802ca90b', 'CREATE TABLE `z_prefixRuleName_ebet_attendees_001_source` (
  `id_prefixRuleName_ebet_attendees_001_source` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  `last_name` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  `first_name` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_prefixRuleName_ebet_attendees_001_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8;');

INSERT INTO `TemplateQuery` (`tplt_id`, `tplq_query`) VALUES ('54ab1802ca90b', 'CREATE TABLE `z_prefixRuleName_ebet_attendees_001_target` (
  `id_prefixRuleName_ebet_attendees_001_target` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `EmailAddress` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  `SubscriberKey` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  `Full_mydblank_Name` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  `User_mydblank_Defined` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_prefixRuleName_ebet_attendees_001_target`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8;');

INSERT INTO `TemplateQuery` (`tplt_id`, `tplq_query`) VALUES ('54ab1802ca90b', 'CREATE TABLE `z_prefixRuleName_ebet_attendees_001_history` (
  `id_prefixRuleName_ebet_attendees_001_history` varchar(255) COLLATE utf8_unicode_ci NOT NULL DEFAULT '',
  `EmailAddress` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  `SubscriberKey` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  `Full_mydblank_Name` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  `User_mydblank_Defined` varchar(684) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_prefixRuleName_ebet_attendees_001_history`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8;');
