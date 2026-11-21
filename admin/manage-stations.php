<?php

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
        clevo_admin_profile_save($conn,(int)$admin_id,$newName);
        header("Location: manage-stations.php?upload=success");
        exit();
    }
}

$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$admin_pic = $res_pic->fetch_assoc()['ProfilePic'] ?? 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);

$show_msg_modal = false;
$msg_type = "";


if (isset($_POST['confirm_delete_id'])) {
    clevo_require_csrf_token();
    $delete_id = intval($_POST['confirm_delete_id']);
    clevo_db_begin($conn);
    try {
        clevo_admin_command($conn,(int)$admin_id,'station_archive',$delete_id,[]);
        clevo_db_commit($conn);
        $show_msg_modal = true; 
        $msg_type = "deleted";
    } catch (Throwable $e) {
        clevo_db_rollback($conn);
        error_log('CLEVO operation failed.');
        $show_msg_modal = true;
        $msg_type = "error";
    }
}


if (isset($_POST['confirm_block_id'])) {
    clevo_require_csrf_token();
    $block_id = intval($_POST['confirm_block_id']);
    $show_msg_modal=true;
    $msg_type=clevo_admin_web_command($conn,(int)$admin_id,'station_status',$block_id,['admin_status'=>'blocked'])?'blocked':'error';

}


$res_stations = $conn->query("SELECT s.*, u.UserName as owner_name FROM stations s LEFT JOIN `user` u ON s.UserID = u.UserID WHERE s.ArchivedAt IS NULL ORDER BY s.StationID DESC");
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="manage_stations_title">إدارة المحطات | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #3b82f6;
            --border-color: #dee2e6; --accent-blue: #3b82f6; --row-bg: #ffffff;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #1e293b; --text-main: #ffffff !important; --text-muted: #cbd5e1; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --accent-blue: #60a5fa; --primary-blue: #60a5fa; --row-bg: #1e293b;
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-row { display: flex; flex-wrap: nowrap; min-height: 100vh; }
        

        .admin-sidebar { background: var(--bg-sidebar); color: white; padding: 20px 15px; position: sticky; top: 0; height: 100vh; width: 260px; display: flex; flex-direction: column; overflow: visible; z-index: 1000; }
        .admin-pic-container { position: relative; display: inline-block; margin-bottom: 10px; cursor: pointer; }
        .admin-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-blue); transition: 0.3s; }
        .upload-overlay { position: absolute; bottom: 0; right: 0; background: var(--accent-blue); color: white; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid var(--bg-sidebar); }

        .nav-link { color: #ffffff !important; padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: 0.3s; }
        .nav-link:hover, .nav-link.active { background: var(--accent-blue); color: white !important; }
        [dir="rtl"] .nav-link i { margin-left: 10px; }
        [dir="ltr"] .nav-link i { margin-right: 10px; }

        /* Main Content & Header Setup matching all-support-chats.php exactly */
        .admin-main-content { flex: 1; overflow-x: hidden; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-btn-back { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; transition: 0.3s; }
        

        .station-table-container { background-color: var(--bg-card) !important; border-radius: 24px; padding: 25px; border: 1px solid var(--border-color); }
        .custom-table { width: 100%; border-collapse: separate; border-spacing: 0 12px; color: var(--text-main) !important; }
        .custom-table tbody tr { background: var(--row-bg); transition: 0.3s; }
        .custom-table td { padding: 15px; border: none; vertical-align: middle; color: var(--text-main) !important; }
        .custom-table thead th { color: var(--accent-blue) !important; font-weight: 900; text-transform: uppercase; font-size: 0.85rem; border: none; }
        
        .station-img { width: 50px; height: 50px; border-radius: 12px; object-fit: cover; border: 1px solid var(--border-color); }


        .modal-content { border-radius: 30px; background-color: var(--bg-card) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); }
        .text-muted-custom { color: var(--text-main) !important; opacity: 0.7; }

        @media (max-width: 768px) {
            .main-row { flex-direction: column; }
            .admin-sidebar { height: auto; width: 100%; position: relative; padding: 15px; }
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
                        <a href="admin-dashboard.php" class="circle-btn-back shadow-sm">
                            <i class="fas fa-arrow-right" id="backIcon"></i>
                        </a>
                        <h6 class="mb-0 fw-bold" data-i18n="manage_stations_h">المحطات المسجلة</h6>
                        <div style="width: 40px;"></div> 
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div class="station-table-container shadow-sm">
                        <div class="table-responsive">
                            <table class="custom-table text-start">
                                <thead>
                                    <tr>
                                        <th data-i18n="th_img">الصورة</th>
                                        <th data-i18n="th_station_name">المحطة</th>
                                        <th data-i18n="th_owner">المالك</th>
                                        <th data-i18n="th_location">الموقع</th>
                                        <th data-i18n="th_status">الحالة</th>
                                        <th data-i18n="th_action">الإجراء</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($res_stations && $res_stations->num_rows > 0): while($row = $res_stations->fetch_assoc()): ?>
                                        <tr class="shadow-sm rounded-4">
                                            <td><img src="<?php echo htmlspecialchars(clevoStationImageUrl($row['Image'])); ?>" class="station-img"></td>
                                            <td class="fw-bold fs-6 text-primary"><?php echo htmlspecialchars($row['Name']); ?></td>
                                            <td><span class="badge bg-info-subtle text-info rounded-pill px-3 py-2 fw-bold"><?php echo htmlspecialchars($row['owner_name']); ?></span></td>
                                            <td class="small fw-bold opacity-90"><?php echo htmlspecialchars($row['Location']); ?></td>
                                            <td>
                                                <span class="badge rounded-pill px-3 py-2 <?php echo ($row['Status'] == 'Active' ? 'bg-success' : 'bg-danger'); ?>">
                                                    <span data-i18n="<?php echo ($row['Status'] == 'Active' ? 'status_active' : 'status_closed'); ?>"></span>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-2">
                                                    <button class="btn btn-sm btn-outline-warning border-2 rounded-circle" style="width:35px; height:35px;" onclick="confirmBlock(<?php echo $row['StationID']; ?>)"><i class="fas fa-ban"></i></button>
                                                    <button class="btn btn-sm btn-outline-danger border-2 rounded-circle" style="width:35px; height:35px;" onclick="confirmDelete(<?php echo $row['StationID']; ?>)"><i class="fas fa-trash-alt"></i></button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; else: ?>
                                        <tr><td colspan="6" class="text-center py-5 fw-bold" data-i18n="no_data">لا توجد محطات</td></tr>
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
                    <div class="text-danger mb-3"><i class="fas fa-sign-out-alt fa-3x"></i></div>
                    <h5 class="fw-bold mb-2" data-i18n="logout_title">تأكيد الخروج</h5>
                    <p class="small opacity-75 mb-4" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
                    <div class="d-grid gap-2">
                        <form method="POST" action="../general/logout.php" class="m-0"><?= csrf_input(); ?><button type="submit"  class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</button></form>
                        <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 340px;">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="modal-body p-2">
                    <div class="mb-3 text-warning"><i class="fas fa-exclamation-triangle fa-3x"></i></div>
                    <h5 class="fw-bold mb-2" id="confirmTitle"></h5>
                    <p class="small text-muted-custom mb-4" id="confirmBody"></p>
                    <form method="POST" id="confirmForm">
                        <?= csrf_input(); ?>
                        <input type="hidden" name="" id="confirmInputId">
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-danger rounded-pill fw-bold py-2" data-i18n="btn_confirm">تأكيد الإجراء</button>
                            <button type="button" class="btn btn-light border rounded-pill py-2" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: { 
                manage_stations_title: "إدارة المحطات | Clevo", manage_stations_h: "المحطات المسجلة", 
                nav_main: "الرئيسية", nav_approvals: "الموافقات", nav_security: "الأمان", nav_reports: "التقارير", nav_plans: "الخطط", nav_notif: "الإشعارات", nav_support_chat: "دردشة الدعم", nav_logout: "خروج", 
                th_img: "الصورة", th_station_name: "المحطة", th_owner: "المالك", th_location: "الموقع", th_status: "الحالة", th_action: "الإجراء", 
                no_data: "لا توجد محطات", btn_cancel: "إلغاء", btn_confirm: "تأكيد", status_active: "نشط", status_closed: "مغلقة",
                title_del: "حذف المحطة", body_del: "هل أنت متأكد من حذف هذه المحطة نهائياً؟", title_block: "حظر المحطة", body_block: "سيتم تعطيل المحطة فوراً، هل أنت متأكد؟",
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج"
            },
            en: { 
                manage_stations_title: "Manage Stations | Clevo", manage_stations_h: "Registered Stations", 
                nav_main: "Dashboard", nav_approvals: "Approvals", nav_security: "Security", nav_reports: "Reports", nav_plans: "Plans", nav_notif: "Notifications", nav_support_chat: "Support Chat", nav_logout: "Logout", 
                th_img: "Image", th_station_name: "Station", th_owner: "Owner", th_location: "Location", th_status: "Status", th_action: "Action", 
                no_data: "No stations found", btn_cancel: "Cancel", btn_confirm: "Confirm", status_active: "Active", status_closed: "Closed",
                title_del: "Delete Station", body_del: "Are you sure you want to permanently delete this station?", title_block: "Block Station", body_block: "Station will be disabled immediately. Confirm?",
                logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout"
            }
        };

        function confirmLogout() { new bootstrap.Modal(document.getElementById('logoutModal')).show(); }

        const cModal = new bootstrap.Modal(document.getElementById('confirmModal'));
        function confirmDelete(id) { const l = localStorage.getItem('app_lang')||'ar'; document.getElementById('confirmTitle').innerText = translations[l].title_del; document.getElementById('confirmBody').innerText = translations[l].body_del; document.getElementById('confirmInputId').setAttribute('name', 'confirm_delete_id'); document.getElementById('confirmInputId').value = id; cModal.show(); }
        function confirmBlock(id) { const l = localStorage.getItem('app_lang')||'ar'; document.getElementById('confirmTitle').innerText = translations[l].title_block; document.getElementById('confirmBody').innerText = translations[l].body_block; document.getElementById('confirmInputId').setAttribute('name', 'confirm_block_id'); document.getElementById('confirmInputId').value = id; cModal.show(); }

        window.onload = function() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            const isRtl = lang === 'ar';
            
            document.documentElement.setAttribute('data-theme', theme);
            document.getElementById('bootstrap-css').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
            
            document.querySelectorAll('[data-i18n]').forEach(el => { 
                const k = el.getAttribute('data-i18n'); 
                if (translations[lang][k]) el.innerText = translations[lang][k]; 
            });
            document.getElementById('backIcon').className = isRtl ? 'fas fa-arrow-right' : 'fas fa-arrow-left';
        };
    </script>
</body>
</html>
