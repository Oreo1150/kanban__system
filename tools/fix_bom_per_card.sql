-- tools/fix_bom_per_card.sql
-- SQL batch to preview and fix suspicious BOM "quantity_per_card" values
-- IMPORTANT: Backup your database or the `bom_detail` table before running these updates.
-- Example backup (mysqldump):
-- mysqldump -u root -p kanban_system bom_detail > bom_detail_backup.sql

-- 1) Preview rows where quantity_per_card = 1 but quantity_per_unit > 1
SELECT bh.product_id, bh.bom_id, bd.material_id, m.part_code, m.material_name,
       bd.quantity_per_unit, bd.quantity_per_card, bd.card_color
FROM bom_detail bd
JOIN bom_header bh ON bd.bom_id = bh.bom_id
JOIN materials m ON bd.material_id = m.material_id
WHERE bd.quantity_per_card = 1 AND bd.quantity_per_unit > 1
ORDER BY bh.product_id, bd.material_id;

-- 2) Preview rows where quantity_per_card > quantity_per_unit (likely incorrect)
SELECT bh.product_id, bh.bom_id, bd.material_id, m.part_code, m.material_name,
       bd.quantity_per_unit, bd.quantity_per_card, bd.card_color
FROM bom_detail bd
JOIN bom_header bh ON bd.bom_id = bh.bom_id
JOIN materials m ON bd.material_id = m.material_id
WHERE bd.quantity_per_card > bd.quantity_per_unit
ORDER BY bh.product_id, bd.material_id;

-- If the previews look correct and you want to apply the automated fixes, run the statements below inside a transaction.
-- NOTE: These updates follow a conservative heuristic: set quantity_per_card = quantity_per_unit when current quantity_per_card is 1
-- (often used as a placeholder) or when quantity_per_card > quantity_per_unit.

START TRANSACTION;

-- A) Set per_card = per_unit when per_card = 1 and per_unit > 1
UPDATE bom_detail bd
JOIN bom_header bh ON bd.bom_id = bh.bom_id
SET bd.quantity_per_card = bd.quantity_per_unit
WHERE bd.quantity_per_card = 1 AND bd.quantity_per_unit > 1;

-- B) Fix rows where per_card > per_unit by setting per_card = per_unit
UPDATE bom_detail bd
JOIN bom_header bh ON bd.bom_id = bh.bom_id
SET bd.quantity_per_card = bd.quantity_per_unit
WHERE bd.quantity_per_card > bd.quantity_per_unit;

-- C) Optional: normalize types (ensure integer)
UPDATE bom_detail
SET quantity_per_card = CAST(quantity_per_card AS SIGNED),
    quantity_per_unit = CAST(quantity_per_unit AS DECIMAL(10,4));

-- D) Verify results
SELECT bh.product_id, bh.bom_id, bd.material_id, m.part_code, m.material_name,
       bd.quantity_per_unit, bd.quantity_per_card
FROM bom_detail bd
JOIN bom_header bh ON bd.bom_id = bh.bom_id
JOIN materials m ON bd.material_id = m.material_id
WHERE bh.product_id IN (
    SELECT DISTINCT product_id FROM bom_header WHERE status = 'active'
)
ORDER BY bh.product_id, bd.material_id;

-- If everything looks good, commit. Otherwise rollback.
-- COMMIT;
-- ROLLBACK;

-- If you need to update a single known row (example for Wood-01):
-- UPDATE bom_detail SET quantity_per_card = 4 WHERE bom_id = 13 AND material_id = 8;

-- End of file
