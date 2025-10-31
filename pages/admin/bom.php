<?php
// pages/admin/bom.php
$page_title = 'จัดการ BOM (Bill of Materials)';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'จัดการ BOM']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['admin', 'planning']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลสินค้าทั้งหมด
$products = $db->query("
    SELECT p.*, 
           (SELECT COUNT(*) FROM bom_header bh WHERE bh.product_id = p.product_id AND bh.status = 'active') as has_bom
    FROM products p 
    WHERE p.status = 'active' 
    ORDER BY p.product_name
")->fetchAll();

// ดึงข้อมูลวัสดุทั้งหมด
$materials = $db->query("
    SELECT * FROM materials 
    WHERE status = 'active' 
    ORDER BY part_code
")->fetchAll();
?>

<style>
    .product-bom-card {
        transition: all 0.3s ease;
        border: 2px solid #e9ecef;
        cursor: pointer;
    }
    
    .product-bom-card:hover {
        border-color: var(--primary-color);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        transform: translateY(-2px);
    }
    
    .bom-badge {
        position: absolute;
        top: 10px;
        right: 10px;
    }
    
    .material-row {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        border-left: 4px solid var(--primary-color);
    }
    
    .material-row:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .add-material-btn {
        border: 2px dashed #dee2e6;
        background: transparent;
        padding: 20px;
        width: 100%;
        border-radius: 10px;
        transition: all 0.3s ease;
    }
    
    .add-material-btn:hover {
        border-color: var(--primary-color);
        background: rgba(102, 126, 234, 0.05);
    }
    
    .material-selector {
        display: none;
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-list-alt me-2"></i>รายการสินค้าและ BOM</h5>
                            <div>
                                <button class="btn btn-primary" onclick="showCreateBOMModal()">
                                    <i class="fas fa-plus me-1"></i>สร้าง BOM ใหม่
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($products as $product): ?>
                                    <div class="col-lg-4 col-md-6 mb-4">
                                        <div class="product-bom-card card h-100" onclick="viewBOM(<?= $product['product_id'] ?>)">
                                            <div class="card-body position-relative">
                                                <?php if ($product['has_bom']): ?>
                                                    <span class="bom-badge badge bg-success">
                                                        <i class="fas fa-check"></i> มี BOM
                                                    </span>
                                                <?php else: ?>
                                                    <span class="bom-badge badge bg-warning">
                                                        <i class="fas fa-exclamation-triangle"></i> ยังไม่มี BOM
                                                    </span>
                                                <?php endif; ?>
                                                
                                                <div class="text-center mb-3">
                                                    <i class="fas fa-cube fa-3x text-primary mb-3"></i>
                                                    <h6><?= htmlspecialchars($product['product_name']) ?></h6>
                                                    <small class="text-muted"><?= htmlspecialchars($product['product_code']) ?></small>
                                                </div>
                                                
                                                <?php if ($product['description']): ?>
                                                    <p class="text-muted small mb-0">
                                                        <?= htmlspecialchars(mb_substr($product['description'], 0, 80)) ?>
                                                        <?= mb_strlen($product['description']) > 80 ? '...' : '' ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card-footer bg-transparent">
                                                <div class="d-flex justify-content-between">
                                                    <?php if ($product['has_bom']): ?>
                                                        <button class="btn btn-info btn-sm" onclick="event.stopPropagation(); viewBOM(<?= $product['product_id'] ?>)">
                                                            <i class="fas fa-eye"></i> ดู BOM
                                                        </button>
                                                        <button class="btn btn-warning btn-sm" onclick="event.stopPropagation(); editBOM(<?= $product['product_id'] ?>)">
                                                            <i class="fas fa-edit"></i> แก้ไข
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-primary btn-sm w-100" onclick="event.stopPropagation(); createBOMForProduct(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['product_name']) ?>', '<?= htmlspecialchars($product['product_code']) ?>')">
                                                            <i class="fas fa-plus"></i> สร้าง BOM
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Create/Edit BOM Modal -->
    <div class="modal fade" id="bomModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bomModalTitle">
                        <i class="fas fa-list-alt me-2"></i>จัดการ BOM
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="bomForm">
                    <div class="modal-body">
                        <input type="hidden" id="bom_id" name="bom_id">
                        <input type="hidden" id="product_id" name="product_id">
                        
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <label class="form-label">ชื่อสินค้า <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="product_name_input" 
                                       name="product_name" placeholder="เช่น: กล่องกระดาษ A4" required>
                                <small class="text-muted">กรอกชื่อสินค้าที่ต้องการสร้าง BOM</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">รหัสสินค้า <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="product_code_input" 
                                       name="product_code" placeholder="เช่น: PROD-001" required>
                                <small class="text-muted">กรอกรหัสสินค้า</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">เวอร์ชัน BOM</label>
                                <input type="text" class="form-control" id="bom_version" name="version" value="1.0" required>
                                <small class="text-muted">เช่น: 1.0, 1.1, 2.0</small>
                            </div>
                        </div>
                        
                        <!-- Product Info Display -->
                        <div id="selected_product_info" class="alert alert-info" style="display: none;">
                            <i class="fas fa-info-circle me-2"></i>
                            กำลังสร้าง BOM สำหรับ: <strong id="display_product_name"></strong> (<span id="display_product_code"></span>)
                        </div>
                        
                        <hr>
                        
                        <h6 class="mb-3">
                            <i class="fas fa-boxes me-2"></i>รายการวัสดุที่ใช้
                        </h6>
                        
                        <div id="materials-list">
                            <!-- Material rows will be added here -->
                        </div>
                        
                        <button type="button" class="add-material-btn" onclick="showMaterialSelector()">
                            <i class="fas fa-plus-circle fa-2x d-block mb-2 text-primary"></i>
                            <strong>เพิ่มวัสดุ</strong>
                        </button>
                        
                        <!-- Material Selector -->
                        <div id="material-selector" class="material-selector mt-3">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0">เลือกวัสดุที่ต้องการเพิ่ม</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label class="form-label">วัสดุ</label>
                                            <select class="form-select" id="selected_material">
                                                <option value="">เลือกวัสดุ</option>
                                                <?php foreach ($materials as $material): ?>
                                                    <option value="<?= $material['material_id'] ?>" 
                                                            data-code="<?= htmlspecialchars($material['part_code']) ?>"
                                                            data-name="<?= htmlspecialchars($material['material_name']) ?>"
                                                            data-unit="<?= htmlspecialchars($material['unit']) ?>">
                                                        <?= htmlspecialchars($material['part_code']) ?> - <?= htmlspecialchars($material['material_name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">จำนวนต่อหน่วย</label>
                                            <input type="number" class="form-control" id="material_quantity" step="0.01" min="0" placeholder="0.00">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label">&nbsp;</label>
                                            <div class="d-flex gap-2">
                                                <button type="button" class="btn btn-success" onclick="addMaterial()">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-secondary" onclick="hideMaterialSelector()">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div id="no-materials-message" class="alert alert-info mt-3" style="display: none;">
                            <i class="fas fa-info-circle me-2"></i>
                            ยังไม่มีรายการวัสดุ กรุณาเพิ่มวัสดุที่ใช้ในการผลิต
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>ยกเลิก
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>บันทึก BOM
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View BOM Modal -->
    <div class="modal fade" id="viewBOMModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-eye me-2"></i>รายละเอียด BOM
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="bom-details">
                        <!-- BOM details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="button" class="btn btn-warning" id="editBOMBtn">
                        <i class="fas fa-edit me-1"></i>แก้ไข
                    </button>
                    <button type="button" class="btn btn-danger" id="deleteBOMBtn">
                        <i class="fas fa-trash me-1"></i>ลบ BOM
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        let bomMaterials = [];
        let currentProductId = null;
        let currentBomId = null;
        
        function showCreateBOMModal() {
            currentProductId = null;
            currentBomId = null;
            bomMaterials = [];
            
            document.getElementById('bomModalTitle').textContent = 'สร้าง BOM ใหม่';
            document.getElementById('bomForm').reset();
            document.getElementById('product_name_input').readOnly = false;
            document.getElementById('product_code_input').readOnly = false;
            document.getElementById('bom_version').value = '1.0';
            document.getElementById('materials-list').innerHTML = '';
            document.getElementById('selected_product_info').style.display = 'none';
            
            updateMaterialsList();
            new bootstrap.Modal(document.getElementById('bomModal')).show();
        }
        
        function createBOMForProduct(productId, productName, productCode) {
            currentProductId = productId;
            currentBomId = null;
            bomMaterials = [];
            
            document.getElementById('bomModalTitle').textContent = 'สร้าง BOM ใหม่';
            document.getElementById('product_id').value = productId;
            document.getElementById('product_name_input').value = productName;
            document.getElementById('product_code_input').value = productCode;
            document.getElementById('bom_version').value = '1.0';
            
            // แสดง info banner
            document.getElementById('display_product_name').textContent = productName;
            document.getElementById('display_product_code').textContent = productCode;
            document.getElementById('selected_product_info').style.display = 'block';
            
            // ล็อคช่องกรอกชื่อและรหัส
            document.getElementById('product_name_input').readOnly = true;
            document.getElementById('product_code_input').readOnly = true;
            
            document.getElementById('materials-list').innerHTML = '';
            updateMaterialsList();
            new bootstrap.Modal(document.getElementById('bomModal')).show();
        }
        
        function showMaterialSelector() {
            document.getElementById('material-selector').style.display = 'block';
            document.getElementById('selected_material').focus();
        }
        
        function hideMaterialSelector() {
            document.getElementById('material-selector').style.display = 'none';
            document.getElementById('selected_material').value = '';
            document.getElementById('material_quantity').value = '';
        }
        
        function addMaterial() {
            const materialSelect = document.getElementById('selected_material');
            const quantity = parseFloat(document.getElementById('material_quantity').value);
            
            if (!materialSelect.value) {
                Swal.fire('กรุณาเลือกวัสดุ', '', 'warning');
                return;
            }
            
            if (!quantity || quantity <= 0) {
                Swal.fire('กรุณาระบุจำนวน', 'จำนวนต้องมากกว่า 0', 'warning');
                return;
            }
            
            const selectedOption = materialSelect.options[materialSelect.selectedIndex];
            const materialId = materialSelect.value;
            
            // Check if material already exists
            if (bomMaterials.some(m => m.material_id == materialId)) {
                Swal.fire('วัสดุซ้ำ', 'วัสดุนี้ถูกเพิ่มไปแล้ว', 'warning');
                return;
            }
            
            const material = {
                material_id: materialId,
                part_code: selectedOption.dataset.code,
                material_name: selectedOption.dataset.name,
                unit: selectedOption.dataset.unit,
                quantity_per_unit: quantity
            };
            
            bomMaterials.push(material);
            updateMaterialsList();
            hideMaterialSelector();
        }
        
        function removeMaterial(index) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: 'คุณต้องการลบวัสดุนี้ออกจาก BOM หรือไม่?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    bomMaterials.splice(index, 1);
                    updateMaterialsList();
                }
            });
        }
        
        function updateMaterialsList() {
            const container = document.getElementById('materials-list');
            const noMaterialsMsg = document.getElementById('no-materials-message');
            
            if (bomMaterials.length === 0) {
                container.innerHTML = '';
                noMaterialsMsg.style.display = 'block';
                return;
            }
            
            noMaterialsMsg.style.display = 'none';
            
            let html = '';
            bomMaterials.forEach((material, index) => {
                html += `
                    <div class="material-row">
                        <div class="row align-items-center">
                            <div class="col-md-1 text-center">
                                <strong>${index + 1}</strong>
                            </div>
                            <div class="col-md-4">
                                <strong>${material.part_code}</strong><br>
                                <small class="text-muted">${material.material_name}</small>
                            </div>
                            <div class="col-md-3">
                                <div class="input-group">
                                    <input type="number" class="form-control" value="${material.quantity_per_unit}" 
                                           step="0.01" min="0"
                                           onchange="updateMaterialQuantity(${index}, this.value)">
                                    <span class="input-group-text">${material.unit}/ชิ้น</span>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <small class="text-muted">
                                    ผลิต 100 ชิ้น ใช้ ${(material.quantity_per_unit * 100).toLocaleString()} ${material.unit}
                                </small>
                            </div>
                            <div class="col-md-1 text-end">
                                <button type="button" class="btn btn-danger btn-sm" onclick="removeMaterial(${index})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        function updateMaterialQuantity(index, newValue) {
            const quantity = parseFloat(newValue);
            if (quantity > 0) {
                bomMaterials[index].quantity_per_unit = quantity;
                updateMaterialsList();
            }
        }
        
        // Submit BOM Form
        document.getElementById('bomForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (bomMaterials.length === 0) {
                Swal.fire('กรุณาเพิ่มวัสดุ', 'ต้องมีวัสดุอย่างน้อย 1 รายการ', 'warning');
                return;
            }
            
            const productName = document.getElementById('product_name_input').value.trim();
            const productCode = document.getElementById('product_code_input').value.trim();
            
            if (!productName || !productCode) {
                Swal.fire('กรุณากรอกข้อมูลสินค้า', 'ต้องระบุชื่อและรหัสสินค้า', 'warning');
                return;
            }
            
            const formData = new FormData(this);
            formData.append('action', currentBomId ? 'update' : 'create');
            formData.append('materials', JSON.stringify(bomMaterials));
            
            if (currentProductId) {
                formData.append('product_id', currentProductId);
            }
            
            if (currentBomId) {
                formData.append('bom_id', currentBomId);
            }
            
            Swal.fire({
                title: 'กำลังบันทึก...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('../../api/bom.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', data.message, 'success').then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('bomModal')).hide();
                        location.reload();
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
        
        function viewBOM(productId) {
            fetch(`../../api/bom.php?action=get_bom&product_id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayBOMDetails(data.bom);
                        new bootstrap.Modal(document.getElementById('viewBOMModal')).show();
                    } else {
                        Swal.fire('ไม่พบ BOM', data.message, 'info');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }
        
        function displayBOMDetails(bom) {
            currentProductId = bom.product_id;
            currentBomId = bom.bom_id;
            
            let detailsHtml = `
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h6>สินค้า:</h6>
                        <p class="text-primary"><strong>${bom.product_name}</strong> (${bom.product_code})</p>
                    </div>
                    <div class="col-md-3">
                        <h6>เวอร์ชัน:</h6>
                        <p><span class="badge bg-info">${bom.version}</span></p>
                    </div>
                    <div class="col-md-3">
                        <h6>สร้างเมื่อ:</h6>
                        <p><small>${new Date(bom.created_at).toLocaleDateString('th-TH')}</small></p>
                    </div>
                </div>
                
                <hr>
                
                <h6 class="mb-3">รายการวัสดุที่ใช้:</h6>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>รหัสวัสดุ</th>
                                <th>ชื่อวัสดุ</th>
                                <th>จำนวนต่อหน่วย</th>
                                <th>สต็อกปัจจุบัน</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            bom.details.forEach((detail, index) => {
                detailsHtml += `
                    <tr>
                        <td>${index + 1}</td>
                        <td><strong>${detail.part_code}</strong></td>
                        <td>${detail.material_name}</td>
                        <td>${detail.quantity_per_unit} ${detail.unit}/ชิ้น</td>
                        <td>
                            <span class="badge ${detail.current_stock > 0 ? 'bg-success' : 'bg-danger'}">
                                ${detail.current_stock.toLocaleString()} ${detail.unit}
                            </span>
                        </td>
                    </tr>
                `;
            });
            
            detailsHtml += `
                        </tbody>
                    </table>
                </div>
            `;
            
            document.getElementById('bom-details').innerHTML = detailsHtml;
            
            // Setup edit button
            document.getElementById('editBOMBtn').onclick = () => {
                bootstrap.Modal.getInstance(document.getElementById('viewBOMModal')).hide();
                editBOM(productId);
            };
            
            // Setup delete button
            document.getElementById('deleteBOMBtn').onclick = () => {
                deleteBOM(bom.bom_id);
            };
        }
        
        function editBOM(productId) {
            fetch(`../../api/bom.php?action=get_bom&product_id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const bom = data.bom;
                        currentProductId = bom.product_id;
                        currentBomId = bom.bom_id;
                        
                        document.getElementById('bomModalTitle').textContent = 'แก้ไข BOM';
                        document.getElementById('product_id').value = bom.product_id;
                        document.getElementById('product_name_input').value = bom.product_name;
                        document.getElementById('product_code_input').value = bom.product_code;
                        document.getElementById('bom_version').value = bom.version;
                        document.getElementById('bom_id').value = bom.bom_id;
                        
                        // ล็อคช่องกรอก
                        document.getElementById('product_name_input').readOnly = true;
                        document.getElementById('product_code_input').readOnly = true;
                        
                        // โหลดวัสดุ
                        bomMaterials = bom.details.map(detail => ({
                            material_id: detail.material_id,
                            part_code: detail.part_code,
                            material_name: detail.material_name,
                            unit: detail.unit,
                            quantity_per_unit: parseFloat(detail.quantity_per_unit)
                        }));
                        
                        updateMaterialsList();
                        new bootstrap.Modal(document.getElementById('bomModal')).show();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }
        
        function deleteBOM(bomId) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: 'การลบ BOM จะทำให้ไม่สามารถคำนวณวัสดุอัตโนมัติได้',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ลบ BOM',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('bom_id', bomId);
                    
                    fetch('../../api/bom.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('สำเร็จ', 'ลบ BOM เรียบร้อยแล้ว', 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถลบข้อมูลได้', 'error');
                    });
                }
            });
        }
    </script>

</body>
</html>