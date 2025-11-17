<?php
// pages/admin/units.php
$page_title = 'จัดการหน่วยนับ';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'จัดการหน่วยนับ']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงสถิติ
$stats = $db->query("
    SELECT 
        COUNT(*) as total_units,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_units,
        COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_units
    FROM units
")->fetch();
?>

<style>
    .unit-card {
        transition: all 0.3s ease;
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 15px;
    }
    
    .unit-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }
    
    .unit-icon {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        font-weight: bold;
    }
    
    .usage-badge {
        background: #f8f9fa;
        padding: 8px 15px;
        border-radius: 20px;
        border: 2px solid #dee2e6;
    }
</style>

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="stats-card text-center">
                        <i class="fas fa-ruler-combined icon"></i>
                        <div class="number"><?= number_format($stats['total_units']) ?></div>
                        <div class="label">หน่วยนับทั้งหมด</div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <i class="fas fa-check-circle icon"></i>
                        <div class="number"><?= number_format($stats['active_units']) ?></div>
                        <div class="label">ใช้งานอยู่</div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #dc3545, #fd7e14);">
                        <i class="fas fa-times-circle icon"></i>
                        <div class="number"><?= number_format($stats['inactive_units']) ?></div>
                        <div class="label">ไม่ได้ใช้งาน</div>
                    </div>
                </div>
            </div>

            <!-- Units Management Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-ruler-combined me-2"></i>รายการหน่วยนับทั้งหมด</h5>
                    <button class="btn btn-primary" onclick="showAddUnitModal()">
                        <i class="fas fa-plus me-1"></i>เพิ่มหน่วยนับใหม่
                    </button>
                </div>
                <div class="card-body">
                    <!-- Filter Options -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <select class="form-select" id="filterStatus" onchange="loadUnits()">
                                <option value="active">ใช้งานอยู่</option>
                                <option value="inactive">ไม่ได้ใช้งาน</option>
                                <option value="all">ทั้งหมด</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="searchUnit" 
                                   placeholder="ค้นหารหัส หรือชื่อหน่วยนับ" onkeyup="loadUnits()">
                        </div>
                    </div>
                    
                    <div id="unitsContainer">
                        <!-- Units will be loaded here -->
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Add/Edit Unit Modal -->
    <div class="modal fade" id="unitModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="unitModalTitle">
                        <i class="fas fa-plus me-2"></i>เพิ่มหน่วยนับใหม่
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="unitForm">
                    <div class="modal-body">
                        <input type="hidden" id="unit_id" name="unit_id">
                        <input type="hidden" id="action" name="action" value="create">
                        
                        <div class="mb-3">
                            <label for="unit_code" class="form-label">
                                รหัสหน่วยนับ <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="unit_code" name="unit_code" 
                                   placeholder="เช่น: pcs, kg, m" required>
                            <small class="text-muted">ใช้ตัวอักษรภาษาอังกฤษพิมพ์เล็กเท่านั้น (a-z)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="unit_name" class="form-label">
                                ชื่อหน่วยนับ (ภาษาไทย) <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="unit_name" name="unit_name" 
                                   placeholder="เช่น: ชิ้น, กิโลกรัม, เมตร" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="unit_name_en" class="form-label">ชื่อหน่วยนับ (ภาษาอังกฤษ)</label>
                            <input type="text" class="form-control" id="unit_name_en" name="unit_name_en" 
                                   placeholder="เช่น: Pieces, Kilogram, Meter">
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">รายละเอียด</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="3" placeholder="ระบุรายละเอียดเพิ่มเติม (ถ้ามี)"></textarea>
                        </div>
                        
                        <div class="mb-3" id="statusSection" style="display: none;">
                            <label for="status" class="form-label">สถานะ</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active">ใช้งานอยู่</option>
                                <option value="inactive">ไม่ได้ใช้งาน</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>ยกเลิก
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Unit Modal -->
    <div class="modal fade" id="viewUnitModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i>รายละเอียดหน่วยนับ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="unit-details">
                        <!-- Unit details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="button" class="btn btn-warning" id="editUnitBtn">
                        <i class="fas fa-edit me-1"></i>แก้ไข
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        let currentViewUnitId = null;
        
        // Load units on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadUnits();
        });
        
        function loadUnits() {
            const status = document.getElementById('filterStatus').value;
            const search = document.getElementById('searchUnit').value;
            
            fetch(`../../api/units.php?action=get_all&status=${status}&search=${encodeURIComponent(search)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayUnits(data.units);
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }
        
        function displayUnits(units) {
            const container = document.getElementById('unitsContainer');
            
            if (units.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">ไม่พบข้อมูลหน่วยนับ</p>
                    </div>
                `;
                return;
            }
            
            let html = '<div class="row">';
            
            units.forEach(unit => {
                const statusBadge = unit.status === 'active' 
                    ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>ใช้งานอยู่</span>'
                    : '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>ไม่ได้ใช้งาน</span>';
                
                const usageText = unit.material_count > 0 
                    ? `<span class="text-primary"><i class="fas fa-boxes me-1"></i>${unit.material_count} รายการ</span>`
                    : '<span class="text-muted">ยังไม่มีการใช้งาน</span>';
                
                html += `
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="unit-card">
                            <div class="d-flex align-items-start">
                                <div class="unit-icon me-3">
                                    ${unit.unit_code.toUpperCase()}
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">${unit.unit_name}</h6>
                                    ${unit.unit_name_en ? `<small class="text-muted">${unit.unit_name_en}</small>` : ''}
                                    <div class="mt-2">
                                        <code class="bg-light px-2 py-1 rounded">${unit.unit_code}</code>
                                    </div>
                                </div>
                            </div>
                            
                            ${unit.description ? `
                            <div class="mt-3">
                                <small class="text-muted">${unit.description}</small>
                            </div>
                            ` : ''}
                            
                            <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                                <div>
                                    ${statusBadge}
                                </div>
                                <div>
                                    ${usageText}
                                </div>
                            </div>
                            
                            <div class="mt-3 d-flex gap-2">
                                <button class="btn btn-info btn-sm flex-fill" onclick="viewUnit(${unit.unit_id})">
                                    <i class="fas fa-eye me-1"></i>ดู
                                </button>
                                ${unit.status === 'active' ? `
                                    <button class="btn btn-warning btn-sm flex-fill" onclick="editUnit(${unit.unit_id})">
                                        <i class="fas fa-edit me-1"></i>แก้ไข
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteUnit(${unit.unit_id}, '${unit.unit_code}')">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                ` : `
                                    <button class="btn btn-success btn-sm flex-fill" onclick="restoreUnit(${unit.unit_id})">
                                        <i class="fas fa-undo me-1"></i>กู้คืน
                                    </button>
                                `}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            container.innerHTML = html;
        }
        
        function showAddUnitModal() {
            document.getElementById('unitForm').reset();
            document.getElementById('unit_id').value = '';
            document.getElementById('action').value = 'create';
            document.getElementById('unitModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>เพิ่มหน่วยนับใหม่';
            document.getElementById('unit_code').readOnly = false;
            document.getElementById('statusSection').style.display = 'none';
            new bootstrap.Modal(document.getElementById('unitModal')).show();
        }
        
        function editUnit(unitId) {
            fetch(`../../api/units.php?action=get&id=${unitId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const unit = data.unit;
                        
                        document.getElementById('unitModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>แก้ไขหน่วยนับ';
                        document.getElementById('unit_id').value = unit.unit_id;
                        document.getElementById('action').value = 'update';
                        document.getElementById('unit_code').value = unit.unit_code;
                        document.getElementById('unit_name').value = unit.unit_name;
                        document.getElementById('unit_name_en').value = unit.unit_name_en || '';
                        document.getElementById('description').value = unit.description || '';
                        document.getElementById('status').value = unit.status;
                        
                        // Disable unit_code editing (เพราะอาจมีวัสดุใช้งานอยู่)
                        document.getElementById('unit_code').readOnly = true;
                        document.getElementById('statusSection').style.display = 'block';
                        
                        new bootstrap.Modal(document.getElementById('unitModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }
        
        function viewUnit(unitId) {
            currentViewUnitId = unitId;
            
            fetch(`../../api/units.php?action=get&id=${unitId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayUnitDetails(data.unit);
                        new bootstrap.Modal(document.getElementById('viewUnitModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }
        
        function displayUnitDetails(unit) {
            const statusBadge = unit.status === 'active' 
                ? '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>ใช้งานอยู่</span>'
                : '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>ไม่ได้ใช้งาน</span>';
            
            // Check usage
            fetch(`../../api/units.php?action=check_usage&unit_code=${unit.unit_code}`)
                .then(response => response.json())
                .then(data => {
                    let usageHtml = '';
                    
                    if (data.success && data.count > 0) {
                        usageHtml = `
                            <hr>
                            <h6>วัสดุที่ใช้หน่วยนับนี้ (${data.count} รายการ)</h6>
                            <div class="list-group">
                        `;
                        
                        data.materials.forEach(material => {
                            usageHtml += `
                                <div class="list-group-item">
                                    <strong>${material.part_code}</strong> - ${material.material_name}
                                </div>
                            `;
                        });
                        
                        usageHtml += '</div>';
                        
                        if (data.count > 10) {
                            usageHtml += `<p class="text-muted mt-2">... และอีก ${data.count - 10} รายการ</p>`;
                        }
                    } else {
                        usageHtml = `
                            <hr>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                ยังไม่มีวัสดุที่ใช้หน่วยนับนี้
                            </div>
                        `;
                    }
                    
                    const detailsHtml = `
                        <div class="text-center mb-4">
                            <div class="unit-icon" style="width: 80px; height: 80px; font-size: 2rem; margin: 0 auto 20px;">
                                ${unit.unit_code.toUpperCase()}
                            </div>
                            <h4>${unit.unit_name}</h4>
                            ${unit.unit_name_en ? `<p class="text-muted">${unit.unit_name_en}</p>` : ''}
                            ${statusBadge}
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">รหัสหน่วยนับ</label>
                                <div><code class="bg-light px-2 py-1 rounded">${unit.unit_code}</code></div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="text-muted small">วันที่สร้าง</label>
                                <div><i class="fas fa-calendar me-2 text-primary"></i>${new Date(unit.created_at).toLocaleDateString('th-TH')}</div>
                            </div>
                            ${unit.description ? `
                            <div class="col-12 mb-3">
                                <label class="text-muted small">รายละเอียด</label>
                                <div class="alert alert-light">${unit.description}</div>
                            </div>
                            ` : ''}
                        </div>
                        
                        ${usageHtml}
                    `;
                    
                    document.getElementById('unit-details').innerHTML = detailsHtml;
                });
            
            // Setup edit button
            document.getElementById('editUnitBtn').onclick = () => {
                bootstrap.Modal.getInstance(document.getElementById('viewUnitModal')).hide();
                editUnit(currentViewUnitId);
            };
        }
        
        function deleteUnit(unitId, unitCode) {
            // Check usage first
            fetch(`../../api/units.php?action=check_usage&unit_code=${unitCode}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.count > 0) {
                        Swal.fire({
                            title: 'ไม่สามารถลบได้',
                            html: `หน่วยนับนี้กำลังถูกใช้งานโดยวัสดุ <strong>${data.count}</strong> รายการ<br><br>กรุณาเปลี่ยนหน่วยนับของวัสดุเหล่านั้นก่อนจึงจะลบได้`,
                            icon: 'warning',
                            confirmButtonText: 'รับทราบ'
                        });
                    } else {
                        Swal.fire({
                            title: 'ยืนยันการลบ?',
                            text: 'คุณต้องการลบหน่วยนับนี้หรือไม่?',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#dc3545',
                            cancelButtonColor: '#6c757d',
                            confirmButtonText: '<i class="fas fa-trash me-1"></i>ลบ',
                            cancelButtonText: 'ยกเลิก'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                performDelete(unitId);
                            }
                        });
                    }
                });
        }
        
        function performDelete(unitId) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('unit_id', unitId);
            
            fetch('../../api/units.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', data.message, 'success');
                    loadUnits();
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถลบได้', 'error');
            });
        }
        
        function restoreUnit(unitId) {
            Swal.fire({
                title: 'ยืนยันการกู้คืน?',
                text: 'คุณต้องการกู้คืนหน่วยนับนี้หรือไม่?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-undo me-1"></i>กู้คืน',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'restore');
                    formData.append('unit_id', unitId);
                    
                    fetch('../../api/units.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('สำเร็จ', data.message, 'success');
                            loadUnits();
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถกู้คืนได้', 'error');
                    });
                }
            });
        }
        
        // Form Submission
        document.getElementById('unitForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            // Validate unit_code format (lowercase letters only)
            const unitCode = document.getElementById('unit_code').value;
            if (!/^[a-z0-9]+$/.test(unitCode)) {
                Swal.fire('รหัสไม่ถูกต้อง', 'กรุณาใช้ตัวอักษรภาษาอังกฤษพิมพ์เล็กและตัวเลขเท่านั้น', 'error');
                return;
            }
            
            Swal.fire({
                title: 'กำลังบันทึก...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('../../api/units.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', data.message, 'success').then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('unitModal')).hide();
                        loadUnits();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถบันทึกข้อมูลได้', 'error');
            });
        });
    </script>

</body>
</html>