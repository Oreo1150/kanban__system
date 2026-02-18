<?php
// pages/store/inventory.php
$page_title = 'สินค้าคงเหลือ';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'สินค้าคงเหลือ']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['store', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลวัสดุ
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';

$where_conditions = ["m.status = 'active'"];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(m.part_code LIKE ? OR m.material_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category_filter)) {
    $where_conditions[] = "m.category = ?";
    $params[] = $category_filter;
}

$where_clause = implode(' AND ', $where_conditions);

$materials_query = "
    SELECT 
        m.*,
        COUNT(DISTINCT it.transaction_id) as transaction_count,
        MAX(it.transaction_date) as last_transaction_date
    FROM materials m
    LEFT JOIN inventory_transactions it ON m.material_id = it.material_id
    WHERE $where_clause
    GROUP BY m.material_id
    ORDER BY 
        CASE 
            WHEN m.current_stock <= m.min_stock THEN 1
            WHEN m.current_stock > m.max_stock THEN 2
            ELSE 3
        END,
        m.part_code ASC
";

$stmt = $db->prepare($materials_query);
$stmt->execute($params);
$materials = $stmt->fetchAll();

// ดึงหมวดหมู่ (ตรวจสอบว่า field 'category' มีอยู่ในตาราง materials หรือไม่)
$categories = [];
try {
    $colCheck = $db->query("SHOW COLUMNS FROM materials LIKE 'category'");
    if ($colCheck && $colCheck->rowCount() > 0) {
        $categories = $db->query(
            "SELECT DISTINCT category FROM materials WHERE status = 'active' ORDER BY category"
        )->fetchAll();
    }
} catch (PDOException $e) {
    // If SHOW COLUMNS fails, leave categories empty
    $categories = [];
}

// สถิติ - ทำให้ทนต่อการขาดคอลัมน์บางตัว (current_stock, min_stock, max_stock)
$stats = [
    'total_materials' => 0,
    'low_stock_count' => 0,
    'overstock_count' => 0,
    'total_stock_value' => 0
];
try {
    $statsQuery = "SELECT COUNT(*) as total_materials";
    // check columns before using them
    $hasCurrent = $db->query("SHOW COLUMNS FROM materials LIKE 'current_stock'")->rowCount() > 0;
    $hasMin = $db->query("SHOW COLUMNS FROM materials LIKE 'min_stock'")->rowCount() > 0;
    $hasMax = $db->query("SHOW COLUMNS FROM materials LIKE 'max_stock'")->rowCount() > 0;

    if ($hasCurrent && $hasMin) {
        $statsQuery .= ", SUM(CASE WHEN current_stock <= min_stock THEN 1 ELSE 0 END) as low_stock_count";
    } else {
        $statsQuery .= ", 0 as low_stock_count";
    }

    if ($hasCurrent && $hasMax) {
        $statsQuery .= ", SUM(CASE WHEN current_stock > max_stock THEN 1 ELSE 0 END) as overstock_count";
    } else {
        $statsQuery .= ", 0 as overstock_count";
    }

    if ($hasCurrent) {
        $statsQuery .= ", SUM(current_stock) as total_stock_value";
    } else {
        $statsQuery .= ", 0 as total_stock_value";
    }

    $statsQuery .= " FROM materials WHERE status = 'active'";
    $stats = $db->query($statsQuery)->fetch();
} catch (PDOException $e) {
    // keep defaults
}
?>

<?php
// --- Transactions: server-side search and recent receipts ---
$tx_search = trim($_GET['tx_search'] ?? '');
$tx_page = max(1, (int)($_GET['tx_page'] ?? 1));
$tx_limit = (int)($_GET['tx_limit'] ?? 25);
$tx_offset = ($tx_page - 1) * $tx_limit;

$tx_where = ["1=1"];
$tx_params = [];
if ($tx_search !== '') {
    $tx_where[] = "(m.part_code LIKE ? OR m.material_name LIKE ? OR it.reference_type LIKE ? OR it.notes LIKE ?)";
    $tx_params[] = "%$tx_search%";
    $tx_params[] = "%$tx_search%";
    $tx_params[] = "%$tx_search%";
    $tx_params[] = "%$tx_search%";
}

$tx_where_clause = implode(' AND ', $tx_where);

// Total count for transactions
$tx_count_q = "SELECT COUNT(*) as total FROM inventory_transactions it LEFT JOIN materials m ON it.material_id = m.material_id WHERE $tx_where_clause";
$tx_count_stmt = $db->prepare($tx_count_q);
$tx_count_stmt->execute($tx_params);
$tx_total = (int)$tx_count_stmt->fetchColumn();

$tx_q = "SELECT it.*, m.part_code, m.material_name, u.full_name as transaction_by_name
         FROM inventory_transactions it
         LEFT JOIN materials m ON it.material_id = m.material_id
         LEFT JOIN users u ON it.transaction_by = u.user_id
         WHERE $tx_where_clause
         ORDER BY it.transaction_date DESC
         LIMIT ? OFFSET ?";
$tx_stmt = $db->prepare($tx_q);
// bind search params then bind LIMIT/OFFSET as integers
$bindIndex = 1;
foreach ($tx_params as $p) {
    $tx_stmt->bindValue($bindIndex++, $p);
}
$tx_stmt->bindValue($bindIndex++, (int)$tx_limit, PDO::PARAM_INT);
$tx_stmt->bindValue($bindIndex++, (int)$tx_offset, PDO::PARAM_INT);
$tx_stmt->execute();
$transactions = $tx_stmt->fetchAll();

// Recent receipts (type 'in')
$recent_receipts_stmt = $db->prepare("SELECT it.*, m.part_code, m.material_name, u.full_name as transaction_by_name FROM inventory_transactions it LEFT JOIN materials m ON it.material_id = m.material_id LEFT JOIN users u ON it.transaction_by = u.user_id WHERE it.transaction_type = 'in' ORDER BY it.transaction_date DESC LIMIT 10");
$recent_receipts_stmt->execute();
$recent_receipts = $recent_receipts_stmt->fetchAll();
?>

            <!-- Stats -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3><?= number_format($stats['total_materials']) ?></h3>
                            <p class="mb-0 text-muted">วัสดุทั้งหมด</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-danger"><?= number_format($stats['low_stock_count']) ?></h3>
                            <p class="mb-0 text-muted">สต็อกต่ำ</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-warning"><?= number_format($stats['overstock_count']) ?></h3>
                            <p class="mb-0 text-muted">สต็อกเกิน</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3><?= number_format($stats['total_stock_value']) ?></h3>
                            <p class="mb-0 text-muted">รวมจำนวนทั้งหมด</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search & Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="searchInput" 
                                       placeholder="ค้นหารหัส หรือชื่อวัสดุ..." 
                                       value="<?= htmlspecialchars($search) ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <select class="form-control" id="categoryFilter">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= htmlspecialchars($cat['category']) ?>" 
                                            <?= $category_filter === $cat['category'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['category']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 text-end">
                            <button class="btn btn-primary" onclick="refreshPage()">
                                <i class="fas fa-sync-alt me-1"></i>รีเฟรช
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Materials Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="inventoryTable">
                        <thead class="table-light">
                            <tr>
                                <th>รหัส</th>
                                <th>ชื่อวัสดุ</th>
                                <th>หมวดหมู่</th>
                                <th>หน่วย</th>
                                <th>คงเหลือ</th>
                                <th>ต่ำสุด</th>
                                <th>สูงสุด</th>
                                <th>สถานะ</th>
                                <th>ที่เก็บ</th>
                                <th>การกระทำ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($materials)): ?>
                                <?php foreach ($materials as $material): ?>
                                    <?php
                                    $stock_status = $material['current_stock'] <= $material['min_stock'] ? 'danger' :
                                                   ($material['current_stock'] > $material['max_stock'] ? 'warning' : 'success');
                                    $stock_text = $material['current_stock'] <= $material['min_stock'] ? 'ต่ำ' :
                                                 ($material['current_stock'] > $material['max_stock'] ? 'เกิน' : 'ปกติ');
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($material['part_code']) ?></strong></td>
                                        <td><?= htmlspecialchars($material['material_name']) ?></td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?= htmlspecialchars($material['category'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($material['unit']) ?></td>
                                        <td>
                                            <strong><?= number_format($material['current_stock']) ?></strong>
                                        </td>
                                        <td><?= number_format($material['min_stock']) ?></td>
                                        <td><?= number_format($material['max_stock']) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $stock_status ?>">
                                                <i class="fas fa-<?= $stock_status === 'danger' ? 'exclamation-triangle' : ($stock_status === 'warning' ? 'arrow-up' : 'check') ?> me-1"></i>
                                                <?= $stock_text ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($material['location'] ?? 'N/A') ?></td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button class="btn btn-outline-primary btn-sm btn-view-material" data-id="<?= (int)$material['material_id'] ?>" data-code="<?= htmlspecialchars($material['part_code'] ?? '') ?>">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="btn btn-outline-secondary btn-sm btn-create-pr" data-id="<?= (int)$material['material_id'] ?>">
                                                    <i class="fas fa-shopping-cart"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox fa-2x mb-2"></i>
                                        <p>ไม่พบวัสดุ</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- Material Detail Modal -->
    <div class="modal fade" id="materialDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-box me-2"></i>รายละเอียดวัสดุ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="materialDetailContent">
                        <!-- Detail will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Material In/Out Modal -->
    <div class="modal fade" id="transactionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exchange-alt me-2"></i><span id="transactionTitle">บันทึกการเคลื่อนไหว</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="transactionForm">
                    <div class="modal-body">
                        <input type="hidden" id="transaction_material_id" name="material_id">
                        <input type="hidden" id="transaction_type" name="transaction_type">
                        
                        <div class="mb-3">
                            <label class="form-label">วัสดุ</label>
                            <input type="text" class="form-control" id="transaction_material_name" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">สต็อกปัจจุบัน</label>
                            <input type="text" class="form-control" id="transaction_current_stock" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">จำนวน <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="transaction_quantity" name="quantity" required min="1" step="0.01">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                            <select class="form-control" id="transaction_reference_type" name="reference_type" required>
                                <option value="">-- เลือกประเภท --</option>
                                <option value="purchase">การซื้อ</option>
                                <option value="production">การผลิต</option>
                                <option value="return">การคืน</option>
                                <option value="adjustment">การปรับปรุง</option>
                                <option value="damage">ชำรุด/สูญหาย</option>
                                <option value="other">อื่นๆ</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" id="transaction_notes" name="notes" rows="3" placeholder="ระบุหมายเหตุเพิ่มเติม"></textarea>
                        </div>
                    </div>
    
                        <!-- Inline Create PR Modal (inventory) -->
                        <div class="modal fade" id="invCreatePRModal" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>สร้างคำขอสั่งซื้อ</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div id="invLowStockAlert" class="alert alert-warning d-none" role="alert">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            <strong>แจ้งเตือน!</strong> วัสดุนี้มีสต็อกต่ำกว่าระดับต่ำสุด
                                        </div>
                                        <form id="invCreatePRForm">
                                            <div class="mb-3">
                                                <label class="form-label">วัสดุ <span class="text-danger">*</span></label>
                                                <select id="invPrMaterial" class="form-select" onchange="invUpdateMaterialInfo()"></select>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">หน่วย</label>
                                                    <input type="text" id="invPrUnit" class="form-control" readonly>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label">สต็อกปัจจุบัน</label>
                                                    <div class="input-group">
                                                        <input type="number" id="invPrCurrentStock" class="form-control" readonly>
                                                        <span class="input-group-text" id="invPrMinStock"></span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">จำนวนที่ขอ <span class="text-danger">*</span></label>
                                                <input type="number" id="invPrQuantity" class="form-control" min="1" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">วันที่คาดว่าจะได้รับ <span class="text-danger">*</span></label>
                                                <input type="date" id="invPrExpectedDate" class="form-control" required>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">ระดับความเร่งด่วน <span class="text-danger">*</span></label>
                                                <select id="invPrUrgency" class="form-select" required>
                                                    <option value="">-- เลือก --</option>
                                                    <option value="low">ต่ำ</option>
                                                    <option value="medium">ปานกลาง</option>
                                                    <option value="high">สูง</option>
                                                    <option value="urgent">ฉุกเฉิน</option>
                                                </select>
                                            </div>

                                            <div class="mb-3">
                                                <label class="form-label">หมายเหตุ</label>
                                                <textarea id="invPrNotes" class="form-control" rows="2"></textarea>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                                        <button type="button" class="btn btn-success" onclick="invSubmitCreatePR()"><i class="fas fa-save me-1"></i>สร้างคำขอ</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Search functionality
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchTerm = this.value;
            searchTimeout = setTimeout(() => {
                const currentUrl = new URL(window.location);
                if (searchTerm) {
                    currentUrl.searchParams.set('search', searchTerm);
                } else {
                    currentUrl.searchParams.delete('search');
                }
                window.location.href = currentUrl.toString();
            }, 500);
        });

        // Category filter
        document.getElementById('categoryFilter').addEventListener('change', function() {
            const currentUrl = new URL(window.location);
            if (this.value) {
                currentUrl.searchParams.set('category', this.value);
            } else {
                currentUrl.searchParams.delete('category');
            }
            window.location.href = currentUrl.toString();
        });

        // View material detail
        function viewMaterialDetail(materialId, partCode) {
            fetch(`../../api/materials.php?action=get&id=${materialId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const m = data.material;
                        let html = `
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>รหัส:</strong> ${htmlspecialchars(m.part_code)}<br>
                                    <strong>ชื่อ:</strong> ${htmlspecialchars(m.material_name)}<br>
                                    <strong>หมวดหมู่:</strong> ${htmlspecialchars(m.category || 'N/A')}<br>
                                    <strong>หน่วย:</strong> ${htmlspecialchars(m.unit)}<br>
                                    <strong>ที่เก็บ:</strong> ${htmlspecialchars(m.location || 'N/A')}
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title">ข้อมูลสต็อก</h6>
                                            <p class="mb-1"><strong>คงเหลือ:</strong> <span class="text-primary">${Number(m.current_stock).toLocaleString()}</span></p>
                                            <p class="mb-1"><strong>ต่ำสุด:</strong> <span class="text-muted">${Number(m.min_stock).toLocaleString()}</span></p>
                                            <p class="mb-0"><strong>สูงสุด:</strong> <span class="text-muted">${Number(m.max_stock).toLocaleString()}</span></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        document.getElementById('materialDetailContent').innerHTML = html;
                        new bootstrap.Modal(document.getElementById('materialDetailModal')).show();
                    }
    
                        <!-- Transactions & Recent Receipts -->
                        <div class="container-fluid mt-4">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="card mb-3">
                                        <div class="card-body">
                                            <h5 class="card-title">ประวัติการเคลื่อนไหว</h5>
                                            <div class="row mb-3">
                                                <div class="col-md-8">
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                                        <input id="txSearchInput" type="text" class="form-control" placeholder="ค้นหา รหัส/ชื่อวัสดุ หรือ หมายเหตุ..." value="<?= htmlspecialchars($tx_search) ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <label class="small">แสดงต่อหน้า</label>
                                                    <select id="txLimitSelect" class="form-select form-select-sm d-inline-block w-auto">
                                                        <?php foreach ([10,25,50,100] as $opt): ?>
                                                            <option value="<?= $opt ?>" <?= $tx_limit == $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>วันที่</th>
                                                            <th>รหัส</th>
                                                            <th>ชื่อวัสดุ</th>
                                                            <th>ประเภท</th>
                                                            <th>จำนวน</th>
                                                            <th>โดย</th>
                                                            <th>หมายเหตุ</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (!empty($transactions)): ?>
                                                            <?php foreach ($transactions as $tx): ?>
                                                                <tr>
                                                                    <td><?= htmlspecialchars($tx['transaction_date']) ?></td>
                                                                    <td><?= htmlspecialchars($tx['part_code'] ?? 'N/A') ?></td>
                                                                    <td><?= htmlspecialchars($tx['material_name'] ?? 'N/A') ?></td>
                                                                    <td><?= htmlspecialchars($tx['transaction_type']) ?></td>
                                                                    <td><?= htmlspecialchars($tx['quantity']) ?></td>
                                                                    <td><?= htmlspecialchars($tx['transaction_by_name'] ?? 'System') ?></td>
                                                                    <td><?= htmlspecialchars($tx['notes'] ?? '') ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <tr><td colspan="7" class="text-center text-muted">ไม่พบรายการ</td></tr>
                                                        <?php endif; ?>
                                                    </tbody>
                                                </table>
                                            </div>

                                            <div class="d-flex justify-content-between align-items-center mt-2">
                                                <div class="small text-muted">รวม <strong><?= number_format($tx_total) ?></strong> รายการ</div>
                                                <div>
                                                    <?php $tx_total_pages = max(1, ceil($tx_total / $tx_limit)); ?>
                                                    <div class="btn-group">
                                                        <button class="btn btn-sm btn-outline-secondary" <?= $tx_page <= 1 ? 'disabled' : '' ?> onclick="txChangePage(<?= $tx_page - 1 ?>)">ก่อนหน้า</button>
                                                        <button class="btn btn-sm btn-outline-secondary" <?= $tx_page >= $tx_total_pages ? 'disabled' : '' ?> onclick="txChangePage(<?= $tx_page + 1 ?>)">ถัดไป</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title">รับเข้า ล่าสุด</h6>
                                            <ul class="list-group list-group-flush">
                                                <?php if (!empty($recent_receipts)): ?>
                                                    <?php foreach ($recent_receipts as $r): ?>
                                                        <li class="list-group-item small">
                                                            <div><strong><?= htmlspecialchars($r['part_code'] ?? '') ?></strong> - <?= htmlspecialchars($r['material_name'] ?? '') ?></div>
                                                            <div class="text-muted small"><?= htmlspecialchars($r['transaction_date']) ?> • <?= htmlspecialchars($r['quantity']) ?> by <?= htmlspecialchars($r['transaction_by_name'] ?? 'System') ?></div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <li class="list-group-item small text-muted">ไม่มีรายการรับเข้า</li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                })
                .catch(error => {
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดรายละเอียดได้', 'error');
                });
        }

        // Record material in
        function recordMaterialIn(materialId, partCode) {
            const modal = new bootstrap.Modal(document.getElementById('transactionModal'));
            document.getElementById('transactionTitle').textContent = 'รับวัสดุเข้า';
            document.getElementById('transaction_material_id').value = materialId;
            document.getElementById('transaction_type').value = 'in';
            document.getElementById('transaction_material_name').value = partCode;
            
            // Get current stock
            fetch(`../../api/materials.php?action=get&id=${materialId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('transaction_current_stock').value = Number(data.material.current_stock).toLocaleString();
                    }
                });
            
            modal.show();
        }

        // Record material out
        function recordMaterialOut(materialId, partCode) {
            const modal = new bootstrap.Modal(document.getElementById('transactionModal'));
            document.getElementById('transactionTitle').textContent = 'จ่ายวัสดุออก';
            document.getElementById('transaction_material_id').value = materialId;
            document.getElementById('transaction_type').value = 'out';
            document.getElementById('transaction_material_name').value = partCode;
            
            // Get current stock
            fetch(`../../api/materials.php?action=get&id=${materialId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('transaction_current_stock').value = Number(data.material.current_stock).toLocaleString();
                    }
                });
            
            modal.show();
        }

        // Submit transaction
        document.getElementById('transactionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const transactionType = formData.get('transaction_type');
            
            Swal.fire({
                title: `ยืนยัน${transactionType === 'in' ? 'รับวัสดุเข้า' : 'จ่ายวัสดุออก'}?`,
                text: `จำนวน: ${formData.get('quantity')}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    formData.append('action', 'transaction');
                    fetch('../../api/inventory.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('สำเร็จ', data.message, 'success').then(() => {
                                bootstrap.Modal.getInstance(document.getElementById('transactionModal')).hide();
                                location.reload();
                            });
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถบันทึกข้อมูลได้', 'error');
                    });
                }
            });
        });

        function refreshPage() {
            location.reload();
        }

        function htmlspecialchars(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Open inline Create PR modal for selected material
        function createPurchaseRequest(materialId) {
            openInvCreatePRModal(materialId);
        }

        // --- Inline Create PR modal logic (inventory page) ---
        function openInvCreatePRModal(preselectId = null) {
            const select = document.getElementById('invPrMaterial');
            select.innerHTML = '<option value="">-- โหลดข้อมูล... --</option>';

            fetch('../../api/materials.php?action=get_all&limit=200')
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) {
                        Swal.fire('แจ้งเตือน', 'ไม่สามารถโหลดรายการวัสดุได้', 'warning');
                        return;
                    }
                    select.innerHTML = '<option value="">-- เลือกวัสดุ --</option>';
                    const materials = data.data || data.materials || [];
                    materials.forEach(m => {
                        const opt = document.createElement('option');
                        opt.value = m.material_id;
                        opt.textContent = `${m.part_code} - ${m.material_name}` + (m.current_stock < m.min_stock ? ' ⚠️' : '');
                        opt.dataset.unit = m.unit;
                        opt.dataset.stock = m.current_stock;
                        opt.dataset.minStock = m.min_stock;
                        opt.dataset.maxStock = m.max_stock;
                        select.appendChild(opt);
                    });
                    if (preselectId) {
                        select.value = preselectId;
                        invUpdateMaterialInfo();
                    }
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 7);
                    document.getElementById('invPrExpectedDate').valueAsDate = tomorrow;
                    new bootstrap.Modal(document.getElementById('invCreatePRModal')).show();
                })
                .catch(err => {
                    console.error(err);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดรายการวัสดุได้', 'error');
                });
        }

        function invUpdateMaterialInfo() {
            const select = document.getElementById('invPrMaterial');
            const opt = select.options[select.selectedIndex];
            if (!opt) return;
            document.getElementById('invPrUnit').value = opt.dataset.unit || '';
            document.getElementById('invPrCurrentStock').value = opt.dataset.stock || '';
            document.getElementById('invPrMinStock').textContent = `ต่ำสุด: ${opt.dataset.minStock || 0} / สูงสุด: ${opt.dataset.maxStock || 0}`;
            const alertBox = document.getElementById('invLowStockAlert');
            if ((parseInt(opt.dataset.stock) || 0) < (parseInt(opt.dataset.minStock) || 0)) {
                alertBox.classList.remove('d-none');
            } else {
                alertBox.classList.add('d-none');
            }
        }

        function invSubmitCreatePR() {
            const materialId = document.getElementById('invPrMaterial').value;
            const quantity = document.getElementById('invPrQuantity').value;
            const expectedDate = document.getElementById('invPrExpectedDate').value;
            const urgency = document.getElementById('invPrUrgency').value;
            const notes = document.getElementById('invPrNotes').value;
            if (!materialId || !quantity || !expectedDate || !urgency) {
                Swal.fire('แจ้งเตือน', 'กรุณากรอกข้อมูลที่จำเป็นทั้งหมด', 'warning');
                return;
            }
            const fd = new FormData();
            fd.append('action', 'create');
            fd.append('material_id', materialId);
            fd.append('quantity_requested', quantity);
            fd.append('expected_date', expectedDate);
            fd.append('urgency', urgency);
            fd.append('notes', notes);

            fetch('../../api/purchase-requests.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('สำเร็จ', `สร้างคำขอ ${data.pr_number} เรียบร้อยแล้ว`, 'success')
                            .then(() => {
                                bootstrap.Modal.getInstance(document.getElementById('invCreatePRModal')).hide();
                                refreshPage();
                            });
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message || 'ไม่สำเร็จ', 'error');
                    }
                })
                .catch(err => {
                    console.error(err);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถสร้างคำขอได้', 'error');
                });
        }

        // Transactions search & pagination handlers
        let txSearchTimeout;
        const txSearchInput = document.getElementById('txSearchInput');
        const txLimitSelect = document.getElementById('txLimitSelect');
        if (txSearchInput) {
            txSearchInput.addEventListener('input', function() {
                clearTimeout(txSearchTimeout);
                const val = this.value;
                txSearchTimeout = setTimeout(() => {
                    const url = new URL(window.location);
                    if (val) url.searchParams.set('tx_search', val); else url.searchParams.delete('tx_search');
                    url.searchParams.set('tx_page', 1);
                    window.location.href = url.toString();
                }, 400);
            });
        }
        if (txLimitSelect) {
            txLimitSelect.addEventListener('change', function() {
                const url = new URL(window.location);
                url.searchParams.set('tx_limit', this.value);
                url.searchParams.set('tx_page', 1);
                window.location.href = url.toString();
            });
        }

        function txChangePage(page) {
            const url = new URL(window.location);
            url.searchParams.set('tx_page', page);
            window.location.href = url.toString();
        }

        // Delegated click handlers for inventory table buttons
        (function() {
            const table = document.getElementById('inventoryTable');
            console.log('Inventory table handler init, table=', !!table);
            if (!table) return;
            table.addEventListener('click', function(e) {
                const viewBtn = e.target.closest('.btn-view-material');
                if (viewBtn) {
                    console.log('view button clicked', viewBtn.dataset);
                    const id = parseInt(viewBtn.dataset.id);
                    const code = viewBtn.dataset.code || '';
                    if (typeof viewMaterialDetail === 'function') {
                        viewMaterialDetail(id, code);
                    } else {
                        console.log('viewMaterialDetail not defined');
                    }
                    return;
                }

                const prBtn = e.target.closest('.btn-create-pr');
                if (prBtn) {
                    console.log('create-pr clicked', prBtn.dataset);
                    const id = parseInt(prBtn.dataset.id);
                    if (typeof openInvCreatePRModal === 'function') {
                        openInvCreatePRModal(id);
                    } else {
                        console.log('openInvCreatePRModal not defined');
                    }
                    return;
                }
            });
        })();
    </script>

</body>
</html>
