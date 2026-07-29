ALTER TABLE `ospos_sales` ADD COLUMN `location_id` INT(11) NULL DEFAULT NULL AFTER `sale_id`;
UPDATE `ospos_sales` SET `location_id` = (SELECT MIN(`location_id`) FROM `ospos_stock_locations`) WHERE `location_id` IS NULL;
ALTER TABLE `ospos_sales` ADD CONSTRAINT `ospos_sales_ibfk_location` FOREIGN KEY (`location_id`) REFERENCES `ospos_stock_locations` (`location_id`);

ALTER TABLE `ospos_cash_up` ADD COLUMN `location_id` INT(11) NULL DEFAULT NULL AFTER `cashup_id`;
UPDATE `ospos_cash_up` SET `location_id` = (SELECT MIN(`location_id`) FROM `ospos_stock_locations`) WHERE `location_id` IS NULL;
ALTER TABLE `ospos_cash_up` ADD CONSTRAINT `ospos_cash_up_ibfk_location` FOREIGN KEY (`location_id`) REFERENCES `ospos_stock_locations` (`location_id`);

ALTER TABLE `ospos_expenses` ADD COLUMN `location_id` INT(11) NULL DEFAULT NULL AFTER `expense_id`;
UPDATE `ospos_expenses` SET `location_id` = (SELECT MIN(`location_id`) FROM `ospos_stock_locations`) WHERE `location_id` IS NULL;
ALTER TABLE `ospos_expenses` ADD CONSTRAINT `ospos_expenses_ibfk_location` FOREIGN KEY (`location_id`) REFERENCES `ospos_stock_locations` (`location_id`);

ALTER TABLE `ospos_receivings` ADD COLUMN `location_id` INT(11) NULL DEFAULT NULL AFTER `receiving_id`;
UPDATE `ospos_receivings` SET `location_id` = (SELECT MIN(`location_id`) FROM `ospos_stock_locations`) WHERE `location_id` IS NULL;
ALTER TABLE `ospos_receivings` ADD CONSTRAINT `ospos_receivings_ibfk_location` FOREIGN KEY (`location_id`) REFERENCES `ospos_stock_locations` (`location_id`);

ALTER TABLE `ospos_dinner_tables` ADD COLUMN `location_id` INT(11) NULL DEFAULT NULL AFTER `dinner_table_id`;
UPDATE `ospos_dinner_tables` SET `location_id` = (SELECT MIN(`location_id`) FROM `ospos_stock_locations`) WHERE `location_id` IS NULL;
ALTER TABLE `ospos_dinner_tables` ADD CONSTRAINT `ospos_dinner_tables_ibfk_location` FOREIGN KEY (`location_id`) REFERENCES `ospos_stock_locations` (`location_id`);
