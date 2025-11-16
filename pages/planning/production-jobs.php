<?php
// pages/planning/production-jobs.php
$page_title = 'รายการงานการผลิต';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'รายการงานการผลิต']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['planning', 'admin', 'production', 'management']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลงานทั้งหมด
$user_role = getUserRole();
$user_id = $_SESSION['user_id'];

// สร้าง WHERE clause ตาม role
$where_clause = "1=1";
$params = [];

if ($user_role === 'production') {
    // Production เห็นเฉพาะงานที่มอบหมายให้ตัวเอง
    $where_clause .= " AND (pj.assigned_to = ? OR pj.assigned_to_name LIKE ?)";
    $params[] = $user_id;
    $params[] = "%" . $_SESSION['full_name'] . "%";
}

// ดึงข้อมูลงาน
$jobs = $db->prepare("
    SELECT pj.*, 
           p.product_name, 
           p.product_code,
           u1.full_name as created_by_name,
           u2.full_name as assigned_user_name,
           DATEDIFF(pj.end_date, CURDATE()) as days_remaining,
           CASE 
               WHEN pj.quantity_planned > 0 THEN ROUND((pj.quantity_produced / pj.quantity_planned) * 100, 2)
               ELSE 0 
           END as progress_percent
    FROM production_jobs pj
    LEFT JOIN products p ON pj.product_id = p.product_id
    LEFT JOIN users u1 ON pj.created_by = u1.user_id
    LEFT JOIN users u2 ON pj.assigned_to = u2.user_id
    WHERE $where_clause
    ORDER BY 
        CASE pj.status 
            WHEN 'pending' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'completed' THEN 3
            WHEN 'cancelled' THEN 4
        END,
        pj.created_at DESC
");
$jobs->execute($params);
$all_jobs = $jobs->fetchAll();

// สถิติ
$stats = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM production_jobs pj
    WHERE $where_clause
");
$stats->execute($params);
$statistics = $stats->fetch();
?>

<style>
    .job-card {
        border: 2px solid #e9ecef;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 15px;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .job-card:hover {
        border-color: var(--primary-color);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        transform: translateY(-2px);
    }

    .job-card.urgent {
        border-left: 5px solid #dc3545;
    }

    .job-card.overdue {
        border-left: 5px solid #ffc107;
        background: #fff8e1;
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
        font-weight: 600;
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

    .progress-bar-custom {
        height: 8px;
        border-radius: 10px;
        background: #e9ecef;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        transition: width 0.3s ease;
    }

    .status-badge {
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
    }

    .status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-in_progress {
        background: #cce7ff;
        color: #004085;
    }

    .status-completed {
        background: #d4edda;
        color: #155724;
    }

    .status-cancelled {
        background: #f8d7da;
        color: #721c24;
    }

    .action-buttons .btn {
        margin: 2px;
        border-radius: 20px;
        font-size: 0.85rem;
    }

    .stats-card {
        background: white;
        border-radius: 15px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
    }

    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
    }

    .stats-number {
        font-size: 2rem;
        font-weight: bold;
        margin-bottom: 5px;
    }

    .stats-label {
        color: #6c757d;
        font-size: 0.9rem;
    }

    .assigned-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 12px;
        background: #e3f2fd;
        border-radius: 20px;
        font-size: 0.85rem;
    }

    .assigned-badge i {
        margin-right: 5px;
    }
</style>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stats-card" style="border-left: 4px solid #667eea;">
            <div class="stats-number text-primary"><?= number_format($statistics['total']) ?></div>
            <div class="stats-label">งานทั้งหมด</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stats-card" style="border-left: 4px solid #ffc107;">
            <div class="stats-number text-warning"><?= number_format($statistics['pending']) ?></div>
            <div class="stats-label">รอเริ่มงาน</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stats-card" style="border-left: 4px solid #17a2b8;">
            <div class="stats-number text-info"><?= number_format($statistics['in_progress']) ?></div>
            <div class="stats-label">กำลังผลิต</div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="stats-card" style="border-left: 4px solid #28a745;">
            <div class="stats-number text-success"><?= number_format($statistics['completed']) ?></div>
            <div class="stats-label">เสร็จแล้ว</div>
        </div>
    </div>
</div>

<!-- Main Card -->
<div class="card">
    <div class="card-header">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h5><i class="fas fa-tasks me-2"></i>รายการงานการผลิต</h5>
            </div>
            <div class="col-md-6 text-end">
                <div class="d-flex justify-content-end gap-2">
                    <input type="text" id="searchJob" class="form-control form-control-sm" 
                           placeholder="ค้นหาเลขที่งาน, สินค้า, ผู้รับผิดชอบ..." 
                           style="max-width: 300px;">
                    <?php if (in_array($user_role, ['planning', 'admin'])): ?>
                        <a href="create-job.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-plus me-1"></i>สร้างงานใหม่
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body">
        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <div class="filter-tab active" data-filter="all">
                <i class="fas fa-list"></i> ทั้งหมด (<?= count($all_jobs) ?>)
            </div>
            <div class="filter-tab" data-filter="pending">
                <i class="fas fa-clock"></i> รอเริ่มงาน (<?= $statistics['pending'] ?>)
            </div>
            <div class="filter-tab" data-filter="in_progress">
                <i class="fas fa-cogs"></i> กำลังผลิต (<?= $statistics['in_progress'] ?>)
            </div>
            <div class="filter-tab" data-filter="completed">
                <i class="fas fa-check-circle"></i> เสร็จแล้ว (<?= $statistics['completed'] ?>)
            </div>
            <div class="filter-tab" data-filter="overdue">
                <i class="fas fa-exclamation-triangle"></i> เกินกำหนด
            </div>
        </div>

        <!-- Jobs List -->
        <div id="jobsList">
            <?php if (!empty($all_jobs)): ?>
                <?php foreach ($all_jobs as $job): ?>
                    <?php
                    // กำหนดสถานะ
                    $status_config = [
                        'pending' => ['class' => 'pending', 'icon' => 'clock', 'text' => 'รอเริ่มงาน'],
                        'in_progress' => ['class' => 'in_progress', 'icon' => 'cogs', 'text' => 'กำลังผลิต'],
                        'completed' => ['class' => 'completed', 'icon' => 'check-circle', 'text' => 'เสร็จแล้ว'],
                        'cancelled' => ['class' => 'cancelled', 'icon' => 'times-circle', 'text' => 'ยกเลิก']
                    ];
                    $status = $status_config[$job['status']];
                    
                    // เช็คว่าเกินกำหนดหรือไม่
                    $is_overdue = $job['days_remaining'] < 0 && in_array($job['status'], ['pending', 'in_progress']);
                    
                    // กำหนดสีตามวันที่เหลือ
                    $days_class = '';
                    if ($is_overdue) {
                        $days_class = 'text-danger';
                    } elseif ($job['days_remaining'] <= 3) {
                        $days_class = 'text-warning';
                    } else {
                        $days_class = 'text-success';
                    }
                    
                    // ชื่อผู้รับผิดชอบ - ใช้ assigned_to_name ถ้ามี ถ้าไม่มีใช้ assigned_user_name
                    $assigned_name = !empty($job['assigned_to_name']) 
                        ? $job['assigned_to_name'] 
                        : ($job['assigned_user_name'] ?? 'ไม่ระบุ');
                    ?>
                    <div class="job-card <?= $is_overdue ? 'overdue' : '' ?>" 
                         data-status="<?= $job['status'] ?>" 
                         data-overdue="<?= $is_overdue ? '1' : '0' ?>"
                         data-search="<?= strtolower($job['job_number'] . ' ' . $job['product_name'] . ' ' . $assigned_name) ?>"
                         onclick="viewJobDetail(<?= $job['job_id'] ?>)">
                        
                        <div class="row">
                            <!-- ข้อมูลหลัก -->
                            <div class="col-lg-8">
                                <div class="d-flex align-items-start mb-3">
                                    <div class="flex-grow-1">
                                        <h5 class="mb-1">
                                            <i class="fas fa-briefcase text-primary me-2"></i>
                                            <strong><?= htmlspecialchars($job['job_number']) ?></strong>
                                            <span class="status-badge status-<?= $status['class'] ?> ms-2">
                                                <i class="fas fa-<?= $status['icon'] ?>"></i>
                                                <?= $status['text'] ?>
                                            </span>
                                            <?php if ($is_overdue): ?>
                                                <span class="badge bg-danger ms-2">
                                                    <i class="fas fa-exclamation-triangle"></i> เกินกำหนด
                                                </span>
                                            <?php endif; ?>
                                        </h5>
                                        <p class="text-muted mb-2">
                                            <i class="fas fa-box me-1"></i>
                                            <strong><?= htmlspecialchars($job['product_name']) ?></strong>
                                            (<?= htmlspecialchars($job['product_code']) ?>)
                                        </p>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <small class="text-muted">จำนวนที่ผลิต:</small>
                                        <div>
                                            <span class="fs-5 fw-bold text-primary"><?= number_format($job['quantity_produced']) ?></span>
                                            <span class="text-muted">/ <?= number_format($job['quantity_planned']) ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted">ผู้รับผิดชอบ:</small>
                                        <div>
                                            <span class="assigned-badge">
                                                <i class="fas fa-user"></i>
                                                <?= htmlspecialchars($assigned_name) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <small class="text-muted">วันที่เหลือ:</small>
                                        <div class="<?= $days_class ?> fw-bold">
                                            <?php if ($is_overdue): ?>
                                                <i class="fas fa-exclamation-circle"></i>
                                                เกิน <?= abs($job['days_remaining']) ?> วัน
                                            <?php elseif ($job['days_remaining'] == 0): ?>
                                                <i class="fas fa-calendar-day"></i> วันนี้
                                            <?php else: ?>
                                                <i class="fas fa-calendar-alt"></i>
                                                อีก <?= $job['days_remaining'] ?> วัน
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Progress Bar -->
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="text-muted">ความคืบหน้า:</small>
                                        <small class="fw-bold"><?= $job['progress_percent'] ?>%</small>
                                    </div>
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill" style="width: <?= min($job['progress_percent'], 100) ?>%"></div>
                                    </div>
                                </div>

                                <!-- วันที่ -->
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar me-1"></i>
                                        เริ่ม: <?= date('d/m/Y', strtotime($job['start_date'])) ?>
                                        <i class="fas fa-arrow-right mx-2"></i>
                                        เสร็จ: <?= date('d/m/Y', strtotime($job['end_date'])) ?>
                                        <span class="ms-3">
                                            <i class="fas fa-clock me-1"></i>
                                            สร้างโดย: <?= htmlspecialchars($job['created_by_name']) ?>
                                        </span>
                                    </small>
                                </div>
                            </div>

                            <!-- ปุ่มจัดการ -->
                            <div class="col-lg-4 text-end">
                                <div class="action-buttons" onclick="event.stopPropagation();">
                                    <button class="btn btn-info btn-sm" onclick="viewJobDetail(<?= $job['job_id'] ?>)">
                                        <i class="fas fa-eye"></i> ดูรายละเอียด
                                    </button>

                                    <?php if ($job['status'] === 'pending' && in_array($user_role, ['planning', 'admin'])): ?>
                                        <button class="btn btn-success btn-sm" onclick="startJob(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')">
                                            <i class="fas fa-play"></i> เริ่มงาน
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($job['status'] === 'in_progress' && in_array($user_role, ['production', 'admin'])): ?>
                                        <button class="btn btn-primary btn-sm" onclick="updateProgress(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')">
                                            <i class="fas fa-edit"></i> อัพเดท
                                        </button>
                                    <?php endif; ?>

                                    <?php if (in_array($job['status'], ['pending', 'in_progress']) && in_array($user_role, ['planning', 'admin'])): ?>
                                        <button class="btn btn-warning btn-sm" onclick="editJob(<?= $job['job_id'] ?>)">
                                            <i class="fas fa-pencil-alt"></i> แก้ไข
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="cancelJob(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')">
                                            <i class="fas fa-times"></i> ยกเลิก
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($job['notes'])): ?>
                                    <div class="mt-3 text-start">
                                        <small class="text-muted">
                                            <i class="fas fa-sticky-note"></i>
                                            <?= nl2br(htmlspecialchars(mb_substr($job['notes'], 0, 100))) ?>
                                            <?= mb_strlen($job['notes']) > 100 ? '...' : '' ?>
                                        </small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <p class="text-muted mb-3">ไม่มีงานการผลิต</p>
                    <?php if (in_array($user_role, ['planning', 'admin'])): ?>
                        <a href="create-job.php" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>สร้างงานใหม่
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- View Job Detail Modal -->
<div class="modal fade" id="jobDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">รายละเอียดงานการผลิต</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="jobDetailContent">
                <!-- Content loaded by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<!-- Update Progress Modal -->
<div class="modal fade" id="updateProgressModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">อัพเดทความคืบหน้า</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="updateProgressForm">
                <div class="modal-body">
                    <input type="hidden" id="update_job_id" name="job_id">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="in_progress">
                    
                    <div class="mb-3">
                        <label class="form-label">เลขที่งาน</label>
                        <input type="text" class="form-control" id="update_job_number" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">จำนวนที่ผลิตได้ <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="quantity_produced" 
                               required min="0" placeholder="ระบุจำนวนที่ผลิตได้">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">หมายเหตุ</label>
                        <textarea class="form-control" name="notes" rows="3" 
                                  placeholder="บันทึกความคืบหน้า, ปัญหาที่พบ"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
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
        const cards = document.querySelectorAll('.job-card');
        
        cards.forEach(card => {
            if (filter === 'all') {
                card.style.display = 'block';
            } else if (filter === 'overdue') {
                card.style.display = card.dataset.overdue === '1' ? 'block' : 'none';
            } else {
                card.style.display = card.dataset.status === filter ? 'block' : 'none';
            }
        });
    });
});

// Search functionality
document.getElementById('searchJob').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const cards = document.querySelectorAll('.job-card');
    
    cards.forEach(card => {
        const searchData = card.dataset.search;
        card.style.display = searchData.includes(searchTerm) ? 'block' : 'none';
    });
});

// View Job Detail
function viewJobDetail(jobId) {
    fetch(`../../api/jobs.php?action=get&id=${jobId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayJobDetail(data.job);
                new bootstrap.Modal(document.getElementById('jobDetailModal')).show();
            } else {
                Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
            }
        })
        .catch(error => {
            Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
        });
}

function displayJobDetail(job) {
    const statusConfig = {
        'pending': { class: 'warning', text: 'รอเริ่มงาน' },
        'in_progress': { class: 'info', text: 'กำลังผลิต' },
        'completed': { class: 'success', text: 'เสร็จแล้ว' },
        'cancelled': { class: 'danger', text: 'ยกเลิก' }
    };
    
    const status = statusConfig[job.status];
    const assignedName = job.assigned_to_name || job.assigned_user_name || 'ไม่ระบุ';
    
    document.getElementById('jobDetailContent').innerHTML = `
        <div class="row mb-4">
            <div class="col-md-6">
                <h6><i class="fas fa-info-circle me-2"></i>ข้อมูลงาน</h6>
                <table class="table table-borderless">
                    <tr>
                        <td width="40%"><strong>เลขที่งาน:</strong></td>
                        <td>${job.job_number}</td>
                    </tr>
                    <tr>
                        <td><strong>สถานะ:</strong></td>
                        <td><span class="badge bg-${status.class}">${status.text}</span></td>
                    </tr>
                    <tr>
                        <td><strong>สินค้า:</strong></td>
                        <td>${job.product_name} (${job.product_code})</td>
                    </tr>
                    <tr>
                        <td><strong>ผู้รับผิดชอบ:</strong></td>
                        <td><span class="badge bg-info">${assignedName}</span></td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h6><i class="fas fa-chart-line me-2"></i>ความคืบหน้า</h6>
                <table class="table table-borderless">
                    <tr>
                        <td width="40%"><strong>จำนวนวางแผน:</strong></td>
                        <td>${parseInt(job.quantity_planned).toLocaleString()} ชิ้น</td>
                    </tr>
                    <tr>
                        <td><strong>จำนวนที่ผลิตได้:</strong></td>
                        <td>${parseInt(job.quantity_produced).toLocaleString()} ชิ้น</td>
                    </tr>
                    <tr>
                        <td><strong>วันที่เริ่ม:</strong></td>
                        <td>${new Date(job.start_date).toLocaleDateString('th-TH')}</td>
                    </tr>
                    <tr>
                        <td><strong>วันที่เสร็จ:</strong></td>
                        <td>${new Date(job.end_date).toLocaleDateString('th-TH')}</td>
                    </tr>
                </table>
            </div>
        </div>
        ${job.notes ? `
            <div class="alert alert-light">
                <h6><i class="fas fa-sticky-note me-2"></i>หมายเหตุ</h6>
                <p class="mb-0">${job.notes.replace(/\n/g, '<br>')}</p>
            </div>
        ` : ''}
    `;
}

// Start Job
function startJob(jobId, jobNumber) {
    Swal.fire({
        title: 'เริ่มงานการผลิต?',
        html: `คุณต้องการเริ่มงาน <strong>${jobNumber}</strong> หรือไม่?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'เริ่มงาน',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            updateJobStatus(jobId, 'in_progress', 0, 'เริ่มงานการผลิต');
        }
    });
}

// Update Progress
function updateProgress(jobId, jobNumber) {
    document.getElementById('update_job_id').value = jobId;
    document.getElementById('update_job_number').value = jobNumber;
    new bootstrap.Modal(document.getElementById('updateProgressModal')).show();
}

// Update Progress Form Submit
document.getElementById('updateProgressForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('../../api/jobs.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('สำเร็จ', 'อัพเดทความคืบหน้าเรียบร้อย', 'success').then(() => {
                bootstrap.Modal.getInstance(document.getElementById('updateProgressModal')).hide();
                location.reload();
            });
        } else {
            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
        }
    });
});

// Cancel Job
function cancelJob(jobId, jobNumber) {
    Swal.fire({
        title: 'ยกเลิกงาน?',
        html: `คุณต้องการยกเลิกงาน <strong>${jobNumber}</strong> หรือไม่?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'ยกเลิกงาน',
        cancelButtonText: 'ย้อนกลับ',
        input: 'textarea',
        inputLabel: 'เหตุผล',
        inputPlaceholder: 'ระบุเหตุผลในการยกเลิก',
        inputValidator: (value) => {
            if (!value) {
                return 'กรุณาระบุเหตุผล'
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            updateJobStatus(jobId, 'cancelled', null, result.value);
        }
    });
}

// Update Job Status
function updateJobStatus(jobId, status, quantityProduced, notes) {
    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('job_id', jobId);
    formData.append('status', status);
    if (quantityProduced !== null) {
        formData.append('quantity_produced', quantityProduced);
    }
    if (notes) {
        formData.append('notes', notes);
    }
    
    fetch('../../api/jobs.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire('สำเร็จ', data.message, 'success').then(() => {
                location.reload();
            });
        } else {
            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
        }
    });
}

// Edit Job
function editJob(jobId) {
    window.location.href = `edit-job.php?id=${jobId}`;
}

// Toggle Sidebar
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('show');
}
</script>

</body>
</html>