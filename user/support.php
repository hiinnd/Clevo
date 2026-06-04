<?php
session_start();
include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';
include_once __DIR__ . '/../includes/support-helpers.php';

$uName = $_SESSION['userName'] ?? '';
$uID = isset($_SESSION['userID']) ? (int) $_SESSION['userID'] : 0;
$uRole = $_SESSION['role'] ?? 'user';
$prefillSubject = trim((string) ($_GET['subject'] ?? ''));
$success = false;

clevoEnsureSupportMessagesTable($conn);
clevoCleanupExpiredSupportChats($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_ticket_action'])) {
    clevo_require_csrf_token();
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $senderName = $uID > 0 ? $uName : trim((string) ($_POST['guest_name'] ?? 'Guest'));
    $isGuest = $uID > 0 ? 0 : 1;

    if ($subject !== '' && $message !== '') {
        $stmt = $conn->prepare("INSERT INTO support_tickets (user_id, user_name, user_type, subject, message, status, is_guest) VALUES (?, ?, ?, ?, ?, 'New', ?)");
        $stmt->bind_param("issssi", $uID, $senderName, $uRole, $subject, $message, $isGuest);
        if ($stmt->execute()) {
            clevoAddSupportMessage($conn, (int) $conn->insert_id, 'user', $uID ?: null, $message);
            $success = true;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_ticket_action']) && $uID > 0) {
    clevo_require_csrf_token();
    $ticketID = (int) ($_POST['ticket_id'] ?? 0);
    $message = trim((string) ($_POST['message'] ?? ''));

    $check = $conn->prepare("SELECT id FROM support_tickets WHERE id = ? AND user_id = ? LIMIT 1");
    $check->bind_param("ii", $ticketID, $uID);
    $check->execute();
    if ($message !== '' && $check->get_result()->fetch_assoc()) {
        clevoAddSupportMessage($conn, $ticketID, 'user', $uID, $message);
        $update = $conn->prepare("UPDATE support_tickets SET message = CONCAT(message, '\n\n--- User Reply ---\n', ?), status = 'New' WHERE id = ? AND user_id = ?");
        $update->bind_param("sii", $message, $ticketID, $uID);
        $update->execute();
        header('Location: support.php?ticket_id=' . $ticketID);
        exit;
    }
}

$myTickets = null;
$openTicketID = (int) ($_GET['ticket_id'] ?? 0);
if ($uID > 0) {
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%); --glass: rgba(255,255,255,.86); --text: #0369a1; --accent: #0ea5e9; --border: rgba(3,105,161,.18); --admin-bg: rgba(14,165,233,.16); --user-bg: rgba(34,197,94,.13); --input-text: #0369a1; }
        [data-theme="dark"] { --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); --glass: rgba(30,41,59,.74); --text: #f1f5f9; --accent: #38bdf8; --border: rgba(255,255,255,.14); --admin-bg: rgba(56,189,248,.2); --user-bg: rgba(34,197,94,.18); --input-text: #f1f5f9; }
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; margin: 0; padding-bottom: 24px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 22px; color: var(--text); }
        .support-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; height: 168px; border-radius: 0 0 36px 36px; background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 58px; }
        .back-circle-btn { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; background: rgba(255,255,255,.2); color: #fff; }
        .spacer-div { width: 40px; }
        [dir="rtl"] .back-circle-btn { order: 1; }
        [dir="rtl"] .spacer-div { order: 3; }
        [dir="rtl"] #backIcon { transform: rotate(0deg); }
        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] .spacer-div { order: 3; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }
        .support-title { order: 2; flex: 1; text-align: center; color: white !important; margin: 0; font-weight: 900; }
        .support-card { padding: 22px; margin-top: -78px; position: relative; z-index: 20; }
        .form-control { border-radius: 14px; background: var(--glass) !important; border: 1px solid var(--border); color: var(--input-text) !important; padding: 12px; }
        .form-control::placeholder { color: var(--text); opacity: .6; }
        .btn-send { border-radius: 50px; padding: 13px; font-weight: 800; background: var(--accent); border: 0; color: white; width: 100%; }
        .ticket-card { padding: 16px; margin-bottom: 14px; }
        .chat-thread { display: grid; gap: 10px; margin-top: 12px; }
        .msg { border-radius: 16px; padding: 12px; max-width: 92%; border: 1px solid var(--border); }
        .msg.user { justify-self: start; background: var(--user-bg); }
        .msg.admin { justify-self: end; background: var(--admin-bg); }
        .msg small { display: block; opacity: .68; margin-top: 6px; font-size: .72rem; }
        .reply-row { margin-top: 12px; display: grid; gap: 8px; }
        .expiry-note { font-size: .78rem; opacity: .8; margin-top: 8px; }
        .faq-card { border-radius: 18px; margin-bottom: 12px; border: 1px solid var(--border); overflow: hidden; background: var(--glass); color: var(--text); }
        .faq-question { padding: 15px; cursor: pointer; font-weight: 800; display: flex; justify-content: space-between; align-items: center; }
        .faq-answer { padding: 0 15px 15px; display: none; opacity: .9; font-size: .9rem; }
        @media (max-width: 560px) { .support-header { height: 140px; margin-bottom: 44px; } .support-card { margin-top: -64px; padding: 16px; } .msg { max-width: 100%; } }
    </style>
</head>
<body>
    <div class="support-header shadow">
        <div class="spacer-div"></div>
        <h4 class="support-title" data-i18n="support_page_title">الدعم الفني</h4>
        <a href="javascript:history.back();" class="back-circle-btn"><i class="fas fa-arrow-right" id="backIcon"></i></a>
    </div>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10">
                <div class="glass support-card shadow-sm mx-2">
                    <?php if ($success): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5 class="fw-bold" data-i18n="sent_success">تم الإرسال!</h5>
                            <p class="small opacity-75" data-i18n="request_received">تم استلام طلبك بنجاح.</p>
                            <a class="btn-send d-inline-flex align-items-center justify-content-center text-decoration-none" href="support.php" data-i18n="btn_ok">موافق</a>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <?= csrf_input(); ?>
                            <input type="hidden" name="send_ticket_action" value="1">
                            <h6 class="mb-4 fw-bold"><i class="fas fa-headset me-2"></i><span data-i18n="support_header_title">تواصل معنا</span></h6>
                            <?php if (!$uID): ?>
                                <div class="mb-3">
                                    <label class="small fw-bold mb-1" data-i18n="name_label">الاسم الكامل</label>
                                    <input type="text" name="guest_name" class="form-control" data-i18n-placeholder="ph_name" required>
                                </div>
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="small fw-bold mb-1" data-i18n="placeholder_subject">الموضوع</label>
                                <input type="text" name="subject" class="form-control" data-i18n-placeholder="ph_subject" value="<?= e($prefillSubject) ?>" required>
                            </div>
                            <div class="mb-4">
                                <label class="small fw-bold mb-1" data-i18n="placeholder_message">رسالتك</label>
                                <textarea name="message" class="form-control" rows="4" data-i18n-placeholder="ph_msg" required></textarea>
                            </div>
                            <button type="submit" class="btn-send shadow-sm"><i class="fas fa-paper-plane me-2"></i><span data-i18n="btn_send_ticket">إرسال الطلب الآن</span></button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if ($myTickets && $myTickets->num_rows > 0): ?>
                    <h6 class="mt-5 px-3 fw-black mb-3"><i class="fas fa-comments me-2 text-primary"></i><span data-i18n="my_messages_title">رسائلي مع الدعم</span></h6>
                    <div class="px-2">
                        <?php while ($ticket = $myTickets->fetch_assoc()): ?>
                            <?php $messages = clevoSupportMessagesForTicket($conn, $ticket); ?>
                            <div class="glass ticket-card shadow-sm" id="ticket-<?= (int) $ticket['id'] ?>">
                                <div class="d-flex justify-content-between gap-2 flex-wrap">
                                    <div class="fw-bold">#<?= (int) $ticket['id'] ?> - <?= e($ticket['subject']) ?></div>
                                    <span class="badge <?= $ticket['status'] === 'New' ? 'bg-danger' : 'bg-success' ?> rounded-pill"><?= e($ticket['status']) ?></span>
                                </div>
                                <div class="chat-thread">
                                    <?php foreach ($messages as $msg): ?>
                                        <div class="msg <?= $msg['SenderRole'] === 'admin' ? 'admin' : 'user' ?>">
                                            <strong><?= $msg['SenderRole'] === 'admin' ? '<span data-i18n="admin_label">الدعم</span>' : '<span data-i18n="you_label">أنت</span>' ?></strong>
                                            <div><?= nl2br(e($msg['Message'])) ?></div>
                                            <small><?= e($msg['CreatedAt'] ?? '') ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <form method="POST" class="reply-row">
                                    <?= csrf_input(); ?>
                                    <input type="hidden" name="reply_ticket_action" value="1">
                                    <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
                                    <textarea name="message" class="form-control" rows="2" data-i18n-placeholder="reply_placeholder" required></textarea>
                                    <button type="submit" class="btn-send"><span data-i18n="btn_reply">إرسال رد</span></button>
                                </form>
                                <div class="expiry-note" data-i18n="expiry_note">بعد رد الدعم، إذا لم ترد خلال 5 دقائق سيتم حذف المحادثة بالكامل.</div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>

                <h6 class="mt-5 px-3 fw-black mb-3"><i class="fas fa-question-circle me-2 text-primary"></i><span data-i18n="faq_title">الأسئلة الشائعة</span></h6>
                <div class="px-2 pb-5">
                    <div class="faq-card shadow-sm">
                        <div class="faq-question" onclick="toggleFaq(this)"><span data-i18n="faq_q1">كيف يمكنني تتبع طلبي؟</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer" data-i18n="faq_a1">عبر صفحة السجلات في حسابك.</div>
                    </div>
                    <div class="faq-card shadow-sm">
                        <div class="faq-question" onclick="toggleFaq(this)"><span data-i18n="faq_q2">ما هي طرق الدفع؟</span><i class="fas fa-chevron-down"></i></div>
                        <div class="faq-answer" data-i18n="faq_a2">كاش، محفظة، أو بطاقة بنكية.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                support_page_title: "الدعم الفني", support_header_title: "تواصل معنا", name_label: "الاسم الكامل",
                placeholder_subject: "الموضوع", placeholder_message: "رسالتك", btn_send_ticket: "إرسال الطلب الآن",
                faq_title: "الأسئلة الشائعة", faq_q1: "كيف يمكنني تتبع طلبي؟", faq_a1: "عبر صفحة السجلات في حسابك.",
                faq_q2: "ما هي طرق الدفع؟", faq_a2: "كاش، محفظة، أو بطاقة بنكية.", sent_success: "تم الإرسال!",
                request_received: "تم استلام طلبك بنجاح.", btn_ok: "موافق", ph_name: "أدخل اسمك...",
                ph_subject: "", ph_msg: "اكتب تفاصيل استفسارك هنا...", my_messages_title: "رسائلي مع الدعم",
                admin_label: "الدعم", you_label: "أنت", btn_reply: "إرسال رد", reply_placeholder: "اكتب ردك هنا...",
                expiry_note: "بعد رد الدعم، إذا لم ترد خلال 5 دقائق سيتم حذف المحادثة بالكامل."
            },
            en: {
                support_page_title: "Support", support_header_title: "Contact Us", name_label: "Full Name",
                placeholder_subject: "Subject", placeholder_message: "Message", btn_send_ticket: "Send Request Now",
                faq_title: "FAQ", faq_q1: "How to track my order?", faq_a1: "Via the records page in your account.",
                faq_q2: "Payment methods?", faq_a2: "Cash, Wallet, or Credit Card.", sent_success: "Sent!",
                request_received: "Request received successfully.", btn_ok: "OK", ph_name: "Enter your name...",
                ph_subject: "", ph_msg: "Write your message details here...", my_messages_title: "My Support Messages",
                admin_label: "Support", you_label: "You", btn_reply: "Reply", reply_placeholder: "Write your reply...",
                expiry_note: "After support replies, if you do not respond within 5 minutes, the whole conversation is deleted."
            }
        };

        function toggleFaq(el) {
            const ans = el.nextElementSibling;
            const icon = el.querySelector('i');
            const open = ans.style.display === 'block';
            document.querySelectorAll('.faq-answer').forEach(a => a.style.display = 'none');
            document.querySelectorAll('.faq-question i').forEach(i => i.style.transform = 'rotate(0deg)');
            if (!open) {
                ans.style.display = 'block';
                icon.style.transform = 'rotate(180deg)';
            }
        }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.getElementById('bootstrap-css').href = lang === 'ar'
                ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css'
                : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
            document.getElementById('backIcon').className = 'fas fa-arrow-right';
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });
            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                if (translations[lang][key]) el.placeholder = translations[lang][key];
            });
            const openTicket = <?= (int) $openTicketID ?>;
            if (openTicket) document.getElementById('ticket-' + openTicket)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        };
    </script>
    <script src="../assets/lamea.js?v=20260605b"></script>
</body>
</html>
