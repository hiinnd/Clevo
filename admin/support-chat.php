<?php

include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';
include_once __DIR__ . '/../includes/support-helpers.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../general/login.php");
    exit;
}


$admin_name = $_SESSION['userName'] ?? "Admin";
$admin_id = $_SESSION['userID'] ?? 0;
clevoEnsureSupportMessagesTable($conn);



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    clevo_require_csrf_token();
    $file = $_FILES['admin_pic_upload'];
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        clevo_admin_profile_save($conn,(int)$admin_id,$newName);
        if (true) {
            header("Location: support-chat.php?ticket_id=".(int)($_GET['ticket_id']??0));
            exit();
        }
    }
}

$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$admin_pic = $res_pic->fetch_assoc()['ProfilePic'] ?? 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);


if (!isset($_GET['ticket_id'])) {
    header("Location: all-support-chats.php");
    exit;
}

$t_id = intval($_GET['ticket_id']);


if ($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['admin_reply'])) {
    $success_sent=clevo_admin_web_command($conn,(int)$admin_id,'support_reply',$t_id,['message'=>trim($_POST['reply_msg']??'')]);
}
$ticket_res = $conn->query("SELECT * FROM support_tickets WHERE id = $t_id");
$ticket = $ticket_res->fetch_assoc();

if (!$ticket) {
    die("خطأ: التذكرة غير موجودة!");
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="chat_title">محادثة: <?php echo htmlspecialchars($ticket['user_name']); ?> | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
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
        
        .nav-link { color: #ffffff !important; padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; text-decoration: none; font-weight: 600; transition: 0.3s; font-size: 0.9rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent-blue); color: white !important; }


        .admin-main-content { flex: 1; overflow-x: hidden; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .back-btn-circle { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; transition: 0.3s; }
        
        .chat-container { max-width: 700px; margin: 0 auto; }
        .chat-card { background: var(--bg-card); border-radius: 24px; border: 1px solid var(--border-color); overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        
        .card-top { padding: 20px; border-bottom: 1px solid var(--border-color); background: rgba(59, 130, 246, 0.03); }
        
        .message-area { padding: 25px; max-height: 450px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; background: var(--box-bg); }
        .msg-bubble { background: var(--bg-body); color: var(--text-main); padding: 15px; border-radius: 20px; border: 1px solid var(--border-color); position: relative; max-width: 80%; }
        .msg-admin { align-self: flex-start; border-right: 5px solid var(--primary-blue); }
        .not-rtl .msg-admin { border-right: none; border-left: 5px solid var(--primary-blue); }
        .msg-reply { align-self: flex-end; border-left: 5px solid #10b981; background: rgba(16, 185, 129, 0.05); }
        .not-rtl .msg-reply { border-left: none; border-right: 5px solid #10b981; }
        
        .reply-box { padding: 20px; background: var(--bg-card); border-top: 1px solid var(--border-color); }
        
        .form-control { background: var(--bg-body) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); border-radius: 12px; font-size: 0.9rem; padding: 12px; resize: none; }
        .form-control::placeholder { color: var(--text-muted); opacity: 0.7; }
        .btn-send { background: var(--primary-blue); color: white !important; border: none; border-radius: 50px; padding: 12px; font-weight: 700; width: 100%; transition: 0.2s; }

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
        <div class="row g-0 main-row" id="mainRowWrapper">
            
            <?php include __DIR__ . '/../includes/shared_admin_sidebar.php'; ?>

            <main class="admin-main-content">
                <div class="orders-header">
                    <div class="container d-flex justify-content-between align-items-center">
                        <a href="all-support-chats.php" class="back-btn-circle shadow-sm">
                            <i class="fas fa-arrow-right" id="backIcon"></i>
                        </a>
                        <h6 class="mb-0 fw-bold">
                            <span data-i18n="chat_with">محادثة مع:</span> <?php echo htmlspecialchars($ticket['user_name']); ?>
                        </h6>
                        <span class="badge bg-danger rounded-pill px-3 py-2 small">#<?php echo $ticket['id']; ?></span>
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div class="chat-container">
                        <div class="chat-card">
                            <div class="card-top d-flex justify-content-between align-items-center text-start">
                                <div>
                                    <small class="opacity-75" data-i18n="subject">الموضوع:</small>
                                    <div class="fw-bold text-primary"><?php echo htmlspecialchars($ticket['subject']); ?></div>
                                    <?php if (!empty($ticket['guest_email'])): ?>
                                        <div class="small opacity-75 mt-1">
                                            <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($ticket['guest_email']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <span class="badge bg-<?php echo ($ticket['status'] == 'New' ? 'danger' : 'success'); ?> rounded-pill px-3 py-2 small">
                                    <?php 
                                        if($ticket['status'] == 'New') {
                                            echo '<span data-i18n="status_new">جديد</span>';
                                        } else {
                                            echo '<span data-i18n="status_replied">تم الرد</span>';
                                        }
                                    ?>
                                </span>
                            </div>

                            <div class="message-area text-start">
                                <?php 
                                $chat_parts = explode("\n\n--- Admin Reply ---\n", $ticket['message']);
                                ?>
                                
                                <div class="msg-bubble msg-admin shadow-sm">
                                    <div class="small fw-bold text-primary mb-1"><?php echo htmlspecialchars($ticket['user_name']); ?>:</div>
                                    <div style="white-space: pre-wrap; font-weight: 600; color:var(--text-main);"><?php echo htmlspecialchars($chat_parts[0]); ?></div>
                                    <div class="text-end opacity-50 mt-2" style="font-size: 0.7rem; color:var(--text-main);"><?php echo $ticket['created_at']; ?></div>
                                </div>

                                <?php if (count($chat_parts) > 1): ?>
                                    <?php for ($i = 1; $i < count($chat_parts); $i++): ?>
                                    <div class="msg-bubble msg-reply shadow-sm">
                                        <div class="small fw-bold text-success mb-1" data-i18n="you_admin">أنت (الدعم الفني):</div>
                                        <div style="white-space: pre-wrap; font-weight: 600; color:var(--text-main);"><?php echo htmlspecialchars($chat_parts[$i]); ?></div>
                                        <div class="text-end opacity-50 mt-1" style="font-size: 0.7rem; color:var(--text-main);" data-i18n="status_replied">تم الرد</div>
                                    </div>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </div>

                            <div class="reply-box">
                                <form method="POST">
                                    <?= csrf_input(); ?>
                                    <textarea name="reply_msg" class="form-control mb-3" rows="3" data-i18n-placeholder="placeholder_reply" placeholder="اكتب ردك هنا..." required></textarea>
                                    <button type="submit" name="admin_reply" class="btn-send shadow-sm">
                                        <i class="fas fa-paper-plane me-1"></i> <span data-i18n="btn_send">إرسال الرد</span>
                                    </button>
                                </form>
                            </div>
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

    <div class="modal fade" id="successModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="text-success mb-2"><i class="fas fa-check-circle fa-4x"></i></div>
                <h6 class="fw-bold" data-i18n="msg_sent">تم إرسال الرد بنجاح!</h6>
                <p class="small opacity-75 mb-3" data-i18n="modal_success_body">تم تحديث حالة التذكرة وإبلاغ المستخدم.</p>
                <button type="button" class="btn btn-primary btn-sm rounded-pill px-5 fw-bold" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: { 
                chat_title: "محادثة الدعم | Clevo", chat_with: "محادثة مع:", subject: "الموضوع:", btn_send: "إرسال الرد", msg_sent: "تم إرسال الرد بنجاح!",
                modal_success_body: "تم تحديث حالة التذكرة وإبلاغ المستخدم.",
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", 
                btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء",
                status_new: "جديد", status_replied: "تم الرد", you_admin: "أنت (الدعم الفني):",
                placeholder_reply: "اكتب ردك هنا..."
            },
            en: { 
                chat_title: "Support Chat | Clevo", chat_with: "Chat with:", subject: "Subject:", btn_send: "Send Reply", msg_sent: "Reply sent successfully!",
                modal_success_body: "Ticket status updated and user notified.",
                logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", 
                btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel",
                status_new: "New", status_replied: "Replied", you_admin: "You (Support):",
                placeholder_reply: "Type your reply here..."
            }
        };

        function applySettings() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            const isRtl = lang === 'ar';
            
            document.documentElement.setAttribute('data-theme', theme);
            document.getElementById('bootstrap-css').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
            document.documentElement.lang = lang;


            const wrapper = document.getElementById('mainRowWrapper');
            if(isRtl) wrapper.classList.remove('not-rtl'); else wrapper.classList.add('not-rtl');

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) el.innerText = translations[lang][key];
            });

            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                if (translations[lang] && translations[lang][key]) el.placeholder = translations[lang][key];
            });

            const backIcon = document.getElementById('backIcon');
            if(backIcon) backIcon.className = isRtl ? 'fas fa-arrow-right' : 'fas fa-arrow-left';

            <?php if(isset($success_sent)): ?>
                new bootstrap.Modal(document.getElementById('successModal')).show();
            <?php endif; ?>
        }

        window.onload = applySettings;
    </script>
</body>
</html>
