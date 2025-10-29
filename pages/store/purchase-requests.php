<?php
// pages/store/purchase-requests.php
$page_title = 'คำขอสั่งซื้อวัสดุ';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'คำขอสั่งซื้อวัสดุ']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['store', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลคำขอทั้งหมด
$my_requests = $db->query("
    SELECT pr.*, m.part_code, m.material_name, m.unit, m.current_stock,
           u1.full_name as created_by_name,
           u2.full_name as approved_by_name
    FROM purchase_requests pr
    LEFT JOIN materials m ON pr.material_id = m.material_id
    LEFT JOIN users u1 ON pr.created_by = u1.user_id
    LEFT JOIN users u2 ON pr.approved_by = u2.user_id
    WHERE pr.created_by = {$_SESSION['user_id']}
    ORDER BY pr.created_at DESC
")->fetchAll();

// ดึงข้อมูลวัสดุที่ควรสั่งซื้อ
$materials_to_order = $db->query("
    SELECT m.*,
           (m.min_stock - m.current_stock) as need_to_order,
           (SELECT pr_id FROM purchase_requests 
            WHERE material_id = m.material_id 
            AND status IN ('pending', 'approved', 'ordered')
            ORDER BY created_at DESC LIMIT 1) as existing_pr_id
    FROM materials m
    WHERE m.current_stock <= m.min_stock AND m.status = 'active'
    ORDER BY (m.current_stock / m.min_stock) ASC
")->fetchAll();

// สถิติคำขอ
$stats = $db->query("
    SELECT 
        COUNT(*) as total_requests,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        SUM(CASE WHEN status = 'ordered' THEN 1 ELSE 0 END) as ordered
    FROM purchase_requests
    WHERE created_by = {$_SESSION['user_id']}
")->fetch();
?>

<style>
    .stats-card {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 20px;
        transition: all 0.3s ease;
    }

    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
    }

    .stats-card h3 {
        font-size: 2rem;
        margin: 10px 0;
    }

    .material-card {
        border: 2px solid #e9ecef;
        border-radius: 15px;
        padding: 15px;
        margin-bottom: 10px;
        transition: all 0.3s ease;
    }

    .material-card:hover {
        border-color: var(--primary-color);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
    }

    .stock-level {
        position: relative;
        height: 8px;
        background: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
    }

    .stock-level-fill {
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        transition: width 0.3s ease;
    }

    .urgency-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .urgency-low {
        background: #e9ecef;
        color: #495057;
    }

    .urgency-medium {
        background: #fff3cd;
        color: #856404;
    }

    .urgency-high {
        background: #f8d7da;
        color: #721c24;
    }

    .urgency-urgent {
        background: #dc3545;
        color: white;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .timeline {
        position: relative;
        padding-left: 30px;
    }

    .timeline::before {
        content: '';
        position: absolute;
        left: 10px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #e9ecef;
    }

    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }

    .timeline-marker {
        position: absolute;
        left: -25px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid #fff;
        box-shadow: 0 0 0 2px #e9ecef;
    }

    .timeline-content {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 10px;
        border-left: 3px solid #007bff;
    }
</style>

<div class="row">
    <!-- Statistics Cards -->
    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card text-center">
            <i class="fas fa-shopping-cart fa-2x mb-2"></i>
            <h3><?= number_format($stats['total_requests']) ?></h3>
            <p class="mb-0">คำขอทั้งหมด</p>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card text-center" style="background: linear-gradient(135deg, #ffc107, #ff8c00);">
            <i class="fas fa-clock fa-2x mb-2"></i>
            <h3><?= number_format($stats['pending']) ?></h3>
            <p class="mb-0">รอพิจารณา</p>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
            <i class="fas fa-check-circle fa-2x mb-2"></i>
            <h3><?= number_format($stats['approved']) ?></h3>
            <p class="mb-0">อนุมัติแล้ว</p>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-4">
        <div class="stats-card text-center" style="background: linear-gradient(135deg, #17a2b8, #6f42c1);">
            <i class="fas fa-truck fa-2x mb-2"></i>
            <h3><?= number_format($stats['ordered']) ?></h3>
            <p class="mb-0">สั่งซื้อแล้ว</p>
        </div>
    </div>
</div>

<div class="row">
    <!-- Materials to Order -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="fas fa-exclamation-triangle text-warning me-2"></i>วัสดุที่ควรสั่งซื้อ</h5>
                <span class="badge bg-warning"><?= count($materials_to_order) ?> รายการ</span>
            </div>
            <div class="card-body">
                <div style="max-height: 600px; overflow-y: auto;">
                    <?php if (!empty($materials_to_order)): ?>
                        <?php foreach ($materials_to_order as $material): ?>
                            <?php
                            $stock_percent = $material['min_stock'] > 0 
                                ? ($material['current_stock'] / $material['min_stock']) * 100 
                                : 0;
                            $stock_color = $stock_percent < 50 ? '#dc3545' : '#ffc107';
                            ?>
                            <div class="material-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1">
                                            <strong><?= htmlspecialchars($material['part_code']) ?></strong>
                                        </h6>
                                        <p class="text-muted mb-1 small">
                                            <?= htmlspecialchars($material['material_name']) ?>
                                        </p>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-danger">
                                            <?= number_format($material['current_stock']) ?>
                                        </span>
                                    </div>
                                </div>

                                <!-- Stock Level Bar -->
                                <div class="stock-level mb-2">
                                    <div class="stock-level-fill" 
                                         style="width: <?= min($stock_percent, 100) ?>%; background: <?= $stock_color ?>;">
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between align-items-center">
                                    <small class="text-muted">
                                        ต้องการสั่ง: <strong><?= number_format($material['need_to_order']) ?> <?= htmlspecialchars($material['unit']) ?></strong>
                                    </small>
                                    
                                    <?php if ($material['existing_pr_id']): ?>
                                        <span class="badge bg-info">
                                            <i class="fas fa-clock"></i> มี PR แล้ว
                                        </span>
                                    <?php else: ?>
                                        <button class="btn btn-warning btn-sm" 
                                                onclick="createPR(<?= $material['material_id'] ?>, '<?= htmlspecialchars($material['part_code']) ?>', '<?= htmlspecialchars($material['material_name']) ?>', <?= $material['need_to_order'] ?>, '<?= htmlspecialchars($material['unit']) ?>')">
                                            <i class="fas fa-plus"></i> สร้าง PR
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if ($material['location']): ?>
                                    <small class="text-muted">
                                        <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($material['location']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                            <p class="text-success mb-0">วัสดุทุกรายการเพียงพอ</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- My Purchase Requests -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="fas fa-list me-2"></i>คำขอสั่งซื้อของฉัน</h5>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createPRModal">
                    <i class="fas fa-plus me-1"></i>สร้างคำขอใหม่
                </button>
            </div>
            <div class="card-body">
                <div style="max-height: 600px; overflow-y: auto;">
                    <?php if (!empty($my_requests)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>เลขที่</th>
                                        <th>วัสดุ</th>
                                        <th>จำนวน</th>
                                        <th>ความเร่งด่วน</th>
                                        <th>สถานะ</th>
                                        <th>วันที่</th>
                                        <th>จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($my_requests as $request): ?>
                                        <?php
                                        $status_config = [
                                            'pending' => ['class' => 'bg-warning', 'icon' => 'clock', 'text' => 'รอพิจารณา'],
                                            'approved' => ['class' => 'bg-success', 'icon' => 'check-circle', 'text' => 'อนุมัติ'],
                                            'rejected' => ['class' => 'bg-danger', 'icon' => 'times-circle', 'text' => 'ปฏิเสธ'],
                                            'ordered' => ['class' => 'bg-info', 'icon' => 'truck', 'text' => 'สั่งซื้อแล้ว'],
                                            'received' => ['class' => 'bg-secondary', 'icon' => 'check', 'text' => 'รับแล้ว']
                                        ];
                                        $status = $status_config[$request['status']] ?? $status_config['pending'];
                                        
                                        $urgency_config = [
                                            'low' => ['class' => 'urgency-low', 'text' => 'ปกติ'],
                                            'medium' => ['class' => 'urgency-medium', 'text' => 'ค่อนข้างเร่ง'],
                                            'high' => ['class' => 'urgency-high', 'text' => 'เร่งด่วน'],
                                            'urgent' => ['class' => 'urgency-urgent', 'text' => 'เร่งด่วนมาก']
                                        ];
                                        $urgency = $urgency_config[$request['urgency']] ?? $urgency_config['medium'];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($request['pr_number']) ?></strong>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($request['part_code']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($request['material_name']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?= number_format($request['quantity_requested']) ?>
                                                </span><br>
                                                <small class="text-muted"><?= htmlspecialchars($request['unit']) ?></small>
                                            </td>
                                            <td>
                                                <span class="urgency-badge <?= $urgency['class'] ?>">
                                                    <?= $urgency['text'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?= $status['class'] ?>">
                                                    <i class="fas fa-<?= $status['icon'] ?>"></i>
                                                    <?= $status['text'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small>
                                                    <?= date('d/m/Y', strtotime($request['created_at'])) ?><br>
                                                    <?= date('H:i', strtotime($request['created_at'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <button class="btn btn-info btn-sm" onclick="viewPR(<?= $request['pr_id'] ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($request['status'] === 'pending'): ?>
                                                    <button class="btn btn-danger btn-sm" onclick="cancelPR(<?= $request['pr_id'] ?>)">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted mb-3">ยังไม่มีคำขอสั่งซื้อ</p>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createPRModal">
                                <i class="fas fa-plus me-1"></i>สร้างคำขอใหม่
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create PR Modal -->
<div class="modal fade" id="createPRModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>สร้างคำขอสั่งซื้อวัสดุ
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="createPRForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-box me-1"></i>วัสดุ <span class="text-danger">*</span>
                            </label>
                            <select class="form-control" id="pr_material_id" name="material_id" required onchange="updateMaterialInfo()">
                                <option value="">เลือกวัสดุ</option>
                                <?php
                                $all_materials = $db->query("
                                    SELECT material_id, part_code, material_name, unit, current_stock, min_stock
                                    FROM materials 
                                    WHERE status = 'active'
                                    ORDER BY part_code
                                ")->fetchAll();
                                
                                foreach ($all_materials as $mat):
                                ?>
                                    <option value="<?= $mat['material_id'] ?>" 
                                            data-unit="<?= htmlspecialchars($mat['unit']) ?>"
                                            data-stock="<?= $mat['current_stock'] ?>"
                                            data-min="<?= $mat['min_stock'] ?>">
                                        <?= htmlspecialchars($mat['part_code']) ?> - <?= htmlspecialchars($mat['material_name']) ?>
                                        (คงเหลือ: <?= number_format($mat['current_stock']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="material-info" class="mt-2" style="display: none;">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i>
                                    คงเหลือ: <strong id="info-stock">0</strong> | 
                                    ต่ำสุด: <strong id="info-min">0</strong> |
                                    หน่วย: <strong id="info-unit">-</strong>
                                </small>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-hashtag me-1"></i>จำนวนที่ต้องการ <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control" id="pr_quantity" name="quantity_requested" required min="1" step="1">
                            <div class="form-text">
                                <i class="fas fa-lightbulb"></i> ระบบจะแนะนำจำนวนที่ควรสั่ง
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-flag me-1"></i>ความเร่งด่วน <span class="text-danger">*</span>
                            </label>
                            <select class="form-control" name="urgency" required>
                                <option value="low">ปกติ</option>
                                <option value="medium" selected>ค่อนข้างเร่ง</option>
                                <option value="high">เร่งด่วน</option>
                                <option value="urgent">เร่งด่วนมาก</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">
                                <i class="fas fa-calendar me-1"></i>วันที่ต้องการ <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" name="expected_date" id="expected_date" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-sticky-note me-1"></i>หมายเหตุ / เหตุผลในการสั่งซื้อ
                        </label>
                        <textarea class="form-control" name="notes" rows="4" placeholder="ระบุเหตุผลหรือรายละเอียดเพิ่มเติม เช่น สำหรับงาน Job XXX, สต็อกหมด, เตรียมสำรอง"></textarea>
                    </div>

                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>ข้อมูลสำคัญ</h6>
                        <ul class="mb-0">
                            <li>คำขอจะถูกส่งไปยังแผนกวางแผนเพื่อพิจารณา</li>
                            <li>แผนกวางแผนจะตรวจสอบและดำเนินการสั่งซื้อ</li>
                            <li>คุณจะได้รับการแจ้งเตือนเมื่อมีการเปลี่ยนแปลงสถานะ</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>ยกเลิก
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i>ส่งคำขอ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View PR Details Modal -->
<div class="modal fade" id="viewPRModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-file-alt me-2"></i>รายละเอียดคำขอสั่งซื้อ
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="prDetails">
                    <!-- Details will be loaded here -->
                </div>
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
// Set default expected date (7 days from now)
document.addEventListener('DOMContentLoaded', function() {
    const expectedDate = new Date();
    expectedDate.setDate(expectedDate.getDate() + 7);
    document.getElementById('expected_date').value = expectedDate.toISOString().split('T')[0];
});

// Quick create PR from material list
function createPR(materialId, partCode, materialName, suggestedQty, unit) {
    // Set values in modal
    document.getElementById('pr_material_id').value = materialId;
    document.getElementById('pr_quantity').value = Math.ceil(suggestedQty);
    
    // Trigger change event to update info
    updateMaterialInfo();
    
    // Show modal
    new bootstrap.Modal(document.getElementById('createPRModal')).show();
}

// Update material info when selection changes
function updateMaterialInfo() {
    const select = document.getElementById('pr_material_id');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.value) {
        const stock = selectedOption.dataset.stock;
        const min = selectedOption.dataset.min;
        const unit = selectedOption.dataset.unit;
        
        document.getElementById('info-stock').textContent = parseInt(stock).toLocaleString();
        document.getElementById('info-min').textContent = parseInt(min).toLocaleString();
        document.getElementById('info-unit').textContent = unit;
        document.getElementById('material-info').style.display = 'block';
        
        // Suggest quantity if stock is below minimum
        if (parseInt(stock) < parseInt(min)) {
            const suggested = parseInt(min) - parseInt(stock);
            document.getElementById('pr_quantity').value = suggested;
        }
    } else {
        document.getElementById('material-info').style.display = 'none';
    }
}

// Submit create PR form
document.getElementById('createPRForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'create');
    
    Swal.fire({
        title: 'ยืนยันการส่งคำขอ?',
        text: 'คำขอจะถูกส่งไปยังแผนกวางแผนเพื่อพิจารณา',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'ยืนยัน',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            submitPR(formData);
        }
    });
});

function submitPR(formData) {
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
                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                        <h5>สร้างคำขอสั่งซื้อเรียบร้อย</h5>
                        <p><strong>เลขที่:</strong> ${data.pr_number}</p>
                        <p class="text-muted">คำขอถูกส่งไปยังแผนกวางแผนแล้ว</p>
                    </div>
                `,
                icon: 'success',
                confirmButtonText: 'ตกลง'
            }).then(() => {
                bootstrap.Modal.getInstance(document.getElementById('createPRModal')).hide();
                location.reload();
            });
        } else {
            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถส่งคำขอได้', 'error');
    });
}

// View PR details
function viewPR(prId) {
    fetch(`../../api/purchase-requests.php?action=get&id=${prId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayPRDetails(data.data);
                new bootstrap.Modal(document.getElementById('viewPRModal')).show();
            } else {
                Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
        });
}

function displayPRDetails(pr) {
    const statusConfig = {
        'pending': { class: 'warning', icon: 'clock', text: 'รอพิจารณา' },
        'approved': { class: 'success', icon: 'check-circle', text: 'อนุมัติ' },
        'rejected': { class: 'danger', icon: 'times-circle', text: 'ปฏิเสธ' },
        'ordered': { class: 'info', icon: 'truck', text: 'สั่งซื้อแล้ว' },
        'received': { class: 'secondary', icon: 'check', text: 'รับแล้ว' }
    };
    
    const status = statusConfig[pr.status] || statusConfig['pending'];
    
    const urgencyConfig = {
        'low': 'ปกติ',
        'medium': 'ค่อนข้างเร่ง',
        'high': 'เร่งด่วน',
        'urgent': 'เร่งด่วนมาก'
    };
    
    let html = `
        <div class="row mb-4">
            <div class="col-md-6">
                <h5 class="mb-3">ข้อมูลคำขอ</h5>
                <table class="table table-borderless">
                    <tr>
                        <td><strong>เลขที่:</strong></td>
                        <td>${pr.pr_number}</td>
                    </tr>
                    <tr>
                        <td><strong>สถานะ:</strong></td>
                        <td>
                            <span class="status-badge bg-${status.class}">
                                <i class="fas fa-${status.icon}"></i> ${status.text}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>ความเร่งด่วน:</strong></td>
                        <td><span class="badge bg-warning">${urgencyConfig[pr.urgency]}</span></td>
                    </tr>
                    <tr>
                        <td><strong>วันที่สร้าง:</strong></td>
                        <td>${new Date(pr.created_at).toLocaleDateString('th-TH', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        })}</td>
                    </tr>
                    <tr>
                        <td><strong>วันที่ต้องการ:</strong></td>
                        <td>${new Date(pr.expected_date).toLocaleDateString('th-TH', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        })}</td>
                    </tr>
                </table>
            </div>
            <div class="col-md-6">
                <h5 class="mb-3">ข้อมูลวัสดุ</h5>
                <table class="table table-borderless">
                    <tr>
                        <td><strong>รหัส:</strong></td>
                        <td>${pr.part_code}</td>
                    </tr>
                    <tr>
                        <td><strong>ชื่อ:</strong></td>
                        <td>${pr.material_name}</td>
                    </tr>
                    <tr>
                        <td><strong>จำนวนที่ขอ:</strong></td>
                        <td><span class="badge bg-primary fs-6">${pr.quantity_requested.toLocaleString()} ${pr.unit}</span></td>
                    </tr>
                    <tr>
                        <td><strong>สต็อกปัจจุบัน:</strong></td>
                        <td>${pr.current_stock.toLocaleString()} ${pr.unit}</td>
                    </tr>
                </table>
            </div>
        </div>
    `;
    
    if (pr.notes) {
        html += `
            <div class="mb-4">
                <h6><i class="fas fa-sticky-note me-2"></i>หมายเหตุ</h6>
                <div class="alert alert-light">${pr.notes.replace(/\n/g, '<br>')}</div>
            </div>
        `;
    }
    
    // Timeline
    html += `
        <div class="mb-3">
            <h6><i class="fas fa-history me-2"></i>ประวัติการดำเนินการ</h6>
            <div class="timeline">
    `;
    
    // Created
    html += `
        <div class="timeline-item">
            <div class="timeline-marker bg-primary"></div>
            <div class="timeline-content">
                <h6>สร้างคำขอ</h6>
                <p class="mb-0">
                    โดย: ${pr.created_by_name}<br>
                    วันที่: ${new Date(pr.created_at).toLocaleDateString('th-TH', {
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    })}
                </p>
            </div>
        </div>
    `;
    
    // Approved/Rejected
    if (pr.approved_at) {
        html += `
            <div class="timeline-item">
                <div class="timeline-marker bg-${status.class}"></div>
                <div class="timeline-content">
                    <h6>${status.text}</h6>
                    <p class="mb-0">
                        โดย: ${pr.approved_by_name || '-'}<br>
                        วันที่: ${new Date(pr.approved_at).toLocaleDateString('th-TH', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        })}
                    </p>
                </div>
            </div>
        `;
    }
    
    html += `
            </div>
        </div>
    `;
    
    document.getElementById('prDetails').innerHTML = html;
}

// Cancel PR
function cancelPR(prId) {
    Swal.fire({
        title: 'ยืนยันการยกเลิก?',
        text: 'คุณต้องการยกเลิกคำขอสั่งซื้อนี้หรือไม่?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'ยกเลิกคำขอ',
        cancelButtonText: 'ไม่ยกเลิก',
        input: 'textarea',
        inputPlaceholder: 'ระบุเหตุผล (ไม่บังคับ)'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('action', 'cancel');
            formData.append('pr_id', prId);
            formData.append('reason', result.value || '');
            
            fetch('../../api/purchase-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', 'ยกเลิกคำขอเรียบร้อย', 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถยกเลิกคำขอได้', 'error');
            });
        }
    });
}

// Toggle Sidebar for Mobile
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    sidebar.classList.toggle('show');
}
</script>

</body>
</html>