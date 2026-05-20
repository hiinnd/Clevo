<?php
session_start();
include(__DIR__ . "/../includes/config.php");

// دالة JWT ثابتة لضمان أمان الجلسة
function generate_jwt($user_id, $role)
{
    return clevo_generate_jwt((int) $user_id, (string) $role);
}

// التوجيه التلقائي بناءً على الرول
if (isset($_SESSION['userID']) && !empty($_SESSION['userID'])) {
    if ($_SESSION['role'] === 'Admin') {
        header("Location: ../admin/admin-dashboard.php");
    } elseif ($_SESSION['role'] === 'owner') {
        header("Location: ../owner/owner-dashboard.php");
    } elseif ($_SESSION['role'] === 'driver') {
        header("Location: ../driver/driver-dashboard.php");
    } else {
        header("Location: ../user/home.php");
    }
    exit();
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login_action']) && !validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "csrf_error";
    } elseif (isset($_POST['login_action'])) {
        $login_input = trim($_POST['login_input'] ?? "");
        $password    = $_POST['password'] ?? "";
        $device_id   = $_POST['device_id'] ?? "";

        if (empty($login_input) || empty($password)) {
            $error = "fill_all";
        } else {
            if (is_numeric($login_input)) {
                if (strlen($login_input) !== 10 || substr($login_input, 0, 2) !== "07") {
                    $error = "invalid_phone_format";
                }
            }

            if (empty($error)) {
                if (!empty($device_id)) {
                    $check_b = $conn->prepare("SELECT id FROM blacklist WHERE DeviceID = ?");
                    $check_b->bind_param("s", $device_id);
                    $check_b->execute();
                    if ($check_b->get_result()->num_rows > 0) {
                        $error = "device_banned";
                    }
                }

                if (empty($error)) {
                    // جلب بيانات المستخدم كاملة بما فيها UserName
                    $stmt = $conn->prepare("SELECT UserID, UserName, Password, Role, AccountStatus, CurrentAccessCode FROM `user` WHERE PhoneNumber = ? OR Email = ?");
                    $stmt->bind_param("ss", $login_input, $login_input);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows === 0) {
                        $error = "user_not_found";
                    } else {
                        $user = $result->fetch_assoc();
                        
                        $is_password_correct = false;
                        
                        // 1. فحص خاص للأدمن (دعم النص المجرد حالياً لتجنب قفل حسابك، ويفضل لاحقاً تشفيره)
                        if ($user['Role'] === 'Admin') {
                            if ($password === $user['Password']) {
                                $is_password_correct = true;
                            }
                        } else {
                            // 2. استخدام نظام التشفير الآمن لبقية المستخدمين (password_verify)
                            if (password_verify($password, $user['Password'])) {
                                $is_password_correct = true;
                            }
                        }

                        // 3. التحقق الإضافي للسائق عبر كود الدخول (Access Code)
                        $is_driver_code = ($user['Role'] === 'driver' && !empty($user['CurrentAccessCode']) && $password === $user['CurrentAccessCode']);

                        if (!$is_driver_code && !$is_password_correct) {
                            $error = "wrong_pass";
                        } elseif ($user['AccountStatus'] === 'pending') {
                            $error = "pending_status";
                        } elseif ($user['AccountStatus'] === 'rejected' || $user['Role'] === 'Banned') {
                            $error = "user_banned";
                        } else {
                            // توليد التوكن للتطبيق والموقع
                            $token = clevo_generate_jwt((int) $user['UserID'], (string) $user['Role']);
                            
                            // تخزين التوكن في الكوكيز لسهولة استدعائه من المتصفح أو التطبيق
                            setcookie("access_token", $token, [
                                'expires' => time() + (3600 * 24),
                                'path' => '/',
                                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                                'httponly' => true,
                                'samesite' => 'Lax'
                            ]);

                            // إعداد جلسة العمل (Session) التقليدية للويب
                            session_regenerate_id(true);
                            $_SESSION['userID']   = $user['UserID'];
                            $_SESSION['userName'] = $user['UserName']; 
                            $_SESSION['role']     = $user['Role'];
                            $_SESSION['jwt_token'] = $token; // حفظ التوكن في السشن أيضاً

                            // التوجيه بناءً على الرول
                            if ($user['Role'] === 'Admin') {
                                header("Location: ../admin/admin-dashboard.php"); 
                            } elseif ($user['Role'] === 'owner') {
                                header("Location: ../owner/owner-dashboard.php"); 
                            } elseif ($user['Role'] === 'driver') {
                                header("Location: ../driver/driver-dashboard.php");
                            } else {
                                header("Location: ../user/home.php");
                            }
                            exit;
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="login_title">تسجيل الدخول | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-blue: #007bff; --bg-body: #f4f7fe; --card-bg: #ffffff;
            --text-main: #111827; --input-border: #e5e7eb; --modal-bg: #ffffff;
        }
        [data-theme="dark"] {
            --bg-body: #020617; --card-bg: #1e293b; --text-main: #f9fafb;
            --input-border: #334155; --modal-bg: #1e293b;
        }
        body { font-family: 'Cairo', sans-serif; background: var(--bg-body); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; transition: 0.3s; }
        .login-card { background: var(--card-bg); border-radius: 25px; padding: 40px 30px; width: 100%; max-width: 420px; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2); border: 1px solid var(--input-border); text-align: center; color: var(--text-main); }
        .form-control { border-radius: 12px; padding: 12px; border: 2px solid var(--input-border); background: transparent !important; color: var(--text-main) !important; }
        .btn-login { background: var(--brand-blue); color: white; border-radius: 50px; padding: 14px; font-weight: 700; border: none; width: 100%; margin-top: 10px; transition: 0.3s; }
        .modal-content { background-color: var(--modal-bg); color: var(--text-main); border-radius: 25px; border: none; }
        .password-wrapper { position: relative; }
        .password-toggle { position: absolute; top: 50%; transform: translateY(-50%); cursor: pointer; color: var(--brand-blue); z-index: 10; }
        [dir="rtl"] .password-toggle { left: 15px; } [dir="ltr"] .password-toggle { right: 15px; }
        .btn-back-home { display: block; margin-top: 20px; color: #94a3b8; text-decoration: none; font-size: 0.9rem; transition: 0.3s; }
    </style>
</head>
<body>
    <script>
        document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light');
    </script>

    <div class="login-card shadow-lg">
        <div class="brand-logo h1 fw-black text-primary">CLEVO</div>
        <p class="mb-4 opacity-75" data-i18n="welcome_back">مرحباً بعودتك!</p>

        <form method="POST" onsubmit="prepareLogin()">
            <input type="hidden" name="login_action" value="1">
            <?= csrf_input(); ?>
            <input type="hidden" name="device_id" id="device_id">
            <input type="hidden" name="login_input" id="login_input">

            <div class="mb-3 text-start">
                <label class="small mb-2 fw-bold" data-i18n="login_label">رقم الهاتف أو البريد الإلكتروني</label>
                <input type="text" id="raw_input" class="form-control" required placeholder="07XXXXXXXX">
            </div>

            <div class="mb-2 text-start">
                <label class="small mb-2 fw-bold" data-i18n="pass_label">كلمة السر أو كود الدخول</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="password" class="form-control" required>
                    <i onclick="togglePassword('password', 'toggleIcon')" id="toggleIcon" class="fas fa-eye password-toggle"></i>
                </div>
            </div>

            <div class="text-end mb-4">
                <a href="#" class="small text-decoration-none fw-bold" data-bs-toggle="modal" data-bs-target="#forgotStep1" data-i18n="forgot_pass">نسيت كلمة السر؟</a>
            </div>

            <button type="submit" class="btn-login shadow fw-bold" data-i18n="btn_login">تسجيل الدخول</button>
        </form>

        <div class="mt-4 text-center small">
            <span data-i18n="no_account">ليس لديك حساب؟</span>
            <a href="register.php" class="text-primary text-decoration-none fw-bold" data-i18n="create_account">إنشاء حساب جديد</a>
        </div>
        <a href="../general/landing.php" class="btn-back-home fw-bold">
            <i class="fas fa-arrow-right me-1" id="backIcon"></i> <span data-i18n="back_to_home">العودة للرئيسية</span>
        </a>
    </div>

    <div class="modal fade" id="forgotStep1" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3 shadow-lg text-center">
                <div class="modal-body">
                    <div class="mb-3 text-primary"><i class="fas fa-mobile-alt fa-3x"></i></div>
                    <h5 class="fw-bold mb-3" data-i18n="forgot_pass">استعادة كلمة السر</h5>
                    <p class="small opacity-75 mb-4" data-i18n="forgot_pass_desc">أدخل رقم هاتفك لإرسال كود التحقق.</p>
                    <input type="text" id="reset_phone" class="form-control mb-3 text-center" placeholder="07XXXXXXXX">
                    <button class="btn btn-primary rounded-pill w-100 fw-bold" onclick="goToStep(1, 2)" data-i18n="btn_send">إرسال الكود</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="forgotStep2" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3 shadow-lg text-center">
                <div class="modal-body">
                    <div class="mb-3 text-warning"><i class="fas fa-shield-alt fa-3x"></i></div>
                    <h5 class="fw-bold mb-3" data-i18n="enter_code">أدخل كود التحقق</h5>
                    <input type="text" id="verification_code" class="form-control mb-3 text-center" placeholder="- - - -">
                    <button class="btn btn-primary rounded-pill w-100 fw-bold" onclick="goToStep(2, 3)" data-i18n="btn_verify">تحقق</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="forgotStep3" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3 shadow-lg text-center">
                <div class="modal-body">
                    <div class="mb-3 text-success"><i class="fas fa-lock-open fa-3x"></i></div>
                    <h5 class="fw-bold mb-3" data-i18n="reset_pass_title">كلمة سر جديدة</h5>
                    <input type="password" id="new_pw" class="form-control mb-2" placeholder="كلمة السر الجديدة">
                    <input type="password" id="confirm_new_pw" class="form-control mb-3" placeholder="تأكيد كلمة السر">
                    <button class="btn btn-success rounded-pill w-100 fw-bold" onclick="finishReset()" data-i18n="btn_save">حفظ كلمة السر</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="msgModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <div class="mb-3" id="msgIcon"></div>
                <h5 class="fw-bold mb-2" id="msgTitle"></h5>
                <p id="msgText" class="opacity-75"></p>
                <button type="button" class="btn btn-primary rounded-pill px-5 fw-bold" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                login_title: "تسجيل الدخول | Clevo", welcome_back: "مرحباً بعودتك!", login_label: "رقم الهاتف أو البريد الإلكتروني",
                pass_label: "كلمة السر أو كود الدخول", btn_login: "تسجيل الدخول", forgot_pass: "نسيت كلمة السر؟",
                btn_send: "إرسال الكود", enter_code: "أدخل كود التحقق", btn_verify: "تحقق", reset_pass_title: "تعيين كلمة سر جديدة",
                new_pass: "كلمة السر الجديدة", confirm_new_pass: "تأكيد كلمة السر", btn_save: "حفظ",
                success_title: "تم بنجاح", reset_final_msg: "تم تغيير كلمة السر بنجاح، يمكنك الآن تسجيل الدخول.",
                fill_all: "يرجى تعبئة جميع الحقول", wrong_pass: "بيانات الدخول خاطئة", back_to_home: "العودة للرئيسية",
                no_account: "ليس لديك حساب؟", create_account: "إنشاء حساب جديد", user_not_found: "الحساب غير موجود",
                pending_status: "بانتظار الموافقة", device_banned: "الجهاز محظور", user_banned: "الحساب محظور",
                invalid_phone_format: "رقم الهاتف يجب أن يبدأ بـ 07 ويتكون من 10 أرقام",
                error_title: "تنبيه النظام"
            },
            en: {
                login_title: "Login | Clevo", welcome_back: "Welcome Back!", login_label: "Phone or Email",
                pass_label: "Password or Access Code", btn_login: "Login", forgot_pass: "Forgot Password?",
                btn_send: "Send Code", enter_code: "Enter Code", btn_verify: "Verify", reset_pass_title: "Reset Password",
                new_pass: "New Password", confirm_new_pass: "Confirm Password", btn_save: "Save",
                success_title: "Success", reset_final_msg: "Password changed successfully.",
                fill_all: "Fill all fields", wrong_pass: "Invalid credentials", back_to_home: "Back to Home",
                no_account: "No account?", create_account: "Create one", user_not_found: "Account not found",
                pending_status: "Pending approval", device_banned: "Device banned", user_banned: "Account banned",
                invalid_phone_format: "Phone must start with 07 and be 10 digits",
                error_title: "System Alert"
            }
        };

        function goToStep(current, next) {
            bootstrap.Modal.getInstance(document.getElementById('forgotStep' + current)).hide();
            setTimeout(() => {
                new bootstrap.Modal(document.getElementById('forgotStep' + next)).show();
            }, 400);
        }

        function finishReset() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            bootstrap.Modal.getInstance(document.getElementById('forgotStep3')).hide();
            const msgIcon = document.getElementById('msgIcon');
            msgIcon.innerHTML = '<i class="fas fa-check-circle fa-4x text-success"></i>';
            document.getElementById('msgTitle').innerText = translations[lang].success_title;
            document.getElementById('msgText').innerText = translations[lang].reset_final_msg;
            new bootstrap.Modal(document.getElementById('msgModal')).show();
        }

        function togglePassword(id, iconId) {
            const pw = document.getElementById(id);
            const icon = document.getElementById(iconId);
            pw.type = pw.type === 'password' ? 'text' : 'password';
            icon.className = `fas fa-eye${pw.type === 'password' ? '' : '-slash'} password-toggle`;
        }

        function prepareLogin() { document.getElementById('login_input').value = document.getElementById('raw_input').value.trim(); }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.setAttribute('data-theme', theme);
            
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });

            if (lang === 'en') document.getElementById('backIcon').style.transform = 'rotate(180deg)';
            document.getElementById('device_id').value = localStorage.getItem('clevo_device_id') || "DEV-NEW";

            <?php if ($error): ?>
                const errKey = "<?= $error ?>";
                const msgIcon = document.getElementById('msgIcon');
                msgIcon.innerHTML = '<i class="fas fa-times-circle fa-4x text-danger"></i>';
                document.getElementById('msgTitle').innerText = translations[lang].error_title;
                document.getElementById('msgText').innerText = translations[lang][errKey] || errKey;
                new bootstrap.Modal(document.getElementById('msgModal')).show();
            <?php endif; ?>
        };
    </script>
</body>
</html>
