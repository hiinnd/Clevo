<?php
session_start();
include_once(__DIR__ . "/../includes/config.php");


$uName = $_SESSION['userName'] ?? "";
$uID = $_SESSION['userID'] ?? null;
$uRole = $_SESSION['role'] ?? 'user';

$prefillSubject = ''; 
$success = false;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_ticket_action'])) {
    clevo_require_csrf_token();
    $subject = trim($_POST['subject']);
    $message = trim($_POST['message']);
    
    $sender_name = $uID ? $uName : (isset($_POST['guest_name']) ? trim($_POST['guest_name']) : "Guest");
    $is_guest = $uID ? 0 : 1;

    
    $stmt = $conn->prepare("INSERT INTO `support_tickets` (user_id, user_name, user_type, subject, message, status, is_guest) VALUES (?, ?, ?, ?, ?, 'New', ?)");
    $stmt->bind_param("issssi", $uID, $sender_name, $uRole, $subject, $message, $is_guest);
    
    if ($stmt->execute()) {
        $success = true;
    }
}

$myTickets = null;
if ($uID) {
    $ticketsStmt = $conn->prepare("SELECT id, subject, message, status, created_at FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $ticketsStmt->bind_param("i", $uID);
    $ticketsStmt->execute();
    $myTickets = $ticketsStmt->get_result();
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="support_page_title">الدعم الفني | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.85);
            --text: #0369a1; 
            --accent: #0ea5e9; 
            --border: rgba(3, 105, 161, 0.2);
            --admin-reply-bg: rgba(14, 165, 233, 0.15);
            --input-text: #0369a1;
        }
        [data-theme="dark"] { 
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.7);
            --text: #f1f5f9; 
            --accent: #38bdf8; 
            --border: rgba(255, 255, 255, 0.15);
            --admin-reply-bg: rgba(56, 189, 248, 0.2);
            --input-text: #f1f5f9;
        }
        
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 20px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; color: var(--text); }

        .support-header { 
            padding: 20px; display: flex; align-items: center; justify-content: space-between; 
            position: relative; height: 180px; border-radius: 0 0 40px 40px; 
            background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 60px; 
        }
        
        .back-circle-btn { 
            width: 40px; height: 40px; border-radius: 50%; display: flex; 
            align-items: center; justify-content: center; text-decoration: none; 
            background: rgba(255,255,255,0.2); color: #fff; transition: 0.3s; z-index: 10;
        }

        .spacer-div { width: 40px; }
        [dir="rtl"] .back-circle-btn { order: 1; } 
        [dir="rtl"] .spacer-div { order: 3; }
        [dir="rtl"] #backIcon { transform: rotate(0deg); }
        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] .spacer-div { order: 3; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }

        h4.support-title { order: 2; flex-grow: 1; text-align: center; font-weight: 400 !important; color: white !important; margin: 0; }

        .support-card { padding: 25px; margin-top: -80px; position: relative; z-index: 20; }
        .form-control { border-radius: 15px; background: var(--glass) !important; border: 1px solid var(--border); color: var(--input-text) !important; padding: 12px; }
        .form-control::placeholder { color: var(--text); opacity: 0.6; }
        
        .btn-send { 
            border-radius: 50px; padding: 15px; font-weight: 700; 
            background: var(--accent); border: none; color: white; 
            width: 100%; box-shadow: 0 10px 20px rgba(0,0,0,0.1); transition: 0.3s; 
        }
        .btn-send:active { transform: scale(0.95); }

        .faq-card { border-radius: 20px; margin-bottom: 12px; border: 1px solid var(--border); overflow: hidden; background: var(--glass); color: var(--text); }
        .faq-question { padding: 15px; cursor: pointer; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
        .faq-answer { padding: 0 15px 15px; display: none; opacity: 0.9; font-size: 0.9rem; }
        .admin-reply-box { background: var(--admin-reply-bg) !important; color: var(--text); }
    </style>
</head>
<body>
    <div class="support-header shadow">
        <div class="spacer-div"></div>
        <h4 class="text-white support-title m-0" data-i18n="support_page_title">الدعم الفني</h4>
        <!-- تم تعديل الرابط ليعود للصفحة السابقة مباشرة مهما كانت دون توجيه ثابت -->
        <a href="javascript:history.back();" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="glass support-card shadow-sm mx-2">
                    <?php if($success): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5 class="fw-bold" data-i18n="sent_success">تم الإرسال!</h5>
                            <p class="small opacity-75" data-i18n="request_received">تم استلام طلبك بنجاح، سنرد عليك قريباً.</p>
                            <button class="btn-send mt-3" onclick="javascript:history.back();" data-i18n="btn_ok">موافق</button>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <?= csrf_input(); ?>
                            <input type="hidden" name="send_ticket_action" value="1">
                            <h6 class="mb-4 fw-bold"><i class="fas fa-headset me-2"></i><span data-i18n="support_header_title">كيف يمكننا مساعدتك؟</span></h6>
                            
                            <?php if(!$uID): ?>
                                <div class="mb-3">
                                    <label class="small fw-bold mb-1" data-i18n="name_label">الاسم الكامل</label>
                                    <input type="text" name="guest_name" class="form-control" data-i18n-placeholder="ph_name" required>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="small fw-bold mb-1" data-i18n="placeholder_subject">الموضوع</label>
                                <input type="text" name="subject" class="form-control" data-i18n-placeholder="ph_subject" value="<?= htmlspecialchars($prefillSubject) ?>" required>
                            </div>

                            <div class="mb-4">
                                <label class="small fw-bold mb-1" data-i18n="placeholder_message">رسالتك</label>
                                <textarea name="message" class="form-control" rows="4" data-i18n-placeholder="ph_msg" required></textarea>
                            </div>

                            <button type="submit" class="btn-send shadow-sm"><i class="fas fa-paper-plane me-2"></i> <span data-i18n="btn_send_ticket">إرسال الطلب</span></button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if($myTickets && $myTickets->num_rows > 0): ?>
                    <h6 class="mt-5 px-3 fw-black mb-3 text-current"><i class="fas fa-comments me-2 text-primary"></i> <span data-i18n="my_messages_title">رسائلي مع الدعم</span></h6>
                    <div class="px-2">
                        <?php while($ticket = $myTickets->fetch_assoc()): ?>
                            <?php $parts = explode("\n\n--- Admin Reply ---\n", $ticket['message']); ?>
                            <div class="faq-card shadow-sm p-3">
                                <div class="fw-bold mb-1">#<?= (int)$ticket['id'] ?> - <?= htmlspecialchars($ticket['subject']) ?></div>
                                <div class="small opacity-75 mb-2"><?= nl2br(htmlspecialchars($parts[0])) ?></div>
                                <?php if(count($parts) > 1): ?>
                                    <div class="small p-2 rounded-3 admin-reply-box">
                                        <strong data-i18n="admin_reply_label">رد الأدمن:</strong><br>
                                        <?= nl2br(htmlspecialchars(implode("\n\n", array_slice($parts, 1)))) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>

                <h6 class="mt-5 px-3 fw-black mb-3 text-current"><i class="fas fa-question-circle me-2 text-primary"></i> <span data-i18n="faq_title">الأسئلة الشائعة</span></h6>
                <div class="px-2 pb-5">
                    <div class="faq-card shadow-sm">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span data-i18n="faq_q1">كيف يمكنني تتبع طلبي؟</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer" data-i18n="faq_a1">يمكنك التوجه لصفحة السجلات في حسابك والضغط على تتبع الطلب.</div>
                    </div>
                    <div class="faq-card shadow-sm">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span data-i18n="faq_q2">ما هي طرق الدفع المتاحة؟</span>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="faq-answer" data-i18n="faq_a2">نوفر الدفع نقداً عند المحطة، أو عبر محفظة التطبيق، والبطاقات البنكية.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                support_page_title: "الدعم الفني", support_header_title: "تواصل معنا", name_label: "الاسم الكامل", placeholder_subject: "الموضوع", placeholder_message: "رسالتك", btn_send_ticket: "إرسال الطلب الآن", faq_title: "الأسئلة الشائعة", faq_q1: "كيف يمكنني تتبع طلبي؟", faq_a1: "عبر صفحة السجلات في حسابك.", faq_q2: "ما هي طرق الدفع؟", faq_a2: "كاش، محفظة، أو بطاقة بنكية.", sent_success: "تم الإرسال!", request_received: "تم استلام طلبك بنجاح.", btn_ok: "موافق", ph_name: "أدخل اسمك...", ph_subject: "", ph_msg: "اكتب تفاصيل استفسارك هنا...", my_messages_title: "رسائلي مع الدعم", admin_reply_label: "رد الأدمن:"
            },
            en: {
                support_page_title: "Support", support_header_title: "Contact Us", name_label: "Full Name", placeholder_subject: "Subject", placeholder_message: "Message", btn_send_ticket: "Send Request Now", faq_title: "FAQ", faq_q1: "How to track my order?", faq_a1: "Via the records page in your account.", faq_q2: "Payment methods?", faq_a2: "Cash, Wallet, or Credit Card.", sent_success: "Sent!", request_received: "Request received successfully.", btn_ok: "OK", ph_name: "Enter your name...", ph_subject: "", ph_msg: "Write your message details here...", my_messages_title: "My Support Messages", admin_reply_label: "Admin Reply:"
            }
        };

        function toggleFaq(el) {
            const ans = el.nextElementSibling;
            const icon = el.querySelector('i');
            const isOpen = ans.style.display === 'block';
            document.querySelectorAll('.faq-answer').forEach(a => a.style.display = 'none');
            document.querySelectorAll('.faq-question i').forEach(i => i.style.transform = 'rotate(0deg)');
            if (!isOpen) {
                ans.style.display = 'block';
                icon.style.transform = 'rotate(180deg)';
            }
        }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });
            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                if (translations[lang][key]) el.placeholder = translations[lang][key];
            });
        };
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>
