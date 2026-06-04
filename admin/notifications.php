<?php
session_start();
include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../general/login.php");
    exit;
}


$admin_name = $_SESSION['userName'] ?? "Admin";
$admin_id = $_SESSION['userID'] ?? 0;

clevoEnsureNotificationsTargetColumn($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    $file = $_FILES['admin_pic_upload'];
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        $conn->query("UPDATE `user` SET ProfilePic = '$newName' WHERE UserID = $admin_id");
        header("Location: notifications.php?upload=success");
        exit();
    }
}

$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$admin_pic = $res_pic->fetch_assoc()['ProfilePic'] ?? 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);


if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    $notif_id = intval($_GET['read']);
    $stmt_read = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND (user_id = ? OR target_role = 'Admin')");
    $stmt_read->bind_param("ii", $notif_id, $admin_id);
    $stmt_read->execute();
    header("Location: notifications.php");
    exit;
}


if (isset($_GET['read_all'])) {
    $stmt_read_all = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE is_read = 0 AND (user_id = ? OR target_role = 'Admin')");
    $stmt_read_all->bind_param("i", $admin_id);
    $stmt_read_all->execute();
    header("Location: notifications.php");
    exit;
}


$stmt_notifications = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? OR target_role = 'Admin' ORDER BY created_at DESC LIMIT 50");
$stmt_notifications->bind_param("i", $admin_id);
$stmt_notifications->execute();
$res_notifications = $stmt_notifications->get_result();


$stmt_unread = $conn->prepare("SELECT COUNT(*) AS total FROM notifications WHERE is_read = 0 AND (user_id = ? OR target_role = 'Admin')");
$stmt_unread->bind_param("i", $admin_id);
$stmt_unread->execute();
$unread_count = (int) ($stmt_unread->get_result()->fetch_assoc()['total'] ?? 0);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="notif_title">مركز الإشعارات | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-rtl">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --accent-blue: #3b82f6; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #3b82f6;
            --border-color: #dee2e6; --box-bg: #ffffff;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #1e293b; --text-main: #ffffff !important; --text-muted: #cbd5e1; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --accent-blue: #60a5fa; --primary-blue: #60a5fa; --box-bg: #0f172a;
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-row { display: flex; flex-wrap: nowrap; min-height: 100vh; }
        

        .admin-sidebar { background: var(--bg-sidebar); color: white; padding: 20px 15px; position: sticky; top: 0; height: 100vh; z-index: 1000; width: 260px; display: flex; flex-direction: column; overflow: visible; }
        .admin-pic-container { position: relative; display: inline-block; margin-bottom: 10px; cursor: pointer; }
        .admin-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-blue); transition: 0.3s; }
        .upload-overlay { position: absolute; bottom: 0; right: 0; background: var(--accent-blue); color: white; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid var(--bg-sidebar); }

        .sidebar-nav-container { flex-grow: 1; margin-top: 20px; }
        .nav-link { color: #ffffff !important; padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; text-decoration: none; font-weight: 600; transition: 0.3s; font-size: 0.9rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent-blue); color: white !important; }


        .admin-main-content { flex: 1; overflow-x: hidden; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-btn-back { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; transition: 0.3s; }

        /* Cards */
        .notif-card { background: var(--bg-card); border-radius: 24px; padding: 25px; margin-bottom: 15px; border: 1px solid var(--border-color); transition: 0.3s; cursor: pointer; color: var(--text-main); }
        [dir="rtl"] .notif-card { border-right: 5px solid var(--primary-blue); }
        [dir="ltr"] .notif-card { border-left: 5px solid var(--primary-blue); }
        
        .notif-card.unread { background: rgba(96, 165, 250, 0.03); }
        .notif-icon { width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; background: rgba(96, 165, 250, 0.1); color: var(--accent-blue); }

        .modal-sm-custom { max-width: 380px; }
        .modal-content { background-color: var(--bg-card) !important; color: var(--text-main) !important; border-radius: 30px; border: 1px solid var(--border-color); }
        .modal-body-text { color: var(--text-main) !important; opacity: 0.9; font-weight: 600; }

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
                    <div class="container d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <a href="admin-dashboard.php" class="circle-btn-back shadow-sm"><i class="fas fa-arrow-right" id="backIcon"></i></a>
                            <h6 class="mb-0 fw-bold" data-i18n="notif_header">مركز الإشعارات</h6>
                        </div>
                        <?php if ($unread_count > 0): ?>
                        <a href="?read_all=1" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" data-i18n="btn_read_all">تحديد الكل كمقروء</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div class="notif-container">
                        <?php if ($res_notifications && $res_notifications->num_rows > 0): ?>
                            <?php while($notif = $res_notifications->fetch_assoc()): ?>
                            <?php
                                $title_ar = (string) ($notif['title'] ?? '');
                                $body_ar = (string) ($notif['body'] ?? '');
                                $title_en = trim((string) ($notif['title_en'] ?? ''));
                                $body_en = trim((string) ($notif['body_en'] ?? ''));
                                $icon_title = $title_ar . ' ' . $title_en;
                            ?>
                            <div class="notif-card shadow-sm <?php echo ($notif['is_read'] == 0) ? 'unread' : ''; ?>" 
                                 onclick='showNotifDetail(<?php echo json_encode($title_ar); ?>, <?php echo json_encode($body_ar); ?>, <?php echo json_encode($title_en); ?>, <?php echo json_encode($body_en); ?>, <?php echo (int) $notif['id']; ?>)'>
                                <div class="row align-items-center g-3 text-start">
                                    <div class="col-auto">
                                        <div class="notif-icon shadow-sm">
                                            <i class="fas <?php 
                                                if(strpos($icon_title, 'دعم') !== false || strpos($icon_title, 'Support') !== false) echo 'fa-headset';
                                                elseif(strpos($icon_title, 'موافقة') !== false || strpos($icon_title, 'محطة') !== false || strpos($icon_title, 'Station') !== false) echo 'fa-check-double';
                                                else echo 'fa-bell'; 
                                            ?>"></i>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <h6 class="mb-1 fw-bold db-text" data-ar="<?php echo htmlspecialchars($title_ar); ?>" data-en="<?php echo htmlspecialchars($title_en); ?>"><?php echo htmlspecialchars($title_ar); ?></h6>
                                        <p class="small mb-0 opacity-75 text-truncate db-text" data-ar="<?php echo htmlspecialchars($body_ar); ?>" data-en="<?php echo htmlspecialchars($body_en); ?>" style="max-width: 500px; color: var(--text-main);"><?php echo htmlspecialchars($body_ar); ?></p>
                                        <small class="opacity-50 mt-1 d-block"><i class="far fa-clock me-1"></i> <?php echo date('Y-m-d H:i', strtotime($notif['created_at'])); ?></small>
                                    </div>
                                    <div class="col-auto text-end">
                                        <?php if($notif['is_read'] == 0): ?>
                                            <span class="badge bg-primary rounded-pill px-3" data-i18n="notif_new">جديد</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="notif-card text-center py-5 opacity-50 fw-bold" data-i18n="no_notif">لا توجد إشعارات حالياً</div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="notifModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm-custom">
            <div class="modal-content p-4 text-center border-0 shadow-lg">
                <div class="mb-3 text-primary"><i class="fas fa-bell fa-3x"></i></div>
                <h6 id="modalTitle" class="fw-bold mb-3"></h6>
                <div id="modalBody" class="modal-body-text mb-4" style="white-space: pre-wrap; font-size: 0.85rem;"></div>
                <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold" onclick="markAsReadAndClose()" data-i18n="notif_close">إغلاق</button>
            </div>
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
                        <a href="../general/logout.php" class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</a>
                        <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const db_translations = {
            "طلب تسجيل محطة جديدة": "New Station Registration Request",
            "مستخم جديد سجل في النظام": "New user registered in the system",
            "يرجى مراجعة السجل التجاري": "Please review the commercial registration",
            "بتقديم طلب تسجيل محطة": "has submitted a station registration request",
            "بإنشاء حساب جديد كزبون": "has created a new customer account",
            "رسالة دعم جديدة": "New Support Message",
            "لديك تذكرة دعم معلقة": "You have a pending support ticket",
            "قام المستخدم": "User",
            "المستخدم": "User",
            "جديد": "New",
            "مقروء": "Read",
            "نشاط تجريبي": "Demo activity",
            "تم تحميل بيانات تجريبية لمحطات عمان والحجوزات.": "Demo data for Amman stations and bookings was loaded."
        };

        const translations = {
            ar: { 
                notif_title: "الإشعارات | Clevo", nav_main: "الرئيسية", nav_approvals: "الموافقات", nav_security: "الأمان", nav_reports: "التقارير", nav_plans: "الخطط", nav_notif: "الإشعارات", nav_support_chat: "دردشة الدعم", nav_logout: "خروج", 
                notif_header: "مركز الإشعارات", btn_read_all: "تحديد الكل كمقروء", no_notif: "لا توجد إشعارات حالياً", notif_new: "جديد", notif_close: "إغلاق",
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء"
            },
            en: { 
                notif_title: "Notifications | Clevo", nav_main: "Dashboard", nav_approvals: "Approvals", nav_security: "Security", nav_reports: "Reports", nav_plans: "Plans", nav_notif: "Notifications", nav_support_chat: "Support Chat", nav_logout: "Logout", 
                notif_header: "Notification Center", btn_read_all: "Mark all read", no_notif: "No notifications right now", notif_new: "New", notif_close: "Close",
                logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel"
            }
        };

        let currentNotifId = null;
        const nModal = new bootstrap.Modal(document.getElementById('notifModal'));
        const lModal = new bootstrap.Modal(document.getElementById('logoutModal'));

        function translateDBText(text, lang) {
            if (lang === 'ar') return text;
            let t = text;
            Object.keys(db_translations).forEach(k => { 
                t = t.replace(new RegExp(k, "g"), db_translations[k]); 
            });
            return t.trim();
        }

        function localizedDBText(arText, enText, lang) {
            const englishText = enText && enText.trim() ? enText : translateDBText(arText, 'en');
            return lang === 'en' && englishText ? englishText : arText;
        }

        function showNotifDetail(titleAr, bodyAr, titleEn, bodyEn, id) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            document.getElementById('modalTitle').innerText = localizedDBText(titleAr, titleEn, lang);
            document.getElementById('modalBody').innerText = localizedDBText(bodyAr, bodyEn, lang);
            currentNotifId = id;
            nModal.show();
        }

        function confirmLogout() { lModal.show(); }
        function markAsReadAndClose() { if (currentNotifId) window.location.href = '?read=' + currentNotifId; else nModal.hide(); }

        function applySettings() {
            const l = localStorage.getItem('app_lang') || 'ar';
            const t = localStorage.getItem('global_theme') || 'dark';
            const isRtl = l === 'ar';
            document.documentElement.setAttribute('data-theme', t);
            
            document.getElementById('bootstrap-rtl').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
            
            document.querySelectorAll('[data-i18n]').forEach(el => { 
                const k = el.getAttribute('data-i18n'); 
                if (translations[l][k]) el.innerText = translations[l][k]; 
            });

            document.querySelectorAll('.db-text').forEach(el => {
                const arText = el.getAttribute('data-ar') || el.innerText;
                const enText = el.getAttribute('data-en') || '';
                el.innerText = localizedDBText(arText, enText, l);
            });

            document.getElementById('backIcon').className = isRtl ? 'fas fa-arrow-right' : 'fas fa-arrow-left';
        }
        window.onload = applySettings;
    </script>
</body>
</html>
