<?php

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'Admin') {
    exit();
}


$admin_name = $_SESSION['userName'] ?? "Admin";
$admin_id = $_SESSION['userID'] ?? 0;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        die("CSRF token validation failed.");
    }
    $file = $_FILES['admin_pic_upload'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $max_size = 2 * 1024 * 1024;
    if (!in_array($file['type'], $allowed_types) || $file['size'] > $max_size || $file['error'] !== UPLOAD_ERR_OK) {
        die("Invalid file upload.");
    }
    
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        $stmt = $conn->prepare("UPDATE `user` SET ProfilePic = ? WHERE UserID = ?");
        $stmt->bind_param("si", $newName, $admin_id);
        if ($stmt->execute()) {
            
            header("Location: " . $_SERVER['PHP_SELF'] . "?upload=success");
            exit();
        }
    }
}


$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$user_data = $res_pic->fetch_assoc();
$admin_pic = ($user_data && !empty($user_data['ProfilePic'])) ? $user_data['ProfilePic'] : 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);


$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- ستايل إضافي مخصص لزر الإلغاء والمودال ليتوافق مع الدارك مود بشكل كامل -->
<style>
.custom-cancel-btn {
    background-color: rgba(255, 255, 255, 0.08) !important;
    color: var(--text-main, #ffffff) !important;
    border: 1px solid rgba(255, 255, 255, 0.15) !important;
    transition: background-color 0.2s ease;
}
.custom-cancel-btn:hover {
    background-color: rgba(255, 255, 255, 0.18) !important;
    color: var(--text-main, #ffffff) !important;
}
</style>

<!-- هيكل الـ Sidebar الجمالي -->
<nav class="admin-sidebar shadow">
    <div class="text-center mb-4">
        <form id="adminPicForm" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <label for="admin_pic_upload" class="admin-pic-container">
                <img src="<?php echo e($admin_pic_url); ?>" class="admin-avatar shadow" id="sidebarAvatar">
                <div class="upload-overlay"><i class="fas fa-camera"></i></div>
                <input type="file" name="admin_pic_upload" id="admin_pic_upload" hidden onchange="document.getElementById('adminPicForm').submit();">
            </label>
        </form>
        <h6 class="mb-0 text-white u-name-display" style="font-weight: 700;"><?php echo htmlspecialchars($admin_name); ?></h6>
    </div>
    
    <div class="sidebar-nav-container">
        <div class="nav flex-column">
            <a href="admin-dashboard.php" class="nav-link <?php echo ($current_page == 'admin-dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-th-large"></i> <span data-i18n="nav_main">الرئيسية</span></a>
            <a href="station-approvals.php" class="nav-link <?php echo ($current_page == 'station-approvals.php') ? 'active' : ''; ?>"><i class="fas fa-check-double"></i> <span data-i18n="nav_approvals">الموافقات</span></a>
            <a href="security-center.php" class="nav-link <?php echo ($current_page == 'security-center.php') ? 'active' : ''; ?>"><i class="fas fa-shield-alt"></i> <span data-i18n="nav_security">الأمان</span></a>
            <a href="financial-reports.php" class="nav-link <?php echo ($current_page == 'financial-reports.php') ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i> <span data-i18n="nav_reports">التقارير</span></a>
            <a href="manage-plans.php" class="nav-link <?php echo ($current_page == 'manage-plans.php') ? 'active' : ''; ?>"><i class="fas fa-tags"></i> <span data-i18n="nav_plans">الخطط</span></a>
            <a href="notifications.php" class="nav-link <?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>"><i class="fas fa-bell"></i> <span data-i18n="nav_notif">الإشعارات</span></a>
            <a href="all-support-chats.php" class="nav-link <?php echo ($current_page == 'all-support-chats.php') ? 'active' : ''; ?>"><i class="fas fa-comments"></i> <span data-i18n="nav_support_chat">دردشة الدعم</span></a>
        </div>
    </div>

    <div class="mt-auto">
        <hr class="bg-white my-3" style="opacity: 0.2;">
        <a href="javascript:void(0)" onclick="confirmLogout()" class="nav-link text-danger">
            <i class="fas fa-sign-out-alt"></i> <span data-i18n="nav_logout">خروج</span>
        </a>
    </div>
</nav>

<!-- مودال تأكيد الخروج المرفق تلقائياً مع السايد بار (معدل ليدعم الدارك مود بوضوح كامل) -->
<div class="modal fade" id="logoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content text-center p-4 shadow-lg" style="background-color: var(--bg-card, #1e293b) !important; color: var(--text-main, #ffffff) !important; border-radius: 25px; border: 1px solid rgba(255,255,255,0.1);">
            
            <div class="text-danger mb-3">
                <i class="fas fa-sign-out-alt fa-3x"></i>
            </div>
            
            <h5 class="fw-bold mb-2" style="color: var(--text-main, #ffffff) !important;" data-i18n="logout_title">تأكيد الخروج</h5>
            
            <p class="mb-0" style="color: var(--text-muted, #94a3b8) !important; font-size: 0.95rem;" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
            
            <div class="d-grid gap-2 mt-4">
                <a href="../general/logout.php" class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</a>
                <button type="button" class="btn rounded-pill py-2 fw-bold custom-cancel-btn" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
            </div>
        </div>
    </div>
</div>