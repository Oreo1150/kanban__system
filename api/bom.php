<?php
// api/bom.php - Complete Fixed Version
// คัดลอกไฟล์นี้ไปแทนที่ที่ api/bom.php
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
        // ==================== GET_MATERIALS ====================
        // ใช้สำหรับ Planning เมื่อสร้างงานและต้องการคำนวณวัสดุ
        case 'get_materials':
            $product_id = (int)($_GET['product_id'] ?? 0);
            $quantity = (float)($_GET['quantity'] ?? 1);
            
            if ($product_id <= 0) {
                throw new Exception('กรุณาระบุ Product ID');
            }
            
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
                    'message' => 'ไม่พบข้อมูล BOM สำหรับสินค้านี้  (Product ID: ' . $product_id . ')',
                    'materials' => []
                ]);
                exit();
            }
            
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
            
        // ==================== CALCULATE ====================
        // ใช้สำหรับการคำนวณวัสดุใน Planning (step 3)
        case 'calculate':
            $product_id = (int)($_GET['product_id'] ?? 0);
            $quantity = (float)($_GET['quantity'] ?? 1);
            
            if ($product_id <= 0 || $quantity <= 0) {
                throw new Exception('ข้อมูลไม่ถูกต้อง (Product ID: ' . $product_id . ', Quantity: ' . $quantity . ')');
            }
            
            // ดึง BOM พร้อมข้อมูลวัสดุ
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
                throw new Exception('ไม่พบ BOM สำหรับสินค้า ID: ' . $product_id);
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
            
        // ==================== CREATE ====================
        // สร้าง BOM ใหม่
        case 'create':
            checkRole(['admin', 'planning']);
            
            $product_id = (int)($_POST['product_id'] ?? 0);
            $product_name = sanitize($_POST['product_name'] ?? '');
            $product_code = sanitize($_POST['product_code'] ?? '');
            $version = sanitize($_POST['version'] ?? '1.0');
            $materials = json_decode($_POST['materials'] ?? '[]', true);
            
            // ตรวจสอบข้อมูล
            if (!$product_name || !$product_code) {
                throw new Exception('กรุณากรอกชื่อและรหัสสินค้า');
            }
            
            if (empty($materials) || !is_array($materials)) {
                throw new Exception('กรุณาเพิ่มวัสดุในรายการ BOM (ต้องมีอย่างน้อย 1 รายการ)');
            }
            
            $db->beginTransaction();
            
            try {
                // ถ้าไม่มี product_id ให้สร้าง Product ใหม่
                if (!$product_id) {
                    // ตรวจสอบว่ารหัสสินค้าซ้ำหรือไม่
                    $check_query = "SELECT product_id FROM products WHERE product_code = ?";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->execute([$product_code]);
                    $existing = $check_stmt->fetch();
                    
                    if ($existing) {
                        // ถ้ามีสินค้าอยู่แล้ว ใช้ product_id นั้น
                        $product_id = (int)$existing['product_id'];
                    } else {
                        // สร้าง Product ใหม่
                        $product_query = "INSERT INTO products (product_code, product_name, status, created_at) VALUES (?, ?, 'active', NOW())";
                        $product_stmt = $db->prepare($product_query);
                        $product_stmt->execute([$product_code, $product_name]);
                        $product_id = (int)$db->lastInsertId();
                    }
                }
                
                // ตรวจสอบว่า Product นี้มี BOM แบบ active อยู่แล้วหรือไม่
                $check_bom_query = "SELECT bom_id FROM bom_header WHERE product_id = ? AND status = 'active'";
                $check_bom_stmt = $db->prepare($check_bom_query);
                $check_bom_stmt->execute([$product_id]);
                $existing_bom = $check_bom_stmt->fetch();
                
                if ($existing_bom) {
                    throw new Exception('สินค้านี้มี BOM (ID: ' . $existing_bom['bom_id'] . ') ที่ใช้งานอยู่แล้ว กรุณาแก้ไข BOM เดิม หรือเปลี่ยน Version');
                }
                
                // สร้าง BOM Header
                $header_query = "INSERT INTO bom_header (product_id, version, created_by, status, created_at) VALUES (?, ?, ?, 'active', NOW())";
                $stmt = $db->prepare($header_query);
                $stmt->execute([$product_id, $version, $_SESSION['user_id']]);
                $bom_id = (int)$db->lastInsertId();
                
                // สร้าง BOM Details
                $detail_count = 0;
                foreach ($materials as $material) {
                    if (!isset($material['material_id']) || !isset($material['quantity_per_unit'])) {
                        throw new Exception('ข้อมูลวัสดุไม่ครบถ้วน');
                    }
                    
                    $detail_query = "INSERT INTO bom_detail (bom_id, material_id, quantity_per_unit, unit) VALUES (?, ?, ?, ?)";
                    $stmt = $db->prepare($detail_query);
                    $stmt->execute([
                        $bom_id,
                        (int)$material['material_id'],
                        (float)$material['quantity_per_unit'],
                        $material['unit'] ?? null
                    ]);
                    $detail_count++;
                }
                
                $db->commit();
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'สร้าง BOM เรียบร้อยแล้ว (เพิ่มวัสดุ ' . $detail_count . ' รายการ)', 
                    'bom_id' => $bom_id,
                    'product_id' => $product_id,
                    'material_count' => $detail_count
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        // ==================== GET_BOM ====================
        // ดึงข้อมูล BOM ของสินค้า
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
                throw new Exception('ไม่พบ BOM สำหรับสินค้า ID: ' . $product_id);
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
            
        // ==================== UPDATE ====================
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
            
        // ==================== DELETE ====================
        case 'delete':
            checkRole(['admin', 'planning']);
            
            $bom_id = (int)($_POST['bom_id'] ?? 0);
            
            if (!$bom_id) {
                throw new Exception('กรุณาระบุ BOM ID');
            }
            
            // Soft delete
            $query = "UPDATE bom_header SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE bom_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$bom_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('ไม่พบ BOM ที่ต้องการลบ');
            }
            
            echo json_encode(['success' => true, 'message' => 'ลบ BOM เรียบร้อยแล้ว']);
            break;
            
        default:
            throw new Exception('ไม่พบการกระทำที่ระบุ: ' . htmlspecialchars($action));
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage(),
        'action' => $action ?? 'unknown'
    ]);
}