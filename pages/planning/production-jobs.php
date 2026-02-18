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

// Filter
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
// Pagination / page length
$allowed_limits = [10, 25, 50, 100];
$limit = (int)($_GET['limit'] ?? 25);
if (!in_array($limit, $allowed_limits)) $limit = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// สร้าง WHERE clause ตาม role
$where_clause = "1=1";
$params = [];

if ($user_role === 'production') {
    // Production เห็นเฉพาะงานที่มอบหมายให้ตัวเอง
    $where_clause .= " AND (pj.assigned_to = ? OR pj.assigned_to_name LIKE ?)";
    $params[] = $user_id;
    $params[] = "%" . $_SESSION['full_name'] . "%";
}

// Filter by status
if ($filter !== 'all') {
    if ($filter === 'overdue') {
        $where_clause .= " AND DATEDIFF(pj.end_date, CURDATE()) < 0 AND pj.status IN ('pending', 'in_progress')";
    } else {
        $where_clause .= " AND pj.status = ?";
        $params[] = $filter;
    }
}

// Search
if (!empty($search)) {
    $where_clause .= " AND (pj.job_number LIKE ? OR p.product_name LIKE ? OR pj.assigned_to_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

// ดึงข้อมูลงาน
$jobs_query = "
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
        LIMIT {$limit} OFFSET {$offset}
    ";

$jobs_stmt = $db->prepare($jobs_query);
$jobs_stmt->execute($params);
$all_jobs = $jobs_stmt->fetchAll();

// Total count for pagination
$count_query = "SELECT COUNT(*) as total FROM production_jobs pj WHERE $where_clause";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($params);
$total_jobs = (int)$count_stmt->fetchColumn();
$total_pages = $limit > 0 ? ceil($total_jobs / $limit) : 1;

// สถิติ
$stats_where = $where_clause;
$stats_params = $params;
if ($filter !== 'all') {
    // สำหรับสถิติ ให้ดึงทั้งหมด
    if ($user_role === 'production') {
        $stats_where = "(pj.assigned_to = ? OR pj.assigned_to_name LIKE ?)";
        $stats_params = [$user_id, "%" . $_SESSION['full_name'] . "%"];
    } else {
        $stats_where = "1=1";
        $stats_params = [];
    }
}

$stats_query = "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM production_jobs pj
    WHERE $stats_where
";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute($stats_params);
$statistics = $stats_stmt->fetch();
?>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card" style="border-left: 4px solid #667eea;">
            <div class="card-body text-center">
                <div style="font-size: 2rem; font-weight: bold; color: #667eea;"><?= number_format($statistics['total']) ?></div>
                <small class="text-muted">งานทั้งหมด</small>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card" style="border-left: 4px solid #ffc107;">
            <div class="card-body text-center">
                <div style="font-size: 2rem; font-weight: bold; color: #ffc107;"><?= number_format($statistics['pending']) ?></div>
                <small class="text-muted">รอเริ่มงาน</small>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card" style="border-left: 4px solid #17a2b8;">
            <div class="card-body text-center">
                <div style="font-size: 2rem; font-weight: bold; color: #17a2b8;"><?= number_format($statistics['in_progress']) ?></div>
                <small class="text-muted">กำลังผลิต</small>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card" style="border-left: 4px solid #28a745;">
            <div class="card-body text-center">
                <div style="font-size: 2rem; font-weight: bold; color: #28a745;"><?= number_format($statistics['completed']) ?></div>
                <small class="text-muted">เสร็จแล้ว</small>
            </div>
        </div>
    </div>
</div>

<!-- Filter Tabs -->
<div class="mb-4">
    <div class="btn-group" role="group">
        <a href="?filter=all" class="btn btn-outline-primary <?= $filter === 'all' ? 'active' : '' ?>">
            <i class="fas fa-list me-1"></i>ทั้งหมด <span class="badge bg-secondary ms-1"><?= $statistics['total'] ?></span>
        </a>
        <a href="?filter=pending" class="btn btn-outline-primary <?= $filter === 'pending' ? 'active' : '' ?>">
            <i class="fas fa-clock me-1"></i>รอเริ่มงาน <span class="badge bg-warning ms-1"><?= $statistics['pending'] ?></span>
        </a>
        <a href="?filter=in_progress" class="btn btn-outline-primary <?= $filter === 'in_progress' ? 'active' : '' ?>">
            <i class="fas fa-cogs me-1"></i>กำลังผลิต <span class="badge bg-info ms-1"><?= $statistics['in_progress'] ?></span>
        </a>
        <a href="?filter=completed" class="btn btn-outline-primary <?= $filter === 'completed' ? 'active' : '' ?>">
            <i class="fas fa-check-circle me-1"></i>เสร็จแล้ว <span class="badge bg-success ms-1"><?= $statistics['completed'] ?></span>
        </a>
    </div>
</div>

<!-- Search -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="text" class="form-control" id="searchInput" 
                   placeholder="ค้นหาเลขที่งาน, สินค้า, หรือผู้รับผิดชอบ..." 
                   value="<?= htmlspecialchars($search) ?>">
        </div>
    </div>
    <div class="col-md-4 text-end">
        <?php if (in_array($user_role, ['planning', 'admin'])): ?>
            <a href="create-job.php" class="btn btn-success me-2">
                <i class="fas fa-plus me-1"></i>สร้างงานใหม่
            </a>
        <?php endif; ?>
        <button class="btn btn-outline-primary" onclick="refreshPage()">
            <i class="fas fa-sync-alt me-1"></i>รีเฟรช
        </button>
    </div>
</div>

<!-- Page length selector -->
<div class="row mb-3">
    <div class="col-md-2">
        <div class="input-group">
            <label class="input-group-text" for="selectLimit">Show</label>
            <select id="selectLimit" class="form-select" onchange="changeLimit()">
                <?php foreach ($allowed_limits as $opt): ?>
                    <option value="<?= $opt ?>" <?= $limit == $opt ? 'selected' : '' ?>><?= $opt ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="col-md-10 text-end">
        <?php if ($total_jobs > 0): ?>
            <small class="text-muted">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_jobs) ?> of <?= $total_jobs ?> entries</small>
        <?php endif; ?>
    </div>
</div>

<!-- Jobs Table -->
<?php if (!empty($all_jobs)): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่งาน</th>
                        <th>สินค้า</th>
                        <th>จำนวน / ความคืบหน้า</th>
                        <th>ผู้รับผิดชอบ</th>
                        <th>สถานะ</th>
                        <th>วันที่กำหนด</th>
                        <th>การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_jobs as $job): ?>
                        <?php
                        $status_colors = [
                            'pending' => 'warning',
                            'in_progress' => 'info',
                            'completed' => 'success',
                            'cancelled' => 'danger'
                        ];
                        
                        $status_texts = [
                            'pending' => 'รอเริ่มงาน',
                            'in_progress' => 'กำลังผลิต',
                            'completed' => 'เสร็จแล้ว',
                            'cancelled' => 'ยกเลิก'
                        ];
                        
                        $is_overdue = $job['days_remaining'] < 0 && in_array($job['status'], ['pending', 'in_progress']);
                        if (!empty($job['assigned_to_name'])) {
                            $assigned_name = $job['assigned_to_name'];
                        } elseif (!empty($job['assigned_user_name'])) {
                            $assigned_name = $job['assigned_user_name'];
                        } else {
                            // Fallback: try to extract from notes (format added by older code)
                            $assigned_name = 'ไม่ระบุ';
                            if (!empty($job['notes'])) {
                                if (preg_match('/Assigned to:\s*(.+)/u', $job['notes'], $m)) {
                                    $assigned_name = trim($m[1]);
                                }
                            }
                        }
                        ?>
                        <tr <?= $is_overdue ? 'style="background-color: #fff8e1;"' : '' ?>>
                            <td><strong><?= htmlspecialchars($job['job_number']) ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($job['product_code']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($job['product_name']) ?></small>
                            </td>
                            <td>
                                <div>
                                    <small><?= number_format($job['quantity_produced']) ?>/<?= number_format($job['quantity_planned']) ?></small>
                                </div>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar" role="progressbar" style="width: <?= $job['progress_percent'] ?>%;" 
                                         aria-valuenow="<?= $job['progress_percent'] ?>" aria-valuemin="0" aria-valuemax="100">
                                        <?= round($job['progress_percent']) ?>%
                                    </div>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($assigned_name) ?></td>
                            <td>
                                <span class="badge bg-<?= $status_colors[$job['status']] ?>">
                                    <?= $status_texts[$job['status']] ?>
                                </span>
                                <?php if ($is_overdue): ?>
                                    <br><span class="badge bg-danger mt-1">
                                        <i class="fas fa-exclamation-triangle"></i> เกินกำหนด
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= date('d/m/Y', strtotime($job['end_date'])) ?>
                                <br>
                                <small class="<?= $is_overdue ? 'text-danger' : ($job['days_remaining'] <= 3 ? 'text-warning' : 'text-success') ?>">
                                    <?php if ($job['days_remaining'] < 0): ?>
                                        เกิน <?= abs($job['days_remaining']) ?> วัน
                                    <?php else: ?>
                                        เหลือ <?= $job['days_remaining'] ?> วัน
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <button class="btn btn-outline-primary btn-sm" onclick="viewJobDetail(<?= $job['job_id'] ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <?php
            // Helper to build page URL preserving params
            function build_page_url($p, $limit) {
                $params = $_GET;
                $params['page'] = $p;
                $params['limit'] = $limit;
                return '?' . http_build_query($params);
            }

            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            if ($end_page - $start_page < 4) {
                // expand range to 5 pages when possible
                $start_page = max(1, min($start_page, $total_pages - 4));
                $end_page = min($total_pages, max($end_page, $start_page + 4));
            }
        ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <div>
                <small class="text-muted">Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $total_jobs) ?> of <?= $total_jobs ?> entries</small>
            </div>
            <nav>
                <ul class="pagination mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $page <= 1 ? '#' : build_page_url($page-1, $limit) ?>" aria-label="Previous">&laquo;</a>
                    </li>
                    <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                        <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= build_page_url($p, $limit) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= $page >= $total_pages ? '#' : build_page_url($page+1, $limit) ?>" aria-label="Next">&raquo;</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="fas fa-inbox fa-4x text-muted mb-4"></i>
            <h4 class="text-muted">ไม่พบงานการผลิต</h4>
            <?php if (!empty($search)): ?>
                <p class="text-muted">ไม่มีงานที่ตรงกับคำค้นหา</p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

        </div>
    </div>

    <!-- Job Detail Modal -->
    <div class="modal fade" id="jobDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-briefcase me-2"></i>รายละเอียดงานการผลิต
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="jobDetailContent">
                        <!-- Detail will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
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

    function viewJobDetail(jobId) {
        fetch(`../../api/jobs.php?action=get_detail&job_id=${jobId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const job = data.data;
                    const statusColors = {
                        'pending': 'warning',
                        'in_progress': 'info',
                        'completed': 'success',
                        'cancelled': 'danger'
                    };
                    const statusTexts = {
                        'pending': 'รอเริ่มงาน',
                        'in_progress': 'กำลังผลิต',
                        'completed': 'เสร็จแล้ว',
                        'cancelled': 'ยกเลิก'
                    };

                    const displayAssigned = job.assigned_to_name || job.assigned_user_name || (job.notes && (job.notes.match(/Assigned to:\s*(.+)/) || [])[1]) || 'ไม่ระบุ';

                    let html = `
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6 class="mb-1">
                                    <strong>เลขที่งาน:</strong> ${job.job_number}
                                </h6>
                            </div>
                            <div class="col-md-6 text-end">
                                <span class="badge bg-${statusColors[job.status]}">
                                    ${statusTexts[job.status]}
                                </span>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>สินค้า:</strong><br>
                                ${job.product_code} - ${job.product_name}
                            </div>
                            <div class="col-md-6">
                                <strong>ผู้รับผิดชอบ:</strong><br>
                                ${displayAssigned}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>จำนวนที่วางแผน:</strong><br>
                                ${Number(job.quantity_planned).toLocaleString()} หน่วย
                            </div>
                            <div class="col-md-6">
                                <strong>จำนวนที่ผลิตแล้ว:</strong><br>
                                ${Number(job.quantity_produced).toLocaleString()} หน่วย
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <strong>วันเริ่ม:</strong><br>
                                ${new Date(job.start_date).toLocaleDateString('th-TH')}
                            </div>
                            <div class="col-md-6">
                                <strong>วันสิ้นสุด:</strong><br>
                                ${new Date(job.end_date).toLocaleDateString('th-TH')}
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar" role="progressbar" 
                                     style="width: ${(job.quantity_produced / job.quantity_planned * 100) || 0}%;"
                                     aria-valuenow="${(job.quantity_produced / job.quantity_planned * 100) || 0}" 
                                     aria-valuemin="0" aria-valuemax="100">
                                    ${Math.round((job.quantity_produced / job.quantity_planned * 100) || 0)}%
                                </div>
                            </div>
                        </div>
                        ${job.notes ? `
                            <div class="mb-3 p-3 bg-light rounded">
                                <strong>หมายเหตุ:</strong><br>
                                <small>${job.notes.replace(/\n/g, '<br>')}</small>
                            </div>
                        ` : ''}
                        
                        ${job.required_materials && job.required_materials.length > 0 ? `
                            <hr>
                            <h6 class="mb-3"><i class="fas fa-list me-2"></i>รายการวัสดุ</h6>
                            <div class="table-responsive">
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
                                        ${job.required_materials.map(m => {
                                            const quantityPerCard = m.quantity_per_card || m.quantity_per_unit || 1;
                                            const requiredQty = m.required_quantity || 0;
                                            const totalCards = Math.ceil(requiredQty / quantityPerCard);
                                            const cardColor = m.card_color || '#3498db';
                                            return `
                                                <tr>
                                                    <td><strong>${m.part_code}</strong></td>
                                                    <td>${m.material_name}</td>
                                                    <td class="text-end">${Number(requiredQty).toLocaleString()}</td>
                                                    <td>${m.unit}</td>
                                                    <td class="text-center">
                                                        <div style="display: inline-flex; align-items: center;">
                                                            <div style="width: 45px; height: 45px; background-color: ${cardColor}; border: 2px solid #ddd; border-radius: 6px; display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                                                                <span style="color: white; font-weight: bold; text-shadow: 0 1px 1px rgba(0,0,0,0.2); font-size: 14px;">${totalCards}</span>
                                                                <span style="color: white; font-weight: 500; text-shadow: 0 1px 1px rgba(0,0,0,0.2); font-size: 8px;">ใบ</span>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        ` : ''}
                    `;
                    
                    document.getElementById('jobDetailContent').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('jobDetailModal')).show();
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message || 'ไม่สามารถโหลดข้อมูลได้', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
            });
    }

    function changeLimit() {
        const sel = document.getElementById('selectLimit');
        const newLimit = sel.value;
        const url = new URL(window.location);
        url.searchParams.set('limit', newLimit);
        url.searchParams.set('page', 1);
        window.location.href = url.toString();
    }

    function refreshPage() {
        location.reload();
    }

    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        sidebar.classList.toggle('show');
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
