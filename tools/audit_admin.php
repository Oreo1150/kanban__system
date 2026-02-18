<?php
// tools/audit_admin.php
// Usage: http://localhost/kanban-system/tools/audit_admin.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$db = $database->getConnection();

$result = [ 'success' => true, 'issues' => [] ];

// 1) Find duplicate bom_detail rows for same bom_id + material_id
try {
    $q = "SELECT bh.product_id, bd.bom_id, bd.material_id, m.part_code, COUNT(*) as cnt
          FROM bom_detail bd
          JOIN bom_header bh ON bd.bom_id = bh.bom_id
          JOIN materials m ON bd.material_id = m.material_id
          GROUP BY bd.bom_id, bd.material_id
          HAVING cnt > 1";
    $dup = $db->query($q)->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($dup)) {
        $result['issues']['duplicate_bom_detail'] = $dup;
    }
} catch (Exception $e) {
    $result['issues']['duplicate_bom_detail_error'] = $e->getMessage();
}

// 2) Find bom_detail where quantity_per_card is 1 but quantity_per_unit > 1 (suspicious)
try {
    $q = "SELECT bh.product_id, bd.bom_id, bd.material_id, m.part_code, bd.quantity_per_unit, bd.quantity_per_card
          FROM bom_detail bd
          JOIN bom_header bh ON bd.bom_id = bh.bom_id
          JOIN materials m ON bd.material_id = m.material_id
          WHERE bd.quantity_per_card = 1 AND bd.quantity_per_unit > 1";
    $sus = $db->query($q)->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($sus)) {
        $result['issues']['suspicious_per_card_ones'] = $sus;
    }
} catch (Exception $e) {
    $result['issues']['suspicious_per_card_error'] = $e->getMessage();
}

// 3) List BOM entries where quantity_per_card > quantity_per_unit (probably wrong)
try {
    $q = "SELECT bh.product_id, bd.bom_id, bd.material_id, m.part_code, bd.quantity_per_unit, bd.quantity_per_card
          FROM bom_detail bd
          JOIN bom_header bh ON bd.bom_id = bh.bom_id
          JOIN materials m ON bd.material_id = m.material_id
          WHERE bd.quantity_per_card > bd.quantity_per_unit";
    $bad = $db->query($q)->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($bad)) {
        $result['issues']['per_card_gt_per_unit'] = $bad;
    }
} catch (Exception $e) {
    $result['issues']['per_card_gt_per_unit_error'] = $e->getMessage();
}

// 4) Count admin files with inline onclick and console.log (static file scan)
$adminDir = __DIR__ . '/../pages/admin/';
$files = glob($adminDir . '*.php');
$onclickFiles = [];
$consoleFiles = [];
foreach ($files as $f) {
    $content = file_get_contents($f);
    if (strpos($content, 'onclick=') !== false) $onclickFiles[] = basename($f);
    if (strpos($content, 'console.log(') !== false) $consoleFiles[] = basename($f);
}
$result['issues']['admin_files_onclick'] = $onclickFiles;
$result['issues']['admin_files_console'] = $consoleFiles;

// 5) Quick PHP lint of admin files using shell php -l if available
$lintResults = [];
foreach ($files as $f) {
    $out = null; $code = null;
    // Try PHP lint
    exec("php -l " . escapeshellarg($f) . " 2>&1", $out, $code);
    $lintResults[basename($f)] = ['exit' => $code, 'output' => $out];
}
$result['lint'] = $lintResults;

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
