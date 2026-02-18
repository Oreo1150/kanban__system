<?php
// pages/store/stock-alerts.php
$page_title = 'การแจ้งเตือนสต็อก';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'การแจ้งเตือนสต็อก']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['store', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงการแจ้งเตือนที่ยังไม่ได้จัดการ
// Check if 'urgency' and 'created_at' columns exist on stock_alerts
$has_sa_urgency = false;
$has_sa_created = false;
try {
    $colCheck = $db->query("SHOW COLUMNS FROM stock_alerts LIKE 'urgency'");
    $has_sa_urgency = $colCheck && $colCheck->rowCount() > 0;
} catch (PDOException $e) {
    $has_sa_urgency = false;
}
try {
    $colCheck2 = $db->query("SHOW COLUMNS FROM stock_alerts LIKE 'created_at'");
    $has_sa_created = $colCheck2 && $colCheck2->rowCount() > 0;
} catch (PDOException $e) {
    $has_sa_created = false;
}

// find a fallback column to order by if created_at missing
$sa_fallback_order_col = 'sa.material_id';
foreach (['stock_alert_id','id','alert_id','material_id'] as $c) {
    try {
        $chk = $db->query("SHOW COLUMNS FROM stock_alerts LIKE '" . $c . "'");
        if ($chk && $chk->rowCount() > 0) { $sa_fallback_order_col = 'sa.' . $c; break; }
    } catch (PDOException $e) { }
}

if ($has_sa_urgency && $has_sa_created) {
    $activeAlertsOrder = "ORDER BY \n        CASE sa.urgency \n            WHEN 'urgent' THEN 1 \n            WHEN 'high' THEN 2 \n            ELSE 3 \n        END,\n        sa.created_at DESC";
} elseif ($has_sa_urgency) {
    $activeAlertsOrder = "ORDER BY \n        CASE sa.urgency \n            WHEN 'urgent' THEN 1 \n            WHEN 'high' THEN 2 \n            ELSE 3 \n        END,\n        " . $sa_fallback_order_col . " DESC";
} elseif ($has_sa_created) {
    $activeAlertsOrder = "ORDER BY sa.created_at DESC";
} else {
    $activeAlertsOrder = "ORDER BY " . $sa_fallback_order_col . " DESC";
}

$active_alerts = $db->query(
    "SELECT \n        sa.*,\n        m.part_code,\n        m.material_name,\n        m.unit,\n        m.current_stock,\n        m.min_stock,\n        m.max_stock,\n        m.location,\n        COALESCE(pr.pending_quantity, 0) as pending_pr_quantity,\n        pr.pr_id,\n        pr.status as pr_status\n    FROM stock_alerts sa\n    JOIN materials m ON sa.material_id = m.material_id\n    LEFT JOIN (\n        SELECT material_id, \n               SUM(quantity_requested) as pending_quantity,\n               GROUP_CONCAT(pr_id) as pr_id,\n               GROUP_CONCAT(status) as status\n        FROM purchase_requests \n        WHERE status IN ('pending', 'approved', 'ordered')\n        GROUP BY material_id\n    ) pr ON m.material_id = pr.material_id\n    WHERE sa.status = 'active'\n    AND m.status = 'active'\n    " . $activeAlertsOrder
)->fetchAll();

// ดึง PR Suggestions
// Check if 'urgency' and 'created_at' exist on pr_suggestions
$has_ps_urgency = false;
$has_ps_created = false;
try {
    $colCheck3 = $db->query("SHOW COLUMNS FROM pr_suggestions LIKE 'urgency'");
    $has_ps_urgency = $colCheck3 && $colCheck3->rowCount() > 0;
} catch (PDOException $e) { $has_ps_urgency = false; }
try {
    $colCheck4 = $db->query("SHOW COLUMNS FROM pr_suggestions LIKE 'created_at'");
    $has_ps_created = $colCheck4 && $colCheck4->rowCount() > 0;
} catch (PDOException $e) { $has_ps_created = false; }

$ps_fallback_order_col = 'ps.material_id';
foreach (['pr_suggestion_id','id','material_id'] as $c) {
    try {
        $chk = $db->query("SHOW COLUMNS FROM pr_suggestions LIKE '" . $c . "'");
        if ($chk && $chk->rowCount() > 0) { $ps_fallback_order_col = 'ps.' . $c; break; }
    } catch (PDOException $e) { }
}

if ($has_ps_urgency && $has_ps_created) {
    $psOrder = "ORDER BY \n        CASE ps.urgency \n            WHEN 'urgent' THEN 1 \n            WHEN 'high' THEN 2 \n            ELSE 3 \n        END,\n        ps.created_at DESC";
} elseif ($has_ps_urgency) {
    $psOrder = "ORDER BY \n        CASE ps.urgency \n            WHEN 'urgent' THEN 1 \n            WHEN 'high' THEN 2 \n            ELSE 3 \n        END,\n        " . $ps_fallback_order_col . " DESC";
} elseif ($has_ps_created) {
    $psOrder = "ORDER BY ps.created_at DESC";
} else {
    $psOrder = "ORDER BY " . $ps_fallback_order_col . " DESC";
}

// Check if pr_suggestions table exists before querying
$pr_suggestions = [];
$has_pr_suggestions_table = false;
try {
    $tblCheck = $db->query("SHOW TABLES LIKE 'pr_suggestions'");
    $has_pr_suggestions_table = $tblCheck && $tblCheck->rowCount() > 0;
} catch (PDOException $e) {
    $has_pr_suggestions_table = false;
}

if ($has_pr_suggestions_table) {
    $pr_suggestions = $db->query(
        "SELECT \n        ps.*,\n        m.part_code,\n        m.material_name,\n        m.unit,\n        m.current_stock,\n        m.min_stock,\n        m.max_stock\n    FROM pr_suggestions ps\n    JOIN materials m ON ps.material_id = m.material_id\n    WHERE ps.status = 'pending'\n    AND m.status = 'active'\n    " . $psOrder . "\n    LIMIT 20"
    )->fetchAll();
}

// สถิติ
$stats = ['total_alerts' => 0, 'urgent_count' => 0, 'high_count' => 0, 'pending_suggestions' => 0];
try {
    $statsSelect = "SELECT COUNT(DISTINCT sa.material_id) as total_alerts, ";
    if ($has_sa_urgency) {
        $statsSelect .= "SUM(CASE WHEN sa.urgency = 'urgent' THEN 1 ELSE 0 END) as urgent_count, ";
        $statsSelect .= "SUM(CASE WHEN sa.urgency = 'high' THEN 1 ELSE 0 END) as high_count, ";
    } else {
        $statsSelect .= "0 as urgent_count, 0 as high_count, ";
    }
    if ($has_pr_suggestions_table) {
        $statsSelect .= "COUNT(DISTINCT ps.pr_suggestion_id) as pending_suggestions ";
        $statsSelect .= "FROM stock_alerts sa LEFT JOIN pr_suggestions ps ON sa.material_id = ps.material_id AND ps.status = 'pending' WHERE sa.status = 'active'";
    } else {
        $statsSelect .= "0 as pending_suggestions ";
        $statsSelect .= "FROM stock_alerts sa WHERE sa.status = 'active'";
    }
    $stats = $db->query($statsSelect)->fetch();
} catch (PDOException $e) {
    // keep defaults
}
?>

<style>
    .alert-card {
        border-left: 5px solid;
        transition: all 0.3s ease;
    }
    
    .alert-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        transform: translateX(5px);
    }
    
    .alert-urgent {
        border-left-color: #dc3545;
        background: #fff5f5;
    }
    
    .alert-high {
        border-left-color: #ffc107;
        background: #fffbf0;
    }
    
    .alert-medium {
        border-left-color: #17a2b8;
        background: #f0f9ff;
    }
    
    .stock-gauge {
        height: 10px;
        background: #e9ecef;
        border-radius: 5px;
        overflow: hidden;
        position: relative;
    }
    
    .stock-gauge-fill {
        height: 100%;
        transition: width 0.3s ease;
    }
    
    .suggestion-card {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 15px;
        padding: 20px;
        margin-bottom: 15px;
    }
    
    .urgency-badge {
        padding: 8px 16px;
        border-radius: 20px;
        font-weight: 600;
        font-size: 0.85rem;
    }
    
    .urgency-urgent {
        background: #dc3545;
        color: white;
    }
    
    .urgency-high {
        background: #ffc107;
        color: #000;
    }
    
    .urgency-medium {
        background: #17a2b8;
        color: white;
    }
</style>

            <!-- สถิติ -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-danger"><?= $stats['total_alerts'] ?></h3>
                            <p class="mb-0">การแจ้งเตือนทั้งหมด</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-danger"><?= $stats['urgent_count'] ?></h3>
                            <p class="mb-0">เร่งด่วนมาก</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-warning"><?= $stats['high_count'] ?></h3>
                            <p class="mb-0">เร่งด่วน</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-info"><?= $stats['pending_suggestions'] ?></h3>
                            <p class="mb-0">รอสร้าง PR</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- การแจ้งเตือนสต็อก -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-exclamation-triangle me-2 text-danger"></i>การแจ้งเตือนสต็อกต่ำ</h5>
                            <button class="btn btn-sm btn-outline-primary" onclick="refreshAlerts()">
                                <i class="fas fa-sync-alt"></i> รีเฟรช
                            </button>
                        </div>
                        <div class="card-body" style="max-height: 800px; overflow-y: auto;">
                            <?php if (!empty($active_alerts)): ?>
                                <?php foreach ($active_alerts as $alert): ?>
                                    <?php
                                    $urgency_class = 'alert-' . ($alert['urgency'] ?? 'medium');
                                    $stock_percentage = ($alert['current_stock'] / $alert['min_stock']) * 100;
                                    $shortage = $alert['shortage_quantity'];
                                    $gauge_color = $stock_percentage < 30 ? '#dc3545' : ($stock_percentage < 60 ? '#ffc107' : '#17a2b8');
                                    ?>
                                    <div class="alert-card <?= $urgency_class ?> card mb-3">
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div>
                                                            <h6 class="mb-1">
                                                                <strong><?= htmlspecialchars($alert['part_code']) ?></strong>
                                                                - <?= htmlspecialchars($alert['material_name']) ?>
                                                            </h6>
                                                            <span class="urgency-badge urgency-<?= $alert['urgency'] ?? 'medium' ?>">
                                                                <?= $alert['urgency'] === 'urgent' ? '🚨 เร่งด่วนมาก' : 
                                                                   ($alert['urgency'] === 'high' ? '⚠️ เร่งด่วน' : 'ℹ️ ปกติ') ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="mb-2">
                                                        <div class="d-flex justify-content-between mb-1">
                                                            <small class="text-muted">ระดับสต็อก</small>
                                                            <small>
                                                                <strong><?= number_format($alert['current_stock']) ?></strong> / 
                                                                <?= number_format($alert['min_stock']) ?> <?= $alert['unit'] ?>
                                                                (<?= number_format($stock_percentage, 1) ?>%)
                                                            </small>
                                                        </div>
                                                        <div class="stock-gauge">
                                                            <div class="stock-gauge-fill" 
                                                                 style="width: <?= min($stock_percentage, 100) ?>%; background: <?= $gauge_color ?>;"></div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="row mt-3">
                                                        <div class="col-6">
                                                            <small class="text-muted">ขาดแคลน:</small>
                                                            <strong class="text-danger d-block">
                                                                <?= number_format($shortage) ?> <?= $alert['unit'] ?>
                                                            </strong>
                                                        </div>
                                                        <div class="col-6">
                                                            <small class="text-muted">ที่เก็บ:</small>
                                                            <strong class="d-block"><?= htmlspecialchars($alert['location'] ?? 'N/A') ?></strong>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if ($alert['pending_pr_quantity'] > 0): ?>
                                                        <div class="alert alert-info py-2 mt-2 mb-0">
                                                            <i class="fas fa-info-circle me-1"></i>
                                                            <small>มี PR อยู่ในระบบ: <?= number_format($alert['pending_pr_quantity']) ?> <?= $alert['unit'] ?></small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="col-md-4 text-end">
                                                    <div class="mb-2">
                                                        <small class="text-muted d-block">แจ้งเตือนเมื่อ:</small>
                                                        <small><?= date('d/m/Y H:i', strtotime($alert['created_at'])) ?></small>
                                                    </div>
                                                    
                                                    <div class="d-grid gap-2 mt-3">
                                                        <?php if (!$alert['pr_id']): ?>
                                                            <button class="btn btn-primary btn-sm" 
                                                                    onclick="createPRFromAlert(<?= $alert['material_id'] ?>, '<?= htmlspecialchars($alert['part_code']) ?>', <?= $shortage ?>)">
                                                                <i class="fas fa-plus me-1"></i>สร้าง PR
                                                            </button>
                                                        <?php else: ?>
                                                            <button class="btn btn-outline-info btn-sm" 
                                                                    onclick="viewPR(<?= $alert['pr_id'] ?>)">
                                                                <i class="fas fa-eye me-1"></i>ดู PR
                                                            </button>
                                                        <?php endif; ?>
                                                        
                                                        <button class="btn btn-outline-secondary btn-sm" 
                                                                onclick="dismissAlert(<?= $alert['alert_id'] ?>)">
                                                            <i class="fas fa-check me-1"></i>รับทราบ
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                    <h5 class="text-success">ไม่มีการแจ้งเตือน</h5>
                                    <p class="text-muted">สต็อกวัสดุทั้งหมดอยู่ในระดับปกติ</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- ข้อเสนอแนะ PR -->
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-lightbulb me-2 text-warning"></i>ข้อเสนอแนะ PR</h5>
                        </div>
                        <div class="card-body" style="max-height: 800px; overflow-y: auto;">
                            <?php if (!empty($pr_suggestions)): ?>
                                <?php foreach ($pr_suggestions as $suggestion): ?>
                                    <div class="suggestion-card">
                                        <h6 class="mb-2">
                                            <strong><?= htmlspecialchars($suggestion['part_code']) ?></strong>
                                        </h6>
                                        <p class="text-muted small mb-2"><?= htmlspecialchars($suggestion['material_name']) ?></p>
                                        
                                        <div class="mb-2">
                                            <small class="text-muted">สต็อกปัจจุบัน:</small>
                                            <strong class="d-block"><?= number_format($suggestion['current_stock']) ?> <?= $suggestion['unit'] ?></strong>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <small class="text-muted">แนะนำให้สั่ง:</small>
                                            <strong class="text-primary d-block">
                                                <?= number_format($suggestion['suggested_quantity']) ?> <?= $suggestion['unit'] ?>
                                            </strong>
                                        </div>
                                        
                                        <?php if ($suggestion['reason']): ?>
                                            <div class="alert alert-light py-2 mb-2">
                                                <small><?= nl2br(htmlspecialchars($suggestion['reason'])) ?></small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-grid gap-2">
                                            <button class="btn btn-success btn-sm" 
                                                    onclick="createPRFromSuggestion(<?= $suggestion['pr_suggestion_id'] ?>, <?= $suggestion['material_id'] ?>, '<?= htmlspecialchars($suggestion['part_code']) ?>', <?= $suggestion['suggested_quantity'] ?>)">
                                                <i class="fas fa-plus me-1"></i>สร้าง PR
                                            </button>
                                            <button class="btn btn-outline-secondary btn-sm" 
                                                    onclick="dismissSuggestion(<?= $suggestion['pr_suggestion_id'] ?>)">
                                                <i class="fas fa-times me-1"></i>ยกเลิก
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center py-4">ไม่มีข้อเสนอแนะ</p>
                            <?php endif; ?>
                        </div>
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
                        <i class="fas fa-shopping-cart me-2"></i>สร้างใบขอสั่งซื้อ (Purchase Request)
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="createPRForm">
                    <div class="modal-body">
                        <input type="hidden" id="pr_material_id" name="material_id">
                        <input type="hidden" id="pr_alert_id" name="alert_id">
                        <input type="hidden" id="pr_suggestion_id" name="suggestion_id">
                        
                        <div class="alert alert-info">
                            <strong id="pr_material_info"></strong>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    จำนวนที่ต้องการสั่ง <span class="text-danger">*</span>
                                </label>
                                <input type="number" class="form-control form-control-lg" 
                                       id="pr_quantity" name="quantity_requested" 
                                       required min="1" step="1">
                                <small class="form-text text-muted" id="pr_quantity_hint"></small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    ความเร่งด่วน <span class="text-danger">*</span>
                                </label>
                                <select class="form-control form-control-lg" id="pr_urgency" name="urgency" required>
                                    <option value="urgent">🚨 เร่งด่วนมาก (1-3 วัน)</option>
                                    <option value="high">⚠️ เร่งด่วน (4-7 วัน)</option>
                                    <option value="medium" selected>ปกติ (7-14 วัน)</option>
                                    <option value="low">ไม่เร่งด่วน (14+ วัน)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">วันที่ต้องการ <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="pr_expected_date" name="expected_date" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">หมายเหตุ / เหตุผลในการสั่งซื้อ</label>
                            <textarea class="form-control" id="pr_notes" name="notes" rows="4"
                                      placeholder="ระบุเหตุผลหรือข้อมูลเพิ่มเติม เช่น สำหรับงานการผลิต, สต็อกต่ำกว่าเกณฑ์"></textarea>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>หมายเหตุ:</strong> ใบขอสั่งซื้อจะถูกส่งไปยัง Planning Department เพื่ออนุมัติ
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-1"></i>ส่งคำขอ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        function createPRFromAlert(materialId, partCode, suggestedQuantity) {
            document.getElementById('pr_material_id').value = materialId;
            document.getElementById('pr_alert_id').value = '';
            document.getElementById('pr_material_info').textContent = 
                `วัสดุ: ${partCode} | แนะนำจำนวน: ${suggestedQuantity.toLocaleString()}`;
            document.getElementById('pr_quantity').value = suggestedQuantity;
            document.getElementById('pr_quantity_hint').textContent = 
                `แนะนำ: ${suggestedQuantity.toLocaleString()} (เพื่อให้ถึงระดับสต็อกสูงสุด)`;
            
            // Set default expected date (7 days from now)
            const expectedDate = new Date();
            expectedDate.setDate(expectedDate.getDate() + 7);
            document.getElementById('pr_expected_date').value = expectedDate.toISOString().split('T')[0];
            
            new bootstrap.Modal(document.getElementById('createPRModal')).show();
        }
        
        function createPRFromSuggestion(suggestionId, materialId, partCode, suggestedQuantity) {
            document.getElementById('pr_material_id').value = materialId;
            document.getElementById('pr_suggestion_id').value = suggestionId;
            document.getElementById('pr_material_info').textContent = 
                `วัสดุ: ${partCode} | แนะนำจำนวน: ${suggestedQuantity.toLocaleString()}`;
            document.getElementById('pr_quantity').value = suggestedQuantity;
            document.getElementById('pr_quantity_hint').textContent = 
                `แนะนำ: ${suggestedQuantity.toLocaleString()}`;
            
            const expectedDate = new Date();
            expectedDate.setDate(expectedDate.getDate() + 7);
            document.getElementById('pr_expected_date').value = expectedDate.toISOString().split('T')[0];
            
            new bootstrap.Modal(document.getElementById('createPRModal')).show();
        }
        
        document.getElementById('createPRForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'create');
            formData.append('requested_by', <?= $_SESSION['user_id'] ?>);
            
            Swal.fire({
                title: 'กำลังสร้างใบขอสั่งซื้อ...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('../../api/purchase-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'สำเร็จ!',
                        text: data.message,
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
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถสร้างใบขอสั่งซื้อได้', 'error');
            });
        });
        
        function dismissAlert(alertId) {
            Swal.fire({
                title: 'รับทราบการแจ้งเตือน?',
                text: 'คุณต้องการปิดการแจ้งเตือนนี้หรือไม่?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'รับทราบ',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('../../api/stock-alerts.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: `action=dismiss&alert_id=${alertId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('สำเร็จ', 'ปิดการแจ้งเตือนแล้ว', 'success');
                            location.reload();
                        }
                    });
                }
            });
        }
        
        function dismissSuggestion(suggestionId) {
            fetch('../../api/pr-suggestions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=dismiss&suggestion_id=${suggestionId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        function refreshAlerts() {
            Swal.fire({
                title: 'กำลังอัพเดท...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            fetch('../../stock-monitor.php?run=1')
            .then(response => response.json())
            .then(data => {
                Swal.close();
                location.reload();
            })
            .catch(() => {
                Swal.close();
                location.reload();
            });
        }
        
        // Auto refresh every 5 minutes
        setInterval(() => {
            location.reload();
        }, 300000);
    </script>
</body>
</html>