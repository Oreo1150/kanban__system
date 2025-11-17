<?php
// api/products.php
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

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_all':
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? 'active';
            
            $where = [];
            $params = [];
            
            if ($status !== 'all') {
                $where[] = "status = ?";
                $params[] = $status;
            }
            
            if (!empty($search)) {
                $where[] = "(product_code LIKE ? OR product_name LIKE ?)";
                $searchTerm = "%$search%";
                $params = array_merge($params, [$searchTerm, $searchTerm]);
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $query = "SELECT p.*,
                             (SELECT COUNT(*) FROM bom_header bh WHERE bh.product_id = p.product_id AND bh.status = 'active') as has_bom
                      FROM products p 
                      $whereClause
                      ORDER BY p.product_name";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $products = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'products' => $products
            ]);
            break;
            
        case 'get':
            $product_id = (int)($_GET['id'] ?? 0);
            
            if (!$product_id) {
                throw new Exception('กรุณาระบุ Product ID');
            }
            
            $query = "SELECT * FROM products WHERE product_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();
            
            if (!$product) {
                throw new Exception('ไม่พบสินค้าที่ระบุ');
            }
            
            echo json_encode(['success' => true, 'product' => $product]);
            break;
            
        case 'create':
            checkRole(['admin', 'planning']);
            
            $data = [
                'product_code' => sanitize($_POST['product_code']),
                'product_name' => sanitize($_POST['product_name']),
                'description' => sanitize($_POST['description'] ?? '')
            ];
            
            // Validate required fields
            if (empty($data['product_code']) || empty($data['product_name'])) {
                throw new Exception('กรุณากรอกรหัสและชื่อสินค้า');
            }
            
            // Check if product_code already exists
            $checkQuery = "SELECT product_id FROM products WHERE product_code = ? AND status = 'active'";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([$data['product_code']]);
            
            if ($checkStmt->rowCount() > 0) {
                throw new Exception('รหัสสินค้านี้มีอยู่แล้ว');
            }
            
            // Insert
            $query = "INSERT INTO products (product_code, product_name, description, status, created_at) 
                      VALUES (?, ?, ?, 'active', NOW())";
            $stmt = $db->prepare($query);
            $stmt->execute([
                $data['product_code'],
                $data['product_name'],
                $data['description']
            ]);
            
            $product_id = $db->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'เพิ่มสินค้าสำเร็จ',
                'product_id' => $product_id
            ]);
            break;
            
        case 'update':
            checkRole(['admin', 'planning']);
            
            $product_id = (int)$_POST['product_id'];
            
            if (!$product_id) {
                throw new Exception('กรุณาระบุ Product ID');
            }
            
            $data = [
                'product_name' => sanitize($_POST['product_name']),
                'description' => sanitize($_POST['description'] ?? '')
            ];
            
            // Validate
            if (empty($data['product_name'])) {
                throw new Exception('กรุณากรอกชื่อสินค้า');
            }
            
            // Update
            $query = "UPDATE products 
                      SET product_name = ?, description = ?, updated_at = NOW()
                      WHERE product_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([
                $data['product_name'],
                $data['description'],
                $product_id
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'แก้ไขสินค้าสำเร็จ'
            ]);
            break;
            
        case 'delete':
            checkRole(['admin', 'planning']);
            
            $product_id = (int)$_POST['product_id'];
            
            if (!$product_id) {
                throw new Exception('กรุณาระบุ Product ID');
            }
            
            // Check if product has active BOM
            $bomCheck = $db->prepare("SELECT bom_id FROM bom_header WHERE product_id = ? AND status = 'active'");
            $bomCheck->execute([$product_id]);
            
            if ($bomCheck->rowCount() > 0) {
                throw new Exception('ไม่สามารถลบสินค้าได้ เนื่องจากมี BOM ที่ใช้งานอยู่ กรุณาลบ BOM ก่อน');
            }
            
            // Check if product is used in production jobs
            $jobCheck = $db->prepare("SELECT job_id FROM production_jobs WHERE product_id = ? LIMIT 1");
            $jobCheck->execute([$product_id]);
            
            if ($jobCheck->rowCount() > 0) {
                // Soft delete if used in production
                $query = "UPDATE products SET status = 'inactive', updated_at = NOW() WHERE product_id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$product_id]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'เปลี่ยนสถานะสินค้าเป็นไม่ใช้งานแล้ว (มีประวัติการผลิต)'
                ]);
            } else {
                // Hard delete if not used
                $query = "DELETE FROM products WHERE product_id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$product_id]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'ลบสินค้าออกจากระบบเรียบร้อยแล้ว'
                ]);
            }
            break;
            
        default:
            throw new Exception('ไม่พบการกระทำที่ระบุ: ' . htmlspecialchars($action));
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}