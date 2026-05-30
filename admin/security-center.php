<?php
session_start();
include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../general/login.php");
    exit();
}


$admin_name = $_SESSION['userName'] ?? "Admin";
$admin_id = $_SESSION['userID'] ?? 0;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    clevo_require_csrf_token();
    $file = $_FILES['admin_pic_upload'];
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        $conn->query("UPDATE `user` SET ProfilePic = '$newName' WHERE UserID = $admin_id");
        header("Location: security-center.php?upload=success");
        exit();
    }
}

$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$user_data = $res_pic->fetch_assoc();
$admin_pic = ($user_data && !empty($user_data['ProfilePic'])) ? $user_data['ProfilePic'] : 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    clevo_require_csrf_token();
    $u_id = intval($_POST['user_id']);
    $user_info = $conn->query("SELECT DeviceID FROM user WHERE UserID = $u_id")->fetch_assoc();
    $device_id = $user_info['DeviceID'] ?? '';
    $conn->query("UPDATE user SET Role = 'User' WHERE UserID = $u_id");
    if (!empty($device_id)) {
        $conn->query("DELETE FROM blacklist WHERE DeviceID = '$device_id'");
    }
    header("Location: security-center.php?unbanned=success");
    exit;
}


$res_banned = $conn->query("SELECT COUNT(*) as total FROM blacklist");
$total_banned = $res_banned->fetch_assoc()['total'] ?? 0;


$blacklist_res = $conn->query("
    SELECT u.UserID, b.DeviceID, u.UserName 
    FROM blacklist b 
    LEFT JOIN user u ON b.DeviceID = u.DeviceID 
    ORDER BY b.id DESC
");
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="security_header_title">مركز الأمان | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --accent-blue: #3b82f6; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #3b82f6;
            --border-color: #dee2e6; --text-muted-custom: #4b5563; --table-head-bg: #f8f9fa;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #1e293b; --text-main: #ffffff !important; --text-muted: #cbd5e1; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --accent-blue: #60a5fa; --primary-blue: #60a5fa; --table-head-bg: #1e293b; --text-muted-custom: #cbd5e1;
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-row { display: flex; flex-wrap: nowrap; min-height: 100vh; }
        
        .admin-sidebar { background: var(--bg-sidebar); color: white; padding: 20px 15px; position: sticky; top: 0; height: 100vh; z-index: 1000; width: 260px; display: flex; flex-direction: column; overflow: visible; }
        .admin-pic-container { position: relative; display: inline-block; margin-bottom: 10px; cursor: pointer; }
        .admin-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-blue); }

        .nav-link { color: #ffffff !important; padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: 0.3s; }
        .nav-link:hover, .nav-link.active { background: var(--accent-blue); color: white !important; }

        .admin-main-content { flex: 1; overflow-x: hidden; padding: 0; }
        
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-btn-back { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; transition: 0.3s; }

        /* البطاقات العلوية */
        .custom-card { background-color: var(--bg-card) !important; border: 1px solid var(--border-color); border-radius: 24px; padding: 25px; }
        .security-alert { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 24px; padding: 25px; height: 100%; display: flex; align-items: center; color: var(--text-main); }

        /* الجدول */
        .table-container { background-color: var(--bg-card) !important; border-radius: 24px; padding: 25px; border: 1px solid var(--border-color); overflow: hidden; }
        .custom-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; color: var(--text-main) !important; }
        .custom-table thead tr th { background: var(--table-head-bg); color: var(--accent-blue) !important; padding: 15px; border: none; font-weight: 900; }
        .custom-table tbody tr { background: rgba(255,255,255, 0.02); transition: 0.3s; }
        .custom-table tbody td { padding: 15px; border: none; vertical-align: middle; }
        
        .badge-id { background: rgba(59, 130, 246, 0.1); color: var(--accent-blue); border: 1px solid var(--accent-blue); padding: 5px 12px; border-radius: 8px; font-weight: 700; font-family: monospace; }

        .modal-content { background-color: var(--bg-card) !important; color: var(--text-main) !important; border-radius: 30px; border: 1px solid var(--border-color); }
        .u-name-display { color: var(--accent-blue) !important; }
        .modal-body p { color: var(--text-main) !important; }

        @media (max-width: 991px) {
            .main-row { flex-direction: column; }
            .admin-main-content { padding-bottom: 100px !important; }
        }
    </style>
</head>
<body>

    <div class="container-fluid p-0">
        <div class="row g-0 main-row">
            <?php include __DIR__ . '/../includes/shared_admin_sidebar.php'; ?>

            <main class="admin-main-content">
                <div class="orders-header">
                    <div class="container d-flex justify-content-between align-items-center">
                        <a href="admin-dashboard.php" class="circle-btn-back shadow-sm">
                            <i class="fas fa-arrow-right" id="backIcon"></i>
                        </a>
                        <h6 class="mb-0 fw-bold" data-i18n="security_header_title">مركز أمان النظام</h6>
                        <div style="width: 40px;"></div> 
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div class="row g-4 mb-4 text-start">
                        <div class="col-md-4">
                            <div class="custom-card shadow-sm h-100" style="border-inline-start: 5px solid #ef4444 !important;">
                                <small class="opacity-75 d-block mb-1 fw-bold" data-i18n="banned_devices_label">الأجهزة المحظورة</small>
                                <h2 class="text-danger fw-bold m-0"><?php echo $total_banned; ?> <span data-i18n="devices_unit" class="fs-5">جهازا</span></h2>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="security-alert shadow-sm">
                                <i class="fas fa-user-shield mx-3 fs-3 text-danger"></i>
                                <div>
                                    <h6 class="fw-bold mb-1" data-i18n="security_alert_title">نظام الحماية النشط</h6>
                                    <p class="small mb-0 opacity-75"><span data-i18n="security_alert_msg">يتم مراقبة الأجهزة لضمان المستحقات والالتزام يا</span> <strong class="u-name-display"><?php echo htmlspecialchars($admin_name); ?></strong></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="table-container shadow-sm text-start">
                        <h6 class="fw-bold mb-4" data-i18n="blacklist_title">إدارة القائمة السوداء</h6>
                        <div class="table-responsive">
                            <table class="custom-table align-middle">
                                <thead>
                                    <tr>
                                        <th data-i18n="th_device_id">المعرف</th>
                                        <th data-i18n="th_user">المستخدم</th>
                                        <th data-i18n="th_status">الحالة</th>
                                        <th data-i18n="th_action">الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($blacklist_res && $blacklist_res->num_rows > 0): while($user_b = $blacklist_res->fetch_assoc()): ?>
                                        <tr>
                                            <td><span class="badge-id"><?php echo htmlspecialchars($user_b['DeviceID'] ?? 'N/A'); ?></span></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($user_b['UserName'] ?? 'غير معروف'); ?></td>
                                            <td><span class="badge bg-danger rounded-pill px-3" data-i18n="status_banned">محظور</span></td>
                                            <td><button class="btn btn-sm btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="askUnban('<?php echo $user_b['UserID']; ?>', '<?php echo $user_b['UserName']; ?>')" data-i18n="btn_unban">فك الحظر</button></td>
                                        </tr>
                                    <?php endwhile; else: ?>
                                        <tr><td colspan="4" class="text-center py-5 opacity-50 fw-bold" data-i18n="no_banned">لا يوجد محظورين حالياً</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- تم حذف الهيكل القديم لـ logoutModal المتكرر من هنا لمنع التضارب، والاعتماد الكلي على منيو الـ Sidebar المركزي للسيستم -->

    <div class="modal fade" id="unbanModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <form method="POST">
                    <?= csrf_input(); ?>
                    <input type="hidden" name="user_id" id="modalUserId">
                    <div class="text-success mb-3"><i class="fas fa-unlock-alt fa-3x"></i></div>
                    <h6 class="fw-bold mb-3">فك حظر المستخدم</h6>
                    <p class="small mb-4 opacity-75">هل تريد فك حظر <strong id="targetName" class="text-primary fw-bold"></strong>؟</p>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success rounded-pill fw-bold">نعم، فك الحظر</button>
                        <button type="button" class="btn btn-light border rounded-pill fw-bold" data-bs-dismiss="modal">إلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: { security_header_title: "مركز أمان النظام", banned_devices_label: "الأجهزة المحظورة", devices_unit: "جهازا", security_alert_title: "نظام الحماية النشط", security_alert_msg: "يتم مراقبة الأجهزة لضمان المستحقات والالتزام يا", blacklist_title: "إدارة القائمة السوداء", th_device_id: "المعرف", th_user: "المستخدم", th_status: "الحالة", th_action: "الإجراء", status_banned: "محظور", btn_unban: "فك الحظر", no_banned: "لا يوجد محظورين حالياً", nav_main: "الرئيسية", nav_approvals: "الموافقات", nav_security: "الأمان", nav_reports: "التقارير", nav_plans: "الخطط", nav_notif: "الإشعارات", nav_support_chat: "دردشة الدعم", nav_logout: "خروج", logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء" },
            en: { security_header_title: "Security Center", banned_devices_label: "Banned Devices", devices_unit: "Devices", security_alert_title: "Active Security System", security_alert_msg: "Devices are monitored to ensure payments and compliance,", blacklist_title: "Blacklist Management", th_device_id: "ID", th_user: "User", th_status: "Status", th_action: "Action", status_banned: "Banned", btn_unban: "Unban", no_banned: "No bans found", nav_main: "Dashboard", nav_approvals: "Approvals", nav_security: "Security", nav_reports: "Reports", nav_plans: "Plans", nav_notif: "Notifications", nav_support_chat: "Support Chat", nav_logout: "Logout", logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel" }
        };

        function askUnban(id, name) { document.getElementById('targetName').innerText = name; document.getElementById('modalUserId').value = id; new bootstrap.Modal(document.getElementById('unbanModal')).show(); }

        window.onload = function() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            const isRtl = lang === 'ar';
            
            document.documentElement.setAttribute('data-theme', theme);
            document.getElementById('bootstrap-css').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
            
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const k = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][k]) el.innerText = translations[lang][k];
            });
            const backIcon = document.getElementById('backIcon');
            if (backIcon) backIcon.className = isRtl ? 'fas fa-arrow-right' : 'fas fa-arrow-left';
        }
    </script>
</body>
</html>