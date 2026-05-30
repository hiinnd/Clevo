<?php
session_start();
include_once __DIR__ . '/../includes/config.php';

$message_sent = false;

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['full_name'])) {
    clevo_require_csrf_token();
    $name = mysqli_real_escape_string($conn, $_POST['full_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    $message = mysqli_real_escape_string($conn, $_POST['message']);
    
    
    $uID = isset($_SESSION['userID']) ? $_SESSION['userID'] : "NULL";
    $uRole = $_SESSION['role'] ?? 'guest';

    
    $sql = "INSERT INTO support_tickets (user_id, user_name, user_type, subject, message, status, is_guest) 
            VALUES ($uID, '$name', '$uRole', '$subject', '$message', 'New', " . ($uID === "NULL" ? 1 : 0) . ")";
    
    if (mysqli_query($conn, $sql)) {
        $message_sent = true;
        
        
        $notif_title = "رسالة دعم جديدة";
        $notif_body = "لديك رسالة جديدة من: " . $name . " بخصوص: " . $subject;
        mysqli_query($conn, "INSERT INTO notifications (title, body, type, is_read) VALUES ('$notif_title', '$notif_body', 'info', 0)");
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="nav_contact">تواصل معنا | Clevo</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;700&display=swap" rel="stylesheet">

    <style>
        :root { 
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.7);
            --text: #0369a1; --accent: #0ea5e9; --border: rgba(255, 255, 255, 0.1);
        }
        [data-theme="dark"] { 
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.5);
            --text: #f1f5f9; --accent: #38bdf8; --border: rgba(255, 255, 255, 0.05);
        }
        
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 20px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; }

        /* الهيدر الموحد */
        .page-header { 
            padding: 20px; display: flex; align-items: center; justify-content: space-between;
            height: 180px; border-radius: 0 0 40px 40px; 
            background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 40px; 
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

        /* تعديل الخط ليكون نحيفاً (Normal/Light) تماماً كالصورة */
        .header-title-light { 
            order: 2; 
            flex-grow: 1; 
            text-align: center; 
            margin: 0; 
            font-family: 'Cairo', sans-serif !important;
            font-weight: 400 !important; /* الوزن العادي النحيف */
            font-size: 1.5rem;
            color: white !important; 
        }

        .contact-card { padding: 30px; margin-top: -60px; position: relative; z-index: 20; }
        .info-item { display: flex; align-items: center; margin-bottom: 20px; gap: 15px; padding: 15px; border-radius: 20px; background: rgba(14, 165, 233, 0.05); }
        .info-icon { width: 45px; height: 45px; background: var(--accent); color: white; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
        
        .form-control { border-radius: 15px; padding: 12px; border: 1px solid var(--border); background: var(--glass) !important; color: var(--text) !important; }
        .btn-send { border-radius: 50px; padding: 15px; font-weight: 700; background: var(--accent); border: none; color: white; width: 100%; box-shadow: 0 10px 20px rgba(0,0,0,0.1); transition: 0.3s; }

        .custom-footer { margin-top: 40px; text-align: center; padding: 15px; }
        .footer-logo { width: 70px; margin-bottom: 10px; }
        .footer-copy { font-size: 0.75rem; opacity: 0.6; }
    </style>
</head>

<body>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>

    <div class="page-header shadow">
        <div class="spacer-div"></div>
        <h4 class="header-title-light" data-i18n="nav_contact">تواصل معنا</h4>
        <a href="javascript:history.back()" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="container">
        <div class="glass contact-card shadow-lg mx-2">
            <?php if($message_sent): ?>
                <div class="text-center py-4">
                    <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                    <h5 class="fw-bold" data-i18n="msg_success">تم الإرسال بنجاح!</h5>
                    <p class="small opacity-75" data-i18n="contact_subtitle">سنقوم بالرد عليك في أقرب وقت ممكن.</p>
                    <button class="btn btn-primary rounded-pill px-5" onclick="window.location.href='landing.php'" data-i18n="btn_ok">موافق</button>
                </div>
            <?php else: ?>
                <div class="row g-4">
                    <div class="col-lg-5">
                        <h5 class="fw-bold mb-4" data-i18n="contact_info_t">معلومات الاتصال</h5>
                        <div class="info-item">
                            <div class="info-icon"><i class="fas fa-phone-alt"></i></div>
                            <div>
                                <h6 class="fw-bold mb-0" data-i18n="phone_label">رقم الهاتف</h6>
                                <p class="small opacity-75 mb-0" dir="ltr">+962 7 9527 9261</p>
                                <p class="small opacity-75 mb-0" dir="ltr">+962 7 9053 0063</p>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-icon"><i class="fas fa-envelope"></i></div>
                            <div>
                                <h6 class="fw-bold mb-0" data-i18n="email_label">البريد الإلكتروني</h6>
                                <p class="small opacity-75 mb-0">support.clevo@gmail.com</p>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-icon"><i class="fas fa-map-marker-alt"></i></div>
                            <div>
                                <h6 class="fw-bold mb-0" data-i18n="location_label">الموقع</h6>
                                <p class="small opacity-75 mb-0" data-i18n="address_val">عمان، الأردن</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <form action="contact.php" method="POST">
                            <?= csrf_input(); ?>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="small fw-bold mb-1" data-i18n="full_name_label">الاسم الكامل</label>
                                    <input type="text" name="full_name" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold mb-1" data-i18n="email_label">البريد الإلكتروني</label>
                                    <input type="email" name="email" class="form-control" placeholder="example@mail.com" required>
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold mb-1" data-i18n="placeholder_subject">الموضوع</label>
                                    <input type="text" name="subject" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold mb-1" data-i18n="placeholder_message">الرسالة</label>
                                    <textarea name="message" class="form-control" rows="4" required></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn-send mt-2" data-i18n="btn_submit">إرسال الرسالة</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="custom-footer">
        <img src="../assets/Images/Logo.png" alt="Logo" class="footer-logo">
        <div class="footer-copy">© 2026 Clevo | <span data-i18n="f_copy">صمم بكل حب لخدمتكم</span></div>
    </footer>

    <script>
        const translations = {
            ar: {
                nav_contact: "تواصل معنا", contact_info_t: "معلومات الاتصال", phone_label: "رقم الهاتف",
                email_label: "البريد الإلكتروني", location_label: "الموقع", address_val: "عمان، الأردن",
                full_name_label: "الاسم الكامل", placeholder_subject: "الموضوع", placeholder_message: "الرسالة",
                btn_submit: "إرسال الرسالة", msg_success: "تم إرسال رسالتك بنجاح!", f_copy: "صمم بكل حب لخدمتكم", btn_ok: "موافق",
                contact_subtitle: "سنقوم بالرد عليك في أقرب وقت ممكن."
            },
            en: {
                nav_contact: "Contact Us", contact_info_t: "Contact Info", phone_label: "Phone",
                email_label: "Email", location_label: "Location", address_val: "Amman, Jordan",
                full_name_label: "Full Name", placeholder_subject: "Subject", placeholder_message: "Message",
                btn_submit: "Send Message", msg_success: "Message sent successfully!", f_copy: "Designed with love", btn_ok: "OK",
                contact_subtitle: "We will get back to you as soon as possible."
            }
        };

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light');
            
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });
        };
    </script>
</body>
</html>
