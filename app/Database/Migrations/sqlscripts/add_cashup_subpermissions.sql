INSERT INTO `ospos_permissions` (`permission_id`, `module_id`) VALUES ('cashups_delete', 'cashups');
INSERT INTO `ospos_permissions` (`permission_id`, `module_id`) VALUES ('cashups_reopen', 'cashups');
INSERT INTO `ospos_grants` (`permission_id`, `person_id`, `menu_group`) VALUES ('cashups_delete', 1, '--');
INSERT INTO `ospos_grants` (`permission_id`, `person_id`, `menu_group`) VALUES ('cashups_reopen', 1, '--');
