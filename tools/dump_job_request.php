<?php
// tools/dump_job_request.php
// Usage:
//  - http://localhost/kanban-system/tools/dump_job_request.php?job=JOB202602180001
//  - or: http://localhost/kanban-system/tools/dump_job_request.php?request=MR000016

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

$jobNumber = $_GET['job'] ?? null;
$requestNumber = $_GET['request'] ?? null;

try {
    $result = [ 'success' => true, 'query' => [], 'data' => [] ];

    if ($jobNumber) {
        // Get job
        $stmt = $db->prepare("SELECT job_id, job_number, product_id, quantity_planned, quantity_produced FROM production_jobs WHERE job_number = ? LIMIT 1");
        $stmt->execute([$jobNumber]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        $result['query'][] = "SELECT job by job_number";
        $result['data']['job'] = $job ?: null;

        if (!$job) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบ job: ' . $jobNumber], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $productId = (int)$job['product_id'];

        // Get BOM items
        $bomQ = "SELECT bh.bom_id, bd.material_id, bd.quantity_per_unit, bd.quantity_per_card, bd.card_color, m.part_code, m.material_name, m.unit
                 FROM bom_header bh
                 JOIN bom_detail bd ON bh.bom_id = bd.bom_id
                 JOIN materials m ON bd.material_id = m.material_id
                 WHERE bh.product_id = ? AND bh.status = 'active'";
        $stmt = $db->prepare($bomQ);
        $stmt->execute([$productId]);
        $bomItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result['query'][] = 'SELECT bom items for product_id ' . $productId;
        $result['data']['bom_items'] = $bomItems;

        // Get material requests for this job
        $reqQ = "SELECT mr.request_id, mr.request_number, mr.status, mr.request_date, mr.notes, u.full_name as requested_by
                 FROM material_requests mr
                 LEFT JOIN users u ON mr.requested_by = u.user_id
                 WHERE mr.job_id = ? ORDER BY mr.request_date DESC";
        $stmt = $db->prepare($reqQ);
        $stmt->execute([$job['job_id']]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result['query'][] = 'SELECT requests for job_id ' . $job['job_id'];
        $result['data']['requests'] = [];

        foreach ($requests as $r) {
            $detailQ = "SELECT mrd.request_detail_id, mrd.material_id, m.part_code, m.material_name, m.unit, mrd.quantity_requested, mrd.quantity_fulfilled, bd.quantity_per_unit, bd.quantity_per_card, bd.card_color, m.current_stock, m.min_stock
                        FROM material_request_details mrd
                        LEFT JOIN materials m ON mrd.material_id = m.material_id
                        LEFT JOIN bom_detail bd ON bd.material_id = m.material_id
                        LEFT JOIN bom_header bh ON bd.bom_id = bh.bom_id AND bh.product_id = ? AND bh.status = 'active'
                        WHERE mrd.request_id = ?";
            $stmt2 = $db->prepare($detailQ);
            $stmt2->execute([$productId, $r['request_id']]);
            $details = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $result['data']['requests'][] = [ 'request' => $r, 'details' => $details ];
        }

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($requestNumber) {
        // Get request by request_number
        $stmt = $db->prepare("SELECT request_id, request_number, job_id, status, request_date FROM material_requests WHERE request_number = ? LIMIT 1");
        $stmt->execute([$requestNumber]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        $result['query'][] = 'SELECT request by request_number';
        $result['data']['request'] = $req ?: null;

        if (!$req) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบ request: ' . $requestNumber], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Get job to find product
        $stmt = $db->prepare("SELECT job_id, job_number, product_id, quantity_planned FROM production_jobs WHERE job_id = ? LIMIT 1");
        $stmt->execute([$req['job_id']]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        $result['data']['job'] = $job;

        $productId = (int)$job['product_id'];

        $detailQ = "SELECT mrd.request_detail_id, mrd.material_id, m.part_code, m.material_name, m.unit, mrd.quantity_requested, mrd.quantity_fulfilled, bd.quantity_per_unit, bd.quantity_per_card, bd.card_color, m.current_stock, m.min_stock
                    FROM material_request_details mrd
                    LEFT JOIN materials m ON mrd.material_id = m.material_id
                    LEFT JOIN bom_detail bd ON bd.material_id = m.material_id
                    LEFT JOIN bom_header bh ON bd.bom_id = bh.bom_id AND bh.product_id = ? AND bh.status = 'active'
                    WHERE mrd.request_id = ?";
        $stmt2 = $db->prepare($detailQ);
        $stmt2->execute([$productId, $req['request_id']]);
        $details = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $result['query'][] = 'SELECT request details for request_id ' . $req['request_id'];
        $result['data']['details'] = $details;

        // Also include BOM items
        $bomQ = "SELECT bh.bom_id, bd.material_id, bd.quantity_per_unit, bd.quantity_per_card, bd.card_color, m.part_code, m.material_name, m.unit
                 FROM bom_header bh
                 JOIN bom_detail bd ON bh.bom_id = bd.bom_id
                 JOIN materials m ON bd.material_id = m.material_id
                 WHERE bh.product_id = ? AND bh.status = 'active'";
        $stmt = $db->prepare($bomQ);
        $stmt->execute([$productId]);
        $bomItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result['data']['bom_items'] = $bomItems;

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'กรุณาระบุ parameter ?job=JOB... หรือ ?request=MR...'], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
