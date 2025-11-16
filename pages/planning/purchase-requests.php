<?php
// pages/planning/purchase-requests.php
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
        SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
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
        background: white;
    }

    .request-card:hover {
        border-color: var(--primary-color);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        transform: translateY(-2px);
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
        display: flex;
        align-items: center;
        gap: 8px;
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

    .stock-progress {
        height: 8px;
        background: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
        margin-top: 5px;
    }

    .stock-progress-bar {
        height: 100%;
        transition: width 0.3s ease;
    }
</style>

<div class="row mb-4">
    <!-- Statistics Cards -->
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <i class="fas fa-clock text-warning fa-2x mb-2"></i>
                <h2 class="text-warning mb-0"><?= $stats['pending'] ?></h2>
                <small class="text-muted">รอพิจารณา</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                <h2 class="text-success mb-0"><?= $stats['approved'] ?></h2>
                <small class="text-muted">อนุมัติ</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <i class="fas fa-times-circle text-danger fa-2x mb-2"></i>
                <h2 class="text-danger mb-0"><?= $stats['rejected'] ?></h2>
                <small class="text-muted">ปฏิเสธ</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <i class="fas fa-truck text-info fa-2x mb-2"></i>
                <h2 class="text-info mb-0"><?= $stats['ordered'] ?></h2>
                <small class="text-muted">สั่งซื้อแล้ว</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <i class="fas fa-box text-secondary fa-2x mb-2"></i>
                <h2 class="text-secondary mb-0"><?= $stats['received'] ?></h2>
                <small class="text-muted">รับแล้ว</small>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-6 mb-3">
        <div class="card text-center border-0 shadow-sm">
            <div class="card-body">
                <i class="fas fa-list text-primary fa-2x mb-2"></i>
                <h2 class="text-primary mb-0"><?= $stats['total_requests'] ?></h2>
                <small class="text-muted">ทั้งหมด (30 วัน)</small>
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
                       placeholder="🔍 ค้นหา PR หรือวัสดุ..." style="min-width: 250px;">
            </div>
        </div>
    </div>
    <div class="card-body">
        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <div class="filter-tab active" data-filter="all">
                <i class="fas fa-list"></i> 
                <span>ทั้งหมด (<?= count($requests) ?>)</span>
            </div>
            <div class="filter-tab" data-filter="pending">
                <i class="fas fa-clock"></i> 
                <span>รอพิจารณา (<?= $stats['pending'] ?>)</span>
            </div>
            <div class="filter-tab" data-filter="urgent">
                <i class="fas fa-exclamation-triangle"></i> 
                <span>เร่งด่วน</span>
            </div>
            <div class="filter-tab" data-filter="approved">
                <i class="fas fa-check-circle"></i> 
                <span>อนุมัติแล้ว (<?= $stats['approved'] ?>)</span>
            </div>
            <div class="filter-tab" data-filter="ordered">
                <i class="fas fa-truck"></i> 
                <span>สั่งซื้อแล้ว (<?= $stats['ordered'] ?>)</span>
            </div>
            <div class="filter-tab" data-filter="received">
                <i class="fas fa-box"></i> 
                <span>รับแล้ว (<?= $stats['received'] ?>)</span>
            </div>
        </div>

        <!-- Requests List -->
        <div id="requestsList">
            <?php if (!empty($requests)): ?>
                <?php foreach ($requests as $request): ?>
                    <?php
                    $urgency_config = [
                        'low' => ['class' => 'urgency-low', 'text' => 'ปกติ', 'badge' => 'secondary'],
                        'medium' => ['class' => 'urgency-medium', 'text' => 'ค่อนข้างเร่ง', 'badge' => 'info'],
                        'high' => ['class' => 'urgency-high', 'text' => 'เร่งด่วน', 'badge' => 'warning'],
                        'urgent' => ['class' => 'urgency-urgent', 'text' => 'เร่งด่วนมาก', 'badge' => 'danger']
                    ];
                    $urgency = $urgency_config[$request['urgency']];
                    
                    $status_config = [
                        'pending' => ['class' => 'warning', 'icon' => 'clock', 'text' => 'รอพิจารณา'],
                        'approved' => ['class' => 'success', 'icon' => 'check-circle', 'text' => 'อนุมัติ'],
                        'rejected' => ['class' => 'danger', 'icon' => 'times-circle', 'text' => 'ปฏิเสธ'],
                        'ordered' => ['class' => 'info', 'icon' => 'truck', 'text' => 'สั่งซื้อแล้ว'],
                        'received' => ['class' => 'secondary', 'icon' => 'check', 'text' => 'รับแล้ว'],
                        'cancelled' => ['class' => 'dark', 'icon' => 'ban', 'text' => 'ยกเลิก']
                    ];
                    $status = $status_config[$request['status']];
                    
                    $stock_percent = $request['min_stock'] > 0 
                        ? ($request['current_stock'] / $request['min_stock']) * 100 
                        : 100;
                    $stock_color = $stock_percent < 50 ? 'danger' : ($stock_percent < 100 ? 'warning' : 'success');
                    ?>
                    <div class="request-card <?= $request['urgency'] ?>" 
                         data-status="<?= $request['status'] ?>" 
                         data-urgency="<?= $request['urgency'] ?>"
                         data-search="<?= strtolower($request['pr_number'] . ' ' . $request['part_code'] . ' ' . $request['material_name']) ?>">
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="d-flex align-items-start mb-3">
                                    <span class="<?= $urgency['class'] ?> urgency-indicator"></span>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1">
                                            <i class="fas fa-file-invoice me-2 text-primary"></i>
                                            <strong><?= htmlspecialchars($request['pr_number']) ?></strong>
                                            <span class="badge bg-<?= $status['class'] ?> ms-2">
                                                <i class="fas fa-<?= $status['icon'] ?>"></i>
                                                <?= $status['text'] ?>
                                            </span>
                                        </h5>
                                        <p class="text-muted mb-0">
                                            <i class="fas fa-box me-1"></i>
                                            <strong><?= htmlspecialchars($request['part_code']) ?></strong> - 
                                            <?= htmlspecialchars($request['material_name']) ?>
                                        </p>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="bg-light p-3 rounded">
                                            <small class="text-muted d-block mb-1">
                                                <i class="fas fa-hashtag me-1"></i>จำนวนที่ขอ
                                            </small>
                                            <h5 class="mb-0 text-primary">
                                                <?= number_format($request['quantity_requested']) ?>
                                                <small class="text-muted"><?= htmlspecialchars($request['unit']) ?></small>
                                            </h5>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="bg-light p-3 rounded">
                                            <small class="text-muted d-block mb-1">
                                                <i class="fas fa-warehouse me-1"></i>สต็อกปัจจุบัน
                                            </small>
                                            <h5 class="mb-0">
                                                <span class="text-<?= $stock_color ?>">
                                                    <?= number_format($request['current_stock']) ?>
                                                </span>
                                                <small class="text-muted">/ <?= number_format($request['min_stock']) ?></small>
                                            </h5>
                                            <div class="stock-progress">
                                                <div class="stock-progress-bar bg-<?= $stock_color ?>" 
                                                     style="width: <?= min($stock_percent, 100) ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="bg-light p-3 rounded">
                                            <small class="text-muted d-block mb-1">
                                                <i class="fas fa-flag me-1"></i>ความเร่งด่วน
                                            </small>
                                            <span class="badge bg-<?= $urgency['badge'] ?> fs-6">
                                                <?= $urgency['text'] ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>
                                        <strong><?= htmlspecialchars($request['created_by_name']) ?></strong>
                                        <span class="badge bg-secondary"><?= ucfirst($request['created_by_role']) ?></span>
                                        
                                        <span class="ms-3">
                                            <i class="fas fa-clock me-1"></i>
                                            <?= date('d/m/Y H:i', strtotime($request['created_at'])) ?>
                                        </span>
                                        
                                        <span class="ms-3">
                                            <i class="fas fa-calendar-check me-1"></i>
                                            ต้องการ: <?= date('d/m/Y', strtotime($request['expected_date'])) ?>
                                        </span>
                                    </small>
                                </div>

                                <?php if ($request['notes']): ?>
                                    <div class="mt-2">
                                        <small class="text-muted">
                                            <i class="fas fa-sticky-note me-1"></i>
                                            <em><?= nl2br(htmlspecialchars(mb_substr($request['notes'], 0, 150))) ?>
                                            <?= mb_strlen($request['notes']) > 150 ? '...' : '' ?></em>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="col-md-4">
                                <div class="d-flex flex-column h-100 justify-content-between">
                                    <div>
                                        <?php if ($request['approved_by_name']): ?>
                                            <div class="alert alert-light mb-3">
                                                <small>
                                                    <strong>ดำเนินการโดย:</strong><br>
                                                    <i class="fas fa-user-check me-1"></i>
                                                    <?= htmlspecialchars($request['approved_by_name']) ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="action-buttons text-end">
                                        <button class="btn btn-info btn-sm mb-2" 
                                                onclick="viewPRDetail(<?= $request['pr_id'] ?>)">
                                            <i class="fas fa-eye"></i> ดูรายละเอียด
                                        </button>

                                        <?php if ($request['status'] === 'pending'): ?>
                                            <button class="btn btn-success btn-sm mb-2" 
                                                    onclick="approvePR(<?= $request['pr_id'] ?>, '<?= htmlspecialchars($request['pr_number']) ?>')">
                                                <i class="fas fa-check"></i> อนุมัติ
                                            </button>
                                            <button class="btn btn-danger btn-sm mb-2" 
                                                    onclick="rejectPR(<?= $request['pr_id'] ?>, '<?= htmlspecialchars($request['pr_number']) ?>')">
                                                <i class="fas fa-times"></i> ปฏิเสธ
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($request['status'] === 'approved'): ?>
                                            <button class="btn btn-primary btn-sm mb-2" 
                                                    onclick="markAsOrdered(<?= $request['pr_id'] ?>, '<?= htmlspecialchars($request['pr_number']) ?>')">
                                                <i class="fas fa-truck"></i> สั่งซื้อแล้ว
                                            </button>
                                        <?php endif; ?>

                                        <?php if ($request['status'] === 'ordered'): ?>
                                            <button class="btn btn-success btn-sm mb-2" 
                                                    onclick="markAsReceived(<?= $request['pr_id'] ?>, '<?= htmlspecialchars($request['material_name']) ?>', <?= $request['quantity_requested'] ?>, '<?= htmlspecialchars($request['pr_number']) ?>')">
                                                <i class="fas fa-check-circle"></i> รับแล้ว
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                    <h5 class="text-muted">ไม่มีคำขอสั่งซื้อ</h5>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Detail Modal -->
<div class="modal fade" id="prDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-file-invoice me-2"></i>รายละเอียดคำขอสั่งซื้อ
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="prDetailContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">กำลังโหลดข้อมูล...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>ปิด
                </button>
            </div>
        </div>
    </div>
</div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check if there's a filter parameter in URL
    const urlParams = new URLSearchParams(window.location.search);
    const filter = urlParams.get('filter');
    
    if (filter) {
        // Find and activate the corresponding filter tab
        const filterTab = document.querySelector(`.filter-tab[data-filter="${filter}"]`);
        if (filterTab) {
            filterTab.click();
        }
    }
});

// Filter functionality
document.querySelectorAll('.filter-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        // Update active tab
        document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
        this.classList.add('active');
        
        const filter = this.dataset.filter;
        const cards = document.querySelectorAll('.request-card');
        
        let visibleCount = 0;
        cards.forEach(card => {
            let shouldShow = false;
            
            if (filter === 'all') {
                shouldShow = true;
            } else if (filter === 'urgent') {
                shouldShow = card.dataset.urgency === 'urgent';
            } else {
                shouldShow = card.dataset.status === filter;
            }
            
            card.style.display = shouldShow ? 'block' : 'none';
            if (shouldShow) visibleCount++;
        });
        
        // Show message if no results
        const requestsList = document.getElementById('requestsList');
        const existingNoResults = requestsList.querySelector('.no-results-message');
        if (existingNoResults) {
            existingNoResults.remove();
        }
        
        if (visibleCount === 0) {
            const noResultsDiv = document.createElement('div');
            noResultsDiv.className = 'text-center py-5 no-results-message';
            noResultsDiv.innerHTML = `
                <i class="fas fa-search fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">ไม่พบข้อมูล</h5>
                <p class="text-muted">ไม่มีรายการในหมวดนี้</p>
            `;
            requestsList.appendChild(noResultsDiv);
        }
        
        // Update URL without reload
        const newUrl = new URL(window.location);
        if (filter === 'all') {
            newUrl.searchParams.delete('filter');
        } else {
            newUrl.searchParams.set('filter', filter);
        }
        window.history.pushState({}, '', newUrl);
    });
});

// Search functionality
document.getElementById('searchPR').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const cards = document.querySelectorAll('.request-card');
    
    let visibleCount = 0;
    cards.forEach(card => {
        const searchData = card.dataset.search;
        const matches = searchData.includes(searchTerm);
        
        // Only show if matches search AND current filter
        const currentFilter = document.querySelector('.filter-tab.active').dataset.filter;
        let matchesFilter = false;
        
        if (currentFilter === 'all') {
            matchesFilter = true;
        } else if (currentFilter === 'urgent') {
            matchesFilter = card.dataset.urgency === 'urgent';
        } else {
            matchesFilter = card.dataset.status === currentFilter;
        }
        
        const shouldShow = matches && matchesFilter;
        card.style.display = shouldShow ? 'block' : 'none';
        if (shouldShow) visibleCount++;
    });
    
    // Show/hide no results message
    const requestsList = document.getElementById('requestsList');
    const existingNoResults = requestsList.querySelector('.no-results-message');
    if (existingNoResults) {
        existingNoResults.remove();
    }
    
    if (visibleCount === 0) {
        const noResultsDiv = document.createElement('div');
        noResultsDiv.className = 'text-center py-5 no-results-message';
        noResultsDiv.innerHTML = `
            <i class="fas fa-search fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">ไม่พบผลการค้นหา</h5>
            <p class="text-muted">ลองค้นหาด้วยคำอื่น</p>
        `;
        requestsList.appendChild(noResultsDiv);
    }
});

// View PR Detail
function viewPRDetail(prId) {
    // Show modal first with loading
    const modal = new bootstrap.Modal(document.getElementById('prDetailModal'));
    modal.show();
    
    document.getElementById('prDetailContent').innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-3 text-muted">กำลังโหลดข้อมูล...</p>
        </div>
    `;
    
    fetch(`../../api/purchase-requests.php?action=get&id=${prId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                displayPRDetail(data.data);
            } else {
                document.getElementById('prDetailContent').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        ${data.message}
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('prDetailContent').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    เกิดข้อผิดพลาดในการโหลดข้อมูล: ${error.message}
                </div>
            `;
        });
}

function displayPRDetail(pr) {
    const statusConfig = {
        'pending': { class: 'warning', icon: 'clock', text: 'รอพิจารณา' },
        'approved': { class: 'success', icon: 'check-circle', text: 'อนุมัติ' },
        'rejected': { class: 'danger', icon: 'times-circle', text: 'ปฏิเสธ' },
        'ordered': { class: 'info', icon: 'truck', text: 'สั่งซื้อแล้ว' },
        'received': { class: 'secondary', icon: 'check', text: 'รับแล้ว' },
        'cancelled': { class: 'dark', icon: 'ban', text: 'ยกเลิก' }
    };
    
    const status = statusConfig[pr.status] || statusConfig['pending'];
    
    const urgencyConfig = {
        'low': { text: 'ปกติ', class: 'secondary' },
        'medium': { text: 'ค่อนข้างเร่ง', class: 'info' },
        'high': { text: 'เร่งด่วน', class: 'warning' },
        'urgent': { text: 'เร่งด่วนมาก', class: 'danger' }
    };
    
    const urgency = urgencyConfig[pr.urgency] || urgencyConfig['medium'];
    
    // Format dates
    const formatDate = (dateStr) => {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('th-TH', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    };
    
    const formatDateOnly = (dateStr) => {
        if (!dateStr) return '-';
        const date = new Date(dateStr);
        return date.toLocaleDateString('th-TH', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    };
    
    // Calculate stock percentage
    const stockPercent = pr.min_stock > 0 ? (pr.current_stock / pr.min_stock * 100) : 100;
    const stockColor = stockPercent < 50 ? 'danger' : (stockPercent < 100 ? 'warning' : 'success');
    
    let html = `
        <!-- Header -->
        <div class="alert alert-light border mb-4">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h4 class="mb-0">
                        <i class="fas fa-file-invoice text-primary me-2"></i>
                        ${pr.pr_number || '-'}
                    </h4>
                </div>
                <div class="col-md-6 text-end">
                    <span class="badge bg-${status.class} fs-5 px-4 py-2">
                        <i class="fas fa-${status.icon} me-2"></i>${status.text}
                    </span>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <!-- ข้อมูลคำขอ -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0">
                            <i class="fas fa-info-circle me-2"></i>ข้อมูลคำขอ
                        </h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tr>
                                <td width="40%" class="text-muted">
                                    <i class="fas fa-hashtag me-1"></i>เลขที่คำขอ:
                                </td>
                                <td><strong>${pr.pr_number || '-'}</strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">
                                    <i class="fas fa-flag me-1"></i>ความเร่งด่วน:
                                </td>
                                <td>
                                    <span class="badge bg-${urgency.class} px-3 py-2">${urgency.text}</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">
                                    <i class="fas fa-user me-1"></i>ผู้ขอ:
                                </td>
                                <td>
                                    <strong>${pr.created_by_name || '-'}</strong>
                                    <span class="badge bg-secondary ms-2">${pr.created_by_role ? pr.created_by_role.charAt(0).toUpperCase() + pr.created_by_role.slice(1) : '-'}</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">
                                    <i class="fas fa-clock me-1"></i>วันที่สร้าง:
                                </td>
                                <td>${formatDate(pr.created_at)}</td>
                            </tr>
                            <tr>
                                <td class="text-muted">
                                    <i class="fas fa-calendar-check me-1"></i>วันที่ต้องการ:
                                </td>
                                <td><strong class="text-primary">${formatDateOnly(pr.expected_date)}</strong></td>
                            </tr>
                            ${pr.approved_by_name ? `
                            <tr>
                                <td class="text-muted">
                                    <i class="fas fa-user-check me-1"></i>ผู้อนุมัติ:
                                </td>
                                <td>
                                    <strong>${pr.approved_by_name}</strong>
                                    <span class="badge bg-info ms-2">${pr.approved_by_role ? pr.approved_by_role.charAt(0).toUpperCase() + pr.approved_by_role.slice(1) : '-'}</span>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">
                                    <i class="fas fa-calendar me-1"></i>วันที่อนุมัติ:
                                </td>
                                <td>${formatDate(pr.approved_at)}</td>
                            </tr>
                            ` : ''}
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- ข้อมูลวัสดุ -->
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0">
                            <i class="fas fa-box me-2"></i>ข้อมูลวัสดุ
                        </h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tr>
                                <td width="40%" class="text-muted">
                                    <i class="fas fa-barcode me-1"></i>รหัส:
                                </td>
                                <td><code class="fs-6">${pr.part_code || '-'}</code></td>
                            </tr>
                            <tr>
                                <td class="text-muted">
                                    <i class="fas fa-tag me-1"></i>ชื่อ:
                                </td>
                                <td><strong>${pr.material_name || '-'}</strong></td>
                            </tr>
                            <tr>
                                <td class="text-muted">
                                    <i class="fas fa-hashtag me-1"></i>จำนวนที่ขอ:
                                </td>
                                <td>
                                    <span class="badge bg-primary fs-5 px-3 py-2">
                                        ${pr.quantity_requested ? pr.quantity_requested.toLocaleString() : '0'} ${pr.unit || ''}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="text-muted">
                                    <i class="fas fa-warehouse me-1"></i>สต็อกปัจจุบัน:
                                </td>
                                <td>
                                    <strong class="text-${stockColor}">
                                        ${pr.current_stock ? pr.current_stock.toLocaleString() : '0'}
                                    </strong>
                                    <span class="text-muted">/ ${pr.min_stock ? pr.min_stock.toLocaleString() : '0'} ${pr.unit || ''}</span>
                                    <div class="progress mt-2" style="height: 10px;">
                                        <div class="progress-bar bg-${stockColor}" 
                                             style="width: ${Math.min(stockPercent, 100)}%"
                                             role="progressbar">
                                        </div>
                                    </div>
                                    <small class="text-muted">${Math.round(stockPercent)}% ของระดับต่ำสุด</small>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        ${pr.notes ? `
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-warning">
                <h6 class="mb-0">
                    <i class="fas fa-sticky-note me-2"></i>หมายเหตุ
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-light mb-0">
                    ${nl2br(htmlEscape(pr.notes))}
                </div>
            </div>
        </div>
        ` : ''}
        
        <!-- Timeline -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0">
                    <i class="fas fa-history me-2"></i>ประวัติการดำเนินการ
                </h6>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <!-- Created -->
                    <div class="d-flex mb-4">
                        <div class="flex-shrink-0">
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" 
                                 style="width: 50px; height: 50px;">
                                <i class="fas fa-plus"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="card border-start border-primary border-4">
                                <div class="card-body">
                                    <h6 class="card-title">สร้างคำขอ</h6>
                                    <p class="card-text mb-1">
                                        <i class="fas fa-user me-1"></i>
                                        <strong>${pr.created_by_name || '-'}</strong>
                                    </p>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        ${formatDate(pr.created_at)}
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    ${pr.approved_at ? `
                    <!-- Approved/Rejected -->
                    <div class="d-flex mb-4">
                        <div class="flex-shrink-0">
                            <div class="rounded-circle bg-${status.class} text-white d-flex align-items-center justify-content-center" 
                                 style="width: 50px; height: 50px;">
                                <i class="fas fa-${status.icon}"></i>
                            </div>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <div class="card border-start border-${status.class} border-4">
                                <div class="card-body">
                                    <h6 class="card-title">${status.text}</h6>
                                    <p class="card-text mb-1">
                                        <i class="fas fa-user-check me-1"></i>
                                        <strong>${pr.approved_by_name || '-'}</strong>
                                    </p>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        ${formatDate(pr.approved_at)}
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('prDetailContent').innerHTML = html;
}

// Helper functions
function nl2br(str) {
    return str.replace(/\n/g, '<br>');
}

function htmlEscape(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Approve PR
function approvePR(prId, prNumber) {
    Swal.fire({
        title: 'อนุมัติคำขอสั่งซื้อ?',
        html: `
            <div class="text-start">
                <p>คุณต้องการอนุมัติคำขอ <strong class="text-primary">${prNumber}</strong> หรือไม่?</p>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    เมื่ออนุมัติแล้ว คำขอจะพร้อมสำหรับดำเนินการสั่งซื้อ
                </div>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-check me-1"></i>อนุมัติ',
        cancelButtonText: '<i class="fas fa-times me-1"></i>ยกเลิก',
        input: 'textarea',
        inputPlaceholder: 'หมายเหตุ (ไม่บังคับ)',
        inputAttributes: {
            'aria-label': 'หมายเหตุ'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'approve');
            formData.append('pr_id', prId);
            formData.append('notes', result.value || '');
            
            Swal.fire({
                title: 'กำลังดำเนินการ...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('../../api/purchase-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'สำเร็จ!',
                        html: `
                            <div class="text-center">
                                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                <h5>อนุมัติคำขอเรียบร้อย</h5>
                                <p class="text-muted">คำขอ ${prNumber} ได้รับการอนุมัติแล้ว</p>
                            </div>
                        `,
                        icon: 'success',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถดำเนินการได้', 'error');
            });
        }
    });
}

// Reject PR
function rejectPR(prId, prNumber) {
    Swal.fire({
        title: 'ปฏิเสธคำขอสั่งซื้อ?',
        html: `
            <div class="text-start">
                <p>คุณต้องการปฏิเสธคำขอ <strong class="text-danger">${prNumber}</strong> หรือไม่?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    กรุณาระบุเหตุผลในการปฏิเสธ
                </div>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-times me-1"></i>ปฏิเสธ',
        cancelButtonText: '<i class="fas fa-ban me-1"></i>ยกเลิก',
        input: 'textarea',
        inputLabel: 'เหตุผล',
        inputPlaceholder: 'ระบุเหตุผลในการปฏิเสธ...',
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
            
            Swal.fire({
                title: 'กำลังดำเนินการ...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('../../api/purchase-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'สำเร็จ!',
                        html: `
                            <div class="text-center">
                                <i class="fas fa-times-circle fa-4x text-danger mb-3"></i>
                                <h5>ปฏิเสธคำขอเรียบร้อย</h5>
                                <p class="text-muted">คำขอ ${prNumber} ถูกปฏิเสธแล้ว</p>
                            </div>
                        `,
                        icon: 'success',
                        confirmButtonColor: '#28a745'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถดำเนินการได้', 'error');
            });
        }
    });
}

// Mark as Ordered
function markAsOrdered(prId, prNumber) {
    Swal.fire({
        title: 'ยืนยันการสั่งซื้อ?',
        html: `
            <div class="text-start">
                <p>คุณได้ดำเนินการสั่งซื้อ <strong class="text-info">${prNumber}</strong> จากซัพพลายเออร์แล้วหรือไม่?</p>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    คุณสามารถระบุข้อมูลเพิ่มเติม เช่น PO Number, ซัพพลายเออร์, วันที่คาดว่าจะได้รับ
                </div>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#17a2b8',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-truck me-1"></i>ยืนยันสั่งซื้อแล้ว',
        cancelButtonText: '<i class="fas fa-times me-1"></i>ยกเลิก',
        input: 'textarea',
        inputPlaceholder: 'หมายเหตุ เช่น PO Number, ซัพพลายเออร์, วันที่คาดว่าจะได้รับ'
    }).then((result) => {
        if (result.isConfirmed) {
            updatePRStatus(prId, 'ordered', result.value, prNumber);
        }
    });
}

// Mark as Received
function markAsReceived(prId, materialName, quantity, prNumber) {
    Swal.fire({
        title: 'ยืนยันการรับวัสดุ?',
        html: `
            <div class="text-start">
                <p>ยืนยันว่าได้รับวัสดุแล้วหรือไม่?</p>
                <div class="alert alert-light border">
                    <h6><i class="fas fa-box me-2 text-primary"></i>รายละเอียด</h6>
                    <p class="mb-1"><strong>คำขอ:</strong> ${prNumber}</p>
                    <p class="mb-1"><strong>วัสดุ:</strong> ${materialName}</p>
                    <p class="mb-0"><strong>จำนวน:</strong> ${quantity.toLocaleString()}</p>
                </div>
                <div class="alert alert-success">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>สต็อกวัสดุจะถูกเพิ่มอัตโนมัติ</strong>
                </div>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-check-circle me-1"></i>ยืนยันรับแล้ว',
        cancelButtonText: '<i class="fas fa-times me-1"></i>ยกเลิก',
        input: 'textarea',
        inputPlaceholder: 'หมายเหตุ (ไม่บังคับ)'
    }).then((result) => {
        if (result.isConfirmed) {
            updatePRStatus(prId, 'received', result.value, prNumber);
        }
    });
}

// Update PR Status
function updatePRStatus(prId, status, notes = '', prNumber = '') {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('pr_id', prId);
    formData.append('status', status);
    formData.append('notes', notes);
    
    Swal.fire({
        title: 'กำลังดำเนินการ...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    fetch('../../api/purchase-requests.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const statusMessages = {
                'ordered': 'บันทึกการสั่งซื้อเรียบร้อย',
                'received': 'รับวัสดุเรียบร้อย สต็อกได้รับการอัพเดทแล้ว'
            };
            
            Swal.fire({
                title: 'สำเร็จ!',
                html: `
                    <div class="text-center">
                        <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                        <h5>${statusMessages[status] || 'อัพเดทสถานะเรียบร้อย'}</h5>
                        ${prNumber ? `<p class="text-muted">คำขอ ${prNumber}</p>` : ''}
                    </div>
                `,
                icon: 'success',
                confirmButtonColor: '#28a745'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
        }
    })
    .catch(error => {
        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถดำเนินการได้', 'error');
    });
}

// Toggle Sidebar
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('show');
}

// Auto-reload every 5 minutes
setInterval(() => {
    location.reload();
}, 300000);
</script>

</body>
</html>