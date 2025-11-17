<?php
// pages/admin/dashboard.php
$page_title = 'แดชบอร์ดผู้ดูแลระบบ';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'แดชบอร์ด']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลสถิติ
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE status = 'active') as total_users,
        (SELECT COUNT(*) FROM materials WHERE status = 'active') as total_materials,
        (SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND status = 'active') as low_stock_materials,
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'in_progress') as active_jobs
";

$stats = $db->query($stats_query)->fetch();

// ดึงข้อมูลวัสดุที่สต็อกต่ำ
$low_stock_query = "
    SELECT material_name, part_code, current_stock, min_stock, 
           ROUND(((current_stock / min_stock) * 100), 2) as stock_percentage
    FROM materials 
    WHERE current_stock <= min_stock AND status = 'active'
    ORDER BY stock_percentage ASC
    LIMIT 10
";
$low_stock_materials = $db->query($low_stock_query)->fetchAll();

// ดึงข้อมูลการใช้งานล่าสุด
$recent_activities = $db->query("
    SELECT al.*, u.full_name, u.role 
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    ORDER BY al.created_at DESC
    LIMIT 10
")->fetchAll();

// ดึงข้อมูลสำหรับกราฟ
$monthly_production = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as job_count,
        SUM(quantity_planned) as total_planned,
        SUM(quantity_produced) as total_produced
    FROM production_jobs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();
?>

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center">
                        <i class="fas fa-users icon"></i>
                        <div class="number"><?= number_format($stats['total_users']) ?></div>
                        <div class="label">ผู้ใช้งานทั้งหมด</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <i class="fas fa-boxes icon"></i>
                        <div class="number"><?= number_format($stats['total_materials']) ?></div>
                        <div class="label">วัสดุทั้งหมด</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #dc3545, #fd7e14);">
                        <i class="fas fa-exclamation-triangle icon"></i>
                        <div class="number"><?= number_format($stats['low_stock_materials']) ?></div>
                        <div class="label">วัสดุสต็อกต่ำ</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #17a2b8, #6f42c1);">
                        <i class="fas fa-tasks icon"></i>
                        <div class="number"><?= number_format($stats['active_jobs']) ?></div>
                        <div class="label">งานกำลังผลิต</div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <!-- กราฟสถิติการผลิต -->
                <div class="col-xl-8 col-lg-8">
                    <div class="card h-100">
                        <div class="card-header bg-gradient" style="background: linear-gradient(135deg, #667eea, #764ba2);">
                            <h5 class="mb-0 text-white"><i class="fas fa-chart-line me-2"></i>สถิติการผลิต 6 เดือนย้อนหลัง</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="productionChart" height="80"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="col-xl-4 col-lg-4">
                    <div class="card h-100">
                        <div class="card-header" style="background: linear-gradient(135deg, #f093fb, #f5576c);">
                            <h5 class="mb-0 text-white"><i class="fas fa-bolt me-2"></i>การจัดการด่วน</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-3">
                                <a href="users.php" class="btn btn-outline-primary d-flex align-items-center justify-content-start">
                                    <i class="fas fa-user-plus me-3 fa-lg"></i>
                                    <div class="text-start">
                                        <strong>เพิ่มผู้ใช้ใหม่</strong>
                                        <small class="d-block text-muted">จัดการบัญชีผู้ใช้งาน</small>
                                    </div>
                                </a>
                                <a href="materials.php" class="btn btn-outline-success d-flex align-items-center justify-content-start">
                                    <i class="fas fa-plus me-3 fa-lg"></i>
                                    <div class="text-start">
                                        <strong>เพิ่มวัสดุใหม่</strong>
                                        <small class="d-block text-muted">เพิ่มรายการวัสดุ</small>
                                    </div>
                                </a>
                                <a href="bom.php" class="btn btn-outline-info d-flex align-items-center justify-content-start">
                                    <i class="fas fa-list-alt me-3 fa-lg"></i>
                                    <div class="text-start">
                                        <strong>จัดการ BOM</strong>
                                        <small class="d-block text-muted">Bill of Materials</small>
                                    </div>
                                </a>
                                <a href="audit-logs.php" class="btn btn-outline-secondary d-flex align-items-center justify-content-start">
                                    <i class="fas fa-history me-3 fa-lg"></i>
                                    <div class="text-start">
                                        <strong>ดูประวัติระบบ</strong>
                                        <small class="d-block text-muted">บันทึกการใช้งาน</small>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- วัสดุที่สต็อกต่ำ -->
                <div class="col-xl-6 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>วัสดุที่สต็อกต่ำ</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($low_stock_materials)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>รหัสวัสดุ</th>
                                                <th>ชื่อวัสดุ</th>
                                                <th>คงเหลือ</th>
                                                <th>สถานะ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($low_stock_materials as $material): ?>
                                                <tr>
                                                    <td><strong><?= htmlspecialchars($material['part_code']) ?></strong></td>
                                                    <td><?= htmlspecialchars($material['material_name']) ?></td>
                                                    <td>
                                                        <span class="badge bg-danger">
                                                            <?= number_format($material['current_stock']) ?>
                                                        </span>
                                                        / <?= number_format($material['min_stock']) ?>
                                                    </td>
                                                    <td>
                                                        <div class="progress" style="height: 6px;">
                                                            <div class="progress-bar bg-danger" style="width: <?= min($material['stock_percentage'], 100) ?>%"></div>
                                                        </div>
                                                        <small class="text-danger"><?= $material['stock_percentage'] ?>%</small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="materials.php?filter=low_stock" class="btn btn-danger">
                                        <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <h5 class="text-success">ดีเยี่ยม!</h5>
                                    <p class="text-muted mb-0">วัสดุทุกรายการมีสต็อกเพียงพอ</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- กิจกรรมล่าสุด -->
                <div class="col-xl-6 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header" style="background: linear-gradient(135deg, #4facfe, #00f2fe);">
                            <h5 class="mb-0 text-white"><i class="fas fa-history me-2"></i>กิจกรรมล่าสุด</h5>
                        </div>
                        <div class="card-body">
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (!empty($recent_activities)): ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="d-flex mb-3 pb-3 border-bottom">
                                            <div class="flex-shrink-0 me-3">
                                                <div class="rounded-circle d-flex align-items-center justify-content-center" 
                                                     style="width: 45px; height: 45px; background: linear-gradient(135deg, #667eea, #764ba2); color: white;">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?= htmlspecialchars($activity['full_name'] ?? 'ระบบ') ?></h6>
                                                <p class="text-muted mb-1 small">
                                                    <?php
                                                    $action_text = [
                                                        'create' => 'สร้าง',
                                                        'update' => 'แก้ไข',
                                                        'delete' => 'ลบ',
                                                        'login' => 'เข้าสู่ระบบ',
                                                        'logout' => 'ออกจากระบบ'
                                                    ];
                                                    echo $action_text[$activity['action']] ?? htmlspecialchars($activity['action']);
                                                    ?>
                                                    <?php if ($activity['table_name']): ?>
                                                        ใน <?= htmlspecialchars($activity['table_name']) ?>
                                                    <?php endif; ?>
                                                </p>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?= date('d/m/Y H:i', strtotime($activity['created_at'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="text-center">
                                        <a href="audit-logs.php" class="btn btn-outline-info">
                                            <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                        <p class="text-muted mb-0">ไม่มีกิจกรรมล่าสุด</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // กราฟสถิติการผลิต
        const ctx = document.getElementById('productionChart').getContext('2d');
        const productionData = <?= json_encode($monthly_production) ?>;
        
        const monthLabels = productionData.map(item => {
            const [year, month] = item.month.split('-');
            const monthNames = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
            return monthNames[parseInt(month) - 1] + ' ' + (parseInt(year) + 543);
        });
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'งานที่สร้าง',
                    data: productionData.map(item => item.job_count),
                    borderColor: 'rgb(102, 126, 234)',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'จำนวนที่วางแผน',
                    data: productionData.map(item => item.total_planned),
                    borderColor: 'rgb(118, 75, 162)',
                    backgroundColor: 'rgba(118, 75, 162, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'จำนวนที่ผลิตจริง',
                    data: productionData.map(item => item.total_produced),
                    borderColor: 'rgb(40, 167, 69)',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            padding: 15,
                            usePointStyle: true,
                            font: {
                                size: 12
                            }
                        }
                    },
                    title: {
                        display: false
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 14
                        },
                        bodyFont: {
                            size: 13
                        }
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            }
                        }
                    }
                }
            }
        });

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Auto refresh notifications every 30 seconds
        setInterval(function() {
            fetch('../../api/notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.count) {
                        const badge = document.querySelector('.notification-badge');
                        if (badge) {
                            badge.textContent = data.count;
                            badge.style.display = data.count > 0 ? 'flex' : 'none';
                        }
                    }
                })
                .catch(error => console.log('Notification update failed:', error));
        }, 30000);

        // Show loading on form submit
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    const loading = document.getElementById('loading');
                    if (loading) {
                        loading.style.display = 'block';
                    }
                });
            });
            
            // Hide loading after page load
            window.addEventListener('load', function() {
                const loading = document.getElementById('loading');
                if (loading) {
                    loading.style.display = 'none';
                }
            });
        });

        // Smooth scroll for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });
    </script>

</body>
</html>