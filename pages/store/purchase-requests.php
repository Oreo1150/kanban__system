<?php
// pages/store/purchase-requests.php
$page_title = 'คำขอสั่งซื้อ';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'คำขอสั่งซื้อ']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['store', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลคำขอสั่งซื้อ
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

$where_conditions = ["1=1"];
$params = [];

if ($filter !== 'all') {
    $where_conditions[] = "pr.status = ?";
    $params[] = $filter;
}

if (!empty($search)) {
    $where_conditions[] = "(pr.pr_number LIKE ? OR m.part_code LIKE ? OR m.material_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

$pr_query = "
    SELECT 
        pr.*,
        m.part_code,
        m.material_name,
        m.unit,
        u1.full_name as created_by_name,
        u2.full_name as approved_by_name
    FROM purchase_requests pr
    LEFT JOIN materials m ON pr.material_id = m.material_id
    LEFT JOIN users u1 ON pr.created_by = u1.user_id
    LEFT JOIN users u2 ON pr.approved_by = u2.user_id
    WHERE $where_clause
    ORDER BY 
        CASE pr.status
            WHEN 'pending' THEN 1
            WHEN 'approved' THEN 2
            WHEN 'ordered' THEN 3
            WHEN 'received' THEN 4
            WHEN 'rejected' THEN 5
        END,
        pr.created_at DESC
";

$stmt = $db->prepare($pr_query);
$stmt->execute($params);
$purchase_requests = $stmt->fetchAll();

// นับจำนวนตามสถานะ
$counts_query = "
    SELECT 
        status,
        COUNT(*) as count
    FROM purchase_requests
    GROUP BY status
";
$counts_result = $db->query($counts_query)->fetchAll();
$status_counts = [
    'all' => 0,
    'pending' => 0,
    'approved' => 0,
    'ordered' => 0,
    'received' => 0,
    'rejected' => 0
];

foreach ($counts_result as $row) {
    if (isset($status_counts[$row['status']])) {
        $status_counts[$row['status']] = $row['count'];
        $status_counts['all'] += $row['count'];
    }
}
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
                        <i class="fas fa-check me-1"></i>อนุมัติ <span class="badge bg-success ms-1"><?= $status_counts['approved'] ?></span>
                    </a>
                    <a href="?filter=ordered" class="btn btn-outline-primary <?= $filter === 'ordered' ? 'active' : '' ?>">
                        <i class="fas fa-truck me-1"></i>สั่งแล้ว <span class="badge bg-info ms-1"><?= $status_counts['ordered'] ?></span>
                    </a>
                    <a href="?filter=received" class="btn btn-outline-primary <?= $filter === 'received' ? 'active' : '' ?>">
                        <i class="fas fa-box me-1"></i>รับแล้ว <span class="badge bg-success ms-1"><?= $status_counts['received'] ?></span>
                    </a>
                </div>
            </div>

            <!-- Search -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" id="searchInput" 
                               placeholder="ค้นหาเลขที่ PR, รหัสวัสดุ, หรือชื่อวัสดุ..." 
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-4 text-end">
                    <button class="btn btn-success me-2" onclick="openCreatePRModal()">
                        <i class="fas fa-plus me-1"></i>สร้างคำขอใหม่
                    </button>
                    <button class="btn btn-outline-primary" onclick="refreshPage()">
                        <i class="fas fa-sync-alt me-1"></i>รีเฟรช
                    </button>
                </div>
            </div>

            <!-- Requests Table -->
            <?php if (!empty($purchase_requests)): ?>
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="requestsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>เลขที่ PR</th>
                                    <th>วัสดุ</th>
                                    <th>จำนวน</th>
                                    <th>ผู้ขอ</th>
                                    <th>วันที่ขอ</th>
                                    <th>สถานะ</th>
                                    <th>วันที่คาดว่าจะมา</th>
                                    <th>การกระทำ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($purchase_requests as $pr): ?>
                                    <?php
                                    $status_colors = [
                                        'pending' => 'warning',
                                        'approved' => 'success',
                                        'ordered' => 'info',
                                        'received' => 'success',
                                        'rejected' => 'danger'
                                    ];
                                    
                                    $status_texts = [
                                        'pending' => 'รอพิจารณา',
                                        'approved' => 'อนุมัติ',
                                        'ordered' => 'สั่งแล้ว',
                                        'received' => 'รับแล้ว',
                                        'rejected' => 'ปฏิเสธ'
                                    ];
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($pr['pr_number']) ?></strong></td>
                                        <td>
                                            <strong><?= htmlspecialchars($pr['part_code']) ?></strong><br>
                                            <small class="text-muted"><?= htmlspecialchars($pr['material_name']) ?></small>
                                        </td>
                                        <td><?= number_format($pr['quantity_requested']) ?> <?= htmlspecialchars($pr['unit']) ?></td>
                                        <td><?= htmlspecialchars($pr['created_by_name']) ?></td>
                                        <td><?= date('d/m/Y', strtotime($pr['created_at'])) ?></td>
                                        <td>
                                            <span class="badge bg-<?= $status_colors[$pr['status']] ?>">
                                                <?= $status_texts[$pr['status']] ?>
                                            </span>
                                        </td>
                                        <td><?= date('d/m/Y', strtotime($pr['expected_date'])) ?></td>
                                        <td>
                                            <button class="btn btn-outline-primary btn-sm" 
                                                    onclick="viewPRDetail(<?= $pr['pr_id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
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
                        <h4 class="text-muted">ไม่พบคำขอสั่งซื้อ</h4>
                        <?php if ($filter === 'pending'): ?>
                            <p class="text-muted">ไม่มีคำขอรอการพิจารณา</p>
                        <?php else: ?>
                            <p class="text-muted">ไม่มีคำขอในสถานะนี้</p>
                            <a href="?" class="btn btn-primary">ดูทั้งหมด</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Create PR Modal -->
    <div class="modal fade" id="createPRModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>สร้างคำขอสั่งซื้อใหม่
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="lowStockAlert" class="alert alert-warning d-none" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>แจ้งเตือน!</strong> วัสดุนี้มีสต็อกต่ำกว่าระดับต่ำสุด
                    </div>

                    <form id="createPRForm">
                        <div class="mb-3">
                            <label for="prMaterial" class="form-label">วัสดุ <span class="text-danger">*</span></label>
                            <select class="form-select" id="prMaterial" required onchange="updateMaterialInfo()">
                                <option value="">-- เลือกวัสดุ --</option>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">หน่วยวัด</label>
                                    <input type="text" class="form-control" id="prUnit" readonly>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">สต็อกปัจจุบัน</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" id="prCurrentStock" readonly>
                                        <span class="input-group-text" id="prMinStock"></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="prQuantity" class="form-label">จำนวนที่ขอ <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="prQuantity" required min="1" placeholder="ระบุจำนวน">
                        </div>

                        <div class="mb-3">
                            <label for="prExpectedDate" class="form-label">วันที่คาดว่าจะได้รับ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="prExpectedDate" required>
                        </div>

                        <div class="mb-3">
                            <label for="prUrgency" class="form-label">ระดับความเร่งด่วน <span class="text-danger">*</span></label>
                            <select class="form-select" id="prUrgency" required>
                                <option value="">-- เลือก --</option>
                                <option value="low">ต่ำ</option>
                                <option value="medium">ปานกลาง</option>
                                <option value="high">สูง</option>
                                <option value="urgent">ฉุกเฉิน</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="prNotes" class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" id="prNotes" rows="2" placeholder="หมายเหตุเพิ่มเติม (ถ้ามี)"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="button" class="btn btn-success" onclick="submitCreatePR()">
                        <i class="fas fa-save me-1"></i>สร้างคำขอ
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- PR Detail Modal -->
    <div class="modal fade" id="prDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-alt me-2"></i>รายละเอียดคำขอสั่งซื้อ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="prDetailContent">
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

        // View PR detail
        function viewPRDetail(prId) {
            fetch(`../../api/purchase-requests.php?action=get&id=${prId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const pr = data.data;
                        const statusColors = {
                            'pending': 'warning', 'approved': 'success', 'ordered': 'info',
                            'received': 'success', 'rejected': 'danger'
                        };
                        const statusTexts = {
                            'pending': 'รอพิจารณา', 'approved': 'อนุมัติ', 'ordered': 'สั่งแล้ว',
                            'received': 'รับแล้ว', 'rejected': 'ปฏิเสธ'
                        };

                        let html = `
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <h6 class="mb-1">
                                        <strong>เลขที่ PR:</strong> ${pr.pr_number}
                                    </h6>
                                    <p class="mb-1 text-muted">
                                        <i class="fas fa-calendar me-1"></i>
                                        ${new Date(pr.created_at).toLocaleDateString('th-TH')}
                                    </p>
                                </div>
                                <div class="col-md-6 text-end">
                                    <span class="badge bg-${statusColors[pr.status]}">
                                        ${statusTexts[pr.status]}
                                    </span>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <strong>วัสดุ:</strong><br>
                                    ${pr.part_code} - ${pr.material_name}
                                </div>
                                <div class="col-md-4">
                                    <strong>จำนวนที่ขอ:</strong><br>
                                    ${Number(pr.quantity_requested).toLocaleString()} ${pr.unit}
                                </div>
                                <div class="col-md-4">
                                    <strong>วันที่คาดว่าจะมา:</strong><br>
                                    ${new Date(pr.expected_date).toLocaleDateString('th-TH')}
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>ผู้สร้าง:</strong> ${pr.created_by_name}
                                </div>
                                <div class="col-md-6">
                                    <strong>ผู้อนุมัติ:</strong> ${pr.approved_by_name || 'ยังไม่อนุมัติ'}
                                </div>
                            </div>
                            ${pr.notes ? `
                                <div class="mb-3 p-3 bg-light rounded">
                                    <strong>หมายเหตุ:</strong><br>
                                    <small>${pr.notes.replace(/\n/g, '<br>')}</small>
                                </div>
                            ` : ''}
                        `;
                        
                        document.getElementById('prDetailContent').innerHTML = html;
                        new bootstrap.Modal(document.getElementById('prDetailModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }

        function refreshPage() {
            location.reload();
        }

        function openCreatePRModal(preselectId = null) {
            // Load materials list
            fetch('../../api/materials.php?action=get_all&limit=100')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const select = document.getElementById('prMaterial');
                        select.innerHTML = '<option value="">-- เลือกวัสดุ --</option>';
                        
                        const materials = data.data || data.materials || [];
                        
                        materials.forEach(material => {
                            const option = document.createElement('option');
                            option.value = material.material_id;
                            
                            let optionText = `${material.part_code} - ${material.material_name}`;
                            
                            // แสดงแจ้งเตือนสต็อกต่ำในตัวเลือก
                            if (material.current_stock < material.min_stock) {
                                optionText += ` ⚠️ สต็อกต่ำ`;
                            }
                            
                            option.textContent = optionText;
                            option.dataset.unit = material.unit;
                            option.dataset.stock = material.current_stock;
                            option.dataset.minStock = material.min_stock;
                            option.dataset.maxStock = material.max_stock;
                            select.appendChild(option);
                        });

                        if (preselectId) {
                            select.value = preselectId;
                            updateMaterialInfo();
                        }
                    } else {
                        console.error('ไม่สามารถโหลดวัสดุได้:', data.message);
                        Swal.fire('แจ้งเตือน', 'ไม่สามารถโหลดรายการวัสดุได้', 'warning');
                    }
                })
                .catch(error => {
                    console.error('Error loading materials:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดรายการวัสดุได้', 'error');
                });

            // Set default expected date to 7 days from now
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 7);
            document.getElementById('prExpectedDate').valueAsDate = tomorrow;
            
            new bootstrap.Modal(document.getElementById('createPRModal')).show();
        }

        // Auto-open modal when ?create=1&material_id=... is present
        (function() {
            const params = new URLSearchParams(window.location.search);
            if (params.get('create') === '1') {
                const m = params.get('material_id');
                openCreatePRModal(m ? parseInt(m) : null);
            }
        })();

        function updateMaterialInfo() {
            const select = document.getElementById('prMaterial');
            const selectedOption = select.options[select.selectedIndex];
            const currentStock = parseInt(selectedOption.dataset.stock) || 0;
            const minStock = parseInt(selectedOption.dataset.minStock) || 0;
            const maxStock = parseInt(selectedOption.dataset.maxStock) || 0;
            
            document.getElementById('prUnit').value = selectedOption.dataset.unit || '';
            document.getElementById('prCurrentStock').value = currentStock;
            document.getElementById('prMinStock').textContent = `ต่ำสุด: ${minStock} / สูงสุด: ${maxStock}`;
            
            // แสดงแจ้งเตือนถ้าสต็อกต่ำ
            const alertBox = document.getElementById('lowStockAlert');
            if (currentStock < minStock) {
                alertBox.classList.remove('d-none');
            } else {
                alertBox.classList.add('d-none');
            }
        }

        function submitCreatePR() {
            const materialId = document.getElementById('prMaterial').value;
            const quantity = document.getElementById('prQuantity').value;
            const expectedDate = document.getElementById('prExpectedDate').value;
            const urgency = document.getElementById('prUrgency').value;
            const notes = document.getElementById('prNotes').value;

            if (!materialId || !quantity || !expectedDate || !urgency) {
                Swal.fire('แจ้งเตือน', 'กรุณากรอกข้อมูลที่จำเป็นทั้งหมด', 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('material_id', materialId);
            formData.append('quantity_requested', quantity);
            formData.append('expected_date', expectedDate);
            formData.append('urgency', urgency);
            formData.append('notes', notes);

            fetch('../../api/purchase-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', `สร้างคำขอสั่งซื้อ ${data.pr_number} เรียบร้อยแล้ว`, 'success')
                        .then(() => {
                            bootstrap.Modal.getInstance(document.getElementById('createPRModal')).hide();
                            refreshPage();
                        });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถสร้างคำขอได้', 'error');
            });
        }

        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }
    </script>

<?php require_once '../../includes/footer.php'; ?>
