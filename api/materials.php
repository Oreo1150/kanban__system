<?php
// api/materials.php - แก้ไขให้รองรับการอัปเดตสต็อกปัจจุบัน
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
        case 'get_by_code':
            $code = $_GET['code'] ?? '';
            if (empty($code)) {
                throw new Exception('กรุณาระบุรหัสวัสดุ');
            }
            
            $query = "SELECT * FROM materials WHERE part_code = ? AND status = 'active'";
            $stmt = $db->prepare($query);
            $stmt->execute([$code]);
            $material = $stmt->fetch();
            
            if (!$material) {
                throw new Exception('ไม่พบวัสดุที่มีรหัส: ' . $code);
            }
            
            // Convert numeric values
            $material['current_stock'] = (int)$material['current_stock'];
            $material['min_stock'] = (int)$material['min_stock'];
            $material['max_stock'] = (int)$material['max_stock'];
            
            echo json_encode(['success' => true, 'material' => $material]);
            break;
            
        case 'get_by_id':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('รหัสวัสดุไม่ถูกต้อง');
            }
            
            $query = "SELECT * FROM materials WHERE material_id = ? AND status = 'active'";
            $stmt = $db->prepare($query);
            $stmt->execute([$id]);
            $material = $stmt->fetch();
            
            if (!$material) {
                throw new Exception('ไม่พบวัสดุ');
            }
            
            echo json_encode(['success' => true, 'material' => $material]);
            break;
            
        case 'get_all':
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $search = $_GET['search'] ?? '';
            $filter = $_GET['filter'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = ["status = 'active'"];
            $params = [];
            
            if (!empty($search)) {
                $where[] = "(part_code LIKE ? OR material_name LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            if ($filter === 'low_stock') {
                $where[] = "current_stock <= min_stock";
            } elseif ($filter === 'overstock') {
                $where[] = "current_stock > max_stock";
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM materials WHERE $whereClause";
            $countStmt = $db->prepare($countQuery);
            $countStmt->execute($params);
            $total = $countStmt->fetch()['total'];
            
            // Get materials
            $query = "SELECT * FROM materials WHERE $whereClause ORDER BY material_name LIMIT $limit OFFSET $offset";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $materials = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'materials' => $materials,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        case 'create':
            checkRole(['admin']);
            
            $data = [
                'part_code' => sanitize($_POST['part_code']),
                'material_name' => sanitize($_POST['material_name']),
                'description' => sanitize($_POST['description']),
                'unit' => sanitize($_POST['unit']),
                'min_stock' => (int)$_POST['min_stock'],
                'max_stock' => (int)$_POST['max_stock'],
                'current_stock' => (int)($_POST['current_stock'] ?? 0),
                'location' => sanitize($_POST['location'])
            ];
            
            // Validate required fields
            if (empty($data['part_code']) || empty($data['material_name']) || empty($data['unit'])) {
                throw new Exception('กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน');
            }
            
            // Check if part_code already exists (only active materials)
            $checkQuery = "SELECT material_id FROM materials WHERE part_code = ? AND status = 'active'";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([$data['part_code']]);
            if ($checkStmt->rowCount() > 0) {
                throw new Exception('รหัสวัสดุนี้มีอยู่แล้ว');
            }
            
            $db->beginTransaction();
            
            try {
                // Generate QR Code
                $qr_data = json_encode(['part_code' => $data['part_code'], 'type' => 'material']);
                $data['qr_code'] = $qr_data;
                
                // Insert
                $query = "INSERT INTO materials (part_code, material_name, description, unit, min_stock, max_stock, current_stock, location, qr_code, status) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')";
                $stmt = $db->prepare($query);
                $stmt->execute([
                    $data['part_code'], $data['material_name'], $data['description'], 
                    $data['unit'], $data['min_stock'], $data['max_stock'], 
                    $data['current_stock'], $data['location'], $data['qr_code']
                ]);
                
                $material_id = $db->lastInsertId();
                
                // Record initial stock if > 0
                if ($data['current_stock'] > 0) {
                    $transactionQuery = "INSERT INTO inventory_transactions 
                                        (material_id, transaction_type, quantity, reference_type, reference_id, 
                                         previous_stock, current_stock, transaction_by, notes) 
                                        VALUES (?, 'in', ?, 'initial', NULL, 0, ?, ?, ?)";
                    $transactionStmt = $db->prepare($transactionQuery);
                    $transactionStmt->execute([
                        $material_id,
                        $data['current_stock'],
                        $data['current_stock'],
                        $_SESSION['user_id'],
                        'สต็อกเริ่มต้น'
                    ]);
                }
                
                // Log audit
                logAudit($db, $_SESSION['user_id'], 'create', 'materials', $material_id, null, $data);
                
                $db->commit();
                
                echo json_encode(['success' => true, 'message' => 'เพิ่มวัสดุสำเร็จ', 'material_id' => $material_id]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'update':
            checkRole(['admin']);
            
            $material_id = (int)$_POST['material_id'];
            
            // Get old data for audit
            $oldQuery = "SELECT * FROM materials WHERE material_id = ?";
            $oldStmt = $db->prepare($oldQuery);
            $oldStmt->execute([$material_id]);
            $oldData = $oldStmt->fetch();
            
            if (!$oldData) {
                throw new Exception('ไม่พบวัสดุที่ต้องการแก้ไข');
            }
            
            $db->beginTransaction();
            
            try {
                $data = [
                    'material_name' => sanitize($_POST['material_name']),
                    'description' => sanitize($_POST['description']),
                    'unit' => sanitize($_POST['unit']),
                    'min_stock' => (int)$_POST['min_stock'],
                    'max_stock' => (int)$_POST['max_stock'],
                    'location' => sanitize($_POST['location'])
                ];
                
                // Check if current_stock is being updated
                $new_stock = isset($_POST['current_stock']) ? (int)$_POST['current_stock'] : null;
                $stock_changed = false;
                
                if ($new_stock !== null && $new_stock != $oldData['current_stock']) {
                    $stock_changed = true;
                    $data['current_stock'] = $new_stock;
                    
                    // Record inventory transaction for stock adjustment
                    $stock_diff = $new_stock - $oldData['current_stock'];
                    
                    $transactionQuery = "INSERT INTO inventory_transactions 
                                        (material_id, transaction_type, quantity, reference_type, reference_id, 
                                         previous_stock, current_stock, transaction_by, notes) 
                                        VALUES (?, 'adjustment', ?, 'manual', NULL, ?, ?, ?, ?)";
                    $transactionStmt = $db->prepare($transactionQuery);
                    $transactionStmt->execute([
                        $material_id,
                        abs($stock_diff),
                        $oldData['current_stock'],
                        $new_stock,
                        $_SESSION['user_id'],
                        'ปรับสต็อกจาก ' . number_format($oldData['current_stock']) . ' เป็น ' . number_format($new_stock)
                    ]);
                    
                    // Update with current_stock
                    $query = "UPDATE materials SET material_name = ?, description = ?, unit = ?, min_stock = ?, max_stock = ?, current_stock = ?, location = ? WHERE material_id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([
                        $data['material_name'], $data['description'], $data['unit'],
                        $data['min_stock'], $data['max_stock'], $data['current_stock'], $data['location'], $material_id
                    ]);
                } else {
                    // Update without current_stock
                    $query = "UPDATE materials SET material_name = ?, description = ?, unit = ?, min_stock = ?, max_stock = ?, location = ? WHERE material_id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([
                        $data['material_name'], $data['description'], $data['unit'],
                        $data['min_stock'], $data['max_stock'], $data['location'], $material_id
                    ]);
                }
                
                // Log audit
                logAudit($db, $_SESSION['user_id'], 'update', 'materials', $material_id, $oldData, $data);
                
                $db->commit();
                
                $message = 'แก้ไขวัสดุสำเร็จ';
                if ($stock_changed) {
                    $message .= ' (สต็อกถูกปรับจาก ' . number_format($oldData['current_stock']) . ' เป็น ' . number_format($new_stock) . ')';
                }
                
                echo json_encode(['success' => true, 'message' => $message]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'delete':
            checkRole(['admin']);
            
            $material_id = (int)$_POST['material_id'];
            
            // Get old data for audit
            $oldQuery = "SELECT * FROM materials WHERE material_id = ?";
            $oldStmt = $db->prepare($oldQuery);
            $oldStmt->execute([$material_id]);
            $oldData = $oldStmt->fetch();
            
            if (!$oldData) {
                throw new Exception('ไม่พบวัสดุที่ต้องการลบ');
            }
            
            // Soft delete
            $query = "UPDATE materials SET status = 'inactive' WHERE material_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$material_id]);
            
            // Log audit
            logAudit($db, $_SESSION['user_id'], 'delete', 'materials', $material_id, $oldData, ['status' => 'inactive']);
            
            echo json_encode(['success' => true, 'message' => 'ลบวัสดุสำเร็จ']);
            break;
            
        case 'restore':
            checkRole(['admin']);
            
            $material_id = (int)$_POST['material_id'];
            
            // Get material data
            $oldQuery = "SELECT * FROM materials WHERE material_id = ? AND status = 'inactive'";
            $oldStmt = $db->prepare($oldQuery);
            $oldStmt->execute([$material_id]);
            $material = $oldStmt->fetch();
            
            if (!$material) {
                throw new Exception('ไม่พบวัสดุที่ต้องการกู้คืน');
            }
            
            // Check if part_code is available
            $checkQuery = "SELECT material_id FROM materials WHERE part_code = ? AND status = 'active'";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([$material['part_code']]);
            
            if ($checkStmt->rowCount() > 0) {
                throw new Exception('ไม่สามารถกู้คืนได้ เนื่องจากมีวัสดุรหัส ' . $material['part_code'] . ' อยู่แล้ว');
            }
            
            // Restore
            $query = "UPDATE materials SET status = 'active' WHERE material_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$material_id]);
            
            // Log audit
            logAudit($db, $_SESSION['user_id'], 'restore', 'materials', $material_id, 
                ['status' => 'inactive'], 
                ['status' => 'active']
            );
            
            echo json_encode(['success' => true, 'message' => 'กู้คืนวัสดุสำเร็จ']);
            break;
            
        default:
            throw new Exception('ไม่พบการกระทำที่ระบุ');
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function logAudit($db, $user_id, $action, $table, $record_id, $old_values, $new_values) {
    try {
        $query = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([
            $user_id, $action, $table, $record_id,
            $old_values ? json_encode($old_values) : null,
            $new_values ? json_encode($new_values) : null,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        // Log error but don't fail the main operation
        error_log("Audit log error: " . $e->getMessage());
    }
}