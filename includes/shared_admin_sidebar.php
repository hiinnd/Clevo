<?php

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'Admin') {
    exit();
}

$admin_name = $_SESSION['userName'] ?? "Admin";
$admin_id = $_SESSION['userID'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    clevo_require_csrf_token();
    $file = $_FILES['admin_pic_upload'];
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        clevo_admin_profile_save($conn, (int) $admin_id, $newName);
        header("Location: " . $_SERVER['PHP_SELF'] . "?upload=success");
        exit();
    }
}

$res_pic = clevo_stmt($conn, 'SELECT ProfilePic FROM `user` WHERE UserID = ?', 'i', [(int) $admin_id])->get_result();
$user_data = $res_pic->fetch_assoc();
$admin_pic = ($user_data && !empty($user_data['ProfilePic'])) ? $user_data['ProfilePic'] : 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);

$current_page = basename($_SERVER['PHP_SELF']);
?>

<style>
/* Retain the existing palette while meeting text contrast on the dark sidebar. */
.admin-sidebar .nav-link.active, .admin-sidebar .nav-link:hover {
    background-color: #1d4ed8 !important;
    color: #ffffff !important;
}
.admin-sidebar .text-danger { color: #ff7b86 !important; }
.admin-pic-container:focus-visible { outline: 3px solid #93c5fd; outline-offset: 4px; }
[data-theme="light"] .admin-main-content .btn-outline-primary {
    --bs-btn-color: #0b5ed7;
    --bs-btn-border-color: #0b5ed7;
}
[data-theme="dark"] .admin-main-content .btn-outline-primary {
    --bs-btn-color: #93c5fd;
    --bs-btn-border-color: #93c5fd;
}
[data-theme="dark"] .admin-main-content .btn-outline-danger {
    --bs-btn-color: #ff9ba2;
    --bs-btn-border-color: #ff9ba2;
}
[data-theme="light"] .admin-main-content .text-primary { color: #0b5ed7 !important; }
[data-theme="dark"] .admin-main-content .text-primary { color: #93c5fd !important; }
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


.admin-sidebar {
    background: var(--bg-sidebar, #000000);
    color: white;
    padding: 18px 16px 14px !important;
    position: fixed !important;
    top: 0 !important;
    bottom: 0 !important;
    height: 100vh !important;
    height: 100dvh !important;
    max-height: 100dvh !important;
    z-index: 1050 !important;
    width: 260px !important;
    display: flex !important;
    flex-direction: column !important;
    box-sizing: border-box !important;
    overflow: hidden !important;
    transition: all 0.3s ease;
}

.admin-sidebar .text-center.mb-4 {
    flex: 0 0 auto !important;
    margin-bottom: 12px !important;
}

.admin-sidebar .admin-avatar {
    width: clamp(58px, 11vh, 80px) !important;
    height: clamp(58px, 11vh, 80px) !important;
}


@media (min-width: 992px) {
    [dir="rtl"] .admin-main-content {
        margin-right: 260px !important;
        margin-left: 0 !important;
    }
    [dir="ltr"] .admin-main-content {
        margin-left: 260px !important;
        margin-right: 0 !important;
    }
}

.mobile-logout-btn {
    display: none !important;
}

.sidebar-nav-container {
    flex: 1 1 auto !important;
    min-height: 0 !important;
    margin-top: 8px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    padding-inline: 2px !important;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.sidebar-nav-container::-webkit-scrollbar {
    display: none;
}

.admin-sidebar .nav-link {
    min-height: 44px !important;
    margin-bottom: 4px !important;
    white-space: nowrap !important;
}

.admin-sidebar .nav-link span {
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

.admin-sidebar .mt-auto {
    flex: 0 0 auto !important;
    margin-top: 8px !important;
    padding-top: 6px !important;
}

.admin-sidebar .mt-auto hr {
    margin: 6px 0 8px !important;
}

@media (max-width: 991px) {
    .admin-sidebar {
        position: fixed !important;
        bottom: 0 !important;
        top: auto !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        height: 78px !important;
        max-height: 78px !important;
        flex-direction: row !important;
        padding: 7px max(6px, env(safe-area-inset-left)) calc(7px + env(safe-area-inset-bottom)) max(6px, env(safe-area-inset-right)) !important;
        justify-content: space-around !important;
        align-items: center !important;
        box-shadow: 0 -5px 25px rgba(0,0,0,0.4) !important;
        border-radius: 20px 20px 0 0 !important;
    }
    
    .admin-sidebar .text-center.mb-4, 
    .admin-sidebar hr, 
    .admin-sidebar .mt-auto {
        display: none !important;
    }
    
    .sidebar-nav-container {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow-x: auto !important;
    }
    
    .sidebar-nav-container .nav {
        flex-direction: row !important;
        display: grid !important;
        grid-template-columns: repeat(9, minmax(65px, 1fr)) !important;
        gap: 2px !important;
        width: 100% !important;
    }
    
    .nav-link {
        min-height: 62px !important;
        padding: 7px 3px !important;
        margin-bottom: 0 !important;
        border-radius: 11px !important;
        font-size: 0.62rem !important;
        flex-direction: column !important;
        align-items: center !important;
        gap: 2px !important;
        justify-content: center !important;
        min-width: 0 !important;
        line-height: 1.15 !important;
    }
    
    .nav-link i {
        margin: 0 !important;
        font-size: 1.2rem !important;
    }
    
    .nav-link span {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        font-size: clamp(0.52rem, 1.7vw, 0.66rem) !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
        text-align: center !important;
    }
    
    .mobile-logout-btn {
        display: flex !important;
    }
}

@media (max-width: 380px) {
    .admin-sidebar {
        height: 66px !important;
        max-height: 66px !important;
        padding-top: 5px !important;
    }

    .nav-link {
        min-height: 54px !important;
        padding: 6px 2px !important;
    }

    .nav-link i {
        font-size: 1rem !important;
    }

    .nav-link span {
        font-size: 0.5rem !important;
    }
}
</style>

<script src="../assets/js/admin-common.js" defer></script>
<?php if(isset($_SESSION['admin_error'])):
    $errorMessages=[
        'self_status_change_forbidden'=>['لا يمكنك تقييد حسابك الإداري.','You cannot restrict your own admin account.'],
        'last_admin_required'=>['يجب الإبقاء على مسؤول نشط.','An active administrator must remain.'],
        'email_verification_required'=>['يجب توثيق البريد الإلكتروني أولاً.','Email verification is required first.'],
        'owner_verification_required'=>['يجب توثيق حساب المالك أولاً.','The owner account must be verified first.'],
        'owner_restricted'=>['حساب المالك مقيّد. راجع حالته أولاً.','The owner is restricted. Review the account status first.'],
        'verification_document_required'=>['مستند التحقق مفقود أو غير صالح للقراءة.','The verification document is missing or unreadable.'],
        'driver_has_active_jobs'=>['يجب إنهاء مهام السائق الحالية قبل تقييده.','Resolve the driver’s active jobs before restricting the account.'],
        'ticket_closed'=>['التذكرة مغلقة. أعد فتحها قبل الرد.','The ticket is closed. Reopen it before replying.'],
        'manual_refund_required'=>['يتطلب هذا الاسترجاع مراجعة يدوية.','This refund requires manual review.'],
        'refund_already_processed'=>['تمت مراجعة طلب الاسترجاع مسبقاً.','This refund request was already reviewed.'],
        'payment_gateway_not_configured'=>['مزود الدفع غير مهيأ بعد.','The payment provider is not configured yet.'],
        'provider_settlements_not_approved'=>['التسويات البنكية غير مفعّلة بعد.','Bank settlements are not enabled yet.'],
        'settlement_request_exists'=>['يوجد طلب تسوية قيد المراجعة لهذا المالك.','This owner already has a settlement request under review.'],
        'validation_failed'=>['راجع الحقول المطلوبة والقيم المدخلة.','Review the required fields and entered values.'],
    ];
    $errorText=$errorMessages[$_SESSION['admin_error']]??['تعذر تطبيق التغيير. راجع الحالة الحالية وحاول مجدداً.','Unable to apply this change. Review the current state and retry.'];
?><div role="alert" class="alert alert-danger m-3" data-admin-ar="<?= e($errorText[0]) ?>" data-admin-en="<?= e($errorText[1]) ?>"><?= e($errorText[0]) ?></div><?php unset($_SESSION['admin_error']); endif; ?>
<nav class="admin-sidebar shadow">
    <div class="text-center mb-4">
        <form id="adminPicForm" method="POST" enctype="multipart/form-data" action="<?php echo e(clevoPublicAssetUrl('admin/upload-profile-picture.php')); ?>">
            <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
            <label for="admin_pic_upload" class="admin-pic-container" role="button" tabindex="0" aria-label="تغيير صورة الملف الشخصي" data-admin-aria-ar="تغيير صورة الملف الشخصي" data-admin-aria-en="Change profile photo" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('admin_pic_upload').click();}">
                <img src="<?php echo e($admin_pic_url); ?>" class="admin-avatar shadow" id="sidebarAvatar" alt="">
                <div class="upload-overlay"><i class="fas fa-camera"></i></div>
                <input type="file" name="admin_pic_upload" id="admin_pic_upload" hidden accept="image/jpeg,image/png,image/gif,image/webp" onchange="document.getElementById('adminPicForm').submit();">
            </label>
        </form>
        <h6 class="mb-0 text-white u-name-display" style="font-weight: 700;"><?php echo htmlspecialchars($admin_name); ?></h6>
    </div>
    
    <div class="sidebar-nav-container">
        <div class="nav flex-column">
            <a href="admin-dashboard.php" class="nav-link <?php echo ($current_page == 'admin-dashboard.php') ? 'active' : ''; ?>"><i class="fas fa-th-large"></i> <span data-i18n="nav_main">الرئيسية</span></a>
            <a href="management.php" class="nav-link <?= $current_page==='management.php'?'active':'' ?>"><i class="fas fa-tasks"></i><span data-admin-ar="الإدارة" data-admin-en="Management">الإدارة</span></a>
            <a href="privacy-requests.php" class="nav-link <?= $current_page==='privacy-requests.php'?'active':'' ?>"><i class="fas fa-user-shield"></i><span data-admin-ar="طلبات الخصوصية" data-admin-en="Privacy requests">طلبات الخصوصية</span></a>
            <a href="station-approvals.php" class="nav-link <?php echo ($current_page == 'station-approvals.php') ? 'active' : ''; ?>"><i class="fas fa-check-double"></i> <span data-i18n="nav_approvals">الموافقات</span></a>
            <a href="security-center.php" class="nav-link <?php echo ($current_page == 'security-center.php') ? 'active' : ''; ?>"><i class="fas fa-shield-alt"></i> <span data-i18n="nav_security">الأمان</span></a>
            <a href="financial-reports.php" class="nav-link <?php echo ($current_page == 'financial-reports.php') ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i> <span data-i18n="nav_reports">التقارير</span></a>
            <a href="manage-plans.php" class="nav-link <?php echo ($current_page == 'manage-plans.php') ? 'active' : ''; ?>"><i class="fas fa-tags"></i> <span data-i18n="nav_plans">الخطط</span></a>
            <a href="notifications.php" class="nav-link <?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>"><i class="fas fa-bell"></i> <span data-i18n="nav_notif">الإشعارات</span></a>
            <a href="all-support-chats.php" class="nav-link <?php echo ($current_page == 'all-support-chats.php') ? 'active' : ''; ?>"><i class="fas fa-comments"></i> <span data-i18n="nav_support_chat">الدعم</span></a>
           
            <a href="javascript:void(0)" onclick="confirmLogout()" class="nav-link text-danger mobile-logout-btn"><i class="fas fa-sign-out-alt"></i> <span data-i18n="nav_logout">خروج</span></a>
        </div>
    </div>

    <div class="mt-auto">
        <hr class="bg-white my-3" style="opacity: 0.2;">
        <a href="javascript:void(0)" onclick="confirmLogout()" class="nav-link text-danger">
            <i class="fas fa-sign-out-alt"></i> <span data-i18n="nav_logout">خروج</span>
        </a>
    </div>
</nav>

<div class="modal fade" id="logoutModal" tabindex="-1" style="z-index: 2050;">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content text-center p-4 shadow-lg" style="background-color: var(--bg-card, #1e293b) !important; color: var(--text-main, #ffffff) !important; border-radius: 25px; border: 1px solid rgba(255,255,255,0.1);">
            
            <div class="text-danger mb-3">
                <i class="fas fa-sign-out-alt fa-3x"></i>
            </div>
            
            <h5 class="fw-bold mb-2" style="color: var(--text-main, #ffffff) !important;" data-i18n="logout_title">تأكيد الخروج</h5>
            
            <p class="mb-0" style="color: var(--text-muted, #94a3b8) !important; font-size: 0.95rem;" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
            
            <div class="d-grid gap-2 mt-4">
                <form method="POST" action="../general/logout.php" class="m-0"><?= csrf_input(); ?><button type="submit"  class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</button></form>
                <button type="button" class="btn rounded-pill py-2 fw-bold custom-cancel-btn" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
            </div>
        </div>
    </div>
</div>

<script>
function confirmLogout() {
    const myModal = new bootstrap.Modal(document.getElementById('logoutModal'));
    myModal.show();
}
</script>
