<?php
// api/purchase-requests.php
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
        case 'get':
            $pr_id = (int)($_GET['id'] ?? 0);
            
            if (!$pr_id) {
                throw new Exception('กรุณาระบุ PR ID');
            }
            
            $query = "SELECT pr.*, 
                             m.part_code, m.material_name, m.unit, m.current_stock, m.min_stock,
                             u1.full_name as created_by_name, u1.role as created_by_role,
                             u2.full_name as approved_by_name, u2.role as approved_by_role
                      FROM purchase_requests pr
                      LEFT JOIN materials m ON pr.material_id = m.material_id
                      LEFT JOIN users u1 ON pr.created_by = u1.user_id
                      LEFT JOIN users u2 ON pr.approved_by = u2.user_id
                      WHERE pr.pr_id = ?";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$pr_id]);
            $pr = $stmt->fetch();
            
            if (!$pr) {
                throw new Exception('ไม่พบคำขอที่ระบุ');
            }
            
            echo json_encode(['success' => true, 'data' => $pr]);
            break;
            
        case 'create':
            checkRole(['store', 'planning', 'admin']);
            
            $material_id = (int)$_POST['material_id'];
            $quantity_requested = (int)$_POST['quantity_requested'];
            $urgency = sanitize($_POST['urgency']);
            $expected_date = sanitize($_POST['expected_date']);
            $notes = sanitize($_POST['notes'] ?? '');
            
            if ($material_id <= 0 || $quantity_requested <= 0) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            
            if (!in_array($urgency, ['low', 'medium', 'high', 'urgent'])) {
                throw new Exception('ระดับความเร่งด่วนไม่ถูกต้อง');
            }
            
            $material_query = "SELECT * FROM materials WHERE material_id = ? AND status = 'active'";
            $material_stmt = $db->prepare($material_query);
            $material_stmt->execute([$material_id]);
            $material = $material_stmt->fetch();
            
            if (!$material) {
                throw new Exception('ไม่พบวัสดุที่ระบุ');
            }
            
            $db->beginTransaction();
            
            $pr_number = 'PR' . date('Ymd') . sprintf('%04d', rand(1, 9999));
            
            $check_query = "SELECT pr_id FROM purchase_requests WHERE pr_number = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$pr_number]);
            
            while ($check_stmt->fetch()) {
                $pr_number = 'PR' . date('Ymd') . sprintf('%04d', rand(1, 9999));
                $check_stmt->execute([$pr_number]);
            }
            
            $insert_query = "INSERT INTO purchase_requests 
                           (pr_number, material_id, quantity_requested, urgency, expected_date, notes, created_by, status) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([
                $pr_number,
                $material_id,
                $quantity_requested,
                $urgency,
                $expected_date,
                $notes,
                $_SESSION['user_id']
            ]);
            
            $pr_id = $db->lastInsertId();
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'สร้างคำขอสั่งซื้อเรียบร้อยแล้ว',
                'pr_id' => $pr_id,
                'pr_number' => $pr_number
            ]);
            break;
            
        case 'approve':
            checkRole(['planning', 'admin']);
            
            $pr_id = (int)$_POST['pr_id'];
            $notes = sanitize($_POST['notes'] ?? '');
            
            $db->beginTransaction();
            
            $pr_query = "SELECT * FROM purchase_requests WHERE pr_id = ? AND status = 'pending'";
            $pr_stmt = $db->prepare($pr_query);
            $pr_stmt->execute([$pr_id]);
            $pr = $pr_stmt->fetch();
            
            if (!$pr) {
                throw new Exception('ไม่พบคำขอหรือคำขอถูกดำเนินการแล้ว');
            }
            
            $update_query = "UPDATE purchase_requests 
                           SET status = 'approved', 
                               approved_by = ?, 
                               approved_at = NOW(),
                               notes = CONCAT(COALESCE(notes, ''), '\n[อนุมัติโดย: ', ?, ' เมื่อ: ', NOW(), ']', 
                                            CASE WHEN ? != '' THEN CONCAT('\n', ?) ELSE '' END)
                           WHERE pr_id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([
                $_SESSION['user_id'],
                $_SESSION['full_name'],
                $notes,
                $notes,
                $pr_id
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'อนุมัติคำขอสั่งซื้อเรียบร้อยแล้ว'
            ]);
            break;
            
        case 'reject':
            checkRole(['planning', 'admin']);
            
            $pr_id = (int)$_POST['pr_id'];
            $reason = sanitize($_POST['reason'] ?? '');
            
            if (empty($reason)) {
                throw new Exception('กรุณาระบุเหตุผลในการปฏิเสธ');
            }
            
            $db->beginTransaction();
            
            $pr_query = "SELECT * FROM purchase_requests WHERE pr_id = ? AND status = 'pending'";
            $pr_stmt = $db->prepare($pr_query);
            $pr_stmt->execute([$pr_id]);
            $pr = $pr_stmt->fetch();
            
            if (!$pr) {
                throw new Exception('ไม่พบคำขอหรือคำขอถูกดำเนินการแล้ว');
            }
            
            $update_query = "UPDATE purchase_requests 
                           SET status = 'rejected', 
                               approved_by = ?, 
                               approved_at = NOW(),
                               notes = CONCAT(COALESCE(notes, ''), '\n[ปฏิเสธโดย: ', ?, ' เมื่อ: ', NOW(), ']\nเหตุผล: ', ?)
                           WHERE pr_id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([
                $_SESSION['user_id'],
                $_SESSION['full_name'],
                $reason,
                $pr_id
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'ปฏิเสธคำขอสั่งซื้อเรียบร้อยแล้ว'
            ]);
            break;
            
        case 'update_status':
            checkRole(['planning', 'admin']);
            
            $pr_id = (int)$_POST['pr_id'];
            $new_status = sanitize($_POST['status']);
            $notes = sanitize($_POST['notes'] ?? '');
            
            $valid_statuses = ['ordered', 'received', 'cancelled'];
            if (!in_array($new_status, $valid_statuses)) {
                throw new Exception('สถานะไม่ถูกต้อง');
            }
            
            $db->beginTransaction();
            
            $pr_query = "SELECT * FROM purchase_requests WHERE pr_id = ?";
            $pr_stmt = $db->prepare($pr_query);
            $pr_stmt->execute([$pr_id]);
            $pr = $pr_stmt->fetch();
            
            if (!$pr) {
                throw new Exception('ไม่พบคำขอ');
            }
            
            $update_query = "UPDATE purchase_requests 
                           SET status = ?,
                               notes = CONCAT(COALESCE(notes, ''), '\n[อัพเดทสถานะเป็น: ', ?, ' โดย: ', ?, ' เมื่อ: ', NOW(), ']',
                                            CASE WHEN ? != '' THEN CONCAT('\n', ?) ELSE '' END)
                           WHERE pr_id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([
                $new_status,
                $new_status,
                $_SESSION['full_name'],
                $notes,
                $notes,
                $pr_id
            ]);
            
            if ($new_status === 'received') {
                $material_query = "UPDATE materials 
                                 SET current_stock = current_stock + ?
                                 WHERE material_id = ?";
                $material_stmt = $db->prepare($material_query);
                $material_stmt->execute([
                    $pr['quantity_requested'],
                    $pr['material_id']
                ]);
                
                $material_info_query = "SELECT * FROM materials WHERE material_id = ?";
                $material_info_stmt = $db->prepare($material_info_query);
                $material_info_stmt->execute([$pr['material_id']]);
                $material_info = $material_info_stmt->fetch();
                
                $previous_stock = $material_info['current_stock'] - $pr['quantity_requested'];
                
                $transaction_query = "INSERT INTO inventory_transactions 
                                    (material_id, transaction_type, quantity, reference_type, reference_id, 
                                     previous_stock, current_stock, transaction_by, notes) 
                                    VALUES (?, 'in', ?, 'purchase', ?, ?, ?, ?, ?)";
                $transaction_stmt = $db->prepare($transaction_query);
                $transaction_stmt->execute([
                    $pr['material_id'],
                    $pr['quantity_requested'],
                    $pr_id,
                    $previous_stock,
                    $material_info['current_stock'],
                    $_SESSION['user_id'],
                    'รับวัสดุจาก PR: ' . $pr['pr_number']
                ]);
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'อัพเดทสถานะเรียบร้อยแล้ว'
            ]);
            break;
            
        case 'cancel':
            checkRole(['store', 'admin']);
            
            $pr_id = (int)$_POST['pr_id'];
            $reason = sanitize($_POST['reason'] ?? '');
            
            $db->beginTransaction();
            
            $pr_query = "SELECT * FROM purchase_requests WHERE pr_id = ? AND status = 'pending'";
            $pr_stmt = $db->prepare($pr_query);
            $pr_stmt->execute([$pr_id]);
            $pr = $pr_stmt->fetch();
            
            if (!$pr) {
                throw new Exception('ไม่พบคำขอหรือคำขอถูกดำเนินการแล้ว');
            }
            
            if ($pr['created_by'] != $_SESSION['user_id'] && getUserRole() !== 'admin') {
                throw new Exception('ไม่มีสิทธิ์ยกเลิกคำขอนี้');
            }
            
            $update_query = "UPDATE purchase_requests 
                           SET status = 'cancelled',
                               notes = CONCAT(COALESCE(notes, ''), '\n[ยกเลิกโดย: ', ?, ' เมื่อ: ', NOW(), ']',
                                            CASE WHEN ? != '' THEN CONCAT('\nเหตุผล: ', ?) ELSE '' END)
                           WHERE pr_id = ?";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->execute([
                $_SESSION['full_name'],
                $reason,
                $reason,
                $pr_id
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'ยกเลิกคำขอสั่งซื้อเรียบร้อยแล้ว'
            ]);
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