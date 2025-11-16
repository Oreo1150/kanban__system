<?php
// pages/admin/materials.php - Fixed version
$page_title = 'จัดการวัสดุและสต็อก';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'จัดการวัสดุ']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงสถิติวัสดุ
$stats = $db->query("
    SELECT 
        COUNT(*) as total_materials,
        COUNT(CASE WHEN current_stock < 50000 THEN 1 END) as low_stock_count,
        COUNT(CASE WHEN current_stock > 100000 THEN 1 END) as overstock_count,
        COUNT(CASE WHEN current_stock >= 50000 AND current_stock <= 100000 THEN 1 END) as normal_count
    FROM materials 
    WHERE status = 'active'
")->fetch();
?>

<style>
    .material-card {
        transition: all 0.3s ease;
        border-left: 4px solid #e9ecef;
    }
    
    .material-card.low-stock {
        border-left-color: #dc3545;
        background: #fff5f5;
    }
    
    .material-card.overstock {
        border-left-color: #ffc107;
        background: #fffef5;
    }
    
    .material-card.normal {
        border-left-color: #28a745;
    }
    
    .stock-bar {
        height: 8px;
        background: #e9ecef;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .stock-bar-fill {
        height: 100%;
        transition: width 0.3s ease;
    }
    
    .stock-indicator {
        width: 15px;
        height: 15px;
        border-radius: 50%;
        display: inline-block;
    }
</style>

            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stats-card text-center">
                        <i class="fas fa-boxes icon"></i>
                        <div class="number"><?= number_format($stats['total_materials']) ?></div>
                        <div class="label">วัสดุทั้งหมด</div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #dc3545, #fd7e14);">
                        <i class="fas fa-exclamation-triangle icon"></i>
                        <div class="number"><?= number_format($stats['low_stock_count']) ?></div>
                        <div class="label">สต็อกต่ำ (< 50,000)</div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <i class="fas fa-check-circle icon"></i>
                        <div class="number"><?= number_format($stats['normal_count']) ?></div>
                        <div class="label">ระดับปกติ</div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #ffc107, #ff8c00);">
                        <i class="fas fa-arrow-up icon"></i>
                        <div class="number"><?= number_format($stats['overstock_count']) ?></div>
                        <div class="label">สต็อกเกิน (> 100,000)</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-boxes me-2"></i>รายการวัสดุทั้งหมด</h5>
                    <div>
                        <button class="btn btn-warning me-2" onclick="checkLowStock()">
                            <i class="fas fa-bell me-1"></i>ตรวจสอบสต็อกต่ำ
                        </button>
                        <button class="btn btn-primary" onclick="showAddMaterialModal()">
                            <i class="fas fa-plus me-1"></i>เพิ่มวัสดุใหม่
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table id="materialsTable" class="table table-hover">
                            <thead>
                                <tr>
                                    <th>สถานะ</th>
                                    <th>รหัสวัสดุ</th>
                                    <th>ชื่อวัสดุ</th>
                                    <th>สต็อกปัจจุบัน</th>
                                    <th>ระดับสต็อก</th>
                                    <th>หน่วย</th>
                                    <th>ที่เก็บ</th>
                                    <th>การจัดการ</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Add/Edit Material Modal -->
    <div class="modal fade" id="materialModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="materialModalTitle">เพิ่มวัสดุใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="materialForm">
                    <div class="modal-body">
                        <input type="hidden" id="material_id" name="material_id">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="part_code" class="form-label">
                                        รหัสวัสดุ <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="part_code" name="part_code" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="unit" class="form-label">
                                        หน่วย <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control" id="unit" name="unit" required>
                                        <option value="">เลือกหน่วย</option>
                                        <option value="pcs">ชิ้น (pcs)</option>
                                        <option value="box">กล่อง (box)</option>
                                        <option value="pack">แพ็ค (pack)</option>
                                        <option value="kg">กิโลกรัม (kg)</option>
                                        <option value="m">เมตร (m)</option>
                                        <option value="sheet">แผ่น (sheet)</option>
                                        <option value="roll">ม้วน (roll)</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="material_name" class="form-label">
                                ชื่อวัสดุ <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="material_name" name="material_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">รายละเอียด</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="current_stock" class="form-label">สต็อกปัจจุบัน</label>
                                    <input type="number" class="form-control" id="current_stock" name="current_stock" value="0" min="0">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="min_stock" class="form-label">
                                        สต็อกต่ำสุด <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="min_stock" name="min_stock" value="50000" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="max_stock" class="form-label">
                                        สต็อกสูงสุด <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" class="form-control" id="max_stock" name="max_stock" value="100000" min="0" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="location" class="form-label">ที่เก็บ</label>
                            <input type="text" class="form-control" id="location" name="location" placeholder="เช่น: ชั้น A-01">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Material Modal -->
    <div class="modal fade" id="viewMaterialModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-eye me-2"></i>รายละเอียดวัสดุ
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="materialDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="button" class="btn btn-warning" id="editFromViewBtn">
                        <i class="fas fa-edit me-1"></i>แก้ไข
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        let materialsTable;
        let currentViewMaterialId = null;
        
        $(document).ready(function() {
            initDataTable();
        });
        
        function initDataTable() {
            materialsTable = $('#materialsTable').DataTable({
                processing: true,
                serverSide: false,
                ajax: {
                    url: '../../api/materials.php?action=get_all',
                    dataSrc: 'materials'
                },
                columns: [
                    {
                        data: null,
                        render: function(data) {
                            let status, icon, color;
                            if (data.current_stock < 50000) {
                                status = 'low';
                                icon = 'exclamation-triangle';
                                color = '#dc3545';
                            } else if (data.current_stock > 100000) {
                                status = 'overstock';
                                icon = 'arrow-up';
                                color = '#ffc107';
                            } else {
                                status = 'normal';
                                icon = 'check-circle';
                                color = '#28a745';
                            }
                            return `<span class="stock-indicator" style="background: ${color}" title="${status}"></span>`;
                        }
                    },
                    { data: 'part_code', render: data => `<strong>${data}</strong>` },
                    { data: 'material_name' },
                    {
                        data: null,
                        render: function(data) {
                            const percentage = (data.current_stock / 100000) * 100;
                            let barColor = '#28a745';
                            if (data.current_stock < 50000) barColor = '#dc3545';
                            else if (data.current_stock > 100000) barColor = '#ffc107';
                            
                            return `
                                <div>
                                    <strong>${data.current_stock.toLocaleString()}</strong>
                                    <div class="stock-bar mt-1">
                                        <div class="stock-bar-fill" style="width: ${Math.min(percentage, 100)}%; background: ${barColor}"></div>
                                    </div>
                                </div>
                            `;
                        }
                    },
                    {
                        data: null,
                        render: function(data) {
                            return `
                                <small>
                                    Min: ${data.min_stock.toLocaleString()}<br>
                                    Max: ${data.max_stock.toLocaleString()}
                                </small>
                            `;
                        }
                    },
                    { data: 'unit' },
                    { data: 'location', defaultContent: '-' },
                    {
                        data: null,
                        orderable: false,
                        render: function(data) {
                            return `
                                <div class="btn-group" role="group">
                                    <button class="btn btn-info btn-sm" onclick="viewMaterial(${data.material_id})" title="ดูรายละเอียด">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-warning btn-sm" onclick="editMaterial(${data.material_id})" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteMaterial(${data.material_id})" title="ลบ">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            `;
                        }
                    }
                ],
                order: [[3, 'asc']], // Sort by current_stock ascending
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/th.json'
                },
                pageLength: 25,
                drawCallback: function() {
                    // Re-initialize tooltips after table draw
                    $('[title]').tooltip();
                }
            });
        }
        
        function showAddMaterialModal() {
            document.getElementById('materialForm').reset();
            document.getElementById('material_id').value = '';
            document.getElementById('materialModalTitle').textContent = 'เพิ่มวัสดุใหม่';
            document.getElementById('min_stock').value = '50000';
            document.getElementById('max_stock').value = '100000';
            document.getElementById('current_stock').value = '0';
            document.getElementById('part_code').readOnly = false;
            new bootstrap.Modal(document.getElementById('materialModal')).show();
        }
        
        // **FIX: Add viewMaterial function**
        function viewMaterial(materialId) {
            currentViewMaterialId = materialId;
            
            fetch(`../../api/materials.php?action=get_by_id&id=${materialId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayMaterialDetails(data.material);
                        new bootstrap.Modal(document.getElementById('viewMaterialModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }
        
        function displayMaterialDetails(material) {
            let stockStatus, stockColor;
            if (material.current_stock < material.min_stock) {
                stockStatus = 'สต็อกต่ำ';
                stockColor = 'danger';
            } else if (material.current_stock > material.max_stock) {
                stockStatus = 'สต็อกเกิน';
                stockColor = 'warning';
            } else {
                stockStatus = 'ปกติ';
                stockColor = 'success';
            }
            
            const percentage = (material.current_stock / material.max_stock) * 100;
            
            const html = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>รหัสวัสดุ:</h6>
                        <p class="text-primary"><strong>${material.part_code}</strong></p>
                    </div>
                    <div class="col-md-6">
                        <h6>สถานะสต็อก:</h6>
                        <p><span class="badge bg-${stockColor}">${stockStatus}</span></p>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <h6>ชื่อวัสดุ:</h6>
                        <p>${material.material_name}</p>
                    </div>
                </div>
                
                ${material.description ? `
                <div class="row">
                    <div class="col-md-12">
                        <h6>รายละเอียด:</h6>
                        <p>${material.description}</p>
                    </div>
                </div>
                ` : ''}
                
                <hr>
                
                <div class="row">
                    <div class="col-md-3">
                        <h6>สต็อกปัจจุบัน:</h6>
                        <p class="text-${stockColor}"><strong>${material.current_stock.toLocaleString()}</strong> ${material.unit}</p>
                    </div>
                    <div class="col-md-3">
                        <h6>สต็อกต่ำสุด:</h6>
                        <p>${material.min_stock.toLocaleString()} ${material.unit}</p>
                    </div>
                    <div class="col-md-3">
                        <h6>สต็อกสูงสุด:</h6>
                        <p>${material.max_stock.toLocaleString()} ${material.unit}</p>
                    </div>
                    <div class="col-md-3">
                        <h6>หน่วย:</h6>
                        <p>${material.unit}</p>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <h6>ระดับสต็อก:</h6>
                        <div class="progress" style="height: 25px;">
                            <div class="progress-bar bg-${stockColor}" style="width: ${percentage}%">
                                ${percentage.toFixed(1)}%
                            </div>
                        </div>
                        <small class="text-muted">
                            ${material.current_stock.toLocaleString()} / ${material.max_stock.toLocaleString()} ${material.unit}
                        </small>
                    </div>
                </div>
                
                ${material.location ? `
                <hr>
                <div class="row">
                    <div class="col-md-12">
                        <h6>ที่เก็บ:</h6>
                        <p><i class="fas fa-map-marker-alt text-danger"></i> ${material.location}</p>
                    </div>
                </div>
                ` : ''}
            `;
            
            document.getElementById('materialDetailsContent').innerHTML = html;
            
            // Setup edit button
            document.getElementById('editFromViewBtn').onclick = () => {
                bootstrap.Modal.getInstance(document.getElementById('viewMaterialModal')).hide();
                editMaterial(currentViewMaterialId);
            };
        }
        
        function editMaterial(materialId) {
            fetch(`../../api/materials.php?action=get_by_id&id=${materialId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const material = data.material;
                        document.getElementById('materialModalTitle').textContent = 'แก้ไขวัสดุ';
                        document.getElementById('material_id').value = material.material_id;
                        document.getElementById('part_code').value = material.part_code;
                        document.getElementById('material_name').value = material.material_name;
                        document.getElementById('description').value = material.description || '';
                        document.getElementById('unit').value = material.unit;
                        document.getElementById('current_stock').value = material.current_stock;
                        document.getElementById('min_stock').value = material.min_stock;
                        document.getElementById('max_stock').value = material.max_stock;
                        document.getElementById('location').value = material.location || '';
                        
                        // Disable part_code editing
                        document.getElementById('part_code').readOnly = true;
                        
                        new bootstrap.Modal(document.getElementById('materialModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }
        
        function deleteMaterial(materialId) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: 'คุณต้องการลบวัสดุนี้หรือไม่?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('material_id', materialId);
                    
                    fetch('../../api/materials.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('สำเร็จ', data.message, 'success');
                            materialsTable.ajax.reload();
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                        }
                    });
                }
            });
        }
        
        // Submit Form
        document.getElementById('materialForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const materialId = formData.get('material_id');
            formData.append('action', materialId ? 'update' : 'create');
            
            fetch('../../api/materials.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('materialModal')).hide();
                    materialsTable.ajax.reload();
                    
                    // Re-enable part_code field
                    document.getElementById('part_code').readOnly = false;
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถบันทึกข้อมูลได้', 'error');
            });
        });
        
        function checkLowStock() {
            fetch('../../api/materials.php?action=get_all&filter=low_stock')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.materials.length > 0) {
                        let listHtml = '<ul class="list-group">';
                        data.materials.forEach(material => {
                            const need = 50000 - material.current_stock;
                            listHtml += `
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>${material.part_code}</strong> - ${material.material_name}
                                        <br><small>คงเหลือ: ${material.current_stock.toLocaleString()} ${material.unit}</small>
                                    </div>
                                    <span class="badge bg-danger rounded-pill">
                                        ต้องการ ${need.toLocaleString()}
                                    </span>
                                </li>
                            `;
                        });
                        listHtml += '</ul>';
                        
                        Swal.fire({
                            title: `<i class="fas fa-exclamation-triangle text-warning"></i> วัสดุที่สต็อกต่ำ`,
                            html: `
                                <p>พบวัสดุที่สต็อกต่ำกว่า 50,000 ชิ้น จำนวน <strong>${data.materials.length}</strong> รายการ</p>
                                ${listHtml}
                            `,
                            icon: 'warning',
                            width: '600px',
                            confirmButtonText: 'รับทราบ'
                        });
                    } else {
                        Swal.fire('ดีมาก!', 'ไม่มีวัสดุที่สต็อกต่ำ', 'success');
                    }
                });
        }
    </script>

</body>
</html>