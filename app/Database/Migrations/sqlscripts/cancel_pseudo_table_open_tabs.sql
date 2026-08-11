-- Delivery (1) and Take Away (2) are pseudo-tables, never real table tabs.
-- Every cart that started on them after a lost sale_id left behind an OPENED
-- sale that the register could never reopen nor close, piling up as identical
-- phantom "Delivery" tabs. Sales::_autosave_open_tab() no longer creates them;
-- this retires the ones already on disk by marking them CANCELED (2), the same
-- status Sales::postCancel() gives a discarded cart.
-- Scoped to dinner_table_id <= 2 on purpose: OPENED rows on real tables are
-- live, unpaid tabs and must not be touched. Idempotent -- reruns match nothing.
UPDATE `ospos_sales` SET `sale_status` = 2 WHERE `sale_status` = 3 AND `dinner_table_id` <= 2;
