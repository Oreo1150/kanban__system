<?php
// pages/admin/users.php
$page_title = 'จัดการผู้ใช้งาน';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'จัดการผู้ใช้งาน']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงสถิติผู้ใช้งาน
$stats = $db->query("
    SELECT 
        COUNT(*) as total_users,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active_users,
        COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_users,
        COUNT(CASE WHEN role = 'admin' THEN 1 END) as admin_count,
        COUNT(CASE WHEN role = 'planning' THEN 1 END) as planning_count,
        COUNT(CASE WHEN role = 'production' THEN 1 END) as production_count,
        COUNT(CASE WHEN role = 'store' THEN 1 END) as store_count,
        COUNT(CASE WHEN role = 'management' THEN 1 END) as management_count
    FROM users
")->fetch();
?>

<style>
    .user-card {
        transition: all 0.3s ease;
        border: 1px solid #e9ecef;
    }
    
    .user-card:hover {
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }
    
    .user-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.5rem;
        font-weight: bold;
        margin: 0 auto;
    }
    
    .role-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }
    
    .role-admin { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
    .role-planning { background: linear-gradient(135deg, #f093fb, #f5576c); color: white; }
    .role-production { background: linear-gradient(135deg, #4facfe, #00f2fe); color: white; }
    .role-store { background: linear-gradient(135deg, #43e97b, #38f9d7); color: white; }
    .role-management { background: linear-gradient(135deg, #fa709a, #fee140); color: white; }
    
    .status-active {
        color: #28a745;
    }
    
    .status-inactive {
        color: #dc3545;
    }
    
    .password-strength-meter {
        height: 6px;
        background: #e9ecef;
        border-radius: 3px;
        overflow: hidden;
        margin-top: 5px;
    }
    
    .password-strength-bar {
        height: 100%;
        transition: all 0.3s ease;
    }
</style>

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stats-card text-center">
                        <i class="fas fa-users icon"></i>
                        <div class="number"><?= number_format($stats['total_users']) ?></div>
                        <div class="label">ผู้ใช้งานทั้งหมด</div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <i class="fas fa-check-circle icon"></i>
                        <div class="number"><?= number_format($stats['active_users']) ?></div>
                        <div class="label">ใช้งานอยู่</div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #dc3545, #fd7e14);">
                        <i class="fas fa-times-circle icon"></i>
                        <div class="number"><?= number_format($stats['inactive_users']) ?></div>
                        <div class="label">ไม่ได้ใช้งาน</div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-3">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #6f42c1, #e83e8c);">
                        <i class="fas fa-user-shield icon"></i>
                        <div class="number"><?= number_format($stats['admin_count']) ?></div>
                        <div class="label">ผู้ดูแลระบบ</div>
                    </div>
                </div>
            </div>

            <!-- Users Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5><i class="fas fa-users me-2"></i>รายการผู้ใช้งานทั้งหมด</h5>
                    <div>
                        <button class="btn btn-info me-2" onclick="showRoleDistribution()">
                            <i class="fas fa-chart-pie me-1"></i>กระจายตำแหน่ง
                        </button>
                        <button class="btn btn-primary" onclick="showAddUserModal()">
                            <i class="fas fa-user-plus me-1"></i>เพิ่มผู้ใช้ใหม่
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Filter Options -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <select class="form-select" id="filterRole" onchange="filterUsers()">
                                <option value="">ทุกตำแหน่ง</option>
                                <option value="admin">Admin</option>
                                <option value="planning">Planning</option>
                                <option value="production">Production</option>
                                <option value="store">Store</option>
                                <option value="management">Management</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" id="filterStatus" onchange="filterUsers()">
                                <option value="">ใช้งานอยู่ (ค่าเริ่มต้น)</option>
                                <option value="active">ใช้งานอยู่</option>
                                <option value="inactive">ไม่ได้ใช้งาน</option>
                                <option value="all">ทั้งหมด</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="searchUser" placeholder="ค้นหาชื่อ, อีเมล, หรือชื่อผู้ใช้" onkeyup="filterUsers()">
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table id="usersTable" class="table table-hover">
                            <thead>
                                <tr>
                                    <th>รูปโปรไฟล์</th>
                                    <th>ชื่อ-นามสกุล</th>
                                    <th>ชื่อผู้ใช้</th>
                                    <th>อีเมล</th>
                                    <th>ตำแหน่ง</th>
                                    <th>สถานะ</th>
                                    <th>วันที่สร้าง</th>
                                    <th>การจัดการ</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Add/Edit User Modal -->
    <div class="modal fade" id="userModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalTitle">
                        <i class="fas fa-user-plus me-2"></i>เพิ่มผู้ใช้ใหม่
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="userForm">
                    <div class="modal-body">
                        <input type="hidden" id="user_id" name="user_id">
                        <input type="hidden" id="action" name="action" value="create">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="username" class="form-label">
                                        ชื่อผู้ใช้ <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               placeholder="ตัวอักษรและตัวเลขเท่านั้น" required>
                                    </div>
                                    <small class="text-muted">ใช้สำหรับเข้าสู่ระบบ (a-z, 0-9, _ เท่านั้น)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">
                                        อีเมล <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               placeholder="example@company.com" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="first_name" class="form-label">
                                        ชื่อ <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="last_name" class="form-label">
                                        นามสกุล <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row" id="passwordSection">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password" class="form-label">
                                        รหัสผ่าน <span class="text-danger" id="passwordRequired">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                        <input type="password" class="form-control" id="password" name="password" 
                                               placeholder="อย่างน้อย 6 ตัวอักษร" onkeyup="checkPasswordStrength()">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
                                            <i class="fas fa-eye" id="password-eye"></i>
                                        </button>
                                    </div>
                                    <div class="password-strength-meter">
                                        <div class="password-strength-bar" id="password-strength-bar"></div>
                                    </div>
                                    <small id="password-strength-text" class="text-muted"></small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password_confirm" class="form-label">
                                        ยืนยันรหัสผ่าน <span class="text-danger" id="confirmRequired">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                        <input type="password" class="form-control" id="password_confirm" name="password_confirm" 
                                               placeholder="กรอกรหัสผ่านอีกครั้ง" onkeyup="checkPasswordMatch()">
                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password_confirm')">
                                            <i class="fas fa-eye" id="password_confirm-eye"></i>
                                        </button>
                                    </div>
                                    <small id="password-match-text"></small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info" id="editPasswordNote" style="display: none;">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>หมายเหตุ:</strong> หากต้องการเปลี่ยนรหัสผ่าน ให้กรอกรหัสผ่านใหม่ หากไม่ต้องการเปลี่ยน ปล่อยว่างไว้
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="role" class="form-label">
                                        ตำแหน่ง/บทบาท <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="role" name="role" required onchange="showRoleDescription()">
                                        <option value="">เลือกตำแหน่ง</option>
                                        <option value="admin">Admin - ผู้ดูแลระบบ</option>
                                        <option value="planning">Planning - แผนกวางแผน</option>
                                        <option value="production">Production - แผนกผลิต</option>
                                        <option value="store">Store - แผนกคลังสินค้า</option>
                                        <option value="management">Management - ผู้บริหาร</option>
                                    </select>
                                    <div id="roleDescription" class="mt-2"></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="status" class="form-label">
                                        สถานะ <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" id="status" name="status" required>
                                        <option value="active">ใช้งานอยู่</option>
                                        <option value="inactive">ไม่ได้ใช้งาน</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="phone" class="form-label">เบอร์โทรศัพท์</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       placeholder="08X-XXX-XXXX" pattern="[0-9]{3}-[0-9]{3}-[0-9]{4}">
                            </div>
                            <small class="text-muted">รูปแบบ: 08X-XXX-XXXX</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="department" class="form-label">แผนก</label>
                            <input type="text" class="form-control" id="department" name="department" 
                                   placeholder="ระบุแผนกที่สังกัด">
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

    <!-- View User Modal -->
    <div class="modal fade" id="viewUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-circle me-2"></i>รายละเอียดผู้ใช้งาน
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="user-details">
                        <!-- User details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="button" class="btn btn-warning" id="editUserBtn">
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
        let usersTable;
        
        $(document).ready(function() {
            initDataTable();
        });
        
        function initDataTable() {
            usersTable = $('#usersTable').DataTable({
                processing: true,
                serverSide: false,
                ajax: {
                    url: '../../api/users.php?action=get_all',
                    dataSrc: 'users'
                },
                columns: [
                    {
                        data: null,
                        orderable: false,
                        render: function(data) {
                            const initials = data.full_name.split(' ').map(n => n[0]).join('').toUpperCase();
                            return `<div class="user-avatar">${initials}</div>`;
                        }
                    },
                    { 
                        data: 'full_name',
                        render: function(data, type, row) {
                            return `<strong>${data}</strong>`;
                        }
                    },
                    { 
                        data: 'username',
                        render: function(data) {
                            return `<code>${data}</code>`;
                        }
                    },
                    { data: 'email' },
                    {
                        data: 'role',
                        render: function(data) {
                            const roleNames = {
                                admin: 'Admin',
                                planning: 'Planning',
                                production: 'Production',
                                store: 'Store',
                                management: 'Management'
                            };
                            return `<span class="role-badge role-${data}">${roleNames[data] || data}</span>`;
                        }
                    },
                    {
                        data: 'status',
                        render: function(data) {
                            const icon = data === 'active' ? 'check-circle' : 'times-circle';
                            const className = data === 'active' ? 'status-active' : 'status-inactive';
                            const text = data === 'active' ? 'ใช้งานอยู่' : 'ไม่ได้ใช้งาน';
                            return `<i class="fas fa-${icon} ${className} me-1"></i> ${text}`;
                        }
                    },
                    {
                        data: 'created_at',
                        render: function(data) {
                            return new Date(data).toLocaleDateString('th-TH', {
                                year: 'numeric',
                                month: 'short',
                                day: 'numeric'
                            });
                        }
                    },
                    {
                        data: null,
                        orderable: false,
                        render: function(data) {
                            let buttons = '<div class="btn-group" role="group">';
                            
                            // ปุ่มดูรายละเอียด
                            buttons += `
                                <button class="btn btn-info btn-sm" onclick="viewUser(${data.user_id})" 
                                        data-bs-toggle="tooltip" title="ดูรายละเอียด">
                                    <i class="fas fa-eye"></i>
                                </button>
                            `;
                            
                            if (data.status === 'active') {
                                // ปุ่มแก้ไขสำหรับผู้ใช้ที่ active
                                buttons += `
                                    <button class="btn btn-warning btn-sm" onclick="editUser(${data.user_id})"
                                            data-bs-toggle="tooltip" title="แก้ไข">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                `;
                                
                                // ปุ่มลบ (ถ้าไม่ใช่ตัวเอง)
                                if (data.user_id !== <?= $_SESSION['user_id'] ?>) {
                                    buttons += `
                                        <button class="btn btn-danger btn-sm" onclick="deleteUser(${data.user_id})"
                                                data-bs-toggle="tooltip" title="ลบ">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    `;
                                }
                            } else {
                                // ปุ่มกู้คืนสำหรับผู้ใช้ที่ถูกลบ
                                buttons += `
                                    <button class="btn btn-success btn-sm" onclick="restoreUser(${data.user_id})"
                                            data-bs-toggle="tooltip" title="กู้คืน">
                                        <i class="fas fa-undo"></i>
                                    </button>
                                `;
                            }
                            
                            buttons += '</div>';
                            return buttons;
                        }
                    }
                ],
                order: [[6, 'desc']], // Sort by created_at DESC
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/th.json'
                },
                pageLength: 25
            });
        }
        
        function showAddUserModal() {
            document.getElementById('userForm').reset();
            document.getElementById('user_id').value = '';
            document.getElementById('action').value = 'create';
            document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-plus me-2"></i>เพิ่มผู้ใช้ใหม่';
            
            // Show password fields as required
            document.getElementById('password').required = true;
            document.getElementById('password_confirm').required = true;
            document.getElementById('passwordRequired').style.display = 'inline';
            document.getElementById('confirmRequired').style.display = 'inline';
            document.getElementById('editPasswordNote').style.display = 'none';
            
            // Enable username field
            document.getElementById('username').readOnly = false;
            
            // Reset password strength
            document.getElementById('password-strength-bar').style.width = '0%';
            document.getElementById('password-strength-text').textContent = '';
            document.getElementById('password-match-text').textContent = '';
            
            new bootstrap.Modal(document.getElementById('userModal')).show();
        }
        
        function editUser(userId) {
            fetch(`../../api/users.php?action=get&id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const user = data.user;
                        
                        // แยก full_name เป็น first_name และ last_name
                        const nameParts = user.full_name.split(' ');
                        const firstName = nameParts[0] || '';
                        const lastName = nameParts.slice(1).join(' ') || '';
                        
                        document.getElementById('userModalTitle').innerHTML = '<i class="fas fa-user-edit me-2"></i>แก้ไขข้อมูลผู้ใช้';
                        document.getElementById('user_id').value = user.user_id;
                        document.getElementById('action').value = 'update';
                        document.getElementById('username').value = user.username;
                        document.getElementById('email').value = user.email;
                        document.getElementById('first_name').value = firstName;
                        document.getElementById('last_name').value = lastName;
                        document.getElementById('role').value = user.role;
                        document.getElementById('status').value = user.status;
                        document.getElementById('phone').value = user.phone || '';
                        document.getElementById('department').value = '';
                        
                        // Make password optional for edit
                        document.getElementById('password').required = false;
                        document.getElementById('password_confirm').required = false;
                        document.getElementById('passwordRequired').style.display = 'none';
                        document.getElementById('confirmRequired').style.display = 'none';
                        document.getElementById('editPasswordNote').style.display = 'block';
                        
                        // Disable username editing
                        document.getElementById('username').readOnly = true;
                        
                        // Clear password fields
                        document.getElementById('password').value = '';
                        document.getElementById('password_confirm').value = '';
                        
                        showRoleDescription();
                        new bootstrap.Modal(document.getElementById('userModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }
        
        function viewUser(userId) {
            fetch(`../../api/users.php?action=get&id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayUserDetails(data.user);
                        new bootstrap.Modal(document.getElementById('viewUserModal')).show();
                    }
                })
                .catch(error => {
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }
        
        function displayUserDetails(user) {
            const roleNames = {
                admin: 'Admin - ผู้ดูแลระบบ',
                planning: 'Planning - แผนกวางแผน',
                production: 'Production - แผนกผลิต',
                store: 'Store - แผนกคลังสินค้า',
                management: 'Management - ผู้บริหาร'
            };
            
            const statusText = user.status === 'active' ? 
                '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>ใช้งานอยู่</span>' :
                '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>ไม่ได้ใช้งาน</span>';
            
            const initials = user.full_name.split(' ').map(n => n[0]).join('').toUpperCase();
            
            const detailsHtml = `
                <div class="text-center mb-4">
                    <div class="user-avatar" style="width: 100px; height: 100px; font-size: 2.5rem; margin: 0 auto 20px;">
                        ${initials}
                    </div>
                    <h4>${user.full_name}</h4>
                    <p class="text-muted">@${user.username}</p>
                    ${statusText}
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">อีเมล</label>
                        <div><i class="fas fa-envelope me-2 text-primary"></i>${user.email}</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">ตำแหน่ง</label>
                        <div><span class="role-badge role-${user.role}">${roleNames[user.role]}</span></div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">เบอร์โทรศัพท์</label>
                        <div><i class="fas fa-phone me-2 text-primary"></i>${user.phone || 'ไม่ระบุ'}</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">วันที่สร้าง</label>
                        <div><i class="fas fa-calendar me-2 text-primary"></i>${new Date(user.created_at).toLocaleDateString('th-TH')}</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="text-muted small">เข้าสู่ระบบล่าสุด</label>
                        <div><i class="fas fa-clock me-2 text-primary"></i>${user.last_login ? new Date(user.last_login).toLocaleDateString('th-TH') : 'ยังไม่เคยเข้าสู่ระบบ'}</div>
                    </div>
                </div>
            `;
            
            document.getElementById('user-details').innerHTML = detailsHtml;
            
            // Setup edit button
            document.getElementById('editUserBtn').onclick = () => {
                bootstrap.Modal.getInstance(document.getElementById('viewUserModal')).hide();
                editUser(user.user_id);
            };
        }
        
        function deleteUser(userId) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: 'คุณต้องการลบผู้ใช้งานนี้หรือไม่?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-trash me-1"></i>ลบ',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const formData = new FormData();
                    formData.append('action', 'delete');
                    formData.append('user_id', userId);
                    
                    fetch('../../api/users.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('สำเร็จ', data.message, 'success');
                            usersTable.ajax.reload();
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถลบผู้ใช้งานได้', 'error');
                    });
                }
            });
        }
        
        function restoreUser(userId) {
            Swal.fire({
                title: 'ยืนยันการกู้คืน?',
                text: 'คุณต้องการกู้คืนผู้ใช้งานนี้หรือไม่?',
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
                    formData.append('user_id', userId);
                    
                    fetch('../../api/users.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('สำเร็จ', data.message, 'success');
                            usersTable.ajax.reload();
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถกู้คืนผู้ใช้งานได้', 'error');
                    });
                }
            });
        }
        
        // Form Submission
        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Validate password match if password is filled
            const password = document.getElementById('password').value;
            const passwordConfirm = document.getElementById('password_confirm').value;
            const action = document.getElementById('action').value;
            
            if (password || action === 'create') {
                if (password !== passwordConfirm) {
                    Swal.fire('รหัสผ่านไม่ตรงกัน', 'กรุณาตรวจสอบรหัสผ่านอีกครั้ง', 'error');
                    return;
                }
                
                if (password.length < 6) {
                    Swal.fire('รหัสผ่านสั้นเกินไป', 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร', 'error');
                    return;
                }
            }
            
            const formData = new FormData(this);
            
            Swal.fire({
                title: 'กำลังบันทึก...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('../../api/users.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', data.message, 'success').then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
                        usersTable.ajax.reload();
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
        
        // Password Strength Checker
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.getElementById('password-strength-bar');
            const strengthText = document.getElementById('password-strength-text');
            
            if (password.length === 0) {
                strengthBar.style.width = '0%';
                strengthText.textContent = '';
                return;
            }
            
            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.length >= 10) strength++;
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
            if (/\d/.test(password)) strength++;
            if (/[^a-zA-Z\d]/.test(password)) strength++;
            
            const strengthLevels = [
                { width: '20%', color: '#dc3545', text: 'อ่อนมาก' },
                { width: '40%', color: '#fd7e14', text: 'อ่อน' },
                { width: '60%', color: '#ffc107', text: 'ปานกลาง' },
                { width: '80%', color: '#28a745', text: 'ดี' },
                { width: '100%', color: '#20c997', text: 'ดีเยี่ยม' }
            ];
            
            const level = strengthLevels[strength - 1] || strengthLevels[0];
            strengthBar.style.width = level.width;
            strengthBar.style.background = level.color;
            strengthText.textContent = 'ความแข็งแกร่ง: ' + level.text;
            strengthText.style.color = level.color;
            
            checkPasswordMatch();
        }
        
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const passwordConfirm = document.getElementById('password_confirm').value;
            const matchText = document.getElementById('password-match-text');
            
            if (passwordConfirm.length === 0) {
                matchText.textContent = '';
                return;
            }
            
            if (password === passwordConfirm) {
                matchText.innerHTML = '<i class="fas fa-check-circle text-success me-1"></i>รหัสผ่านตรงกัน';
                matchText.className = 'text-success';
            } else {
                matchText.innerHTML = '<i class="fas fa-times-circle text-danger me-1"></i>รหัสผ่านไม่ตรงกัน';
                matchText.className = 'text-danger';
            }
        }
        
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId + '-eye');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                field.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }
        
        function showRoleDescription() {
            const role = document.getElementById('role').value;
            const descDiv = document.getElementById('roleDescription');
            
            const descriptions = {
                admin: '<small class="text-info"><i class="fas fa-info-circle me-1"></i>สามารถจัดการระบบ, ผู้ใช้, วัสดุ และ BOM ได้ทั้งหมด</small>',
                planning: '<small class="text-info"><i class="fas fa-info-circle me-1"></i>จัดการงานการผลิต, วางแผนวัสดุ และอนุมัติคำขอซื้อ</small>',
                production: '<small class="text-info"><i class="fas fa-info-circle me-1"></i>รับงานการผลิต และสร้างคำขอเบิกวัสดุ</small>',
                store: '<small class="text-info"><i class="fas fa-info-circle me-1"></i>จัดการคลังสินค้า อนุมัติการเบิกวัสดุ และสร้างคำขอซื้อ</small>',
                management: '<small class="text-info"><i class="fas fa-info-circle me-1"></i>ดูรายงาน วิเคราะห์ข้อมูล และติดตามภาพรวม</small>'
            };
            
            descDiv.innerHTML = descriptions[role] || '';
        }
        
        function filterUsers() {
            const role = document.getElementById('filterRole').value;
            const status = document.getElementById('filterStatus').value;
            const search = document.getElementById('searchUser').value;
            
            usersTable.column(4).search(role).draw();
            usersTable.column(5).search(status).draw();
            usersTable.search(search).draw();
        }
        
        function showRoleDistribution() {
            const stats = <?= json_encode($stats) ?>;
            
            Swal.fire({
                title: '<i class="fas fa-chart-pie text-primary"></i> การกระจายตำแหน่งผู้ใช้งาน',
                html: `
                    <div class="row text-start">
                        <div class="col-6 mb-3">
                            <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                <div>
                                    <span class="role-badge role-admin">Admin</span>
                                </div>
                                <h4 class="mb-0">${stats.admin_count}</h4>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                <div>
                                    <span class="role-badge role-planning">Planning</span>
                                </div>
                                <h4 class="mb-0">${stats.planning_count}</h4>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                <div>
                                    <span class="role-badge role-production">Production</span>
                                </div>
                                <h4 class="mb-0">${stats.production_count}</h4>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                <div>
                                    <span class="role-badge role-store">Store</span>
                                </div>
                                <h4 class="mb-0">${stats.store_count}</h4>
                            </div>
                        </div>
                        <div class="col-12 mb-3">
                            <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                                <div>
                                    <span class="role-badge role-management">Management</span>
                                </div>
                                <h4 class="mb-0">${stats.management_count}</h4>
                            </div>
                        </div>
                    </div>
                `,
                showConfirmButton: true,
                confirmButtonText: 'ปิด',
                width: '600px'
            });
        }
    </script>

</body>
</html>