<?php
// pages/store/receipts-report.php
$page_title = 'รายงานการรับวัสดุ (Receipts)';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'รายงานรับเข้า']
];

require_once '../../config/config.php';
require_once '../../config/database.php';

// Ensure user is authorized before exporting
checkRole(['store','admin']);

$database = new Database();
$db = $database->getConnection();

// If exporting CSV, handle before any HTML output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $search = trim($_GET['search'] ?? '');
    $material_id = (int)($_GET['material_id'] ?? 0);
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

    $where = ["it.transaction_type = 'in'"];
    $params = [];
    if ($search !== '') {
        $where[] = "(m.part_code LIKE ? OR m.material_name LIKE ? OR it.notes LIKE ? OR it.reference_type LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($material_id > 0) {
        $where[] = "it.material_id = ?";
        $params[] = $material_id;
    }
    if ($start_date) {
        $where[] = "it.transaction_date >= ?";
        $params[] = $start_date . ' 00:00:00';
    }
    if ($end_date) {
        $where[] = "it.transaction_date <= ?";
        $params[] = $end_date . ' 23:59:59';
    }

    $where_clause = implode(' AND ', $where);

    $q = "SELECT it.*, m.part_code, m.material_name, m.unit, u.full_name as received_by FROM inventory_transactions it LEFT JOIN materials m ON it.material_id = m.material_id LEFT JOIN users u ON it.transaction_by = u.user_id WHERE $where_clause ORDER BY it.transaction_date DESC";
    $stmt = $db->prepare($q);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=receipts_report.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['วันที่', 'รหัส', 'ชื่อวัสดุ', 'จำนวน', 'หน่วย', 'ประเภท', 'หมายเหตุ', 'ผู้รับเข้า']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['transaction_date'],
            $r['part_code'] ?? '',
            $r['material_name'] ?? '',
            $r['quantity'],
            $r['unit'] ?? '',
            $r['reference_type'] ?? '',
            $r['notes'] ?? '',
            $r['received_by'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Now include header and sidebar for normal page rendering
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// Filters
$search = trim($_GET['search'] ?? '');
$material_id = (int)($_GET['material_id'] ?? 0);
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$export = $_GET['export'] ?? '';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = (int)($_GET['limit'] ?? 25);
$offset = ($page - 1) * $limit;

// materials for filter
$materials = $db->query("SELECT material_id, part_code, material_name FROM materials WHERE status = 'active' ORDER BY part_code")->fetchAll();

$where = ["it.transaction_type = 'in'"];
$params = [];
if ($search !== '') {
    $where[] = "(m.part_code LIKE ? OR m.material_name LIKE ? OR it.notes LIKE ? OR it.reference_type LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($material_id > 0) {
    $where[] = "it.material_id = ?";
    $params[] = $material_id;
}
if ($start_date) {
    $where[] = "it.transaction_date >= ?";
    $params[] = $start_date . ' 00:00:00';
}
if ($end_date) {
    $where[] = "it.transaction_date <= ?";
    $params[] = $end_date . ' 23:59:59';
}

$where_clause = implode(' AND ', $where);

// If exporting CSV, fetch all matching rows (no pagination)
if ($export === 'csv') {
    $q = "SELECT it.*, m.part_code, m.material_name, m.unit, u.full_name as received_by FROM inventory_transactions it LEFT JOIN materials m ON it.material_id = m.material_id LEFT JOIN users u ON it.transaction_by = u.user_id WHERE $where_clause ORDER BY it.transaction_date DESC";
    $stmt = $db->prepare($q);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=receipts_report.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['วันที่', 'รหัส', 'ชื่อวัสดุ', 'จำนวน', 'หน่วย', 'ประเภท', 'หมายเหตุ', 'ผู้รับเข้า']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['transaction_date'],
            $r['part_code'] ?? '',
            $r['material_name'] ?? '',
            $r['quantity'],
            $r['unit'] ?? '',
            $r['reference_type'] ?? '',
            $r['notes'] ?? '',
            $r['received_by'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// total count
$count_q = "SELECT COUNT(*) FROM inventory_transactions it LEFT JOIN materials m ON it.material_id = m.material_id WHERE $where_clause";
$count_stmt = $db->prepare($count_q);
$count_stmt->execute($params);
$total = (int)$count_stmt->fetchColumn();

$q = "SELECT it.*, m.part_code, m.material_name, m.unit, u.full_name as received_by
      FROM inventory_transactions it
      LEFT JOIN materials m ON it.material_id = m.material_id
      LEFT JOIN users u ON it.transaction_by = u.user_id
      WHERE $where_clause
      ORDER BY it.transaction_date DESC
      LIMIT ? OFFSET ?";
$stmt = $db->prepare($q);
// Bind existing params (if any), then bind LIMIT and OFFSET as integers
$bindIndex = 1;
foreach ($params as $p) {
    $stmt->bindValue($bindIndex++, $p);
}
$stmt->bindValue($bindIndex++, (int)$limit, PDO::PARAM_INT);
$stmt->bindValue($bindIndex++, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

?>

            <div class="row mb-3">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <form class="row g-2" method="get">
                                <div class="col-md-3">
                                    <input type="text" name="search" class="form-control" placeholder="ค้นหา รหัส/ชื่อ/หมายเหตุ" value="<?= htmlspecialchars($search) ?>">
                                </div>
                                <div class="col-md-3">
                                    <select name="material_id" class="form-select">
                                        <option value="0">-- วัสดุทั้งหมด --</option>
                                        <?php foreach ($materials as $m): ?>
                                            <option value="<?= $m['material_id'] ?>" <?= $material_id == $m['material_id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['part_code'].' - '.$m['material_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                                </div>
                                <div class="col-md-2">
                                    <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                                </div>
                                <div class="col-md-2 text-end">
                                    <button class="btn btn-primary">ค้นหา</button>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-outline-secondary">ส่งออก CSV</a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>วันที่</th>
                                <th>รหัส</th>
                                <th>ชื่อวัสดุ</th>
                                <th>จำนวน</th>
                                <th>หน่วย</th>
                                <th>ประเภท</th>
                                <th>หมายเหตุ</th>
                                <th>ผู้รับเข้า</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($rows)): ?>
                                <?php foreach ($rows as $r): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($r['transaction_date']) ?></td>
                                        <td><?= htmlspecialchars($r['part_code'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['material_name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['quantity']) ?></td>
                                        <td><?= htmlspecialchars($r['unit'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['reference_type'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['notes'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($r['received_by'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" class="text-center text-muted">ไม่พบข้อมูล</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <div class="small text-muted">รวม <?= number_format($total) ?> รายการ</div>
                    <div>
                        <?php $total_pages = max(1, ceil($total / $limit)); ?>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-secondary" <?= $page <= 1 ? 'disabled' : '' ?> onclick="location.href='?<?= http_build_query(array_merge($_GET, ['page'=>$page-1])) ?>'">ก่อนหน้า</button>
                            <button class="btn btn-sm btn-outline-secondary" <?= $page >= $total_pages ? 'disabled' : '' ?> onclick="location.href='?<?= http_build_query(array_merge($_GET, ['page'=>$page+1])) ?>'">ถัดไป</button>
                        </div>
                    </div>
                </div>
            </div>

<?php require_once '../../includes/footer.php'; ?>
