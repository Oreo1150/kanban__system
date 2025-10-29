<?php
// pages/store/material-requests.php
$page_title = 'จัดการคำขอเบิกวัสดุ';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'คำขอเบิกวัสดุ']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['store', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลคำขอเบิกวัสดุทั้งหมด
$filter = $_GET['filter'] ?? 'pending';
$search = $_GET['search'] ?? '';

$where_conditions = ["1=1"];
$params = [];

if ($filter !== 'all') {
    $where_conditions[] = "mr.status = ?";
    $params[] = $filter;
}

if (!empty($search)) {
    $where_conditions[] = "(mr.request_number LIKE ? OR pj.job_number LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

$requests_query = "
    SELECT 
        mr.*,
        pj.job_number,
        pj.product_id,
        pj.quantity_planned,
        p.product_name,
        u1.full_name as requested_by_name,
        u2.full_name as approved_by_name,
        COUNT(mrd.request_detail_id) as item_count,
        SUM(CASE WHEN m.current_stock < mrd.quantity_requested THEN 1 ELSE 0 END) as insufficient_count
    FROM material_requests mr
    LEFT JOIN production_jobs pj ON mr.job_id = pj.job_id
    LEFT JOIN products p ON pj.product_id = p.product_id
    LEFT JOIN users u1 ON mr.requested_by = u1.user_id
    LEFT JOIN users u2 ON mr.approved_by = u2.user_id
    LEFT JOIN material_request_details mrd ON mr.request_id = mrd.request_id
    LEFT JOIN materials m ON mrd.material_id = m.material_id
    WHERE $where_clause
    GROUP BY mr.request_id
    ORDER BY 
        CASE mr.status
            WHEN 'pending' THEN 1
            WHEN 'approved' THEN 2
            WHEN 'fulfilled' THEN 3
            WHEN 'rejected' THEN 4
        END,
        mr.request_date DESC
";

$stmt = $db->prepare($requests_query);
$stmt->execute($params);
$requests = $stmt->fetchAll();

// นับจำนวนตามสถานะ
$counts_query = "
    SELECT 
        status,
        COUNT(*) as count
    FROM material_requests
    GROUP BY status
";
$counts_result = $db->query($counts_query)->fetchAll();
$status_counts = [
    'all' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'fulfilled' => 0
];

foreach ($counts_result as $row) {
    $status_counts[$row['status']] = $row['count'];
    $status_counts['all'] += $row['count'];
}
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
    
    .request-card.status-pending { 
        border-left-color: #ffc107;
        background: linear-gradient(to right, rgba(255, 193, 7, 0.05) 0%, transparent 100%);
    }
    .request-card.status-approved { 
        border-left-color: #28a745;
        background: linear-gradient(to right, rgba(40, 167, 69, 0.05) 0%, transparent 100%);
    }
    .request-card.status-rejected { 
        border-left-color: #dc3545;
        background: linear-gradient(to right, rgba(220, 53, 69, 0.05) 0%, transparent 100%);
    }
    .request-card.status-fulfilled { 
        border-left-color: #17a2b8;
        background: linear-gradient(to right, rgba(23, 162, 184, 0.05) 0%, transparent 100%);
    }
    
    .request-card:hover {
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }
    
    .request-card.urgent {
        animation: pulse-urgent 2s infinite;
    }
    
    @keyframes pulse-urgent {
        0%, 100% { box-shadow: 0 5px 15px rgba(255, 193, 7, 0.3); }
        50% { box-shadow: 0 5px 25px rgba(255, 193, 7, 0.6); }
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
                    <h4><i class="fas fa-clipboard-list me-2"></i>จัดการคำขอเบิกวัสดุ</h4>
                    <p class="text-muted mb-0">อนุมัติและจัดการคำขอเบิกวัสดุจากแผนกผลิต</p>
                </div>
                <div>
                    <span class="badge bg-warning fs-5">
                        <i class="fas fa-clock me-1"></i>
                        รอดำเนินการ: <?= $status_counts['pending'] ?>
                    </span>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> ทั้งหมด (<?= $status_counts['all'] ?>)
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
                        <input type="text" class="form-control" id="searchInput" 
                               placeholder="ค้นหาเลขที่คำขอ, Job, หรือผู้ขอ..." 
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-6 text-end">
                    <button class="btn btn-outline-primary" onclick="refreshPage()">
                        <i class="fas fa-sync-alt me-1"></i>รีเฟรช
                    </button>
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
                        
                        // คำนวณเวลาที่รอ
                        $waiting_time = '';
                        if ($request['status'] === 'pending') {
                            $request_date = new DateTime($request['request_date']);
                            $now = new DateTime();
                            $diff = $now->diff($request_date);
                            
                            if ($diff->h > 0 || $diff->days > 0) {
                                $waiting_time = $diff->days > 0 ? $diff->days . ' วัน ' : '';
                                $waiting_time .= $diff->h . ' ชม.';
                            } else {
                                $waiting_time = $diff->i . ' นาที';
                            }
                        }
                        
                        $urgentClass = ($request['status'] === 'pending' && $diff->h >= 2) ? 'urgent' : '';
                        ?>
                        
                        <div class="col-lg-6 col-xl-4">
                            <div class="request-card status-<?= $request['status'] ?> <?= $urgentClass ?>">
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
                                            <?php if ($waiting_time && $request['status'] === 'pending'): ?>
                                                <br>
                                                <small class="text-warning">
                                                    <i class="fas fa-hourglass-half me-1"></i>
                                                    รอมา <?= $waiting_time ?>
                                                </small>
                                            <?php endif; ?>
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
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-user me-2 text-info"></i>
                                            <strong>ผู้ขอ:</strong>&nbsp;
                                            <?= htmlspecialchars($request['requested_by_name']) ?>
                                        </div>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-list me-2 text-warning"></i>
                                            <strong>รายการวัสดุ:</strong>&nbsp;
                                            <?= $request['item_count'] ?> รายการ
                                            <?php if ($request['insufficient_count'] > 0): ?>
                                                <span class="badge bg-danger ms-2">
                                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                                    สต็อกไม่พอ <?= $request['insufficient_count'] ?>
                                                </span>
                                            <?php endif; ?>
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
                                    
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button class="btn btn-outline-primary btn-sm flex-fill" 
                                                onclick="viewRequestDetails(<?= $request['request_id'] ?>)">
                                            <i class="fas fa-eye"></i> ดูรายละเอียด
                                        </button>
                                        
                                        <?php if ($request['status'] === 'pending'): ?>
                                            <?php if ($request['insufficient_count'] == 0): ?>
                                                <button class="btn btn-success btn-sm" 
                                                        onclick="approveRequest(<?= $request['request_id'] ?>, '<?= htmlspecialchars($request['request_number']) ?>')">
                                                    <i class="fas fa-check"></i> อนุมัติ
                                                </button>
                                            <?php else: ?>
                                                <button class="btn btn-warning btn-sm" 
                                                        onclick="approveRequest(<?= $request['request_id'] ?>, '<?= htmlspecialchars($request['request_number']) ?>')">
                                                    <i class="fas fa-exclamation-triangle"></i> อนุมัติ
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-danger btn-sm" 
                                                    onclick="rejectRequest(<?= $request['request_id'] ?>, '<?= htmlspecialchars($request['request_number']) ?>')">
                                                <i class="fas fa-times"></i> ปฏิเสธ
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($request['status'] === 'fulfilled'): ?>
                                            <button class="btn btn-outline-info btn-sm" 
                                                    onclick="printDeliveryNote(<?= $request['request_id'] ?>)">
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
                            <?php if ($filter === 'pending'): ?>
                                <p class="text-muted">ไม่มีคำขอรอการอนุมัติในขณะนี้</p>
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

    <!-- Request Details Modal -->
    <div class="modal fade" id="requestDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-clipboard-list me-2"></i>รายละเอียดคำขอเบิกวัสดุ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="requestDetailsContent">
                        <!-- Details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer" id="modal-actions">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        let currentRequestId = null;
        let detailsModal = null;

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

        // View request details
        function viewRequestDetails(requestId) {
            currentRequestId = requestId;
            
            fetch(`../../api/get_request_detail.php?request_id=${requestId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('requestDetailsContent').innerHTML = html;
                    
                    // เพิ่มปุ่มอนุมัติ/ปฏิเสธในรายละเอียด
                    updateModalActions(requestId);
                    
                    detailsModal = new bootstrap.Modal(document.getElementById('requestDetailsModal'));
                    detailsModal.show();
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดรายละเอียดได้', 'error');
                });
        }

        function updateModalActions(requestId) {
            // ดึงสถานะจากการ์ด
            const card = document.querySelector(`[onclick*="viewRequestDetails(${requestId})"]`).closest('.request-card');
            const status = card.className.match(/status-(\w+)/)[1];
            
            const actionsDiv = document.getElementById('modal-actions');
            
            if (status === 'pending') {
                // เช็คว่ามีสต็อกไม่พอหรือไม่
                const hasInsufficientStock = card.querySelector('.badge.bg-danger') !== null;
                
                actionsDiv.innerHTML = `
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="button" class="btn btn-danger" onclick="rejectRequestFromModal()">
                        <i class="fas fa-times me-1"></i>ปฏิเสธ
                    </button>
                    <button type="button" class="btn btn-${hasInsufficientStock ? 'warning' : 'success'}" onclick="approveRequestFromModal()">
                        <i class="fas fa-${hasInsufficientStock ? 'exclamation-triangle' : 'check'} me-1"></i>อนุมัติ
                        ${hasInsufficientStock ? ' (มีสต็อกไม่พอ)' : ''}
                    </button>
                `;
            } else {
                actionsDiv.innerHTML = `
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                `;
            }
        }

        // Approve request
        function approveRequest(requestId, requestNumber) {
            currentRequestId = requestId;
            
            Swal.fire({
                title: 'ยืนยันการอนุมัติ?',
                html: `
                    <p>คุณต้องการอนุมัติคำขอเบิกวัสดุหมายเลข</p>
                    <strong>${requestNumber}</strong>
                    <p class="text-muted mt-2">ระบบจะหักสต็อกวัสดุโดยอัตโนมัติ</p>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-check me-1"></i>อนุมัติ',
                cancelButtonText: 'ยกเลิก',
                showLoaderOnConfirm: true,
                preConfirm: () => {
                    return processRequest(requestId, 'approve');
                }
            });
        }

        function approveRequestFromModal() {
            if (detailsModal) {
                detailsModal.hide();
            }
            
            const requestNumber = document.querySelector('#requestDetailsContent').textContent.match(/#(\d+)/)[0];
            approveRequest(currentRequestId, requestNumber);
        }

        // Reject request
        function rejectRequest(requestId, requestNumber) {
            currentRequestId = requestId;
            
            Swal.fire({
                title: 'ยืนยันการปฏิเสธ?',
                html: `
                    <p>คุณต้องการปฏิเสธคำขอเบิกวัสดุหมายเลข</p>
                    <strong>${requestNumber}</strong>
                `,
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'เหตุผลในการปฏิเสธ',
                inputPlaceholder: 'กรุณาระบุเหตุผล...',
                inputAttributes: {
                    'required': 'required'
                },
                inputValidator: (value) => {
                    if (!value) {
                        return 'กรุณาระบุเหตุผล!'
                    }
                },
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-times me-1"></i>ปฏิเสธ',
                cancelButtonText: 'ยกเลิก',
                showLoaderOnConfirm: true,
                preConfirm: (reason) => {
                    return processRequest(requestId, 'reject', reason);
                }
            });
        }

        function rejectRequestFromModal() {
            if (detailsModal) {
                detailsModal.hide();
            }
            
            const requestNumber = document.querySelector('#requestDetailsContent').textContent.match(/#(\d+)/)[0];
            rejectRequest(currentRequestId, requestNumber);
        }

        // Process request (approve/reject)
        function processRequest(requestId, action, reason = '') {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('request_id', requestId);
            if (reason) {
                formData.append('reason', reason);
            }

            return fetch('../../api/material-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'สำเร็จ',
                        text: data.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    throw new Error(data.message);
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', error.message || 'ไม่สามารถดำเนินการได้', 'error');
            });
        }

        // Print delivery note
        function printDeliveryNote(requestId) {
            window.open(`../../reports/print-delivery-note.php?request_id=${requestId}`, '_blank');
        }

        // Refresh page
        function refreshPage() {
            location.reload();
        }

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Auto refresh every 30 seconds for pending requests
        <?php if ($filter === 'pending' && count($requests) > 0): ?>
        setInterval(() => {
            refreshPage();
        }, 30000);
        <?php endif; ?>

        // Notification sound for new requests (optional)
        let previousCount = <?= $status_counts['pending'] ?>;
        
        setInterval(() => {
            fetch('../../api/material-requests.php?action=count_pending')
                .then(response => response.json())
                .then(data => {
                    if (data.count > previousCount) {
                        // Play notification sound
                        const audio = new Audio('../../assets/sounds/notification.mp3');
                        audio.play().catch(e => console.log('Audio play failed'));
                        
                        // Show toast notification
                        showToast('มีคำขอเบิกวัสดุใหม่!', 'info');
                    }
                    previousCount = data.count;
                });
        }, 60000); // Check every minute

        function showToast(message, type = 'info') {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer)
                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                }
            });
            
            Toast.fire({
                icon: type,
                title: message
            });
        }
    </script>

</body>
</html>