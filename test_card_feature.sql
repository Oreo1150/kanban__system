-- Testing Script for Card Feature
-- Run this to verify the card columns were added correctly

-- Check bom_detail structure
DESCRIBE kanban_system.bom_detail;

-- Sample query to see card data
SELECT 
    bd.bom_detail_id,
    bd.bom_id,
    bd.material_id,
    bd.quantity_per_unit,
    bd.card_color,
    bd.quantity_per_card,
    bd.unit,
    m.material_name
FROM bom_detail bd
JOIN materials m ON bd.material_id = m.material_id
LIMIT 10;

-- Check if columns exist
SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_NAME = 'bom_detail' AND TABLE_SCHEMA = 'kanban_system';
