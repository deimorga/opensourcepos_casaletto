ALTER TABLE `ospos_cash_up` ADD COLUMN `status` VARCHAR(10) NOT NULL DEFAULT 'open' AFTER `cashup_id`;
UPDATE `ospos_cash_up` SET `status` = 'closed' WHERE closed_amount_cash != 0 OR closed_amount_due != 0 OR closed_amount_card != 0 OR closed_amount_check != 0;
