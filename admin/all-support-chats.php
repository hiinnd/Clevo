<?php
include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';
include_once __DIR__ . '/../includes/support-helpers.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../general/login.php"); 
    exit;
}

$admin_id = $_SESSION['userID'] ?? 0;
$admin_name = $_SESSION['userName'] ?? "Admin";
clevoEnsureSupportTicketsGuestEmailColumn($conn);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    $file = $_FILES['admin_pic_upload'];
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        clevo_admin_profile_save($conn,(int)$admin_id,$newName);
        header("Location: all-support-chats.php?upload=success");
        exit();
    }
}


$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$admin_pic = $res_pic->fetch_assoc()['ProfilePic'] ?? 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);


$res_all_tickets = $conn->query("SELECT * FROM support_tickets ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="support_chats_title">محادثات الدعم | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-rtl">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --accent-blue: #3b82f6; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #3b82f6;
            --border-color: #dee2e6;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #1e293b; --text-main: #ffffff !important; --text-muted: #cbd5e1; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --accent-blue: #60a5fa; --primary-blue: #60a5fa;
        }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-row { display: flex; flex-wrap: nowrap; min-height: 100vh; }
        
      
        .admin-sidebar { background: var(--bg-sidebar); color: white; padding: 20px 15px; position: sticky; top: 0; height: 100vh; z-index: 1000; width: 260px; display: flex; flex-direction: column; overflow: visible; }
        .admin-pic-container { position: relative; display: inline-block; margin-bottom: 10px; cursor: pointer; }
        .admin-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-blue); transition: 0.3s; }
        .upload-overlay { position: absolute; bottom: 0; right: 0; background: var(--accent-blue); color: white; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid var(--bg-sidebar); }
        
        .nav-link { color: #ffffff !important; padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; text-decoration: none; font-weight: 600; transition: 0.3s; font-size: 0.9rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent-blue); color: white !important; }
        [dir="rtl"] .nav-link i { margin-left: 10px; }
        [dir="ltr"] .nav-link i { margin-right: 10px; }

        
        .admin-main-content { flex: 1; overflow-x: hidden; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-btn-back { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; }
        
        
        .chat-card { background: var(--bg-card); border-radius: 24px; padding: 25px; margin-bottom: 15px; border: 1px solid var(--border-color); transition: 0.3s; color: var(--text-main); }
        [dir="rtl"] .chat-card { border-right: 5px solid var(--primary-blue); }
        [dir="ltr"] .chat-card { border-left: 5px solid var(--primary-blue); }
        
        .user-avatar-circle { width: 50px; height: 50px; border-radius: 50%; background: var(--accent-blue); color: white; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.2rem; }
        .modal-content { border-radius: 30px; background-color: var(--bg-card) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); }

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
                        <h6 class="mb-0 fw-bold" data-i18n="nav_support_chat">دردشة الدعم</h6>
                        <div style="width: 40px;"></div> 
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div id="chats-list">
                        <?php if($res_all_tickets && $res_all_tickets->num_rows > 0): ?>
                            <?php while($ticket = $res_all_tickets->fetch_assoc()): ?>
                            <div class="chat-card shadow-sm">
                                <div class="row align-items-center g-3 text-start">
                                    <div class="col-auto">
                                        <div class="user-avatar-circle shadow-sm">
                                            <?php echo strtoupper(mb_substr($ticket['user_name'], 0, 1, 'UTF-8')); ?>
                                        </div>
                                    </div>
                                    <div class="col">
                                        <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($ticket['user_name']); ?></h6>
                                        <?php if (!empty($ticket['guest_email'])): ?>
                                            <div class="small opacity-75 mb-1">
                                                <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($ticket['guest_email']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <p class="small mb-0 opacity-75 text-truncate" style="max-width: 300px; color: var(--text-main);">
                                            <strong>[<?php echo htmlspecialchars($ticket['subject']); ?>]</strong> - <?php echo htmlspecialchars($ticket['message']); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-3 text-md-end">
                                        <span class="badge bg-<?php echo ($ticket['status'] == 'New' ? 'danger' : 'secondary'); ?> rounded-pill px-3 mb-1">
                                            <?php echo ($ticket['status'] == 'New' ? 'جديد' : 'تم الرد'); ?>
                                        </span>
                                        <div class="small opacity-50"><?php echo date('Y-m-d H:i', strtotime($ticket['created_at'])); ?></div>
                                    </div>
                                    <div class="col-12 col-md-auto">
                                        <a href="support-chat.php?ticket_id=<?php echo $ticket['id']; ?>" class="btn btn-primary rounded-pill px-4 fw-bold w-100">
                                            <span data-i18n="btn_open_chat">فتح المحادثة للرد</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="chat-card text-center opacity-50 fw-bold py-5" data-i18n="no_tickets">لا توجد محادثات دعم حالياً</div>
                        <?php endif; ?>
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
                        <form method="POST" action="../general/logout.php" class="m-0"><?= csrf_input(); ?><button type="submit"  class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</button></form>
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
                support_chats_title: "محادثات الدعم | Clevo", nav_main: "الرئيسية", nav_approvals: "الموافقات", 
                nav_security: "الأمان", nav_reports: "التقارير", nav_plans: "الخطط", nav_notif: "الإشعارات", 
                nav_support_chat: "دردشة الدعم", nav_logout: "خروج", btn_open_chat: "فتح المحادثة للرد",
                logout_title: "تأكيد الخروج", no_tickets: "لا توجد محادثات دعم حالياً",
                logout_msg: "هل أنت متأكد من الخروج من النظام؟",
                btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء"
            },
            en: {
                support_chats_title: "Support Chats | Clevo", nav_main: "Dashboard", nav_approvals: "Approvals", 
                nav_security: "Security", nav_reports: "Reports", nav_plans: "Plans", nav_notif: "Notifications", 
                nav_support_chat: "Support Chat", nav_logout: "Logout", btn_open_chat: "Open Chat to Reply",
                logout_title: "Logout Confirmation", no_tickets: "No support chats found",
                logout_msg: "Are you sure you want to log out?",
                btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel"
            }
        };

        function confirmLogout() { 
            new bootstrap.Modal(document.getElementById('logoutModal')).show(); 
        }

        function applySettings() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = (lang === 'ar' ? 'rtl' : 'ltr');

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) el.innerText = translations[lang][key];
            });

            const backIcon = document.getElementById('backIcon');
            if (backIcon) backIcon.className = (lang === 'ar' ? 'fas fa-arrow-right' : 'fas fa-arrow-left');
        }

        window.onload = applySettings;
    </script>
</body>
</html>
