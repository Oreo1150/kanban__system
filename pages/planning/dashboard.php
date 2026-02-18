<?php
// pages/planning/dashboard.php
$page_title = 'แดชบอร์ดแผนกวางแผน';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'แดชบอร์ด']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['planning', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลสถิติการวางแผน
$stats = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM production_jobs WHERE created_by = {$_SESSION['user_id']}) as total_jobs,
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'pending' AND created_by = {$_SESSION['user_id']}) as pending_jobs,
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'in_progress' AND created_by = {$_SESSION['user_id']}) as active_jobs,
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'completed' AND created_by = {$_SESSION['user_id']}) as completed_jobs,
        (SELECT COUNT(*) FROM purchase_requests WHERE status = 'pending') as pending_prs,
        (SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND status = 'active') as low_stock_materials
")->fetch();

// งานที่กำลังดำเนินการ
$active_jobs = $db->query("
    SELECT pj.*, p.product_name, u.full_name as assigned_to_name,
           DATEDIFF(pj.end_date, CURDATE()) as days_remaining,
           ROUND((pj.quantity_produced / pj.quantity_planned) * 100, 2) as progress_percent
    FROM production_jobs pj
    LEFT JOIN products p ON pj.product_id = p.product_id
    LEFT JOIN users u ON pj.assigned_to = u.user_id
    WHERE pj.status IN ('pending', 'in_progress') AND pj.created_by = {$_SESSION['user_id']}
    ORDER BY pj.start_date ASC
    LIMIT 8
")->fetchAll();

// (Removed material/PR and weekly performance queries — these sections were removed from the UI)
?>

            <div class="row">
                <!-- Stats Cards -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center">
                        <i class="fas fa-tasks icon"></i>
                        <div class="number"><?= number_format($stats['total_jobs']) ?></div>
                        <div class="label">งานทั้งหมด</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #ffc107, #ff8c00);">
                        <i class="fas fa-clock icon"></i>
                        <div class="number"><?= number_format($stats['pending_jobs']) ?></div>
                        <div class="label">งานรอเริ่ม</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #17a2b8, #6f42c1);">
                        <i class="fas fa-cogs icon"></i>
                        <div class="number"><?= number_format($stats['active_jobs']) ?></div>
                        <div class="label">กำลังผลิต</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <i class="fas fa-check-circle icon"></i>
                        <div class="number"><?= number_format($stats['completed_jobs']) ?></div>
                        <div class="label">เสร็จแล้ว</div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-bolt me-2"></i>การดำเนินการด่วน</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-4 col-md-4 mb-3">
                                    <a href="create-job.php" class="btn btn-primary btn-lg w-100">
                                        <i class="fas fa-plus fa-2x d-block mb-2"></i>
                                        สร้างงานใหม่
                                    </a>
                                </div>
                                <div class="col-lg-4 col-md-4 mb-3">
                                    <a href="purchase-requests.php" class="btn btn-warning btn-lg w-100">
                                        <i class="fas fa-shopping-cart fa-2x d-block mb-2"></i>
                                        สั่งซื้อวัสดุ
                                    </a>
                                </div>
                                <div class="col-lg-4 col-md-4 mb-3">
                                    <a href="production-jobs.php" class="btn btn-info btn-lg w-100">
                                        <i class="fas fa-list-alt fa-2x d-block mb-2"></i>
                                        ติดตามงาน
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- งานที่กำลังดำเนินการ (ขยายเป็นเต็มความกว้าง) -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-tasks me-2"></i>งานที่กำลังดำเนินการ</h5>
                            <span class="badge bg-primary"><?= (is_countable($active_jobs) ? count($active_jobs) : 0) ?> งาน</span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($active_jobs)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>เลขที่งาน</th>
                                                <th>สินค้า</th>
                                                <th>ผู้รับผิดชอบ</th>
                                                <th>ความคืบหน้า</th>
                                                <th>สถานะ</th>
                                                <th>วันที่เหลือ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($active_jobs as $job): ?>
                                                <?php
                                                $status_class = [
                                                    'pending' => 'status-pending',
                                                    'in_progress' => 'status-in-progress',
                                                    'completed' => 'status-completed',
                                                    'cancelled' => 'status-cancelled'
                                                ];
                                                
                                                $status_text = [
                                                    'pending' => 'รอเริ่ม',
                                                    'in_progress' => 'กำลังผลิต',
                                                    'completed' => 'เสร็จแล้ว',
                                                    'cancelled' => 'ยกเลิก'
                                                ];
                                                
                                                $days_class = $job['days_remaining'] < 0 ? 'text-danger' : ($job['days_remaining'] <= 3 ? 'text-warning' : 'text-success');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($job['job_number']) ?></strong>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($job['product_name']) ?><br>
                                                        <small class="text-muted">
                                                            <?= number_format($job['quantity_produced']) ?>/<?= number_format($job['quantity_planned']) ?> ชิ้น
                                                        </small>
                                                    </td>
                                                    <td><?= htmlspecialchars($job['assigned_to_name']) ?></td>
                                                    <td>
                                                        <div class="progress mb-1" style="height: 8px;">
                                                            <div class="progress-bar bg-success" style="width: <?= min($job['progress_percent'], 100) ?>%"></div>
                                                        </div>
                                                        <small><?= $job['progress_percent'] ?>%</small>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?= $status_class[$job['status']] ?>">
                                                            <?= $status_text[$job['status']] ?>
                                                        </span>
                                                    </td>
                                                    <td class="<?= $days_class ?>">
                                                        <?php if ($job['days_remaining'] < 0): ?>
                                                            เกินกำหนด <?= abs($job['days_remaining']) ?> วัน
                                                        <?php elseif ($job['days_remaining'] == 0): ?>
                                                            วันนี้
                                                        <?php else: ?>
                                                            อีก <?= $job['days_remaining'] ?> วัน
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="production-jobs.php" class="btn btn-primary">
                                        <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-3">ไม่มีงานที่กำลังดำเนินการ</p>
                                    <a href="create-job.php" class="btn btn-primary">
                                        <i class="fas fa-plus me-1"></i>สร้างงานใหม่
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right notification column removed -->
            </div>

            <!-- Performance chart removed -->

        </div>
    </div>

    <!-- Create PR Modal removed -->

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // (Performance chart and Create PR functionality removed)

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Auto refresh every 2 minutes
        setInterval(() => {
            location.reload();
        }, 120000);
    </script>

</body>
</html>