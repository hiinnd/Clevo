<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../vendor/autoload.php';
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
        $stmt = $conn->prepare("UPDATE `user` SET ProfilePic = ? WHERE UserID = ?");
        $stmt->bind_param("si", $newName, $admin_id);
        if ($stmt->execute()) {
            header("Location: station-approvals.php?upload=success");
            exit();
        }
    }
}


$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$admin_pic = $res_pic->fetch_assoc()['ProfilePic'] ?? 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);


function sendEmailNotification($toEmail, $userName)
{
    $subject = "تم قبول حسابك في Clevo";
    $message = "<html><body dir='rtl' style='text-align: right;'><h2>أهلاً بك يا $userName</h2><p>تم قبول حسابك بنجاح.</p></body></html>";
    $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: no-reply@clevo-system.com\r\n";
    @mail($toEmail, $subject, $message, $headers);
}


$res_pending = $conn->query("
    SELECT u.UserID, u.UserName, u.Email, u.PhoneNumber, COALESCE(s.CommercialRecordPath, u.VerificationFile) AS VerificationFile
    FROM stations s
    JOIN `user` u ON s.UserID = u.UserID
    WHERE LOWER(u.Role) = 'owner' AND s.admin_status = 'pending'
    ORDER BY s.StationID DESC
");


if (isset($_POST['action']) && isset($_POST['user_id'])) {
    clevo_require_csrf_token();
    $u_id = intval($_POST['user_id']);
    $action = $_POST['action'];
    if ($action === 'Approve') {

        $conn->query("UPDATE user SET AccountStatus = 'active' WHERE UserID = $u_id");
        $conn->query("UPDATE stations SET admin_status = 'approved', Status = 'Active' WHERE UserID = $u_id");

        
        $stmt = $conn->prepare("SELECT UserName, Email FROM user WHERE UserID = ?");
        $stmt->bind_param("i", $u_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user) {

            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;

            $mail->Username = 'support.clevo@gmail.com';
            $mail->Password = 'aessfjspgpicvcje';

            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('support.clevo@gmail.com', 'Clevo');

            $mail->addAddress($user['Email'], $user['UserName']);

            $mail->isHTML(true);

            $mail->Subject = 'Station Approved';
            $mail->Body = "
<div style='margin:0;padding:0;background:#f4f7fe;font-family:Arial,sans-serif;'>

    <div style='max-width:650px;margin:40px auto;background:#ffffff;border-radius:30px;overflow:hidden;box-shadow:0 10px 35px rgba(0,0,0,0.08);'>

        <div style='background:linear-gradient(135deg,#007bff,#2563eb);padding:50px 20px;text-align:center;'>

            <div style='width:90px;height:90px;background:#ffffff;border-radius:50%;margin:auto;line-height:90px;font-size:42px;color:#16a34a;font-weight:bold;'>
                ✔
            </div>

            <h1 style='color:#ffffff;margin-top:25px;font-size:34px;font-weight:800;'>
                Congratulations 
            </h1>

            <p style='color:#dbeafe;font-size:17px;margin-top:10px;'>
                Your station has been officially approved
            </p>
        </div>

        <div style='padding:45px 35px;text-align:center;color:#111827;'>

            <h2 style='margin-bottom:15px;font-size:26px;'>
                Hello, {$user['UserName']} 
            </h2>

            <p style='font-size:17px;line-height:1.9;color:#4b5563;margin-bottom:15px;'>
                We are excited to inform you that your station request
                has been approved successfully on Clevo.
            </p>

            <p style='font-size:16px;line-height:1.8;color:#6b7280;margin-bottom:35px;'>
                You can now access your dashboard and start managing
                your station services and bookings.
            </p>

            <a href='http://localhost/Clevo/general/login.php'
               style='display:inline-block;
               background:linear-gradient(135deg,#007bff,#2563eb);
               color:#ffffff;
               text-decoration:none;
               padding:16px 38px;
               border-radius:14px;
               font-size:18px;
               font-weight:bold;
               box-shadow:0 8px 20px rgba(37,99,235,0.3);'>
               Login To Dashboard
            </a>

        </div>

        <div style='background:#f8fafc;padding:25px;text-align:center;border-top:1px solid #e5e7eb;'>

            <p style='margin:0;color:#94a3b8;font-size:14px;'>
                Clevo System © 2026
            </p>

            <p style='margin-top:8px;color:#94a3b8;font-size:13px;'>
                Smart Car Wash Management Platform
            </p>
        </div>

    </div>
</div>
";

            $mail->send();
        }
    } else {
        $conn->query("UPDATE user SET AccountStatus = 'rejected' WHERE UserID = $u_id");
        $conn->query("UPDATE stations SET admin_status = 'rejected', Status = 'Closed' WHERE UserID = $u_id");
    }
    header("Location: station-approvals.php?success=1");
    exit;
}

?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="approvals_title">موافقات المحطات | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-rtl">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --accent-blue: #3b82f6;
            --bg-body: #f0f2f5;
            --bg-sidebar: #0f172a;
            --bg-header: #ffffff;
            --bg-card: #ffffff;
            --text-main: #000000;
            --text-muted: #1a1a1a;
            --badge-bg: #e7f1ff;
            --border-card: #dee2e6;
            --primary-blue: #3b82f6;
            --border-color: #dee2e6;
            --text-muted-custom: #4b5563;
        }

        [data-theme="dark"] {
            --bg-body: #020617;
            --bg-sidebar: #000000;
            --bg-header: #000000;
            --bg-card: #1e293b;
            --text-main: #ffffff !important;
            --text-muted: #cbd5e1;
            --badge-bg: #334155;
            --border-card: #334155;
            --border-color: #334155;
            --accent-blue: #60a5fa;
            --primary-blue: #60a5fa;
            --text-muted-custom: #cbd5e1;
        }

        body {
            font-family: 'Cairo', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            transition: 0.3s ease;
            margin: 0;
            overflow-x: hidden;
        }

        .main-row {
            display: flex;
            flex-wrap: nowrap;
            min-height: 100vh;
        }

        /* Sidebar الأدمن الثابت والمحمي بالكامل */
        .admin-sidebar {
            background: var(--bg-sidebar);
            color: white;
            padding: 20px 15px;
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 1000;
            width: 260px;
            display: flex;
            flex-direction: column;
            overflow: visible;
        }

        .admin-pic-container {
            position: relative;
            display: inline-block;
            margin-bottom: 10px;
            cursor: pointer;
        }

        .admin-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--accent-blue);
            transition: 0.3s;
        }

        .upload-overlay {
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--accent-blue);
            color: white;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            border: 2px solid var(--bg-sidebar);
        }

        .nav-link {
            color: #ffffff !important;
            padding: 12px 15px;
            border-radius: 12px;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            text-decoration: none;
            font-weight: 600;
            transition: 0.3s;
            font-size: 0.9rem;
        }

        .nav-link:hover,
        .nav-link.active {
            background: var(--accent-blue);
            color: white !important;
        }

        [dir="rtl"] .nav-link i {
            margin-left: 10px;
            margin-right: 0;
        }

        [dir="ltr"] .nav-link i {
            margin-right: 10px;
            margin-left: 0;
        }

        /* Main Content & Header Setup המوحد تماماً بالنص ومكان وحجم السهم الأزرق */
        .admin-main-content {
            flex: 1;
            overflow-x: hidden;
            padding: 0;
        }

        .orders-header {
            background: var(--bg-header);
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid var(--border-card);
            margin-bottom: 25px;
        }

        .circle-btn-back {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-blue);
            color: white !important;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 4px 10px rgba(0, 123, 255, 0.3);
            border: none;
            transition: 0.3s;
        }

        /* Cards & Modals */
        .approval-card {
            background: var(--bg-card);
            border-radius: 24px;
            padding: 25px;
            margin-bottom: 15px;
            border: 1px solid var(--border-color);
            color: var(--text-main);
        }

        [dir="rtl"] .approval-card {
            border-right: 5px solid var(--primary-blue);
        }

        [dir="ltr"] .approval-card {
            border-left: 5px solid var(--primary-blue);
        }

        .text-muted-custom {
            color: var(--text-muted-custom) !important;
            font-weight: 600;
        }

        .modal-content {
            border-radius: 30px;
            background-color: var(--bg-card) !important;
            color: var(--text-main) !important;
            border: 1px solid var(--border-color);
        }

        @media (max-width: 768px) {
            .main-row {
                flex-direction: column;
            }

            .admin-sidebar {
                height: auto;
                width: 100%;
                position: relative;
                padding: 15px;
            }

            .nav-link {
                margin-bottom: 0;
                justify-content: center;
                font-size: 0.8rem;
            }
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
                        <h6 class="mb-0 fw-bold" data-i18n="approvals_header">طلبات انضمام المحطات</h6>
                        <div style="width: 40px;"></div>
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div id="requests-list">
                        <?php if ($res_pending && $res_pending->num_rows > 0): while ($row = $res_pending->fetch_assoc()): ?>
                                <div class="approval-card shadow-sm">
                                    <div class="row align-items-center g-3">
                                        <div class="col-auto">
                                            <div class="p-3 bg-primary-subtle rounded-3 text-primary"><i class="fas fa-car-wash fa-lg"></i></div>
                                        </div>
                                        <div class="col text-start">
                                            <div class="small text-muted-custom" data-i18n="station_label">صاحب الطلب</div>
                                            <div class="fw-bold fs-5"><?php echo htmlspecialchars($row['UserName']); ?></div>
                                            <div class="small text-muted-custom"><?php echo htmlspecialchars($row['PhoneNumber']); ?></div>
                                        </div>
                                        <div class="col-12 col-md-auto d-flex gap-2">
                                            <button class="btn btn-primary btn-sm rounded-pill px-4 fw-bold shadow-sm" onclick="viewDoc('../uploads/verification/<?php echo $row['VerificationFile']; ?>')" data-i18n="btn_view_doc">السجل</button>
                                            <button class="btn btn-success btn-sm rounded-pill px-4 fw-bold shadow-sm" onclick="ask('<?php echo $row['UserID']; ?>', 'Approve')" data-i18n="btn_approve">موافقة</button>
                                            <button class="btn btn-danger btn-sm rounded-pill px-4 fw-bold shadow-sm" onclick="ask('<?php echo $row['UserID']; ?>', 'Reject')" data-i18n="btn_reject">رفض</button>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile;
                        else: ?>
                            <div class="approval-card text-center py-5 opacity-50"><i class="fas fa-clipboard-check fa-4x mb-3 text-primary"></i>
                                <h5 data-i18n="no_pending" class="fw-bold">لا توجد طلبات معلقة</h5>
                            </div>
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
                    <div class="text-danger mb-3"><i class="fas fa-sign-out-alt fa-3x"></i></div>
                    <h5 class="fw-bold mb-2" data-i18n="logout_title">تأكيد الخروج</h5>
                    <p class="text-muted-custom small mb-4" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
                    <div class="d-grid gap-2">
                        <a href="../general/logout.php" class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</a>
                        <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="docModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3 shadow-lg">
                <h6 class="fw-bold mb-3 text-center text-primary" data-i18n="doc_title">إثبات السجل التجاري</h6><img src="" id="docImage" class="img-fluid rounded-4 mb-3 border"><button class="btn btn-secondary w-100 rounded-pill fw-bold" data-bs-dismiss="modal" data-i18n="btn_close">إغلاق</button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 340px;">
            <div class="modal-content text-center p-4 shadow-lg">
                <form method="POST"><?= csrf_input(); ?><input type="hidden" name="user_id" id="modal_st_id"><input type="hidden" name="action" id="modal_st_action">
                    <div id="mIcon" class="mb-3 fs-1"></div>
                    <h6 class="fw-bold" data-i18n="confirm_action_t">تأكيد الإجراء</h6>
                    <p class="small text-muted-custom mb-4" data-i18n="confirm_msg">هل أنت متأكد؟</p>
                    <div class="d-grid gap-2"><button type="submit" class="btn btn-primary rounded-pill py-2 fw-bold" data-i18n="btn_confirm">تأكيد</button><button type="button" data-bs-dismiss="modal" class="btn btn-light border rounded-pill py-2 fw-bold" data-i18n="btn_cancel">إلغاء</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                approvals_title: "موافقات المحطات | Clevo",
                approvals_header: "طلبات انضمام المحطات",
                station_label: "اسم صاحب الطلب",
                btn_view_doc: "السجل",
                btn_approve: "موافقة",
                btn_reject: "رفض",
                confirm_action_t: "تأكيد الإجراء",
                confirm_msg: "هل أنت متأكد؟",
                btn_confirm: "تأكيد",
                btn_cancel: "إلغاء",
                no_pending: "لا توجد طلبات معلقة",
                logout_title: "تأكيد الخروج",
                logout_msg: "هل أنت متأكد من الخروج من النظام؟",
                btn_confirm_logout: "نعم، خروج",
                doc_title: "إثبات السجل التجاري",
                btn_close: "إغلاق",
                nav_main: "الرئيسية",
                nav_approvals: "الموافقات",
                nav_security: "الأمان",
                nav_reports: "التقارير",
                nav_plans: "الخطط",
                nav_notif: "الإشعارات",
                nav_support_chat: "دردشة الدعم",
                nav_logout: "خروج"
            },
            en: {
                approvals_title: "Approvals | Clevo",
                approvals_header: "Station Requests",
                station_label: "Owner Name",
                btn_view_doc: "Record",
                btn_approve: "Approve",
                btn_reject: "Reject",
                confirm_action_t: "Confirm Action",
                confirm_msg: "Are you sure?",
                btn_confirm: "Confirm",
                btn_cancel: "Cancel",
                no_pending: "No pending requests",
                logout_title: "Logout Confirmation",
                logout_msg: "Are you sure you want to log out?",
                btn_confirm_logout: "Yes, Logout",
                doc_title: "Commercial Record",
                btn_close: "Close",
                nav_main: "Dashboard",
                nav_approvals: "Approvals",
                nav_security: "Security",
                nav_reports: "Reports",
                nav_plans: "Plans",
                nav_notif: "Notifications",
                nav_support_chat: "Support Chat",
                nav_logout: "Logout"
            }
        };

        function translateDynamicText(text, lang) {
            if (lang === 'ar') return text;
            return text.replace("قام", "The user").replace("بتقديم طلب تسجيل محطة", "has submitted a station registration request").replace("يرجى مراجعة", "Please review");
        }

        function confirmLogout() {
            new bootstrap.Modal(document.getElementById('logoutModal')).show();
        }

        function viewDoc(src) {
            document.getElementById('docImage').src = src;
            new bootstrap.Modal(document.getElementById('docModal')).show();
        }

        function ask(id, action) {
            document.getElementById('modal_st_id').value = id;
            document.getElementById('modal_st_action').value = action;
            document.getElementById('mIcon').innerHTML = action === 'Approve' ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-times-circle text-danger"></i>';
            new bootstrap.Modal(document.getElementById('confirmModal')).show();
        }

        window.onload = function() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';

            document.documentElement.setAttribute('data-theme', theme);
            const isRtl = lang === 'ar';
            document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
            document.getElementById('bootstrap-rtl').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const k = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][k]) el.innerText = translations[lang][k];
            });

            if (lang === 'en') {
                document.querySelectorAll('.db-text-fix').forEach(el => {
                    el.innerText = translateDynamicText(el.innerText, 'en');
                });
            }

            const backIcon = document.getElementById('backIcon');
            if (backIcon) backIcon.className = isRtl ? 'fas fa-arrow-right' : 'fas fa-arrow-left';
            const langBtnText = document.getElementById('adminLangText');
            if (langBtnText) langBtnText.innerText = isRtl ? 'EN' : 'AR';
            const adminThemeIcon = document.getElementById('adminThemeIcon');
            if (adminThemeIcon) adminThemeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }
    </script>
</body>

</html>
