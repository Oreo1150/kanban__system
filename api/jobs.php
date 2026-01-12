<?php
// api/jobs.php
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
            checkRole(['planning', 'admin']);
            
            $data = [
                'product_id' => (int)($_POST['product_id'] ?? 0),
                'quantity_planned' => (int)($_POST['quantity_planned'] ?? 0),
                'start_date' => $_POST['start_date'] ?? null,
                'end_date' => $_POST['end_date'] ?? null,
                'assigned_to' => (int)($_POST['assigned_to'] ?? 0),
                'notes' => sanitize($_POST['notes'] ?? '')
            ];
            
            if ($data['quantity_planned'] <= 0) {
                throw new Exception('จำนวนที่วางแผนต้องมากกว่า 0');
            }

            // Handle customer: accept customer_id or customer_name (create if needed)
            $customer_id = null;
            $customer_name = trim($_POST['customer_name'] ?? '');
            if (!empty($_POST['customer_id'])) {
                $customer_id = (int)$_POST['customer_id'];
            } elseif ($customer_name !== '') {
                try {
                    // Try to find existing customer by exact name
                    $stmt = $db->prepare("SELECT customer_id FROM customers WHERE customer_name = ? LIMIT 1");
                    $stmt->execute([$customer_name]);
                    $existing = $stmt->fetch();
                    if ($existing) {
                        $customer_id = $existing['customer_id'];
                    } else {
                        // Insert new customer (if customers table exists)
                        $ins = $db->prepare("INSERT INTO customers (customer_name, status, created_at) VALUES (?, 'active', NOW())");
                        $ins->execute([$customer_name]);
                        $customer_id = $db->lastInsertId();
                    }
                } catch (PDOException $e) {
                    // If customers table is missing or error occurs, append customer name to notes as fallback
                    if ($customer_name) {
                        $data['notes'] .= "\nCustomer: " . $customer_name;
                    }
                    $customer_id = null;
                }
            }
            
            // Ensure counter table exists (created if missing) — do this BEFORE starting a transaction
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS job_daily_counters (
                    counter_date DATE PRIMARY KEY,
                    last_seq INT NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB");
            } catch (PDOException $e) {
                // ignore creation errors (permissions)
            }

            $db->beginTransaction();

            // Generate job number using a daily counter table that resets each day
            $datePart = date('Ymd');
            $prefix = 'JOB' . $datePart;
            $today = date('Y-m-d');

            // Use the counters table within the transaction to safely reserve the next sequence
            try {
                $seqStmt = $db->prepare("SELECT last_seq FROM job_daily_counters WHERE counter_date = ? FOR UPDATE");
                $seqStmt->execute([$today]);
                $lastSeqRow = $seqStmt->fetchColumn();

                if ($lastSeqRow === false) {
                    // Initialize from existing job numbers for the day (if any)
                    $maxStmt = $db->prepare("SELECT MAX(CAST(RIGHT(job_number,4) AS UNSIGNED)) FROM production_jobs WHERE job_number LIKE ?");
                    $maxStmt->execute([$prefix . '%']);
                    $maxExisting = (int)$maxStmt->fetchColumn();

                    $nextSeq = $maxExisting + 1;
                    $ins = $db->prepare("INSERT INTO job_daily_counters (counter_date, last_seq) VALUES (?, ?)");
                    $ins->execute([$today, $nextSeq]);
                } else {
                    $nextSeq = ((int)$lastSeqRow) + 1;
                    $upd = $db->prepare("UPDATE job_daily_counters SET last_seq = ? WHERE counter_date = ?");
                    $upd->execute([$nextSeq, $today]);
                }
            } catch (PDOException $e) {
                // Fallback: find max suffix in production_jobs (no lock)
                $seqStmt = $db->prepare("SELECT job_number FROM production_jobs WHERE job_number LIKE ? ORDER BY job_number DESC LIMIT 1");
                $seqStmt->execute([$prefix . '%']);
                $lastJob = $seqStmt->fetchColumn();
                $lastSeq = $lastJob ? (int)substr($lastJob, -4) : 0;
                $nextSeq = $lastSeq + 1;
            }

            $job_number = $prefix . sprintf('%04d', $nextSeq);

            // Build insert fields conditionally (if production_jobs has customer_id column)
            $fields = ['job_number','product_id','quantity_planned','start_date','end_date','assigned_to','notes','created_by'];
            $params = [$job_number, $data['product_id'], $data['quantity_planned'], $data['start_date'], $data['end_date'], $data['assigned_to'], $data['notes'], $_SESSION['user_id']];

            try {
                $colCheck = $db->query("SHOW COLUMNS FROM production_jobs LIKE 'customer_id'");
                if ($colCheck && $colCheck->rowCount() > 0 && $customer_id !== null) {
                    $fields[] = 'customer_id';
                    $params[] = $customer_id;
                }
            } catch (PDOException $e) {
                // ignore, table might not exist or no permission
            }

            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $jobQuery = "INSERT INTO production_jobs (" . implode(', ', $fields) . ") VALUES ($placeholders)";
            $jobStmt = $db->prepare($jobQuery);
            $jobStmt->execute($params);
            
            $job_id = $db->lastInsertId();
            
            // Calculate required materials from BOM
            $bomQuery = "SELECT bd.material_id, bd.quantity_per_unit, m.material_name, m.part_code
                        FROM bom_header bh
                        JOIN bom_detail bd ON bh.bom_id = bd.bom_id
                        JOIN materials m ON bd.material_id = m.material_id
                        WHERE bh.product_id = ? AND bh.status = 'active'";
            $bomStmt = $db->prepare($bomQuery);
            $bomStmt->execute([$data['product_id']]);
            $bomItems = $bomStmt->fetchAll();
            
            $required_materials = [];
            foreach ($bomItems as $item) {
                $required_quantity = $item['quantity_per_unit'] * $data['quantity_planned'];
                $required_materials[] = [
                    'material_id' => $item['material_id'],
                    'part_code' => $item['part_code'],
                    'material_name' => $item['material_name'],
                    'quantity_per_unit' => $item['quantity_per_unit'],
                    'required_quantity' => $required_quantity
                ];
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'สร้างงานการผลิตสำเร็จ',
                'job_id' => $job_id,
                'job_number' => $job_number,
                'required_materials' => $required_materials,
                'customer_id' => $customer_id
            ]);
            break;
            
        case 'get_all':
            $role = getUserRole();
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $status = $_GET['status'] ?? '';
            $assigned_to = $_GET['assigned_to'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = ["1=1"];
            $params = [];
            
            // Role-based filtering
            if ($role === 'production') {
                $where[] = "pj.assigned_to = ?";
                $params[] = $_SESSION['user_id'];
            }
            
            if (!empty($status)) {
                $where[] = "pj.status = ?";
                $params[] = $status;
            }
            
            if (!empty($assigned_to)) {
                $where[] = "pj.assigned_to = ?";
                $params[] = $assigned_to;
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM production_jobs pj WHERE $whereClause";
            $countStmt = $db->prepare($countQuery);
            $countStmt->execute($params);
            $total = $countStmt->fetch()['total'];
            
            // Get jobs
            $query = "SELECT pj.*, p.product_name, p.product_code, 
                             u1.full_name as created_by_name, u2.full_name as assigned_to_name
                      FROM production_jobs pj
                      LEFT JOIN products p ON pj.product_id = p.product_id
                      LEFT JOIN users u1 ON pj.created_by = u1.user_id
                      LEFT JOIN users u2 ON pj.assigned_to = u2.user_id
                      WHERE $whereClause
                      ORDER BY pj.created_at DESC
                      LIMIT $limit OFFSET $offset";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $jobs = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'jobs' => $jobs,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        case 'update_status':
            checkRole(['production', 'admin']);
            
            $job_id = (int)$_POST['job_id'];
            $status = $_POST['status'];
            $quantity_produced = isset($_POST['quantity_produced']) ? (int)$_POST['quantity_produced'] : null;
            $notes = sanitize($_POST['notes'] ?? '');
            
            // Validate status
            $valid_statuses = ['pending', 'in_progress', 'completed', 'cancelled'];
            if (!in_array($status, $valid_statuses)) {
                throw new Exception('สถานะไม่ถูกต้อง');
            }
            
            $updateFields = ['status = ?'];
            $params = [$status];
            
            if ($quantity_produced !== null) {
                $updateFields[] = 'quantity_produced = ?';
                $params[] = $quantity_produced;
            }
            
            if (!empty($notes)) {
                $updateFields[] = 'notes = CONCAT(COALESCE(notes, ""), "\n", ?)';
                $params[] = date('Y-m-d H:i:s') . ' - ' . $_SESSION['full_name'] . ': ' . $notes;
            }
            
            $params[] = $job_id;
            
            $query = "UPDATE production_jobs SET " . implode(', ', $updateFields) . " WHERE job_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('ไม่พบงานที่ระบุ');
            }
            
            echo json_encode(['success' => true, 'message' => 'อัพเดทสถานะงานสำเร็จ']);
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