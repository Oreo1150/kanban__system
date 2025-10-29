<?php
if (!function_exists('redirect')) {
    function redirect($url) {
        header("Location: " . $url);
        exit();
    }
}

if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('getUserRole')) {
    function getUserRole() {
        return isset($_SESSION['role']) ? $_SESSION['role'] : '';
    }
}

if (!function_exists('checkRole')) {
    function checkRole($allowed_roles) {
        if (!isLoggedIn()) {
            redirect('login.php');
        }
        if (!in_array(getUserRole(), $allowed_roles)) {
            redirect('unauthorized.php');
        }
    }
}

if (!function_exists('sanitize')) {
    function sanitize($data) {
        return htmlspecialchars(strip_tags(trim($data)));
    }
}

if (!function_exists('generateToken')) {
    function generateToken() {
        return bin2hex(random_bytes(32));
    }
}

if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

if (!function_exists('hashPassword')) {
    function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

// ฟังก์ชันอื่น ๆ เช่น checkAccess, logAudit, generateRequestNumber
// ให้ใช้วิธีเดียวกัน เพิ่ม if (!function_exists('...')) รอบทุกฟังก์ชัน
?>
