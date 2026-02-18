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

$available_jobs = $db->prepare("
    SELECT pj.*, p.product_name
    FROM production_jobs pj
    LEFT JOIN products p ON pj.product_id = p.product_id
    WHERE (pj.assigned_to = ? OR pj.assigned_to IS NULL) AND pj.status IN ('pending', 'in_progress')
    ORDER BY pj.created_at DESC
");
$available_jobs->execute([$user_id]);
$jobs = $available_jobs->fetchAll();
?>

<!-- Filter Tabs -->
<div class="mb-4">
    <div class="btn-group" role="group">
        <a href="?filter=all" class="btn btn-outline-primary <?= $filter === 'all' ? 'active' : '' ?>">
            <i class="fas fa-list me-1"></i>ทั้งหมด <span class="badge bg-secondary ms-1"><?= $status_counts['all'] ?></span>
        </a>
        <a href="?filter=pending" class="btn btn-outline-primary <?= $filter === 'pending' ? 'active' : '' ?>">
            <i class="fas fa-clock me-1"></i>รอพิจารณา <span class="badge bg-warning ms-1"><?= $status_counts['pending'] ?></span>
        </a>
        <a href="?filter=approved" class="btn btn-outline-primary <?= $filter === 'approved' ? 'active' : '' ?>">
            <i class="fas fa-check me-1"></i>อนุมัติแล้ว <span class="badge bg-success ms-1"><?= $status_counts['approved'] ?></span>
        </a>
        <a href="?filter=fulfilled" class="btn btn-outline-primary <?= $filter === 'fulfilled' ? 'active' : '' ?>">
            <i class="fas fa-box me-1"></i>จ่ายแล้ว <span class="badge bg-info ms-1"><?= $status_counts['fulfilled'] ?></span>
        </a>
        <a href="?filter=rejected" class="btn btn-outline-primary <?= $filter === 'rejected' ? 'active' : '' ?>">
            <i class="fas fa-times me-1"></i>ปฏิเสธ <span class="badge bg-danger ms-1"><?= $status_counts['rejected'] ?></span>
        </a>
    </div>
</div>

<!-- Search and Action -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="text" class="form-control" id="searchInput" 
                   placeholder="ค้นหาเลขที่คำขอ หรือ Job..." 
                   value="<?= htmlspecialchars($search) ?>">
        </div>
    </div>
    <div class="col-md-4 text-end">
        <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#createRequestModal">
            <i class="fas fa-plus me-1"></i>สร้างคำขอใหม่
        </button>
        <button class="btn btn-outline-primary" onclick="refreshPage()">
            <i class="fas fa-sync-alt me-1"></i>รีเฟรช
        </button>
    </div>
</div>

<!-- Requests Table -->
<?php if (!empty($requests)): ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>เลขที่คำขอ</th>
                        <th>Job / สินค้า</th>
                        <th>รายการวัสดุ</th>
                        <th>วันที่ขอ</th>
                        <th>สถานะ</th>
                        <th>ผู้อนุมัติ</th>
                        <th>การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
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
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($request['request_number']) ?></strong></td>
                            <td>
                                <strong><?= htmlspecialchars($request['job_number']) ?></strong><br>
                                <small class="text-muted"><?= htmlspecialchars($request['product_name']) ?></small>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?= $request['item_count'] ?> รายการ</span>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($request['request_date'])) ?></td>
                            <td>
                                <span class="badge bg-<?= $status_colors[$request['status']] ?>">
                                    <?= $status_texts[$request['status']] ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($request['approved_by_name']): ?>
                                    <?= htmlspecialchars($request['approved_by_name']) ?><br>
                                    <small class="text-muted"><?= date('d/m/Y', strtotime($request['approved_date'])) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-outline-primary btn-sm" onclick="viewRequestDetails(<?= $request['request_id'] ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($request['status'] === 'fulfilled'): ?>
                                    <button class="btn btn-outline-success btn-sm" onclick="printRequest(<?= $request['request_id'] ?>)">
                                        <i class="fas fa-print"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-body text-center py-5">
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
                                            <th width="12%">รหัสวัสดุ</th>
                                            <th width="20%">ชื่อวัสดุ</th>
                                            <th width="10%">หน่วย</th>
                                            <th width="13%">จำนวนที่ต้องการ</th>
                                            <th width="15%">สต็อกคงเหลือ</th>
                                            <th width="10%">การ์ด</th>
                                            <th width="15%">สถานะ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="materials-tbody">
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">
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

        function refreshPage() {
            location.reload();
        }

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

            // reset selectedMaterials before populating
            selectedMaterials = [];

            materials.forEach(material => {
                const stockStatus = getStockStatus(material.current_stock, material.required_quantity);
                const cardColor = material.card_color || '#3498db';
                const quantityPerCard = material.quantity_per_card || material.quantity_per_unit || 1; // จำนวนต่อการ์ด (fallback to per-unit)
                const quantityPerUnit = material.quantity_per_unit || 1;
                const totalCards = Math.ceil((material.required_quantity || 0) / quantityPerCard); // คำนวณจำนวนการ์ด
                
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
                               value="${material.required_quantity}" readonly tabindex="-1" onkeydown="return false;" onwheel="this.blur();"
                               data-material-id="${material.material_id}">
                    </td>
                    <td>
                        ${material.current_stock.toLocaleString()}
                        ${material.current_stock <= material.min_stock ? '<i class="fas fa-exclamation-triangle text-warning ms-1"></i>' : ''}
                    </td>
                    <td>
                        <div style="display: inline-block; width: 45px; height: 45px; background-color: ${cardColor}; border: 2px solid #ddd; border-radius: 6px; display: flex; flex-direction: column; align-items: center; justify-content: center;" title="จำนวน/การ์ด: ${quantityPerCard}">
                            <span style="color: white; font-weight: bold; text-shadow: 0 1px 1px rgba(0,0,0,0.3); font-size: 14px;">${totalCards}</span>
                            <span style="color: white; font-weight: 500; text-shadow: 0 1px 1px rgba(0,0,0,0.3); font-size: 8px;">ใบ</span>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-${stockStatus.class}">${stockStatus.text}</span>
                    </td>
                `;
                tbody.appendChild(row);

                // Add to selected materials
                selectedMaterials.push({
                    material_id: material.material_id,
                    quantity: material.required_quantity,
                    card_color: cardColor,
                    quantity_per_unit: quantityPerUnit,
                    total_cards: totalCards
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
                    const quantity = parseFloat(quantityInput.value);
                    const quantityPerUnit = material.quantity_per_unit || 1;
                    const totalCards = Math.ceil(quantity / quantityPerUnit);
                    
                    if (this.checked) {
                        selectedMaterials.push({
                            material_id: material.material_id,
                            quantity: quantity,
                            card_color: material.card_color,
                            quantity_per_unit: quantityPerUnit,
                            total_cards: totalCards
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
                        material.total_cards = Math.ceil(material.quantity / material.quantity_per_unit);
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
            fetch(`../../api/material-requests.php?action=get&id=${requestId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const request = data.data;
                        const details = data.data.details || [];
                        
                        const statusColors = {
                            'pending': 'warning',
                            'approved': 'success',
                            'rejected': 'danger',
                            'fulfilled': 'info'
                        };
                        const statusTexts = {
                            'pending': 'รอพิจารณา',
                            'approved': 'อนุมัติแล้ว',
                            'rejected': 'ปฏิเสธ',
                            'fulfilled': 'จ่ายแล้ว'
                        };

                        let html = `
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="mb-1">
                                        <strong>เลขที่คำขอ:</strong> ${request.request_number}
                                    </h6>
                                    <p class="mb-0 text-muted">
                                        <i class="fas fa-calendar me-1"></i>
                                        ${new Date(request.request_date).toLocaleDateString('th-TH')}
                                    </p>
                                </div>
                                <div class="col-md-6 text-end">
                                    <span class="badge bg-${statusColors[request.status]}">
                                        ${statusTexts[request.status]}
                                    </span>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Job:</strong> ${request.job_number}
                                </div>
                                <div class="col-md-6">
                                    <strong>สินค้า:</strong> ${request.product_name}
                                </div>
                            </div>
                            
                            ${request.approved_by_name ? `
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <strong>${request.status === 'approved' || request.status === 'fulfilled' ? 'อนุมัติโดย' : 'ปฏิเสธโดย'}:</strong> ${request.approved_by_name}
                                    </div>
                                    <div class="col-md-6">
                                        <strong>วันที่:</strong> ${new Date(request.approved_date).toLocaleDateString('th-TH')}
                                    </div>
                                </div>
                            ` : ''}
                            
                            <div class="mb-3">
                                <h6><i class="fas fa-list me-2"></i>รายการวัสดุ (${details.length} รายการ)</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead class="table-light">
                                            <tr>
                                                    <th>รหัสวัสดุ</th>
                                                    <th>ชื่อวัสดุ</th>
                                                    <th>หน่วย</th>
                                                    <th class="text-end">จำนวน</th>
                                                    <th class="text-center">การ์ด</th>
                                                    <th class="text-end">สถานะสต็อก</th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                            ${details.map(detail => {
                                                const qtyReq = Number(detail.quantity_requested || 0);
                                                const qtyPerCard = Number(detail.quantity_per_card || detail.quantity_per_unit || 1);
                                                const totalCards = Math.ceil(qtyReq / Math.max(1, qtyPerCard));
                                                const cardColor = detail.card_color || '#3498db';
                                                return `
                                                <tr>
                                                    <td>${detail.part_code}</td>
                                                    <td>${detail.material_name}</td>
                                                    <td>${detail.unit}</td>
                                                    <td class="text-end">${qtyReq.toLocaleString()}</td>
                                                    <td class="text-center">
                                                        <div style="display:inline-block; width: 45px; height: 45px; background-color: ${cardColor}; border: 2px solid #ddd; border-radius: 6px; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                                                            <span style="color: white; font-weight: bold; text-shadow: 0 1px 1px rgba(0,0,0,0.3); font-size:14px;">${totalCards}</span>
                                                            <span style="color: white; font-weight:500; font-size:8px;">ใบ</span>
                                                        </div>
                                                    </td>
                                                    <td class="text-end">
                                                        <small class="badge bg-${detail.current_stock >= detail.quantity_requested ? 'success' : 'warning'}">
                                                            ${detail.current_stock} / ${detail.quantity_requested}
                                                        </small>
                                                    </td>
                                                </tr>
                                            `}).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            
                            ${request.notes ? `
                                <div class="mb-3 p-3 bg-light rounded">
                                    <strong>หมายเหตุ:</strong><br>
                                    <small>${request.notes.replace(/\n/g, '<br>')}</small>
                                </div>
                            ` : ''}
                        `;
                        
                        document.getElementById('requestDetailsContent').innerHTML = html;
                        new bootstrap.Modal(document.getElementById('requestDetailsModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message || 'ไม่สามารถโหลดข้อมูลได้', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
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