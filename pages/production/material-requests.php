<?php
// pages/production/material-requests.php
$page_title = 'คำขอเบิกวัสดุ';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'คำขอเบิกวัสดุ']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['production', 'admin']);

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// ดึงข้อมูลคำขอเบิกวัสดุทั้งหมดของผู้ใช้
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

$where_conditions = ["mr.requested_by = ?"];
$params = [$user_id];

if ($filter !== 'all') {
    $where_conditions[] = "mr.status = ?";
    $params[] = $filter;
}

if (!empty($search)) {
    $where_conditions[] = "(mr.request_number LIKE ? OR pj.job_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

$requests_query = "
    SELECT 
        mr.*,
        pj.job_number,
        pj.product_id,
        p.product_name,
        u1.full_name as requested_by_name,
        u2.full_name as approved_by_name,
        COUNT(mrd.request_detail_id) as item_count
    FROM material_requests mr
    LEFT JOIN production_jobs pj ON mr.job_id = pj.job_id
    LEFT JOIN products p ON pj.product_id = p.product_id
    LEFT JOIN users u1 ON mr.requested_by = u1.user_id
    LEFT JOIN users u2 ON mr.approved_by = u2.user_id
    LEFT JOIN material_request_details mrd ON mr.request_id = mrd.request_id
    WHERE $where_clause
    GROUP BY mr.request_id
    ORDER BY mr.request_date DESC
";

$stmt = $db->prepare($requests_query);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// นับจำนวนตามสถานะ
$status_counts = [
    'all' => count($requests),
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'fulfilled' => 0
];

foreach ($requests as $request) {
    $status_counts[$request['status']]++;
}

// ดึงงานที่สามารถเบิกวัสดุได้
$available_jobs = $db->prepare("
    SELECT pj.*, p.product_name
    FROM production_jobs pj
    LEFT JOIN products p ON pj.product_id = p.product_id
    WHERE pj.assigned_to = ? AND pj.status IN ('pending', 'in_progress')
    ORDER BY pj.created_at DESC
");
$available_jobs->execute([$user_id]);
$jobs = $available_jobs->fetchAll();
?>

<style>
    .request-card {
        border: none;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        margin-bottom: 20px;
        border-left: 5px solid;
    }
    
    .request-card.status-pending { border-left-color: #ffc107; }
    .request-card.status-approved { border-left-color: #28a745; }
    .request-card.status-rejected { border-left-color: #dc3545; }
    .request-card.status-fulfilled { border-left-color: #17a2b8; }
    
    .request-card:hover {
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        transform: translateY(-2px);
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
</style>

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4><i class="fas fa-hand-paper me-2"></i>คำขอเบิกวัสดุ</h4>
                    <p class="text-muted mb-0">จัดการคำขอเบิกวัสดุสำหรับการผลิต</p>
                </div>
                <button class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#createRequestModal">
                    <i class="fas fa-plus me-2"></i>สร้างคำขอใหม่
                </button>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
                    ทั้งหมด (<?= $status_counts['all'] ?>)
                </a>
                <a href="?filter=pending" class="filter-tab <?= $filter === 'pending' ? 'active' : '' ?>">
                    <i class="fas fa-clock"></i> รอพิจารณา (<?= $status_counts['pending'] ?>)
                </a>
                <a href="?filter=approved" class="filter-tab <?= $filter === 'approved' ? 'active' : '' ?>">
                    <i class="fas fa-check"></i> อนุมัติแล้ว (<?= $status_counts['approved'] ?>)
                </a>
                <a href="?filter=fulfilled" class="filter-tab <?= $filter === 'fulfilled' ? 'active' : '' ?>">
                    <i class="fas fa-box"></i> จ่ายแล้ว (<?= $status_counts['fulfilled'] ?>)
                </a>
                <a href="?filter=rejected" class="filter-tab <?= $filter === 'rejected' ? 'active' : '' ?>">
                    <i class="fas fa-times"></i> ปฏิเสธ (<?= $status_counts['rejected'] ?>)
                </a>
            </div>

            <!-- Search -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="searchInput" placeholder="ค้นหาเลขที่คำขอ หรือ Job..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
            </div>

            <!-- Requests List -->
            <div class="row">
                <?php if (!empty($requests)): ?>
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $status_colors = [
                            'pending' => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger',
                            'fulfilled' => 'info'
                        ];
                        
                        $status_texts = [
                            'pending' => 'รอพิจารณา',
                            'approved' => 'อนุมัติแล้ว',
                            'rejected' => 'ปฏิเสธ',
                            'fulfilled' => 'จ่ายแล้ว'
                        ];
                        
                        $status_icons = [
                            'pending' => 'clock',
                            'approved' => 'check-circle',
                            'rejected' => 'times-circle',
                            'fulfilled' => 'box'
                        ];
                        ?>
                        
                        <div class="col-lg-6 col-xl-4">
                            <div class="request-card status-<?= $request['status'] ?>">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start mb-3">
                                        <div>
                                            <h6 class="mb-1">
                                                <strong><?= htmlspecialchars($request['request_number']) ?></strong>
                                            </h6>
                                            <small class="text-muted">
                                                <i class="fas fa-calendar me-1"></i>
                                                <?= date('d/m/Y H:i', strtotime($request['request_date'])) ?>
                                            </small>
                                        </div>
                                        <span class="badge bg-<?= $status_colors[$request['status']] ?>">
                                            <i class="fas fa-<?= $status_icons[$request['status']] ?> me-1"></i>
                                            <?= $status_texts[$request['status']] ?>
                                        </span>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-briefcase me-2 text-primary"></i>
                                            <strong>Job:</strong>&nbsp;
                                            <span class="badge bg-info"><?= htmlspecialchars($request['job_number']) ?></span>
                                        </div>
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-box me-2 text-success"></i>
                                            <strong>สินค้า:</strong>&nbsp;
                                            <?= htmlspecialchars($request['product_name']) ?>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-list me-2 text-warning"></i>
                                            <strong>รายการวัสดุ:</strong>&nbsp;
                                            <?= $request['item_count'] ?> รายการ
                                        </div>
                                    </div>
                                    
                                    <?php if ($request['approved_by_name']): ?>
                                        <div class="mb-3 p-2 bg-light rounded">
                                            <small class="text-muted">
                                                <i class="fas fa-user-check me-1"></i>
                                                <?= $request['status'] === 'approved' || $request['status'] === 'fulfilled' ? 'อนุมัติโดย' : 'ปฏิเสธโดย' ?>:
                                                <strong><?= htmlspecialchars($request['approved_by_name']) ?></strong>
                                                <br>
                                                <i class="fas fa-clock me-1"></i>
                                                <?= date('d/m/Y H:i', strtotime($request['approved_date'])) ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($request['notes']): ?>
                                        <div class="mb-3">
                                            <small class="text-muted">
                                                <i class="fas fa-sticky-note me-1"></i>
                                                <?= htmlspecialchars(mb_substr($request['notes'], 0, 80)) ?>
                                                <?= mb_strlen($request['notes']) > 80 ? '...' : '' ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-outline-primary btn-sm flex-fill" onclick="viewRequestDetails(<?= $request['request_id'] ?>)">
                                            <i class="fas fa-eye"></i> ดูรายละเอียด
                                        </button>
                                        <?php if ($request['status'] === 'fulfilled'): ?>
                                            <button class="btn btn-outline-success btn-sm" onclick="printRequest(<?= $request['request_id'] ?>)">
                                                <i class="fas fa-print"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="text-center py-5">
                            <i class="fas fa-inbox fa-4x text-muted mb-4"></i>
                            <h4 class="text-muted">ไม่พบคำขอเบิกวัสดุ</h4>
                            <?php if ($filter === 'all'): ?>
                                <p class="text-muted mb-4">คุณยังไม่มีคำขอเบิกวัสดุ</p>
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createRequestModal">
                                    <i class="fas fa-plus me-2"></i>สร้างคำขอใหม่
                                </button>
                            <?php else: ?>
                                <p class="text-muted">ไม่มีคำขอในสถานะ "<?= $status_texts[$filter] ?? $filter ?>"</p>
                                <a href="?" class="btn btn-primary">ดูคำขอทั้งหมด</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Create Request Modal -->
    <div class="modal fade" id="createRequestModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus-circle me-2"></i>สร้างคำขอเบิกวัสดุใหม่
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="createRequestForm">
                    <div class="modal-body">
                        <!-- Select Job -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <label class="form-label">เลือกงานการผลิต <span class="text-danger">*</span></label>
                                <select class="form-control form-control-lg" id="select_job" name="job_id" required>
                                    <option value="">-- เลือกงาน --</option>
                                    <?php foreach ($jobs as $job): ?>
                                        <option value="<?= $job['job_id'] ?>" 
                                                data-product-id="<?= $job['product_id'] ?>"
                                                data-job-number="<?= htmlspecialchars($job['job_number']) ?>"
                                                data-product-name="<?= htmlspecialchars($job['product_name']) ?>"
                                                data-quantity="<?= $job['quantity_planned'] ?>">
                                            <?= htmlspecialchars($job['job_number']) ?> - 
                                            <?= htmlspecialchars($job['product_name']) ?> 
                                            (<?= number_format($job['quantity_planned']) ?> ชิ้น)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Job Info Display -->
                        <div id="job-info" class="alert alert-info" style="display: none;">
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Job:</strong> <span id="display-job-number"></span><br>
                                    <strong>สินค้า:</strong> <span id="display-product-name"></span>
                                </div>
                                <div class="col-md-6">
                                    <strong>จำนวนที่ผลิต:</strong> <span id="display-quantity"></span> ชิ้น
                                </div>
                            </div>
                        </div>

                        <!-- BOM Materials -->
                        <div id="bom-materials-section" style="display: none;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6><i class="fas fa-list me-2"></i>วัสดุที่ต้องใช้ (จาก BOM)</h6>
                                <button type="button" class="btn btn-sm btn-success" onclick="loadBOMMaterials()">
                                    <i class="fas fa-sync me-1"></i>โหลดวัสดุจาก BOM
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover" id="materials-table">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="5%">
                                                <input type="checkbox" id="select-all-materials" class="form-check-input">
                                            </th>
                                            <th width="15%">รหัสวัสดุ</th>
                                            <th width="25%">ชื่อวัสดุ</th>
                                            <th width="10%">หน่วย</th>
                                            <th width="15%">จำนวนที่ต้องการ</th>
                                            <th width="15%">สต็อกคงเหลือ</th>
                                            <th width="15%">สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="materials-tbody">
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">
                                                <i class="fas fa-info-circle me-2"></i>
                                                กรุณาเลือกงานและกดปุ่ม "โหลดวัสดุจาก BOM"
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Additional Materials -->
                        <div class="mt-4">
                            <h6><i class="fas fa-plus-circle me-2"></i>เพิ่มวัสดุอื่นๆ (ถ้ามี)</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <input type="text" class="form-control" id="search-material" placeholder="ค้นหาวัสดุเพิ่มเติม...">
                                </div>
                                <div class="col-md-6">
                                    <div id="material-search-results"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="mt-4">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="ระบุข้อมูลเพิ่มเติม หรือความต้องการพิเศษ (ถ้ามี)"></textarea>
                        </div>

                        <!-- Summary -->
                        <div id="request-summary" class="mt-4 p-3 bg-light rounded" style="display: none;">
                            <h6><i class="fas fa-clipboard-check me-2"></i>สรุปคำขอ</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>รายการวัสดุที่เลือก:</strong> <span id="summary-items">0</span> รายการ
                                </div>
                                <div class="col-md-6">
                                    <strong>สถานะสต็อก:</strong> 
                                    <span id="summary-stock-status"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>ยกเลิก
                        </button>
                        <button type="submit" class="btn btn-primary" id="submit-request-btn" disabled>
                            <i class="fas fa-paper-plane me-1"></i>ส่งคำขอเบิกวัสดุ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Request Details Modal -->
    <div class="modal fade" id="requestDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">รายละเอียดคำขอเบิกวัสดุ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="requestDetailsContent">
                        <!-- Details will be loaded here -->
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
        let selectedMaterials = [];
        let currentJobQuantity = 0;

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

        // Job selection handler
        document.getElementById('select_job').addEventListener('change', function() {
            const selected = this.selectedOptions[0];
            if (selected.value) {
                document.getElementById('display-job-number').textContent = selected.dataset.jobNumber;
                document.getElementById('display-product-name').textContent = selected.dataset.productName;
                document.getElementById('display-quantity').textContent = Number(selected.dataset.quantity).toLocaleString();
                currentJobQuantity = Number(selected.dataset.quantity);
                
                document.getElementById('job-info').style.display = 'block';
                document.getElementById('bom-materials-section').style.display = 'block';
                
                // Auto load BOM materials
                loadBOMMaterials();
            } else {
                document.getElementById('job-info').style.display = 'none';
                document.getElementById('bom-materials-section').style.display = 'none';
                selectedMaterials = [];
                updateSummary();
            }
        });

        // Load BOM Materials
        function loadBOMMaterials() {
            const jobSelect = document.getElementById('select_job');
            const productId = jobSelect.selectedOptions[0]?.dataset.productId;
            
            if (!productId) {
                Swal.fire('กรุณาเลือกงานก่อน', '', 'warning');
                return;
            }

            const tbody = document.getElementById('materials-tbody');
            tbody.innerHTML = '<tr><td colspan="7" class="text-center"><i class="fas fa-spinner fa-spin me-2"></i>กำลังโหลดข้อมูล...</td></tr>';

            fetch(`../../api/bom.php?action=get_materials&product_id=${productId}&quantity=${currentJobQuantity}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.materials.length > 0) {
                        displayBOMMaterials(data.materials);
                    } else {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="7" class="text-center text-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    ไม่พบข้อมูล BOM สำหรับสินค้านี้
                                </td>
                            </tr>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error loading BOM:', error);
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="7" class="text-center text-danger">
                                <i class="fas fa-times-circle me-2"></i>
                                เกิดข้อผิดพลาดในการโหลดข้อมูล
                            </td>
                        </tr>
                    `;
                });
        }

        function displayBOMMaterials(materials) {
            const tbody = document.getElementById('materials-tbody');
            tbody.innerHTML = '';

            materials.forEach(material => {
                const stockStatus = getStockStatus(material.current_stock, material.required_quantity);
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>
                        <input type="checkbox" class="form-check-input material-checkbox" 
                               data-material='${JSON.stringify(material)}' checked>
                    </td>
                    <td><strong>${material.part_code}</strong></td>
                    <td>${material.material_name}</td>
                    <td>${material.unit}</td>
                    <td>
                        <input type="number" class="form-control form-control-sm quantity-input" 
                               value="${material.required_quantity}" min="1" step="0.01"
                               data-material-id="${material.material_id}">
                    </td>
                    <td>
                        ${material.current_stock.toLocaleString()}
                        ${material.current_stock <= material.min_stock ? '<i class="fas fa-exclamation-triangle text-warning ms-1"></i>' : ''}
                    </td>
                    <td>
                        <span class="badge bg-${stockStatus.class}">${stockStatus.text}</span>
                    </td>
                `;
                tbody.appendChild(row);

                // Add to selected materials
                selectedMaterials.push({
                    material_id: material.material_id,
                    quantity: material.required_quantity
                });
            });

            setupMaterialCheckboxes();
            updateSummary();
        }

        function getStockStatus(currentStock, requiredQty) {
            if (currentStock >= requiredQty) {
                return { class: 'success', text: 'เพียงพอ' };
            } else if (currentStock > 0) {
                return { class: 'warning', text: 'ไม่เพียงพอ' };
            } else {
                return { class: 'danger', text: 'หมด' };
            }
        }

        function setupMaterialCheckboxes() {
            document.querySelectorAll('.material-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const material = JSON.parse(this.dataset.material);
                    const quantityInput = this.closest('tr').querySelector('.quantity-input');
                    
                    if (this.checked) {
                        selectedMaterials.push({
                            material_id: material.material_id,
                            quantity: parseFloat(quantityInput.value)
                        });
                    } else {
                        selectedMaterials = selectedMaterials.filter(m => m.material_id !== material.material_id);
                    }
                    updateSummary();
                });
            });

            document.querySelectorAll('.quantity-input').forEach(input => {
                input.addEventListener('change', function() {
                    const materialId = parseInt(this.dataset.materialId);
                    const material = selectedMaterials.find(m => m.material_id === materialId);
                    if (material) {
                        material.quantity = parseFloat(this.value);
                    }
                    updateSummary();
                });
            });
        }

        // Select all materials
        document.getElementById('select-all-materials').addEventListener('change', function() {
            document.querySelectorAll('.material-checkbox').forEach(checkbox => {
                checkbox.checked = this.checked;
                checkbox.dispatchEvent(new Event('change'));
            });
        });

        function updateSummary() {
            const count = selectedMaterials.length;
            document.getElementById('summary-items').textContent = count;
            
            if (count > 0) {
                document.getElementById('request-summary').style.display = 'block';
                document.getElementById('submit-request-btn').disabled = false;
                
                // Check stock status
                const insufficientCount = document.querySelectorAll('.badge.bg-warning, .badge.bg-danger').length;
                const statusHtml = insufficientCount > 0 
                    ? `<span class="text-warning"><i class="fas fa-exclamation-triangle me-1"></i>${insufficientCount} รายการสต็อกไม่เพียงพอ</span>`
                    : `<span class="text-success"><i class="fas fa-check-circle me-1"></i>สต็อกเพียงพอทั้งหมด</span>`;
                document.getElementById('summary-stock-status').innerHTML = statusHtml;
            } else {
                document.getElementById('request-summary').style.display = 'none';
                document.getElementById('submit-request-btn').disabled = true;
            }
        }

        // Submit create request form
        document.getElementById('createRequestForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const jobId = document.getElementById('select_job').value;
            const notes = this.querySelector('[name="notes"]').value;
            
            if (selectedMaterials.length === 0) {
                Swal.fire('กรุณาเลือกวัสดุอย่างน้อย 1 รายการ', '', 'warning');
                return;
            }

            Swal.fire({
                title: 'ยืนยันการสร้างคำขอ?',
                html: `
                    <strong>Job:</strong> ${document.getElementById('display-job-number').textContent}<br>
                    <strong>จำนวนวัสดุ:</strong> ${selectedMaterials.length} รายการ
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitRequest(jobId, notes);
                }
            });
        });

        function submitRequest(jobId, notes) {
            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('job_id', jobId);
            formData.append('materials', JSON.stringify(selectedMaterials));
            formData.append('notes', notes);

            fetch('../../api/material-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', 'สร้างคำขอเบิกวัสดุเรียบร้อยแล้ว', 'success').then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('createRequestModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถสร้างคำขอได้', 'error');
            });
        }

        // View request details
        function viewRequestDetails(requestId) {
            fetch(`../../api/get_request_detail.php?request_id=${requestId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('requestDetailsContent').innerHTML = html;
                    new bootstrap.Modal(document.getElementById('requestDetailsModal')).show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดรายละเอียดได้', 'error');
                });
        }

        // Print request
        function printRequest(requestId) {
            window.open(`../../reports/print-material-request.php?request_id=${requestId}`, '_blank');
        }

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }
    </script>

</body>
</html>