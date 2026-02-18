<?php
// pages/production/my-jobs.php - งานของฉัน
$page_title = 'งานของฉัน';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'งานของฉัน']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['production', 'admin']);

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// ดึงข้อมูลงานทั้งหมด
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

$where_conditions = ["(pj.assigned_to = ? OR pj.assigned_to IS NULL)"];
$params = [$user_id];

if (!empty($search)) {
    $where_conditions[] = "(pj.job_number LIKE ? OR p.product_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

// ดึงข้อมูลทั้งหมดก่อน filter status
$jobs_query = "
    SELECT pj.*, p.product_name, p.product_code, u.full_name as created_by_name,
           DATEDIFF(pj.end_date, CURDATE()) as days_remaining,
           ROUND((pj.quantity_produced / pj.quantity_planned) * 100, 2) as progress_percent,
           (SELECT COUNT(*) FROM material_requests mr WHERE mr.job_id = pj.job_id AND mr.requested_by = ?) as material_request_count
    FROM production_jobs pj
    LEFT JOIN products p ON pj.product_id = p.product_id
    LEFT JOIN users u ON pj.created_by = u.user_id
    WHERE $where_clause
    ORDER BY 
        CASE pj.status 
            WHEN 'in_progress' THEN 1 
            WHEN 'pending' THEN 2 
            WHEN 'completed' THEN 3 
            WHEN 'cancelled' THEN 4 
        END,
        pj.end_date ASC
";

$all_params = $params;
$all_params[] = $user_id;
$stmt = $db->prepare($jobs_query);
$stmt->execute($all_params);
$all_jobs = $stmt->fetchAll();

// นับจำนวนตามสถานะ
$status_counts = [
    'all' => count($all_jobs),
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($all_jobs as $job) {
    $status_counts[$job['status']]++;
}

// Filter ตามสถานะ
$jobs = $all_jobs;
if ($filter !== 'all') {
    $jobs = array_filter($all_jobs, function($job) use ($filter) {
        return $job['status'] === $filter;
    });
    $jobs = array_values($jobs); // reset array keys
}
?>

<style>
    .job-card {
        border: none;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        margin-bottom: 20px;
        border-left: 5px solid;
    }
    
    .job-card.status-pending { border-left-color: #ffc107; }
    .job-card.status-in-progress { border-left-color: #17a2b8; }
    .job-card.status-completed { border-left-color: #28a745; }
    .job-card.status-cancelled { border-left-color: #dc3545; }
    
    .job-card:hover {
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }
    
    .job-header {
        display: flex;
        justify-content: between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    
    .job-number {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--primary-color);
    }
    
    .urgency-high {
        border: 2px solid #ff6b35;
        box-shadow: 0 0 15px rgba(255, 107, 53, 0.3);
    }
    
    .urgency-urgent {
        border: 2px solid #dc3545;
        box-shadow: 0 0 15px rgba(220, 53, 69, 0.3);
        animation: pulse-urgent 2s infinite;
    }
    
    @keyframes pulse-urgent {
        0% { box-shadow: 0 0 15px rgba(220, 53, 69, 0.3); }
        50% { box-shadow: 0 0 25px rgba(220, 53, 69, 0.6); }
        100% { box-shadow: 0 0 15px rgba(220, 53, 69, 0.3); }
    }
    
    .progress-container {
        position: relative;
        margin: 10px 0;
    }
    
    .progress-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 0.8rem;
        font-weight: 600;
        color: white;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
    }
    
    .filter-tabs {
        background: #f8f9fa;
        border-radius: 15px;
        padding: 5px;
        margin-bottom: 25px;
    }
    
    .filter-tab {
        display: inline-block;
        padding: 10px 20px;
        border-radius: 10px;
        text-decoration: none;
        color: #6c757d;
        transition: all 0.3s ease;
        margin: 0 2px;
    }
    
    .filter-tab.active {
        background: var(--primary-color);
        color: white;
    }
    
    .filter-tab:hover {
        color: var(--primary-color);
        text-decoration: none;
    }
    
    .search-container {
        position: relative;
        margin-bottom: 25px;
    }
    
    .search-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
    }
    
    .search-input {
        padding-left: 45px;
        border-radius: 25px;
        border: 2px solid #e9ecef;
        transition: all 0.3s ease;
    }
    
    .search-input:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
    }

    .table-responsive {
        border-radius: 10px;
        overflow: hidden;
    }

    .table thead {
        background: #f8f9fa;
    }

    .table tbody tr {
        transition: all 0.3s ease;
    }

    .table tbody tr:hover {
        background: #f8f9fa;
    }
</style>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
                    ทั้งหมด (<?= $status_counts['all'] ?>)
                </a>
                <a href="?filter=pending" class="filter-tab <?= $filter === 'pending' ? 'active' : '' ?>">
                    รอเริ่ม (<?= $status_counts['pending'] ?>)
                </a>
                <a href="?filter=in_progress" class="filter-tab <?= $filter === 'in_progress' ? 'active' : '' ?>">
                    กำลังทำ (<?= $status_counts['in_progress'] ?>)
                </a>
                <a href="?filter=completed" class="filter-tab <?= $filter === 'completed' ? 'active' : '' ?>">
                    เสร็จแล้ว (<?= $status_counts['completed'] ?>)
                </a>
                <a href="?filter=cancelled" class="filter-tab <?= $filter === 'cancelled' ? 'active' : '' ?>">
                    ยกเลิก (<?= $status_counts['cancelled'] ?>)
                </a>
            </div>

            <!-- Search -->
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="form-control search-input" placeholder="ค้นหาเลขที่งาน หรือ ชื่อสินค้า..." 
                       value="<?= htmlspecialchars($search) ?>" id="searchInput">
            </div>

            <!-- Jobs Table -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover" id="jobsTable">
                        <thead>
                            <tr>
                                <th>เลขที่งาน</th>
                                <th>สินค้า</th>
                                <th>สถานะ</th>
                                <th>ความคืบหน้า</th>
                                <th>จำนวน</th>
                                <th>วันที่เสร็จ</th>
                                <th>วันที่เหลือ</th>
                                <th>การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                        <tbody>
                        <?php if (!empty($jobs)): ?>
                            <?php foreach ($jobs as $job): ?>
                                <?php
                                $status_colors = [
                                    'pending' => 'warning',
                                    'in_progress' => 'info', 
                                    'completed' => 'success',
                                    'cancelled' => 'danger'
                                ];
                                
                                $status_texts = [
                                    'pending' => 'รอเริ่มงาน',
                                    'in_progress' => 'กำลังทำ',
                                    'completed' => 'เสร็จแล้ว',
                                    'cancelled' => 'ยกเลิก'
                                ];
                                ?>
                                <tr data-status="<?= $job['status'] ?>" data-search="<?= strtolower($job['job_number'] . ' ' . $job['product_name']) ?>">
                                    <td><strong><?= htmlspecialchars($job['job_number']) ?></strong></td>
                                    <td>
                                        <div><?= htmlspecialchars($job['product_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($job['product_code']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $status_colors[$job['status']] ?>">
                                            <?= $status_texts[$job['status']] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress" style="height: 20px; min-width: 150px;">
                                            <div class="progress-bar" style="width: <?= min($job['progress_percent'], 100) ?>%">
                                                <?= $job['progress_percent'] ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <?= number_format($job['quantity_produced']) ?> / <?= number_format($job['quantity_planned']) ?>
                                    </td>
                                    <td>
                                        <small><?= date('d/m/Y', strtotime($job['end_date'])) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($job['status'] !== 'completed' && $job['status'] !== 'cancelled'): ?>
                                            <small class="<?= $job['days_remaining'] <= 0 ? 'text-danger fw-bold' : ($job['days_remaining'] <= 2 ? 'text-warning fw-bold' : 'text-success') ?>">
                                                <?php if ($job['days_remaining'] < 0): ?>
                                                    เกิน <?= abs($job['days_remaining']) ?> วัน
                                                <?php elseif ($job['days_remaining'] == 0): ?>
                                                    วันนี้
                                                <?php else: ?>
                                                    อีก <?= $job['days_remaining'] ?> วัน
                                                <?php endif; ?>
                                            </small>
                                        <?php else: ?>
                                            <small class="text-muted">-</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <?php if ($job['status'] === 'pending'): ?>
                                                <button class="btn btn-success btn-sm" onclick="startJob(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')" title="เริ่มงาน">
                                                    <i class="fas fa-play"></i>
                                                </button>
                                            <?php elseif ($job['status'] === 'in_progress'): ?>
                                                <button class="btn btn-primary btn-sm" onclick="updateProgress(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>', <?= $job['quantity_planned'] ?>, <?= $job['quantity_produced'] ?>)" title="อัพเดท">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($job['status'] !== 'completed' && $job['status'] !== 'cancelled'): ?>
                                                <button class="btn btn-warning btn-sm" onclick="requestMaterials(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')" title="เบิกวัสดุ">
                                                    <i class="fas fa-hand-paper"></i>
                                                    <?php if ($job['material_request_count'] > 0): ?>
                                                        <span class="badge bg-light text-dark"><?= $job['material_request_count'] ?></span>
                                                    <?php endif; ?>
                                                </button>
                                            <?php endif; ?>
                                            
                                            <button class="btn btn-info btn-sm" onclick="viewJobDetails(<?= $job['job_id'] ?>)" title="ดูรายละเอียด">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                    <div class="text-muted">
                                        <?php if ($filter === 'all'): ?>
                                            ยังไม่มีงานที่ได้รับมอบหมาย
                                        <?php else: ?>
                                            ไม่มีงานในสถานะ "<?= $status_texts[$filter] ?? $filter ?>"
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <!-- Start Job Modal -->
    <div class="modal fade" id="startJobModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">เริ่มงานการผลิต</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="startJobForm">
                    <div class="modal-body">
                        <input type="hidden" id="start_job_id" name="job_id">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            คุณกำลังจะเริ่มงาน: <strong id="start_job_number"></strong>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">หมายเหตุการเริ่มงาน</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="บันทึกข้อมูลเพิ่มเติม เช่น การเตรียมพร้อม, ปัญหาที่พบ (ไม่บังคับ)"></textarea>
                        </div>
                        <div class="alert alert-warning">
                            <small><i class="fas fa-lightbulb me-1"></i> 
                            ก่อนเริ่มงาน ควรตรวจสอบว่าได้เบิกวัสดุครบถ้วนแล้ว</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-play me-1"></i>เริ่มงาน
                        </button>
                    </div>
                </form>
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
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            งาน: <strong id="update_job_number"></strong>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">จำนวนที่ผลิตได้ <span class="text-danger">*</span></label>
                            <div class="row">
                                <div class="col-8">
                                    <input type="number" class="form-control" id="quantity_produced" name="quantity_produced" required min="0">
                                </div>
                                <div class="col-4">
                                    <div class="text-muted">
                                        <small>จาก <span id="total_planned"></span></small>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text">ระบุจำนวนที่ผลิตได้จริงตั้งแต่เริ่มงาน</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">สถานะงาน</label>
                            <select class="form-control" name="status" id="job_status">
                                <option value="in_progress">กำลังผลิต</option>
                                <option value="completed">เสร็จแล้ว</option>
                                <option value="cancelled">ยกเลิก</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="บันทึกความคืบหน้า ปัญหาที่พบ หรือข้อมูลเพิ่มเติม"></textarea>
                        </div>
                        
                        <div id="completion-warning" class="alert alert-warning" style="display: none;">
                            <i class="fas fa-check-circle me-2"></i>
                            การเปลี่ยนเป็น "เสร็จแล้ว" จะไม่สามารถแก้ไขได้อีก
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

    <!-- Job Details Modal -->
    <div class="modal fade" id="jobDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">รายละเอียดงาน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="jobDetailsContent">
                        <!-- Job details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
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

        // Start Job
        function startJob(jobId, jobNumber) {
            document.getElementById('start_job_id').value = jobId;
            document.getElementById('start_job_number').textContent = jobNumber;
            new bootstrap.Modal(document.getElementById('startJobModal')).show();
        }

        // Update Progress
        function updateProgress(jobId, jobNumber, plannedQty, currentQty) {
            document.getElementById('update_job_id').value = jobId;
            document.getElementById('update_job_number').textContent = jobNumber;
            document.getElementById('quantity_produced').value = currentQty;
            document.getElementById('total_planned').textContent = plannedQty.toLocaleString();
            
            new bootstrap.Modal(document.getElementById('updateProgressModal')).show();
        }

        // Request Materials
        function requestMaterials(jobId, jobNumber) {
            window.location.href = `material-requests.php?action=create&job_id=${jobId}`;
        }

        // View Job Details
        function viewJobDetails(jobId) {
            fetch(`../../api/jobs.php?action=get_detail&job_id=${jobId}`)
                .then(response => response.json())
                .then(data => {
                    console.log('Job Detail Response:', data);
                    if (data.success) {
                        const job = data.data || data.job;
                        displayJobDetails(job);
                        new bootstrap.Modal(document.getElementById('jobDetailsModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดรายละเอียดงานได้', 'error');
                });
        }

        function displayJobDetails(job) {
            const statusColors = {
                'pending': 'warning',
                'in_progress': 'info', 
                'completed': 'success',
                'cancelled': 'danger'
            };
            
            const statusTexts = {
                'pending': 'รอเริ่มงาน',
                'in_progress': 'กำลังทำ',
                'completed': 'เสร็จแล้ว',
                'cancelled': 'ยกเลิก'
            };

            const progressPercent = job.quantity_planned > 0 ? 
                Math.round((job.quantity_produced / job.quantity_planned) * 100) : 0;

            let html = `
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h4>${job.job_number}</h4>
                        <h6 class="text-muted">${job.product_name} (${job.product_code})</h6>
                    </div>
                    <div class="col-md-4 text-end">
                        <span class="badge bg-${statusColors[job.status]} fs-6">${statusTexts[job.status]}</span>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <strong>ความคืบหน้า:</strong>
                        <div class="progress mt-2" style="height: 25px;">
                            <div class="progress-bar bg-success" style="width: ${progressPercent}%">
                                ${progressPercent}%
                            </div>
                        </div>
                        <small class="text-muted">${job.quantity_produced.toLocaleString()} / ${job.quantity_planned.toLocaleString()} ชิ้น</small>
                    </div>
                    <div class="col-md-6">
                        <strong>วันที่:</strong><br>
                        <small>เริ่ม: ${new Date(job.start_date).toLocaleDateString('th-TH')}</small><br>
                        <small>เสร็จ: ${new Date(job.end_date).toLocaleDateString('th-TH')}</small>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <strong>สร้างโดย:</strong> ${job.created_by_name}<br>
                        <strong>วันที่สร้าง:</strong> ${new Date(job.created_at).toLocaleDateString('th-TH')}
                    </div>
                    <div class="col-md-6">
                        <strong>ผู้รับผิดชอบ:</strong> ${job.assigned_to_name}
                    </div>
                </div>
            `;

            if (job.notes) {
                html += `
                    <div class="mb-4">
                        <strong>หมายเหตุ:</strong>
                        <div class="p-3 bg-light rounded">${job.notes}</div>
                    </div>
                `;
            }

            // Support both legacy `materials` and new `required_materials` from API
            const materialsList = job.required_materials || job.materials || [];
            if (materialsList && materialsList.length > 0) {
                html += `
                    <div class="mb-4">
                        <strong>วัสดุที่ต้องใช้:</strong>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>รหัสวัสดุ</th>
                                        <th>ชื่อวัสดุ</th>
                                        <th class="text-end">จำนวน</th>
                                        <th>หน่วย</th>
                                        <th class="text-center">การ์ด</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;

                materialsList.forEach(material => {
                    const requiredQty = Number(material.required_quantity || 0);
                    const quantityPerCard = Number(material.quantity_per_card || material.quantity_per_unit || 1);
                    const totalCards = Math.ceil(requiredQty / Math.max(1, quantityPerCard));
                    const cardColor = material.card_color || '#3498db';

                    html += `
                        <tr>
                            <td>${material.part_code}</td>
                            <td>${material.material_name}</td>
                            <td class="text-end">${requiredQty.toLocaleString()}</td>
                            <td>${material.unit}</td>
                            <td class="text-center">
                                <div style="display:inline-flex;align-items:center;gap:6px;">
                                    <div style="width:44px;height:44px;background-color:${cardColor};border:2px solid #ddd;border-radius:6px;display:flex;flex-direction:column;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                                        <span style="color:white;font-weight:700;font-size:14px;">${totalCards}</span>
                                        <span style="color:white;font-size:9px;">ใบ</span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    `;
                });

                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            document.getElementById('jobDetailsContent').innerHTML = html;
        }

        // Form submissions
        document.getElementById('startJobForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'update_status');
            formData.append('status', 'in_progress');
            
            fetch('../../api/jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', 'เริ่มงานเรียบร้อยแล้ว', 'success').then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('startJobModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเริ่มงานได้', 'error');
            });
        });

        document.getElementById('updateProgressForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'update_status');
            
            fetch('../../api/jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', 'อัพเดทความคืบหน้าเรียบร้อยแล้ว', 'success').then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('updateProgressModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถอัพเดทได้', 'error');
            });
        });

        // Show warning when status is completed
        document.getElementById('job_status').addEventListener('change', function() {
            const warning = document.getElementById('completion-warning');
            if (this.value === 'completed') {
                warning.style.display = 'block';
            } else {
                warning.style.display = 'none';
            }
        });

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Auto refresh every 2 minutes for active jobs
        setInterval(() => {
            if (document.querySelectorAll('.status-in-progress').length > 0) {
                location.reload();
            }
        }, 120000);
    </script>

</body>
</html>