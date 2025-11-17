<?php
// api/units.php
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
                $where[] = "(unit_code LIKE ? OR unit_name LIKE ? OR unit_name_en LIKE ?)";
                $searchTerm = "%$search%";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
            }
            
            $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $query = "SELECT u.*, 
                             (SELECT COUNT(*) FROM materials m 
                              WHERE m.unit COLLATE utf8mb4_general_ci = u.unit_code COLLATE utf8mb4_general_ci 
                              AND m.status = 'active') as material_count
                      FROM units u 
                      $whereClause
                      ORDER BY u.unit_name";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $units = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'units' => $units
            ]);
            break;
            
        case 'get':
            $unit_id = (int)($_GET['id'] ?? 0);
            
            if (!$unit_id) {
                throw new Exception('กรุณาระบุ Unit ID');
            }
            
            $query = "SELECT * FROM units WHERE unit_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$unit_id]);
            $unit = $stmt->fetch();
            
            if (!$unit) {
                throw new Exception('ไม่พบหน่วยนับที่ระบุ');
            }
            
            echo json_encode(['success' => true, 'unit' => $unit]);
            break;
            
        case 'create':
            checkRole(['admin']);
            
            $data = [
                'unit_code' => strtolower(sanitize($_POST['unit_code'])),
                'unit_name' => sanitize($_POST['unit_name']),
                'unit_name_en' => sanitize($_POST['unit_name_en'] ?? ''),
                'description' => sanitize($_POST['description'] ?? '')
            ];
            
            // Validate required fields
            if (empty($data['unit_code']) || empty($data['unit_name'])) {
                throw new Exception('กรุณากรอกรหัสและชื่อหน่วยนับ');
            }
            
            // Check if unit_code already exists (only active units)
            $checkQuery = "SELECT unit_id FROM units WHERE unit_code = ? AND status = 'active'";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([$data['unit_code']]);
            
            if ($checkStmt->rowCount() > 0) {
                throw new Exception('รหัสหน่วยนับนี้มีอยู่แล้ว');
            }
            
            // Insert
            $query = "INSERT INTO units (unit_code, unit_name, unit_name_en, description, created_by, status) 
                      VALUES (?, ?, ?, ?, ?, 'active')";
            $stmt = $db->prepare($query);
            $stmt->execute([
                $data['unit_code'],
                $data['unit_name'],
                $data['unit_name_en'],
                $data['description'],
                $_SESSION['user_id']
            ]);
            
            $unit_id = $db->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'เพิ่มหน่วยนับสำเร็จ',
                'unit_id' => $unit_id
            ]);
            break;
            
        case 'update':
            checkRole(['admin']);
            
            $unit_id = (int)$_POST['unit_id'];
            
            if (!$unit_id) {
                throw new Exception('กรุณาระบุ Unit ID');
            }
            
            $data = [
                'unit_name' => sanitize($_POST['unit_name']),
                'unit_name_en' => sanitize($_POST['unit_name_en'] ?? ''),
                'description' => sanitize($_POST['description'] ?? ''),
                'status' => sanitize($_POST['status'] ?? 'active')
            ];
            
            // Validate
            if (empty($data['unit_name'])) {
                throw new Exception('กรุณากรอกชื่อหน่วยนับ');
            }
            
            // Get old data
            $oldQuery = "SELECT * FROM units WHERE unit_id = ?";
            $oldStmt = $db->prepare($oldQuery);
            $oldStmt->execute([$unit_id]);
            $oldData = $oldStmt->fetch();
            
            if (!$oldData) {
                throw new Exception('ไม่พบหน่วยนับที่ต้องการแก้ไข');
            }
            
            // Update (ไม่ให้แก้ไข unit_code เพราะอาจมีการใช้งานในวัสดุแล้ว)
            $query = "UPDATE units 
                      SET unit_name = ?, unit_name_en = ?, description = ?, status = ?
                      WHERE unit_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([
                $data['unit_name'],
                $data['unit_name_en'],
                $data['description'],
                $data['status'],
                $unit_id
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'แก้ไขหน่วยนับสำเร็จ'
            ]);
            break;
            
        case 'delete':
            checkRole(['admin']);
            
            $unit_id = (int)$_POST['unit_id'];
            
            if (!$unit_id) {
                throw new Exception('กรุณาระบุ Unit ID');
            }
            
            // Get unit data
            $unitQuery = "SELECT u.*, 
                                 (SELECT COUNT(*) FROM materials m WHERE m.unit = u.unit_code AND m.status = 'active') as material_count
                          FROM units u 
                          WHERE u.unit_id = ?";
            $unitStmt = $db->prepare($unitQuery);
            $unitStmt->execute([$unit_id]);
            $unit = $unitStmt->fetch();
            
            if (!$unit) {
                throw new Exception('ไม่พบหน่วยนับที่ต้องการลบ');
            }
            
            // Check if unit is being used
            if ($unit['material_count'] > 0) {
                throw new Exception('ไม่สามารถลบได้ เนื่องจากมีวัสดุ ' . $unit['material_count'] . ' รายการที่ใช้หน่วยนับนี้อยู่');
            }
            
            // Soft delete
            $query = "UPDATE units SET status = 'inactive' WHERE unit_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$unit_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'ลบหน่วยนับสำเร็จ'
            ]);
            break;
            
        case 'restore':
            checkRole(['admin']);
            
            $unit_id = (int)$_POST['unit_id'];
            
            if (!$unit_id) {
                throw new Exception('กรุณาระบุ Unit ID');
            }
            
            // Get unit
            $unitQuery = "SELECT * FROM units WHERE unit_id = ? AND status = 'inactive'";
            $unitStmt = $db->prepare($unitQuery);
            $unitStmt->execute([$unit_id]);
            $unit = $unitStmt->fetch();
            
            if (!$unit) {
                throw new Exception('ไม่พบหน่วยนับที่ต้องการกู้คืน');
            }
            
            // Check if code is available
            $checkQuery = "SELECT unit_id FROM units WHERE unit_code = ? AND status = 'active'";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([$unit['unit_code']]);
            
            if ($checkStmt->rowCount() > 0) {
                throw new Exception('ไม่สามารถกู้คืนได้ เนื่องจากมีหน่วยนับรหัส ' . $unit['unit_code'] . ' อยู่แล้ว');
            }
            
            // Restore
            $query = "UPDATE units SET status = 'active' WHERE unit_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$unit_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'กู้คืนหน่วยนับสำเร็จ'
            ]);
            break;
            
        case 'check_usage':
            $unit_code = sanitize($_GET['unit_code'] ?? '');
            
            if (empty($unit_code)) {
                throw new Exception('กรุณาระบุรหัสหน่วยนับ');
            }
            
            // Get materials using this unit
            $query = "SELECT material_id, part_code, material_name 
                      FROM materials 
                      WHERE unit COLLATE utf8mb4_general_ci = ? 
                      AND status = 'active'
                      LIMIT 10";
            $stmt = $db->prepare($query);
            $stmt->execute([$unit_code]);
            $materials = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'count' => count($materials),
                'materials' => $materials
            ]);
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