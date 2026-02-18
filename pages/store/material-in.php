<?php
// pages/store/material-in.php
$page_title = 'รับวัสดุเข้า';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'รับวัสดุเข้า']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['store', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงรายการล่าสุด
$recent_in = $db->query("
    SELECT it.*, m.part_code, m.material_name, m.unit, u.full_name
    FROM inventory_transactions it
    LEFT JOIN materials m ON it.material_id = m.material_id
    LEFT JOIN users u ON it.transaction_by = u.user_id
    WHERE it.transaction_type = 'in'
    ORDER BY it.transaction_date DESC
    LIMIT 20
")->fetchAll();

// ดึงรายการวัสดุ
$materials = $db->query("
    SELECT material_id, part_code, material_name, unit, current_stock 
    FROM materials 
    WHERE status = 'active'
    ORDER BY part_code ASC
")->fetchAll();
?>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body text-center py-5">
                            <h4 class="mb-3">ฟังก์ชัน "รับวัสดุเข้า" ถูกปิดใช้งาน</h4>
                            <p class="text-muted mb-4">หน้านี้ไม่พร้อมใช้งานในระบบของคุณ หากต้องการเปิดใช้งาน ติดต่อผู้ดูแลระบบหรือคืนค่าเมนูจากการตั้งค่า</p>
                            <a href="inventory.php" class="btn btn-primary">กลับไปที่ สินค้าคงเหลือ</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Get material data when selected
        document.getElementById('material_select').addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            document.getElementById('current_stock').value = option.dataset.stock ? Number(option.dataset.stock).toLocaleString() : '';
            
            // Get unit and other info
            if (this.value) {
                fetch(`../../api/materials.php?action=get&id=${this.value}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('unit').value = data.material.unit;
                        }
                    });
            }
        });

        // Submit form
        document.getElementById('materialInForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const materialSelect = document.getElementById('material_select');
            const materialName = materialSelect.options[materialSelect.selectedIndex].textContent;
            const quantity = document.querySelector('[name="quantity"]').value;
            
            Swal.fire({
                title: 'ยืนยันรับวัสดุเข้า?',
                html: `
                    <strong>${materialName}</strong><br>
                    จำนวน: <strong>${quantity}</strong>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData(this);
                    formData.append('action', 'create');
                    formData.append('transaction_type', 'in');
                    
                    fetch('../../api/inventory.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('สำเร็จ', data.message, 'success').then(() => {
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
                }
            });
        });

        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }
    </script>

</body>
</html>
