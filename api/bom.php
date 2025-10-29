<?php
// api/bom.php - Updated version
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ไม่ได้รับอนุญาต']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_materials':
            // สำหรับหน้าเบิกวัสดุ - โหลดวัสดุจาก BOM พร้อมคำนวณจำนวน
            $product_id = (int)($_GET['product_id'] ?? 0);
            $quantity = (float)($_GET['quantity'] ?? 1);
            
            if ($product_id <= 0) {
                throw new Exception('กรุณาระบุ Product ID');
            }
            
            // ดึงข้อมูล BOM
            $bom_query = "
                SELECT 
                    bd.material_id,
                    bd.quantity_per_unit,
                    m.part_code,
                    m.material_name,
                    m.description,
                    m.unit,
                    m.current_stock,
                    m.min_stock,
                    m.max_stock,
                    m.location
                FROM bom_detail bd
                JOIN bom_header bh ON bd.bom_id = bh.bom_id
                JOIN materials m ON bd.material_id = m.material_id
                WHERE bh.product_id = ? 
                    AND bh.status = 'active'
                    AND m.status = 'active'
                ORDER BY m.material_name
            ";
            
            $stmt = $db->prepare($bom_query);
            $stmt->execute([$product_id]);
            $bom_items = $stmt->fetchAll();
            
            if (empty($bom_items)) {
                echo json_encode([
                    'success' => false,
                    'message' => 'ไม่พบข้อมูล BOM สำหรับสินค้านี้',
                    'materials' => []
                ]);
                exit();
            }
            
            // คำนวณจำนวนวัสดุที่ต้องใช้
            $materials = [];
            $insufficient_count = 0;
            
            foreach ($bom_items as $item) {
                $required_quantity = $item['quantity_per_unit'] * $quantity;
                $is_sufficient = $item['current_stock'] >= $required_quantity;
                
                if (!$is_sufficient) {
                    $insufficient_count++;
                }
                
                $materials[] = [
                    'material_id' => (int)$item['material_id'],
                    'part_code' => $item['part_code'],
                    'material_name' => $item['material_name'],
                    'description' => $item['description'],
                    'unit' => $item['unit'],
                    'quantity_per_unit' => (float)$item['quantity_per_unit'],
                    'required_quantity' => round($required_quantity, 2),
                    'current_stock' => (float)$item['current_stock'],
                    'min_stock' => (float)$item['min_stock'],
                    'max_stock' => (float)$item['max_stock'],
                    'location' => $item['location'],
                    'is_sufficient' => $is_sufficient,
                    'shortage' => !$is_sufficient ? round($required_quantity - $item['current_stock'], 2) : 0
                ];
            }
            
            echo json_encode([
                'success' => true,
                'materials' => $materials,
                'total_items' => count($materials),
                'insufficient_count' => $insufficient_count,
                'all_sufficient' => $insufficient_count === 0,
                'product_id' => $product_id,
                'production_quantity' => $quantity
            ]);
            break;
            
        case 'calculate':
            $product_id = (int)($_GET['product_id'] ?? 0);
            $quantity = (float)($_GET['quantity'] ?? 1);
            
            if ($product_id <= 0 || $quantity <= 0) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            
            // ดึงข้อมูล BOM
            $bom_query = "
                SELECT bh.bom_id, bd.*, m.part_code, m.material_name, m.unit, m.current_stock, m.min_stock
                FROM bom_header bh
                JOIN bom_detail bd ON bh.bom_id = bd.bom_id
                JOIN materials m ON bd.material_id = m.material_id
                WHERE bh.product_id = ? AND bh.status = 'active' AND m.status = 'active'
                ORDER BY m.part_code
            ";
            
            $stmt = $db->prepare($bom_query);
            $stmt->execute([$product_id]);
            $bom_items = $stmt->fetchAll();
            
            if (empty($bom_items)) {
                throw new Exception('ไม่พบ BOM สำหรับสินค้านี้');
            }
            
            $materials = [];
            foreach ($bom_items as $item) {
                $required_quantity = $item['quantity_per_unit'] * $quantity;
                
                $materials[] = [
                    'material_id' => (int)$item['material_id'],
                    'part_code' => $item['part_code'],
                    'material_name' => $item['material_name'],
                    'unit' => $item['unit'],
                    'quantity_per_unit' => (float)$item['quantity_per_unit'],
                    'required_quantity' => round($required_quantity, 2),
                    'current_stock' => (float)$item['current_stock'],
                    'min_stock' => (float)$item['min_stock'],
                    'sufficient' => $item['current_stock'] >= $required_quantity
                ];
            }
            
            echo json_encode([
                'success' => true,
                'materials' => $materials,
                'total_items' => count($materials),
                'calculation_date' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'get_bom':
            $product_id = (int)($_GET['product_id'] ?? 0);
            
            if (!$product_id) {
                throw new Exception('กรุณาระบุ Product ID');
            }
            
            $bom_query = "
                SELECT bh.*, p.product_name, p.product_code
                FROM bom_header bh
                JOIN products p ON bh.product_id = p.product_id
                WHERE bh.product_id = ? AND bh.status = 'active'
                ORDER BY bh.version DESC
                LIMIT 1
            ";
            
            $stmt = $db->prepare($bom_query);
            $stmt->execute([$product_id]);
            $bom = $stmt->fetch();
            
            if (!$bom) {
                throw new Exception('ไม่พบ BOM สำหรับสินค้านี้');
            }
            
            // ดึงรายละเอียด BOM
            $detail_query = "
                SELECT bd.*, m.part_code, m.material_name, m.unit, m.current_stock
                FROM bom_detail bd
                JOIN materials m ON bd.material_id = m.material_id
                WHERE bd.bom_id = ?
                ORDER BY m.part_code
            ";
            
            $stmt = $db->prepare($detail_query);
            $stmt->execute([$bom['bom_id']]);
            $bom['details'] = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'bom' => $bom]);
            break;
            
        case 'get_bom_header':
            $product_id = (int)($_GET['product_id'] ?? 0);
            
            if (!$product_id) {
                throw new Exception('กรุณาระบุ Product ID');
            }
            
            $query = "
                SELECT bh.*, p.product_name, p.product_code
                FROM bom_header bh
                JOIN products p ON bh.product_id = p.product_id
                WHERE bh.product_id = ? AND bh.status = 'active'
                ORDER BY bh.version DESC
                LIMIT 1
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$product_id]);
            $bom = $stmt->fetch();
            
            if (!$bom) {
                throw new Exception('ไม่พบข้อมูล BOM');
            }
            
            echo json_encode(['success' => true, 'bom' => $bom]);
            break;
            
        case 'check_stock_availability':
            $product_id = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
            $quantity = (float)($_POST['quantity'] ?? $_GET['quantity'] ?? 0);
            
            if (!$product_id || !$quantity) {
                throw new Exception('กรุณาระบุ Product ID และจำนวน');
            }
            
            // ดึงข้อมูล BOM และตรวจสอบสต็อก
            $query = "
                SELECT 
                    m.material_id,
                    m.part_code,
                    m.material_name,
                    m.current_stock,
                    bd.quantity_per_unit,
                    (bd.quantity_per_unit * ?) as required_quantity
                FROM bom_detail bd
                JOIN bom_header bh ON bd.bom_id = bh.bom_id
                JOIN materials m ON bd.material_id = m.material_id
                WHERE bh.product_id = ? 
                    AND bh.status = 'active'
                    AND m.status = 'active'
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$quantity, $product_id]);
            $items = $stmt->fetchAll();
            
            if (empty($items)) {
                throw new Exception('ไม่พบข้อมูล BOM');
            }
            
            $insufficient = [];
            $sufficient = [];
            
            foreach ($items as $item) {
                if ($item['current_stock'] < $item['required_quantity']) {
                    $insufficient[] = [
                        'material_id' => (int)$item['material_id'],
                        'part_code' => $item['part_code'],
                        'material_name' => $item['material_name'],
                        'current_stock' => (float)$item['current_stock'],
                        'required' => (float)$item['required_quantity'],
                        'shortage' => round($item['required_quantity'] - $item['current_stock'], 2)
                    ];
                } else {
                    $sufficient[] = [
                        'material_id' => (int)$item['material_id'],
                        'part_code' => $item['part_code'],
                        'material_name' => $item['material_name']
                    ];
                }
            }
            
            echo json_encode([
                'success' => true,
                'is_available' => empty($insufficient),
                'sufficient' => $sufficient,
                'insufficient' => $insufficient,
                'total_items' => count($items),
                'sufficient_count' => count($sufficient),
                'insufficient_count' => count($insufficient)
            ]);
            break;
            
        case 'create':
            checkRole(['admin', 'planning']);
            
            $product_id = (int)($_POST['product_id'] ?? 0);
            $version = sanitize($_POST['version'] ?? '1.0');
            $materials = json_decode($_POST['materials'] ?? '[]', true);
            
            if (!$product_id) {
                throw new Exception('กรุณาระบุ Product ID');
            }
            
            if (empty($materials)) {
                throw new Exception('กรุณาเพิ่มวัสดุในรายการ BOM');
            }
            
            $db->beginTransaction();
            
            // สร้าง BOM Header
            $header_query = "INSERT INTO bom_header (product_id, version, created_by) VALUES (?, ?, ?)";
            $stmt = $db->prepare($header_query);
            $stmt->execute([$product_id, $version, $_SESSION['user_id']]);
            $bom_id = $db->lastInsertId();
            
            // สร้าง BOM Details
            foreach ($materials as $material) {
                $detail_query = "INSERT INTO bom_detail (bom_id, material_id, quantity_per_unit, unit) VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($detail_query);
                $stmt->execute([
                    $bom_id,
                    $material['material_id'],
                    $material['quantity_per_unit'],
                    $material['unit'] ?? null
                ]);
            }
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'สร้าง BOM เรียบร้อยแล้ว', 'bom_id' => $bom_id]);
            break;
            
        case 'update':
            checkRole(['admin', 'planning']);
            
            $bom_id = (int)($_POST['bom_id'] ?? 0);
            $materials = json_decode($_POST['materials'] ?? '[]', true);
            
            if (!$bom_id) {
                throw new Exception('กรุณาระบุ BOM ID');
            }
            
            if (empty($materials)) {
                throw new Exception('กรุณาเพิ่มวัสดุในรายการ BOM');
            }
            
            $db->beginTransaction();
            
            // ลบรายการเดิม
            $delete_query = "DELETE FROM bom_detail WHERE bom_id = ?";
            $stmt = $db->prepare($delete_query);
            $stmt->execute([$bom_id]);
            
            // เพิ่มรายการใหม่
            foreach ($materials as $material) {
                $detail_query = "INSERT INTO bom_detail (bom_id, material_id, quantity_per_unit, unit) VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($detail_query);
                $stmt->execute([
                    $bom_id,
                    $material['material_id'],
                    $material['quantity_per_unit'],
                    $material['unit'] ?? null
                ]);
            }
            
            // อัพเดทวันที่แก้ไข
            $update_query = "UPDATE bom_header SET updated_at = CURRENT_TIMESTAMP WHERE bom_id = ?";
            $stmt = $db->prepare($update_query);
            $stmt->execute([$bom_id]);
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'อัพเดท BOM เรียบร้อยแล้ว']);
            break;
            
        case 'get_all':
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $search = $_GET['search'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = ["bh.status = 'active'"];
            $params = [];
            
            if (!empty($search)) {
                $where[] = "(p.product_name LIKE ? OR p.product_code LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Count total
            $count_query = "
                SELECT COUNT(DISTINCT bh.bom_id) as total
                FROM bom_header bh
                JOIN products p ON bh.product_id = p.product_id
                WHERE $whereClause
            ";
            $stmt = $db->prepare($count_query);
            $stmt->execute($params);
            $total = $stmt->fetch()['total'];
            
            // Get BOMs
            $query = "
                SELECT bh.*, p.product_name, p.product_code, u.full_name as created_by_name,
                       COUNT(bd.bom_detail_id) as material_count
                FROM bom_header bh
                JOIN products p ON bh.product_id = p.product_id
                LEFT JOIN users u ON bh.created_by = u.user_id
                LEFT JOIN bom_detail bd ON bh.bom_id = bd.bom_id
                WHERE $whereClause
                GROUP BY bh.bom_id
                ORDER BY bh.created_at DESC
                LIMIT $limit OFFSET $offset
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $boms = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'boms' => $boms,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        case 'copy':
            checkRole(['admin', 'planning']);
            
            $source_bom_id = (int)($_POST['source_bom_id'] ?? 0);
            $target_product_id = (int)($_POST['target_product_id'] ?? 0);
            $new_version = sanitize($_POST['new_version'] ?? '1.0');
            
            if (!$source_bom_id || !$target_product_id) {
                throw new Exception('กรุณาระบุ BOM ต้นฉบับและสินค้าปลายทาง');
            }
            
            $db->beginTransaction();
            
            // ดึงข้อมูล BOM ต้นฉบับ
            $source_query = "SELECT * FROM bom_detail WHERE bom_id = ?";
            $stmt = $db->prepare($source_query);
            $stmt->execute([$source_bom_id]);
            $source_details = $stmt->fetchAll();
            
            if (empty($source_details)) {
                throw new Exception('ไม่พบข้อมูล BOM ต้นฉบับ');
            }
            
            // สร้าง BOM ใหม่
            $header_query = "INSERT INTO bom_header (product_id, version, created_by) VALUES (?, ?, ?)";
            $stmt = $db->prepare($header_query);
            $stmt->execute([$target_product_id, $new_version, $_SESSION['user_id']]);
            $new_bom_id = $db->lastInsertId();
            
            // คัดลอกรายการวัสดุ
            foreach ($source_details as $detail) {
                $detail_query = "INSERT INTO bom_detail (bom_id, material_id, quantity_per_unit, unit) VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($detail_query);
                $stmt->execute([
                    $new_bom_id,
                    $detail['material_id'],
                    $detail['quantity_per_unit'],
                    $detail['unit']
                ]);
            }
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'คัดลอก BOM เรียบร้อยแล้ว', 'new_bom_id' => $new_bom_id]);
            break;
            
        case 'delete':
            checkRole(['admin', 'planning']);
            
            $bom_id = (int)($_POST['bom_id'] ?? 0);
            
            if (!$bom_id) {
                throw new Exception('กรุณาระบุ BOM ID');
            }
            
            // Soft delete - เปลี่ยนสถานะเป็น inactive
            $query = "UPDATE bom_header SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE bom_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$bom_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('ไม่พบ BOM ที่ต้องการลบ');
            }
            
            echo json_encode(['success' => true, 'message' => 'ลบ BOM เรียบร้อยแล้ว']);
            break;
            
        default:
            throw new Exception('ไม่พบการกระทำที่ระบุ: ' . $action);
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}