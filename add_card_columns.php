<?php
// Temporary script to add card columns
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // Check if columns exist first
    $check_query = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='bom_detail' AND COLUMN_NAME='card_color'";
    $stmt = $db->query($check_query);
    
    if ($stmt->rowCount() == 0) {
        // Add card_color column
        $db->exec("ALTER TABLE bom_detail ADD COLUMN card_color VARCHAR(20) DEFAULT '#3498db' AFTER quantity_per_unit");
        echo "✓ Added card_color column\n";
    } else {
        echo "✓ card_color column already exists\n";
    }
    
    // Check for quantity_per_card
    $check_query = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME='bom_detail' AND COLUMN_NAME='quantity_per_card'";
    $stmt = $db->query($check_query);
    
    if ($stmt->rowCount() == 0) {
        // Add quantity_per_card column
        $db->exec("ALTER TABLE bom_detail ADD COLUMN quantity_per_card INT DEFAULT 1 AFTER card_color");
        echo "✓ Added quantity_per_card column\n";
    } else {
        echo "✓ quantity_per_card column already exists\n";
    }
    
    // Check current structure
    echo "\nCurrent bom_detail structure:\n";
    $result = $db->query("DESCRIBE bom_detail");
    foreach ($result as $column) {
        echo "  - " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
    echo "\n✓ Database migration completed successfully!";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage();
}
?>
