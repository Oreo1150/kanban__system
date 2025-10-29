<?php
// pages/planning/purchase-requests-manager.php
$page_title = 'จัดการคำขอสั่งซื้อวัสดุ';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'จัดการคำขอสั่งซื้อ']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['planning', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลคำขอทั้งหมด
$requests = $db->query("
    SELECT pr.*, m.part_code, m.material_name, m.unit, m.current_stock, m.min_stock,
           u1.full_name as created_by_name, u1.role as created_by_role,
           u2.full_name as approved_by_name
    FROM purchase_requests pr
    LEFT JOIN materials m ON pr.material_id = m.material_id
    LEFT JOIN users u1 ON pr.created_by = u1.user_id
    LEFT JOIN users u2 ON pr.approved_by = u2.user_id
    ORDER BY 
        CASE pr.status WHEN 'pending' THEN 1 ELSE 2 END,
        CASE pr.urgency 
            WHEN 'urgent' THEN 1
            WHEN 'high' THEN 2
            WHEN 'medium' THEN 3
            WHEN 'low' THEN 4
        END,
        pr.created_at DESC
")->fetchAll();

// สถิติคำขอ
$stats = $db->query("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'ordered' THEN 1 ELSE 0 END) as ordered,
        SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received
    FROM purchase_requests
    WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
")->fetch();
?>

<style>
    .request-card {
        border: 2px solid #e9ecef;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 15px;
        transition: all 0.3s ease;
    }

    .request-card:hover {
        border-color: var(--primary-color);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
    }

    .request-card.urgent {
        border-left: 5px solid #dc3545;
    }

    .request-card.high {
        border-left: 5px solid #ffc107;
    }

    .action-buttons .btn {
        margin: 2px;
        border-radius: 20px;
    }

    .filter-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        flex-wrap: wrap;
    }

    .filter-tab {
        padding: 10px 20px;
        border-radius: 25px;
        border: 2px solid #e9ecef;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .filter-tab:hover {
        border-color: var(--primary-color);
        color: var(--primary-color);
    }

    .filter-tab.active {
        background: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }

    .urgency-indicator {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 5px;
    }

    .urgency-urgent { background: #dc3545; }
    .urgency-high { background: #ffc107; }
    .urgency-medium { background: #17a2b8; }
    .urgency-low { background: #28a745; }
</style>

<div class="row mb-4">
    <!-- Statistics Cards -->
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="text-warning"><?= $stats['pending'] ?></h2>
                <small>รอพิจารณา</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="text-success"><?= $stats['approved'] ?></h2>
                <small>อนุมัติ</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="text-danger"><?= $stats['rejected'] ?></h2>
                <small>ปฏิเสธ</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="text-info"><?= $stats['ordered'] ?></h2>
                <small>สั่งซื้อแล้ว</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="text-secondary"><?= $stats['received'] ?></h2>
                <small>รับแล้ว</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center">
            <div class="card-body">
                <h2 class="text-primary"><?= $stats['total_requests'] ?></h2>
                <small>ทั้งหมด (30 วัน)</small>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5><i class="fas fa-shopping-cart me-2"></i>คำขอสั่งซื้อทั้งหมด</h5>
            <div>
                <input type="text" id="searchPR" class="form-control form-control-sm" 
                       placeholder="ค้นหา PR หรือวัสดุ..." style="min-width: 250px;">
            </div>
        </div>
    </div>
    <div class="card-body">
        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <div class="filter-tab active" data-filter="all">
                <i class="fas fa-list"></i> ทั้งหมด (<?= count($requests) ?>)
            </div>
            <div class="filter-tab" data-filter="pending">
                <i class="fas fa-clock"></i> รอพิจารณา (<?= $stats['pending'] ?>)
            </div>
            <div class="filter-tab" data-filter="urgent">
                <i class="fas fa-exclamation-triangle"></i> เร่งด่วน
            </div>
            <div class="filter-tab" data-filter="approved">
                <i class="fas fa-check-circle"></i> อนุมัติแล้ว
            </div>
            <div class="filter-tab" data-filter="ordered">
                <i class="fas fa-truck"></i> สั่งซื้อแล้ว
            </div>
        </div>

        <!-- Requests List -->
        <div id="requestsList">
            <?php if (!empty($requests)): ?>
                <?php foreach ($requests as $request): ?>
                    <?php
                    $urgency_config = [
                        'low' => ['class' => 'urgency-low', 'text' => 'ปกติ'],
                        'medium' => ['class' => 'urgency-medium', 'text' => 'ค่อนข้างเร่ง'],
                        'high' => ['class' => 'urgency-high', 'text' => 'เร่งด่วน'],
                        'urgent' => ['class' => 'urgency-urgent', 'text' => 'เร่งด่วนมาก']
                    ];
                    $urgency = $urgency_config[$request['urgency']];
                    
                    $status_config = [
                        'pending' => ['class' => 'warning', 'icon' => 'clock', 'text' => 'รอพิจารณา'],
                        'approved' => ['class' => 'success', 'icon' => 'check-circle', 'text' => 'อนุมัติ'],
                        'rejected' => ['class' => 'danger', 'icon' => 'times-circle', 'text' => 'ปฏิเสธ'],
                        'ordered' => ['class' => 'info', 'icon' => 'truck', 'text' => 'สั่งซื้อแล้ว'],
                        'received' => ['class' => 'secondary', 'icon' => 'check', 'text' => 'รับแล้ว']
                    ];
                    $status = $status_config[$request['status']];
                    
                    $stock_percent = $request['min_stock'] > 0 
                        ? ($request['current_stock'] / $request['min_stock']) * 100 
                        : 100;
                    ?>
                    <div class="request-card <?= $request['urgency'] ?>" 
                         data-status="<?= $request['status'] ?>" 
                         data-urgency="<?= $request['urgency'] ?>"
                         data-search="<?= strtolower($request['pr_number'] . ' ' . $request['part_code'] . ' ' . $request['material_name']) ?>">
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="d-flex align-items-start mb-2">
                                    <span class="<?= $urgency['class'] ?> urgency-indicator"></span>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1">
                                            <strong><?= htmlspecialchars($request['pr_number']) ?></strong>
                                            <span class="badge bg-<?= $status['class'] ?> ms-2">
                                                <i class="fas fa-<?= $status['icon'] ?>"></i>
                                                <?= $status['text'] ?>
                                            </span>
                                        </h5>
                                        <p class="text-muted mb-2">
                                            <strong><?= htmlspecialchars($request['part_code']) ?></strong> - 
                                            <?= htmlspecialchars($request['material_name']) ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6">
                                        <small class="text-muted">จำนวนที่ขอ:</small>
                                        <div>
                                            <strong class="fs-5"><?= number_format($request['quantity_requested']) ?></strong>
                                            <?= htmlspecialchars($request['unit']) ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted">สต็อกปัจจุบัน:</small>
                                        <div>
                                            <span class="<?= $stock_percent < 50 ? 'text-danger' : 'text-warning' ?>">
                                                <strong><?= number_format($request['current_stock']) ?></strong>
                                            </span>
                                            / <?= number_format($request['min_stock']) ?>
                                            <?= htmlspecialchars($request['unit']) ?>
                                        </div>
                                        <div class="progress mt-1" style="height: 5px;">
                                            <div class="progress-bar <?= $stock_percent < 50 ? 'bg-danger' : 'bg-warning' ?>" 
                                                 style="width: <?= min($stock_percent, 100) ?>%"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-user"></i> <?= htmlspecialchars($request['created_by_name']) ?> (<?= ucfirst($request['created_by_role']) ?>)
                                        <span class="ms-3">
                                            <i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                        </span>
                                        <span class="ms-3">
                                            <i class="fas fa-calendar"></i> ต้องการ: <?= date('d/m/Y', strtotime($request['expected_date'])) ?>
                                        </span>
                                    </small>
                                </div>

                                <?php if ($request['notes']): ?>
                                    <div class="mt-2 small">
                                        <i class="fas fa-sticky-note text-muted"></i>
                                        <?= nl2br(htmlspecialchars(mb_substr($request['notes'], 0, 100))) ?>
                                        <?= mb_strlen($request['notes']) > 100 ? '...' : '' ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-4 text-end">
                                <div class="mb-2">
                                    <span class="badge bg-secondary"><?= $urgency['text'] ?></span>
                                </div>

                                <div class="action-buttons">
                                    <button class="btn btn-info btn-sm" onclick="viewPRDetail(<?= $request['pr_id'] ?>)">
                                        <i class="fas fa-eye"></i> ดูรายละเอียด
                                    </button>

                                    <?php if ($request['status'] === 'pending'): ?>
                                        <button class="btn btn-success btn-sm" onclick="approvePR(<?= $request['pr_id'] ?>, '<?= htmlspecialchars($request['pr_number']) ?>')">
                                            <i class="fas fa-check"></i> อนุมัติ
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="rejectPR(<?= $request['pr_id'] ?>, '<?= htmlspecialchars($request['pr_number']) ?>')">
                                            <i class="fas fa-times"></i> ปฏิเสธ
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($request['status'] === 'approved'): ?>
                                        <button class="btn btn-primary btn-sm" onclick="markAsOrdered(<?= $request['pr_id'] ?>)">
                                            <i class="fas fa-truck"></i> สั่งซื้อแล้ว
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($request['status'] === 'ordered'): ?>
                                        <button class="btn btn-success btn-sm" onclick="markAsReceived(<?= $request['pr_id'] ?>, '<?= htmlspecialchars($request['material_name']) ?>', <?= $request['quantity_requested'] ?>)">
                                            <i class="fas fa-check-circle"></i> รับแล้ว
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if ($request['approved_by_name']): ?>
                                    <div class="mt-2 small text-muted">
                                        ดำเนินการโดย:<br>
                                        <?= htmlspecialchars($request['approved_by_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted">ไม่มีคำขอสั่งซื้อ</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Detail Modal -->
<div class="modal fade" id="prDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">รายละเอียดคำขอสั่งซื้อ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="prDetailContent">
                <!-- Content loaded by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Filter functionality
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        // Update active tab
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.dataset.filter;
        const cards = document.querySelectorAll('.request-card');
        
        cards.forEach(card => {
            if (filter === 'all') {
                card.style.display = 'block';
            } else if (filter === 'urgent') {
                card.style.display = card.dataset.urgency === 'urgent' ? 'block' : 'none';
            } else {
                card.style.display = card.dataset.status === filter ? 'block' : 'none';
            }
        });
    });
});

// Search functionality
document.getElementById('searchPR').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const cards = document.querySelectorAll('.request-card');
    
    cards.forEach(card => {
        const searchData = card.dataset.search;
        card.style.display = searchData.includes(searchTerm) ? 'block' : 'none';
    });
});

// View PR Detail
function viewPRDetail(prId) {
    fetch(`../../api/purchase-requests.php?action=get&id=${prId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayPRDetail(data.data);
                new bootstrap.Modal(document.getElementById('prDetailModal')).show();
            } else {
                Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
            }
        });
}

function displayPRDetail(pr) {
    // Similar to Store's display function
    // Implementation details in previous file
    document.getElementById('prDetailContent').innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <h6>ข้อมูลคำขอ</h6>
                <p><strong>เลขที่:</strong> ${pr.pr_number}</p>
                <p><strong>สถานะ:</strong> <span class="badge bg-warning">${pr.status}</span></p>
                <p><strong>ผู้ขอ:</strong> ${pr.created_by_name}</p>
            </div>
            <div class="col-md-6">
                <h6>ข้อมูลวัสดุ</h6>
                <p><strong>รหัส:</strong> ${pr.part_code}</p>
                <p><strong>จำนวน:</strong> ${pr.quantity_requested} ${pr.unit}</p>
                <p><strong>สต็อกปัจจุบัน:</strong> ${pr.current_stock} ${pr.unit}</p>
            </div>
        </div>
        ${pr.notes ? `<div class="mt-3"><strong>หมายเหตุ:</strong><br>${pr.notes}</div>` : ''}
    `;
}

// Approve PR
function approvePR(prId, prNumber) {
    Swal.fire({
        title: 'อนุมัติคำขอสั่งซื้อ?',
        html: `<p>คุณต้องการอนุมัติคำขอ <strong>${prNumber}</strong> หรือไม่?</p>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'อนุมัติ',
        cancelButtonText: 'ยกเลิก',
        input: 'textarea',
        inputPlaceholder: 'หมายเหตุ (ไม่บังคับ)'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'approve');
            formData.append('pr_id', prId);
            formData.append('notes', result.value || '');
            
            fetch('../../api/purchase-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', 'อนุมัติคำขอเรียบร้อย', 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            });
        }
    });
}

// Reject PR
function rejectPR(prId, prNumber) {
    Swal.fire({
        title: 'ปฏิเสธคำขอสั่งซื้อ?',
        html: `<p>คุณต้องการปฏิเสธคำขอ <strong>${prNumber}</strong> หรือไม่?</p>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'ปฏิเสธ',
        cancelButtonText: 'ยกเลิก',
        input: 'textarea',
        inputLabel: 'เหตุผล',
        inputPlaceholder: 'ระบุเหตุผลในการปฏิเสธ',
        inputValidator: (value) => {
            if (!value) {
                return 'กรุณาระบุเหตุผล'
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'reject');
            formData.append('pr_id', prId);
            formData.append('reason', result.value);
            
            fetch('../../api/purchase-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', 'ปฏิเสธคำขอเรียบร้อย', 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            });
        }
    });
}

// Mark as Ordered
function markAsOrdered(prId) {
    Swal.fire({
        title: 'ยืนยันการสั่งซื้อ?',
        text: 'คุณได้ดำเนินการสั่งซื้อจากซัพพลายเออร์แล้วหรือไม่?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#17a2b8',
        confirmButtonText: 'ยืนยัน',
        input: 'textarea',
        inputPlaceholder: 'หมายเหตุ เช่น PO Number, ซัพพลายเออร์, วันที่คาดว่าจะได้รับ'
    }).then((result) => {
        if (result.isConfirmed) {
            updatePRStatus(prId, 'ordered', result.value);
        }
    });
}

// Mark as Received
function markAsReceived(prId, materialName, quantity) {
    Swal.fire({
        title: 'ยืนยันการรับวัสดุ?',
        html: `
            <p>ยืนยันว่าได้รับวัสดุ <strong>${materialName}</strong></p>
            <p>จำนวน <strong>${quantity.toLocaleString()}</strong> แล้วหรือไม่?</p>
            <div class="alert alert-info text-start">
                <i class="fas fa-info-circle"></i>
                สต็อกวัสดุจะถูกเพิ่มอัตโนมัติ
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        confirmButtonText: 'ยืนยันรับแล้ว',
        input: 'textarea',
        inputPlaceholder: 'หมายเหตุ (ไม่บังคับ)'
    }).then((result) => {
        if (result.isConfirmed) {
            updatePRStatus(prId, 'received', result.value);
        }
    });
}

// Update PR Status
function updatePRStatus(prId, status, notes = '') {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('pr_id', prId);
    formData.append('status', status);
    formData.append('notes', notes);
    
    fetch('../../api/purchase-requests.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('สำเร็จ', 'อัพเดทสถานะเรียบร้อย', 'success').then(() => {
                location.reload();
            });
        } else {
            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
        }
    });
}

// Toggle Sidebar
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('show');
}
</script>

</body>
</html>