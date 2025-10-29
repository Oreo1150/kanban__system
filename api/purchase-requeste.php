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
        case 'create':
            checkRole(['store', 'planning', 'admin']);
            
            $material_id = (int)$_POST['material_id'];
            $quantity_requested = (int)$_POST['quantity_requested'];
            $urgency = sanitize($_POST['urgency']);
            $expected_date = sanitize($_POST['expected_date']);
            $notes = sanitize($_POST['notes'] ?? '');
            
            // Validate inputs
            if ($material_id <= 0 || $quantity_requested <= 0) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            
            if (!in_array($urgency, ['low', 'medium', 'high', 'urgent'])) {
                throw new Exception('ระดับความเร่งด่วนไม่ถูกต้อง');
            }
            
            // Check if material exists
            $material_query = "SELECT * FROM materials WHERE material_id = ? AND status = 'active'";
            $material_stmt = $db->prepare($material_query);
            $material_stmt->execute([$material_id]);
            $material = $material_stmt->fetch();
            
            if (!$material) {
                throw new Exception('ไม่พบวัสดุที่ระบุ');
            }
            
            $db->beginTransaction();
            
            // Generate PR number
            $pr_number = 'PR' . date('Ymd') . sprintf('%04d', rand(1, 9999));
            
            // Check if PR number exists
            $check_query = "SELECT pr_id FROM purchase_requests WHERE pr_number = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$pr_number]);
            
            // Regenerate if exists
            while ($check_stmt->fetch()) {
                $pr_number = 'PR' . date('Ymd') . sprintf('%04d', rand(1, 9999));
                $check_stmt->execute([$pr_number]);
            }
            
            // Create purchase request
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
            
            // Log audit
            $audit_query = "INSERT INTO audit_logs 
                          (user_id, action, table_name, record_id, new_values, ip_address, user_agent) 
                          VALUES (?, 'create', 'purchase_requests', ?, ?, ?, ?)";
            $audit_stmt = $db->prepare($audit_query);
            $audit_stmt->execute([
                $_SESSION['user_id'],
                $pr_id,
                json_encode([
                    'pr_number' => $pr_number,
                    'material_id' => $material_id,
                    'quantity_requested' => $quantity_requested,
                    'urgency' => $urgency
                ]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'สร้างคำขอสั่งซื้อเรียบร้อยแล้ว',
                'pr_id' => $pr_id,
                'pr_number' => $pr_number
            ]);
            break;
            
        case 'get':
            $pr_id = (int)$_GET['id'];
            
            $query = "SELECT pr.*, m.part_code, m.material_name, m.unit, m.current_stock,
                            u1.full_name as created_by_name,
                            u2.full_name as approved_by_name
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
            
            // Check access rights
            $user_role = getUserRole();
            if ($user_role === 'store' && $pr['created_by'] != $_SESSION['user_id']) {
                throw new Exception('ไม่มีสิทธิ์เข้าถึงข้อมูลนี้');
            }
            
            echo json_encode(['success' => true, 'data' => $pr]);
            break;
            
        case 'get_all':
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $status = $_GET['status'] ?? '';
            $urgency = $_GET['urgency'] ?? '';
            $user_role = getUserRole();
            
            $offset = ($page - 1) * $limit;
            
            $where = ["1=1"];
            $params = [];
            
            // Role-based filtering
            if ($user_role === 'store') {
                $where[] = "pr.created_by = ?";
                $params[] = $_SESSION['user_id'];
            }
            
            if (!empty($status)) {
                $where[] = "pr.status = ?";
                $params[] = $status;
            }
            
            if (!empty($urgency)) {
                $where[] = "pr.urgency = ?";
                $params[] = $urgency;
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Get total count
            $count_query = "SELECT COUNT(*) as total 
                          FROM purchase_requests pr 
                          WHERE $whereClause";
            $count_stmt = $db->prepare($count_query);
            $count_stmt->execute($params);
            $total = $count_stmt->fetch()['total'];
            
            // Get purchase requests
            $query = "SELECT pr.*, m.part_code, m.material_name, m.unit,
                            u1.full_name as created_by_name,
                            u2.full_name as approved_by_name
                     FROM purchase_requests pr
                     LEFT JOIN materials m ON pr.material_id = m.material_id
                     LEFT JOIN users u1 ON pr.created_by = u1.user_id
                     LEFT JOIN users u2 ON pr.approved_by = u2.user_id
                     WHERE $whereClause
                     ORDER BY 
                        CASE pr.urgency 
                            WHEN 'urgent' THEN 1
                            WHEN 'high' THEN 2
                            WHEN 'medium' THEN 3
                            WHEN 'low' THEN 4
                        END,
                        pr.created_at DESC
                     LIMIT $limit OFFSET $offset";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $prs = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'data' => $prs,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        case 'approve':
            checkRole(['planning', 'admin']);
            
            $pr_id = (int)$_POST['pr_id'];
            $notes = sanitize($_POST['notes'] ?? '');
            
            $db->beginTransaction();
            
            // Get PR info
            $pr_query = "SELECT * FROM purchase_requests WHERE pr_id = ? AND status = 'pending'";
            $pr_stmt = $db->prepare($pr_query);
            $pr_stmt->execute([$pr_id]);
            $pr = $pr_stmt->fetch();
            
            if (!$pr) {
                throw new Exception('ไม่พบคำขอหรือคำขอถูกดำเนินการแล้ว');
            }
            
            // Update status
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
            
            // Log audit
            $audit_query = "INSERT INTO audit_logs 
                          (user_id, action, table_name, record_id, new_values, ip_address, user_agent) 
                          VALUES (?, 'approve', 'purchase_requests', ?, ?, ?, ?)";
            $audit_stmt = $db->prepare($audit_query);
            $audit_stmt->execute([
                $_SESSION['user_id'],
                $pr_id,
                json_encode(['status' => 'approved', 'notes' => $notes]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
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
            
            // Get PR info
            $pr_query = "SELECT * FROM purchase_requests WHERE pr_id = ? AND status = 'pending'";
            $pr_stmt = $db->prepare($pr_query);
            $pr_stmt->execute([$pr_id]);
            $pr = $pr_stmt->fetch();
            
            if (!$pr) {
                throw new Exception('ไม่พบคำขอหรือคำขอถูกดำเนินการแล้ว');
            }
            
            // Update status
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
            
            // Log audit
            $audit_query = "INSERT INTO audit_logs 
                          (user_id, action, table_name, record_id, new_values, ip_address, user_agent) 
                          VALUES (?, 'reject', 'purchase_requests', ?, ?, ?, ?)";
            $audit_stmt = $db->prepare($audit_query);
            $audit_stmt->execute([
                $_SESSION['user_id'],
                $pr_id,
                json_encode(['status' => 'rejected', 'reason' => $reason]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'ปฏิเสธคำขอสั่งซื้อเรียบร้อยแล้ว'
            ]);
            break;
            
        case 'cancel':
            checkRole(['store', 'admin']);
            
            $pr_id = (int)$_POST['pr_id'];
            $reason = sanitize($_POST['reason'] ?? '');
            
            $db->beginTransaction();
            
            // Get PR info and check ownership
            $pr_query = "SELECT * FROM purchase_requests WHERE pr_id = ? AND status = 'pending'";
            $pr_stmt = $db->prepare($pr_query);
            $pr_stmt->execute([$pr_id]);
            $pr = $pr_stmt->fetch();
            
            if (!$pr) {
                throw new Exception('ไม่พบคำขอหรือคำขอถูกดำเนินการแล้ว');
            }
            
            // Check if user owns this PR or is admin
            if ($pr['created_by'] != $_SESSION['user_id'] && getUserRole() !== 'admin') {
                throw new Exception('ไม่มีสิทธิ์ยกเลิกคำขอนี้');
            }
            
            // Update status
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
            
            // Log audit
            $audit_query = "INSERT INTO audit_logs 
                          (user_id, action, table_name, record_id, new_values, ip_address, user_agent) 
                          VALUES (?, 'cancel', 'purchase_requests', ?, ?, ?, ?)";
            $audit_stmt = $db->prepare($audit_query);
            $audit_stmt->execute([
                $_SESSION['user_id'],
                $pr_id,
                json_encode(['status' => 'cancelled', 'reason' => $reason]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'ยกเลิกคำขอสั่งซื้อเรียบร้อยแล้ว'
            ]);
            break;
            
        case 'update_status':
            checkRole(['planning', 'admin']);
            
            $pr_id = (int)$_POST['pr_id'];
            $new_status = sanitize($_POST['status']);
            $notes = sanitize($_POST['notes'] ?? '');
            
            // Validate status
            $valid_statuses = ['ordered', 'received', 'cancelled'];
            if (!in_array($new_status, $valid_statuses)) {
                throw new Exception('สถานะไม่ถูกต้อง');
            }
            
            $db->beginTransaction();
            
            // Get PR info
            $pr_query = "SELECT * FROM purchase_requests WHERE pr_id = ?";
            $pr_stmt = $db->prepare($pr_query);
            $pr_stmt->execute([$pr_id]);
            $pr = $pr_stmt->fetch();
            
            if (!$pr) {
                throw new Exception('ไม่พบคำขอ');
            }
            
            // Update status
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
            
            // If status is 'received', update material stock
            if ($new_status === 'received') {
                $material_query = "UPDATE materials 
                                 SET current_stock = current_stock + ?
                                 WHERE material_id = ?";
                $material_stmt = $db->prepare($material_query);
                $material_stmt->execute([
                    $pr['quantity_requested'],
                    $pr['material_id']
                ]);
                
                // Record inventory transaction
                $material_info = $db->query("SELECT * FROM materials WHERE material_id = " . $pr['material_id'])->fetch();
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
            
            // Log audit
            $audit_query = "INSERT INTO audit_logs 
                          (user_id, action, table_name, record_id, new_values, ip_address, user_agent) 
                          VALUES (?, 'update_status', 'purchase_requests', ?, ?, ?, ?)";
            $audit_stmt = $db->prepare($audit_query);
            $audit_stmt->execute([
                $_SESSION['user_id'],
                $pr_id,
                json_encode(['status' => $new_status, 'notes' => $notes]),
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'อัพเดทสถานะเรียบร้อยแล้ว'
            ]);
            break;
            
        case 'stats':
            $user_role = getUserRole();
            
            $where = $user_role === 'store' ? "WHERE created_by = {$_SESSION['user_id']}" : "";
            
            $query = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                        SUM(CASE WHEN status = 'ordered' THEN 1 ELSE 0 END) as ordered,
                        SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                     FROM purchase_requests $where";
            $stmt = $db->query($query);
            $stats = $stmt->fetch();
            
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;
            
        default:
            throw new Exception('ไม่พบการกระทำที่ระบุ');
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}