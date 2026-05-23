<?php
session_start();
include __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/app-helpers.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../general/login.php");
    exit();
}


$admin_name = $_SESSION['userName'] ?? "Admin";
$admin_id = $_SESSION['userID'] ?? 0;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    $file = $_FILES['admin_pic_upload'];
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        $conn->query("UPDATE `user` SET ProfilePic = '$newName' WHERE UserID = $admin_id");
        header("Location: manage-users.php?upload=success");
        exit();
    }
}

$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$admin_pic = $res_pic->fetch_assoc()['ProfilePic'] ?? 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);


$res_users = $conn->query("SELECT * FROM `user` WHERE Role IN ('customer', 'User') ORDER BY UserID DESC");


if (isset($_GET['toggle_status'])) {
    $u_id = intval($_GET['toggle_status']);
    $current_status = $_GET['current'];
    $new_status = ($current_status === 'active') ? 'rejected' : 'active';
    
    $stmt = $conn->prepare("UPDATE `user` SET AccountStatus = ? WHERE UserID = ?");
    $stmt->bind_param("si", $new_status, $u_id);
    if($stmt->execute()) {
        header("Location: manage-users.php?msg=updated");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="users_title">إدارة المستخدمين | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-rtl">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #3b82f6;
            --border-color: #dee2e6; --box-bg: #ffffff; --accent-blue: #3b82f6; 
            --text-muted-custom: #4b5563;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #1e293b; --text-main: #ffffff !important; --text-muted: #cbd5e1; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --box-bg: #0f172a; --accent-blue: #60a5fa; --primary-blue: #60a5fa;
            --text-muted-custom: #cbd5e1;
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-row { display: flex; flex-wrap: nowrap; min-height: 100vh; }
        
        .admin-sidebar { background: var(--bg-sidebar); color: white; padding: 20px 15px; position: sticky; top: 0; height: 100vh; z-index: 1000; width: 260px; display: flex; flex-direction: column; overflow: visible; }
        .admin-pic-container { position: relative; display: inline-block; margin-bottom: 10px; cursor: pointer; }
        .admin-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-blue); transition: 0.3s; }
        .upload-overlay { position: absolute; bottom: 0; right: 0; background: var(--accent-blue); color: white; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid var(--bg-sidebar); }

        @media (min-width: 768px) { [dir="rtl"] .admin-sidebar { order: 1; } [dir="rtl"] .admin-main-content { order: 2; } }

        .sidebar-nav-container { flex-grow: 1; margin-top: 20px; }
        .nav-link { color: #ffffff !important; padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; text-decoration: none; font-weight: 600; transition: 0.3s; font-size: 0.9rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent-blue); color: white !important; }

        /* Content Area & Header Setup */
        .admin-main-content { flex: 1; overflow-x: hidden; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .back-btn-circle { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; transition: 0.3s; }
        
        .table-container { background-color: var(--bg-card) !important; border-radius: 24px; padding: 25px; border: 1px solid var(--border-color); }
        .custom-table { width: 100%; color: inherit !important; border-collapse: separate; border-spacing: 0 10px; }
        
        .custom-table tbody tr { background: var(--box-bg); transition: 0.3s; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        [dir="rtl"] .custom-table tbody tr { border-right: 5px solid var(--primary-blue); }
        [dir="ltr"] .custom-table tbody tr { border-left: 5px solid var(--primary-blue); }
        
        .custom-table td { padding: 15px 10px; border: none; }
        .user-avatar-list { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid var(--accent-blue); }

        .modal-content { background-color: var(--bg-card) !important; color: var(--text-main) !important; border-radius: 30px; border: 1px solid var(--border-color); }
        .text-muted-custom { color: var(--text-muted-custom) !important; }

        @media (max-width: 768px) {
            .main-row { flex-direction: column; }
            .admin-sidebar { height: auto; width: 100%; position: relative; padding: 15px; }
            .sidebar-nav-container { display: flex; flex-wrap: wrap; gap: 5px; }
            .nav-link { margin-bottom: 0; justify-content: center; font-size: 0.8rem; }
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
                        <a href="admin-dashboard.php" class="back-btn-circle shadow-sm">
                            <i class="fas fa-arrow-right" id="backIcon"></i>
                        </a>
                        <h6 class="mb-0 fw-bold" data-i18n="manage_users_h">إدارة المستخدمين</h6>
                        <div style="width: 40px;"></div> 
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div class="table-container shadow-sm">
                        <div class="table-responsive">
                            <table class="custom-table align-middle text-start">
                                <thead>
                                    <tr class="opacity-75 small fw-bold">
                                        <th data-i18n="th_user_info">المستخدم</th>
                                        <th data-i18n="th_email">البريد الإلكتروني</th>
                                        <th data-i18n="th_phone">رقم الهاتف</th>
                                        <th data-i18n="th_status">الحالة</th>
                                        <th data-i18n="th_action">الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($res_users && $res_users->num_rows > 0): ?>
                                        <?php while($user = $res_users->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-3">
                                                    <img src="<?php echo e(clevoProfileImageUrl($user['ProfilePic'] ?? '', $user['UserName'] ?? 'User')); ?>" class="user-avatar-list shadow-sm">
                                                    <span class="fw-bold"><?php echo htmlspecialchars($user['UserName']); ?></span>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($user['Email'] ?? '---'); ?></td>
                                            <td dir="ltr"><?php echo htmlspecialchars($user['PhoneNumber']); ?></td>
                                            <td>
                                                <span class="badge rounded-pill px-3 bg-<?php echo ($user['AccountStatus'] === 'active') ? 'success' : 'danger'; ?>">
                                                    <span data-i18n="status_<?php echo $user['AccountStatus']; ?>"><?php echo ($user['AccountStatus'] === 'active' ? 'نشط' : 'محظور'); ?></span>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="manage-users.php?toggle_status=<?php echo $user['UserID']; ?>&current=<?php echo $user['AccountStatus']; ?>" 
                                                   class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">
                                                    <i class="fas fa-sync-alt me-1"></i> <span data-i18n="btn_toggle">تغيير الحالة</span>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center py-5" data-i18n="no_users">لا يوجد مستخدمين مسجلين حالياً</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="modal-body p-2">
                    <div class="text-danger mb-3">
                        <i class="fas fa-sign-out-alt fa-3x"></i>
                    </div>
                    <h5 class="fw-bold mb-2" data-i18n="logout_title">تأكيد الخروج</h5>
                    <p class="small opacity-75 mb-4" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
                    <div class="d-grid gap-2">
                        <a href="../general/logout.php" class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</a>
                        <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                users_title: "إدارة المستخدمين | Clevo", manage_users_h: "إدارة المستخدمين",
                nav_main: "الرئيسية", nav_approvals: "الموافقات", nav_security: "الأمان", nav_reports: "التقارير", nav_plans: "الخطط", nav_notif: "الإشعارات", nav_support_chat: "دردشة الدعم", nav_logout: "خروج",
                th_user_info: "المستخدم", th_email: "البريد الإلكتروني", th_phone: "رقم الهاتف", th_status: "الحالة", th_action: "الإجراء",
                status_active: "نشط", status_rejected: "محظور", btn_toggle: "تغيير الحالة", no_users: "لا يوجد مستخدمين مسجلين حالياً",
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء"
            },
            en: {
                users_title: "Manage Users | Clevo", manage_users_h: "User Management",
                nav_main: "Dashboard", nav_approvals: "Approvals", nav_security: "Security", nav_reports: "Reports", nav_plans: "Plans", nav_notif: "Notifications", nav_support_chat: "Support Chat", nav_logout: "Logout",
                th_user_info: "User", th_email: "Email", th_phone: "Phone", th_status: "Status", th_action: "Action",
                status_active: "Active", status_rejected: "Banned", btn_toggle: "Toggle Status", no_users: "No users found",
                logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel"
            }
        };

        function confirmLogout() { new bootstrap.Modal(document.getElementById('logoutModal')).show(); }

        function applySettings() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            const isRtl = lang === 'ar';
            document.documentElement.setAttribute('data-theme', theme);
            
            document.getElementById('bootstrap-rtl').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
            
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) el.innerText = translations[lang][key];
            });

            const backIcon = document.getElementById('backIcon');
            if(backIcon) backIcon.className = isRtl ? 'fas fa-arrow-right' : 'fas fa-arrow-left';
            document.querySelectorAll('.u-name-display').forEach(el => el.innerText = "<?php echo $admin_name; ?>");
        }

        window.onload = applySettings;
    </script>
</body>
</html>