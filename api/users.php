<?php
// api/users.php
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
            checkRole(['admin']);
            
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 100);
            $search = $_GET['search'] ?? '';
            $role_filter = $_GET['role'] ?? '';
            $status_filter = $_GET['status'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = [];
            $params = [];
            
            // Filter by status
            if (empty($status_filter) || $status_filter === '') {
                // Default: แสดงเฉพาะผู้ใช้ที่ active
                $where[] = "status = 'active'";
            } elseif ($status_filter !== 'all') {
                // กรองตาม status ที่เลือก (active หรือ inactive)
                $where[] = "status = ?";
                $params[] = $status_filter;
            }
            // ถ้า status_filter = 'all' ไม่ต้องกรอง status
            
            if (!empty($search)) {
                $where[] = "(username LIKE ? OR email LIKE ? OR full_name LIKE ?)";
                $searchTerm = "%$search%";
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
            }
            
            if (!empty($role_filter)) {
                $where[] = "role = ?";
                $params[] = $role_filter;
            }
            
            $whereClause = !empty($where) ? implode(' AND ', $where) : '1=1';
            
            // Count total
            $count_query = "SELECT COUNT(*) as total FROM users WHERE $whereClause";
            $count_stmt = $db->prepare($count_query);
            $count_stmt->execute($params);
            $total = $count_stmt->fetch()['total'];
            
            // Get users
            $query = "SELECT 
                        user_id, 
                        username, 
                        email, 
                        full_name,
                        role, 
                        status, 
                        phone,
                        created_at,
                        updated_at as last_login
                      FROM users 
                      WHERE $whereClause
                      ORDER BY created_at DESC
                      LIMIT $limit OFFSET $offset";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $users = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'users' => $users,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        case 'get':
            checkRole(['admin']);
            
            $user_id = (int)($_GET['id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('กรุณาระบุ User ID');
            }
            
            $query = "SELECT 
                        user_id, 
                        username, 
                        email, 
                        full_name,
                        role, 
                        status,
                        phone,
                        created_at,
                        updated_at as last_login
                      FROM users 
                      WHERE user_id = ?";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$user_id]);
            $user = $stmt->fetch();
            
            if (!$user) {
                throw new Exception('ไม่พบผู้ใช้งานที่ระบุ');
            }
            
            echo json_encode(['success' => true, 'user' => $user]);
            break;
            
        case 'create':
            checkRole(['admin']);
            
            // Validate required fields
            $required_fields = ['username', 'email', 'password', 'first_name', 'last_name', 'role'];
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("กรุณากรอก $field");
                }
            }
            
            $username = sanitize($_POST['username']);
            $email = sanitize($_POST['email']);
            $password = $_POST['password'];
            $first_name = sanitize($_POST['first_name']);
            $last_name = sanitize($_POST['last_name']);
            $full_name = $first_name . ' ' . $last_name;
            $role = sanitize($_POST['role']);
            $status = sanitize($_POST['status'] ?? 'active');
            $phone = sanitize($_POST['phone'] ?? '');
            $department = sanitize($_POST['department'] ?? '');
            
            // Validate username format (alphanumeric and underscore only)
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                throw new Exception('ชื่อผู้ใช้สามารถใช้ได้เฉพาะตัวอักษร ตัวเลข และขีดล่าง (_) เท่านั้น');
            }
            
            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
            }
            
            // Validate password length
            if (strlen($password) < 6) {
                throw new Exception('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
            }
            
            // Validate role
            $valid_roles = ['admin', 'planning', 'production', 'store', 'management'];
            if (!in_array($role, $valid_roles)) {
                throw new Exception('ตำแหน่งไม่ถูกต้อง');
            }
            
            $db->beginTransaction();
            
            // Check if username exists
            $check_username = $db->prepare("SELECT user_id FROM users WHERE username = ?");
            $check_username->execute([$username]);
            if ($check_username->fetch()) {
                throw new Exception('ชื่อผู้ใช้นี้ถูกใช้งานแล้ว');
            }
            
            // Check if email exists
            $check_email = $db->prepare("SELECT user_id FROM users WHERE email = ?");
            $check_email->execute([$email]);
            if ($check_email->fetch()) {
                throw new Exception('อีเมลนี้ถูกใช้งานแล้ว');
            }
            
            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $insert_query = "INSERT INTO users 
                           (username, email, password, full_name, role, status, phone) 
                           VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $db->prepare($insert_query);
            $stmt->execute([
                $username,
                $email,
                $password_hash,
                $full_name,
                $role,
                $status,
                $phone
            ]);
            
            $user_id = $db->lastInsertId();
            
            // Log audit
            logAudit($db, $_SESSION['user_id'], 'create', 'users', $user_id, null, [
                'username' => $username,
                'email' => $email,
                'role' => $role
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'เพิ่มผู้ใช้งานสำเร็จ',
                'user_id' => $user_id
            ]);
            break;
            
        case 'update':
            checkRole(['admin']);
            
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('กรุณาระบุ User ID');
            }
            
            // Prevent self-role change
            if ($user_id == $_SESSION['user_id']) {
                $role = $_POST['role'];
                $current_user = $db->prepare("SELECT role FROM users WHERE user_id = ?");
                $current_user->execute([$user_id]);
                $current_role = $current_user->fetch()['role'];
                
                if ($role !== $current_role) {
                    throw new Exception('ไม่สามารถเปลี่ยนตำแหน่งของตนเองได้');
                }
            }
            
            // Get old data for audit
            $old_query = "SELECT * FROM users WHERE user_id = ?";
            $old_stmt = $db->prepare($old_query);
            $old_stmt->execute([$user_id]);
            $old_data = $old_stmt->fetch();
            
            if (!$old_data) {
                throw new Exception('ไม่พบผู้ใช้งานที่ต้องการแก้ไข');
            }
            
            $email = sanitize($_POST['email']);
            $first_name = sanitize($_POST['first_name']);
            $last_name = sanitize($_POST['last_name']);
            $full_name = $first_name . ' ' . $last_name;
            $role = sanitize($_POST['role']);
            $status = sanitize($_POST['status']);
            $phone = sanitize($_POST['phone'] ?? '');
            
            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('รูปแบบอีเมลไม่ถูกต้อง');
            }
            
            // Validate role
            $valid_roles = ['admin', 'planning', 'production', 'store', 'management'];
            if (!in_array($role, $valid_roles)) {
                throw new Exception('ตำแหน่งไม่ถูกต้อง');
            }
            
            $db->beginTransaction();
            
            // Check if email is taken by another user
            $check_email = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $check_email->execute([$email, $user_id]);
            if ($check_email->fetch()) {
                throw new Exception('อีเมลนี้ถูกใช้งานโดยผู้ใช้คนอื่นแล้ว');
            }
            
            // Build update query
            $update_fields = [
                'email = ?',
                'full_name = ?',
                'role = ?',
                'status = ?',
                'phone = ?'
            ];
            
            $params = [$email, $full_name, $role, $status, $phone];
            
            // Update password if provided
            $password = $_POST['password'] ?? '';
            if (!empty($password)) {
                if (strlen($password) < 6) {
                    throw new Exception('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
                }
                $update_fields[] = 'password = ?';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
            }
            
            $params[] = $user_id;
            
            $update_query = "UPDATE users SET " . implode(', ', $update_fields) . " WHERE user_id = ?";
            $stmt = $db->prepare($update_query);
            $stmt->execute($params);
            
            // Log audit
            logAudit($db, $_SESSION['user_id'], 'update', 'users', $user_id, $old_data, [
                'email' => $email,
                'role' => $role,
                'status' => $status,
                'password_changed' => !empty($password)
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'แก้ไขข้อมูลผู้ใช้งานสำเร็จ'
            ]);
            break;
            
        case 'delete':
            checkRole(['admin']);
            
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('กรุณาระบุ User ID');
            }
            
            // Prevent self-deletion
            if ($user_id == $_SESSION['user_id']) {
                throw new Exception('ไม่สามารถลบบัญชีของตนเองได้');
            }
            
            // Get old data for audit
            $old_query = "SELECT * FROM users WHERE user_id = ?";
            $old_stmt = $db->prepare($old_query);
            $old_stmt->execute([$user_id]);
            $old_data = $old_stmt->fetch();
            
            if (!$old_data) {
                throw new Exception('ไม่พบผู้ใช้งานที่ต้องการลบ');
            }
            
            $db->beginTransaction();
            
            // Soft delete - just set status to inactive
            $delete_query = "UPDATE users SET status = 'inactive' WHERE user_id = ?";
            $stmt = $db->prepare($delete_query);
            $stmt->execute([$user_id]);
            
            // Log audit
            logAudit($db, $_SESSION['user_id'], 'delete', 'users', $user_id, $old_data, [
                'status' => 'inactive'
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'ลบผู้ใช้งานสำเร็จ'
            ]);
            break;
            
        case 'restore':
            checkRole(['admin']);
            
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('กรุณาระบุ User ID');
            }
            
            // Get old data for audit
            $old_query = "SELECT * FROM users WHERE user_id = ?";
            $old_stmt = $db->prepare($old_query);
            $old_stmt->execute([$user_id]);
            $old_data = $old_stmt->fetch();
            
            if (!$old_data) {
                throw new Exception('ไม่พบผู้ใช้งานที่ต้องการกู้คืน');
            }
            
            if ($old_data['status'] === 'active') {
                throw new Exception('ผู้ใช้งานนี้ยังใช้งานอยู่');
            }
            
            $db->beginTransaction();
            
            // Restore - set status back to active
            $restore_query = "UPDATE users SET status = 'active' WHERE user_id = ?";
            $stmt = $db->prepare($restore_query);
            $stmt->execute([$user_id]);
            
            // Log audit
            logAudit($db, $_SESSION['user_id'], 'restore', 'users', $user_id, $old_data, [
                'status' => 'active'
            ]);
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'message' => 'กู้คืนผู้ใช้งานสำเร็จ'
            ]);
            break;
            
        case 'toggle_status':
            checkRole(['admin']);
            
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('กรุณาระบุ User ID');
            }
            
            // Prevent self-status change
            if ($user_id == $_SESSION['user_id']) {
                throw new Exception('ไม่สามารถเปลี่ยนสถานะของตนเองได้');
            }
            
            // Get current status
            $status_query = "SELECT status FROM users WHERE user_id = ?";
            $status_stmt = $db->prepare($status_query);
            $status_stmt->execute([$user_id]);
            $current = $status_stmt->fetch();
            
            if (!$current) {
                throw new Exception('ไม่พบผู้ใช้งาน');
            }
            
            $new_status = $current['status'] === 'active' ? 'inactive' : 'active';
            
            $update_query = "UPDATE users SET status = ? WHERE user_id = ?";
            $stmt = $db->prepare($update_query);
            $stmt->execute([$new_status, $user_id]);
            
            // Log audit
            logAudit($db, $_SESSION['user_id'], 'toggle_status', 'users', $user_id, 
                ['status' => $current['status']], 
                ['status' => $new_status]
            );
            
            echo json_encode([
                'success' => true,
                'message' => 'เปลี่ยนสถานะสำเร็จ',
                'new_status' => $new_status
            ]);
            break;
            
        case 'reset_password':
            checkRole(['admin']);
            
            $user_id = (int)($_POST['user_id'] ?? 0);
            $new_password = $_POST['new_password'] ?? '';
            
            if (!$user_id) {
                throw new Exception('กรุณาระบุ User ID');
            }
            
            if (strlen($new_password) < 6) {
                throw new Exception('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
            }
            
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            $update_query = "UPDATE users SET password = ? WHERE user_id = ?";
            $stmt = $db->prepare($update_query);
            $stmt->execute([$password_hash, $user_id]);
            
            // Log audit
            logAudit($db, $_SESSION['user_id'], 'reset_password', 'users', $user_id, null, [
                'action' => 'password_reset_by_admin'
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'รีเซ็ตรหัสผ่านสำเร็จ'
            ]);
            break;
            
        case 'check_username':
            checkRole(['admin']);
            
            $username = sanitize($_GET['username'] ?? '');
            
            if (empty($username)) {
                throw new Exception('กรุณาระบุชื่อผู้ใช้');
            }
            
            $query = "SELECT user_id FROM users WHERE username = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$username]);
            $exists = $stmt->fetch() ? true : false;
            
            echo json_encode([
                'success' => true,
                'exists' => $exists
            ]);
            break;
            
        case 'check_email':
            checkRole(['admin']);
            
            $email = sanitize($_GET['email'] ?? '');
            $exclude_user_id = (int)($_GET['exclude_user_id'] ?? 0);
            
            if (empty($email)) {
                throw new Exception('กรุณาระบุอีเมล');
            }
            
            if ($exclude_user_id > 0) {
                $query = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$email, $exclude_user_id]);
            } else {
                $query = "SELECT user_id FROM users WHERE email = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$email]);
            }
            
            $exists = $stmt->fetch() ? true : false;
            
            echo json_encode([
                'success' => true,
                'exists' => $exists
            ]);
            break;
            
        case 'stats':
            checkRole(['admin']);
            
            $stats_query = "
                SELECT 
                    COUNT(*) as total_users,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_users,
                    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_users,
                    COUNT(CASE WHEN role = 'admin' THEN 1 END) as admin_count,
                    COUNT(CASE WHEN role = 'planning' THEN 1 END) as planning_count,
                    COUNT(CASE WHEN role = 'production' THEN 1 END) as production_count,
                    COUNT(CASE WHEN role = 'store' THEN 1 END) as store_count,
                    COUNT(CASE WHEN role = 'management' THEN 1 END) as management_count,
                    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as new_users_30d,
                    COUNT(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as active_7d
                FROM users
            ";
            
            $stats = $db->query($stats_query)->fetch();
            
            echo json_encode([
                'success' => true,
                'stats' => $stats
            ]);
            break;
            
        default:
            throw new Exception('ไม่พบการกระทำที่ระบุ: ' . $action);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function logAudit($db, $user_id, $action, $table, $record_id, $old_values, $new_values) {
    try {
        $query = "INSERT INTO audit_logs 
                 (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            $user_id,
            $action,
            $table,
            $record_id,
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