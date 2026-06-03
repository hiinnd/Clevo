<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;

require_once '../vendor/autoload.php';
include_once(__DIR__ . "/../includes/config.php");
include_once(__DIR__ . "/../includes/app-helpers.php");

$error = "";
$registration_success = false;
$registered_role = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_action'])) {
    clevo_require_csrf_token();
    $role      = $_POST['role'];
    $name      = trim($_POST['full_name']);
    $email     = trim($_POST['email']);
    $phone_suffix = trim($_POST['phone_suffix']);

    if (strlen($phone_suffix) !== 8) {
        $error = "system_error";
    } else {
        $phone     = "07" . $phone_suffix;
        $pass      = $_POST['password'];
        $confirm   = $_POST['confirm_password'];
        $st_name   = trim($_POST['station_name'] ?? "");

        $password_regex = "/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/";

        $check = $conn->prepare("SELECT UserID FROM `user` WHERE PhoneNumber = ? OR Email = ?");
        $check->bind_param("ss", $phone, $email);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $error = "phone_exists";
        } elseif (!preg_match($password_regex, $pass)) {
            $error = "weak_password";
        } elseif ($pass !== $confirm) {
            $error = "pass_mismatch";
        } else {
            $file_path = NULL;
            if ($role === 'owner') {
                if (isset($_FILES['verification_file']) && $_FILES['verification_file']['error'] === 0) {
                    $target_dir = "../uploads/verification/";
                    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                    $file_name = "verify_" . time() . "_" . uniqid() . "." . pathinfo($_FILES["verification_file"]["name"], PATHINFO_EXTENSION);
                    move_uploaded_file($_FILES["verification_file"]["tmp_name"], $target_dir . $file_name);
                    $file_path = $file_name;
                }
            }

            if ($role === 'owner' && (empty($st_name) || empty($file_path))) {
                $error = "owner_docs_required";
            } else {
                $db_committed = false;
                $conn->begin_transaction();
                try {
                    
                    $hashed_pass = password_hash($pass, PASSWORD_DEFAULT);
                    $status = 'pending';
                    $token = bin2hex(random_bytes(32));
                    $stmt = $conn->prepare("INSERT INTO `user` (UserName, Email, PhoneNumber, Password, Role, AccountStatus, VerificationFile, VerificationToken) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssssss", $name, $email, $phone, $hashed_pass, $role, $status, $file_path, $token);
                    $stmt->execute();
                    $new_id = $conn->insert_id;

                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host_name = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $app_path = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/Clevo/general/register.php')), '/\\');
                    $link = $scheme . "://" . $host_name . $app_path . "/Emails/verify.php?token=" . urlencode($token);
                    $wallet_stmt = $conn->prepare("INSERT INTO `wallet` (UserID, Balance) VALUES (?, 0.00)");
                    $wallet_stmt->bind_param("i", $new_id);
                    $wallet_stmt->execute();

                    $loyalty_stmt = $conn->prepare("INSERT INTO `loyalty` (UserID, PointsBalance) VALUES (?, 0)");
                    $loyalty_stmt->bind_param("i", $new_id);
                    $loyalty_stmt->execute();
                    if ($role === 'customer') {
                        clevoCreateRoleNotification(
                            $conn,
                            'Admin',
                            'مستخدم جديد سجل في النظام',
                            'قام المستخدم ' . $name . ' بإنشاء حساب جديد كزبون في التطبيق.',
                            'info',
                            '../admin/manage-users.php',
                            'New user registered in the system',
                            'User ' . $name . ' created a new customer account in the app.'
                        );
                    }
                    if ($role === 'owner') {
                        $stmt_st = $conn->prepare("INSERT INTO `stations` (Name, UserID, Status, admin_status, CommercialRecordPath) VALUES (?, ?, 'Closed', 'pending', ?)");
                        $stmt_st->bind_param("sis", $st_name, $new_id, $file_path);
                        $stmt_st->execute();

                        clevoCreateRoleNotification(
                            $conn,
                            'Admin',
                            'طلب تسجيل محطة جديدة',
                            'قام ' . $name . ' بتقديم طلب تسجيل محطة: ' . $st_name . '، يرجى مراجعة السجل التجاري.',
                            'warning',
                            '../admin/station-approvals.php',
                            'New station registration request',
                            $name . ' submitted a station registration request: ' . $st_name . '. Please review the commercial record.'
                        );
                    }

                    $conn->commit();
                    $db_committed = true;
                    $mail = new PHPMailer(true);

                    $mail->isSMTP();

                    $mail->Host = 'smtp.gmail.com';

                    $mail->SMTPAuth = true;

                    $mail->Username = 'support.clevo@gmail.com';

                    $mail->Password = 'aessfjspgpicvcje';

                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

                    $mail->Port = 587;

                    $mail->setFrom('support.clevo@gmail.com', 'Clevo');

                    $mail->addAddress($email);

                    $mail->isHTML(true);

                    $mail->Subject = 'Verify Your Email';


                    ob_start();

                    include __DIR__ . '/../Emails/verify_template.php';

                    $template = ob_get_clean();

                    $mail->Body = $template;

                    $mail->send();
                    $registration_success = true;
                    $registered_role = $role;
                } catch (Throwable $e) {
                    if (!$db_committed) {
                        $conn->rollback();
                    }
                    $error = "system_error";
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
    <title data-i18n="register_title">إنشاء حساب | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-blue: #007bff;
            --bg-body: #f4f7fe;
            --card-bg: #ffffff;
            --text-main: #111827;
            --input-border: #e5e7eb;
            --owner-box: rgba(0, 123, 255, 0.05);
        }

        [data-theme="dark"] {
            --bg-body: #020617;
            --card-bg: #1e293b;
            --text-main: #f9fafb;
            --input-border: #334155;
            --owner-box: rgba(255, 255, 255, 0.05);
        }

        body {
            font-family: 'Cairo', sans-serif;
            background: var(--bg-body);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: 0.3s;
        }

        .register-card {
            background: var(--card-bg);
            border-radius: 25px;
            padding: 35px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            border: 1px solid var(--input-border);
            color: var(--text-main);
        }

        .form-control,
        .form-select {
            border-radius: 12px;
            padding: 12px;
            border: 2px solid var(--input-border);
            background: transparent !important;
            color: var(--text-main) !important;
        }

        .form-select option {
            background: var(--card-bg);
            color: var(--text-main);
        }

        .btn-register {
            background: var(--brand-blue);
            color: white;
            border-radius: 50px;
            padding: 14px;
            font-weight: 700;
            border: none;
            width: 100%;
            transition: 0.3s;
        }

        .phone-group {
            display: flex;
            direction: ltr;
        }

        .phone-prefix {
            background: var(--brand-blue);
            border: 2px solid var(--brand-blue);
            border-radius: 12px 0 0 12px;
            padding: 12px;
            font-weight: bold;
            color: #fff;
        }

        .phone-input-fixed {
            border-radius: 0 12px 12px 0 !important;
            flex: 1;
        }

        .password-wrapper {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--brand-blue);
            z-index: 10;
        }

        [dir="rtl"] .password-toggle {
            left: 15px;
        }

        [dir="ltr"] .password-toggle {
            right: 15px;
        }

        #ownerSection {
            background: var(--owner-box);
            border: 1px dashed var(--brand-blue) !important;
            color: var(--text-main);
        }

        .btn-back-home {
            display: block;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            text-align: center;
        }

        .modal-content {
            border-radius: 25px;
            background: var(--card-bg);
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }

        .password-hint {
            font-size: 0.75rem;
            opacity: 0.7;
            margin-top: 5px;
            line-height: 1.4;
        }
    </style>
</head>

<body>
    <script>
        document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light');
    </script>

    <div class="register-card shadow-lg">
        <div class="text-center mb-4">
            <h2 class="fw-black text-primary">CLEVO</h2>
            <p class="small opacity-75" data-i18n="create_acc">أنشئ حسابك للبدء</p>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <?= csrf_input(); ?>
            <input type="hidden" name="register_action" value="1">
            <div class="mb-3">
                <label class="small fw-bold mb-2" data-i18n="i_am">أريد التسجيل كـ...</label>
                <select class="form-select fw-bold" name="role" id="roleSelect" onchange="toggleOwner()">
                    <option value="customer" data-i18n="role_user">زبون</option>
                    <option value="owner" data-i18n="role_owner">صاحب محطة</option>
                </select>
            </div>

            <div id="ownerSection" style="display: none;" class="mb-3 p-3 rounded-4">
                <div class="mb-2">
                    <label class="small fw-bold mb-1" data-i18n="st_name">اسم المحطة</label>
                    <input type="text" name="station_name" class="form-control" placeholder="">
                </div>
                <div>
                    <label class="small fw-bold mb-1" data-i18n="st_doc">صورة السجل التجاري</label>
                    <input type="file" name="verification_file" class="form-control" accept="image/*">
                </div>
            </div>

            <div class="mb-3">
                <label class="small fw-bold mb-1" data-i18n="full_name">الاسم الكامل</label>
                <input type="text" name="full_name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label class="small fw-bold mb-1" data-i18n="email_label">البريد الإلكتروني</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="mb-3 text-start">
                <label class="small fw-bold mb-1 d-block" data-i18n="phone">رقم الهاتف</label>
                <div class="phone-group">
                    <span class="phone-prefix">07</span>
                    <input type="text" name="phone_suffix" class="form-control phone-input-fixed"
                        maxlength="8" pattern="\d{8}" title="يجب إدخال 8 أرقام"
                        oninput="this.value = this.value.replace(/[^0-9]/g, '').substring(0, 8);" required>
                </div>
            </div>
            <div class="row g-2 mb-4">
                <div class="col-6">
                    <label class="small fw-bold mb-1" data-i18n="pass">كلمة المرور</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="pass1" class="form-control" required>
                        <i onclick="togglePass('pass1')" class="fas fa-eye password-toggle"></i>
                    </div>
                </div>
                <div class="col-6">
                    <label class="small fw-bold mb-1" data-i18n="confirm_pass">تأكيدها</label>
                    <div class="password-wrapper">
                        <input type="password" name="confirm_password" id="pass2" class="form-control" required>
                        <i onclick="togglePass('pass2')" class="fas fa-eye password-toggle"></i>
                    </div>
                </div>
                <div class="col-12">
                    <p class="password-hint" data-i18n="pass_hint">يجب أن تحتوي على 8 رموز، تشمل حرفاً كبيراً، صغيراً، رقماً ورمزاً خاصاً.</p>
                </div>
            </div>
            <button type="submit" class="btn-register shadow" data-i18n="btn_reg">إنشاء الحساب</button>

            <div class="text-center mt-3 small">
                <span data-i18n="already_have_acc">لديك حساب؟</span>
                <a href="login.php" class="fw-bold text-primary text-decoration-none" data-i18n="login_here">سجل دخولك</a>
            </div>
            <a href="../general/landing.php" class="btn-back-home fw-bold">
                <i class="fas fa-arrow-right me-1" id="backIcon"></i> <span data-i18n="back_to_home">العودة للرئيسية</span>
            </a>
        </form>
    </div>

    <div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4">
                <div id="modalIcon">
                    <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                </div>
                <h5 class="fw-bold" id="modalTitle"></h5>
                <p class="small opacity-75" id="modalMsg"></p>
                <button onclick="location.href='login.php'" class="btn btn-primary w-100 rounded-pill fw-bold py-2 mt-2" data-i18n="btn_login_now">حسناً</button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="errorModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4">
                <i class="fas fa-circle-exclamation fa-4x text-danger mb-3"></i>
                <h5 class="fw-bold" id="errorModalTitle"></h5>
                <p class="small opacity-75" id="errorModalMsg"></p>
                <button type="button" class="btn btn-primary w-100 rounded-pill fw-bold py-2 mt-2" data-bs-dismiss="modal" data-i18n="btn_ok">حسناً</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                register_title: "إنشاء حساب | Clevo",
                create_acc: "أنشئ حسابك للبدء",
                i_am: "أريد التسجيل كـ...",
                role_user: "زبون",
                role_owner: "صاحب محطة",
                st_name: "اسم المحطة",
                st_doc: "صورة السجل التجاري",
                full_name: "الاسم الكامل",
                email_label: "البريد الإلكتروني",
                phone: "رقم الهاتف",
                pass: "كلمة المرور",
                confirm_pass: "تأكيدها",
                btn_reg: "إنشاء الحساب",
                already_have_acc: "لديك حساب؟",
                login_here: "سجل دخولك",
                back_to_home: "العودة للرئيسية",
                phone_exists: "رقم الهاتف أو الإيميل مسجل مسبقاً",
                weak_password: "كلمة المرور ضعيفة (تحتاج كببر، صغير، رقم، رمز)",
                pass_mismatch: "كلمة المرور غير متطابقة",
                owner_docs_required: "يرجى إرفاق اسم المحطة والسجل",
                system_error: "حدث خطأ في النظام، حاول لاحقاً",
                reg_done_t: "تم التسجيل!",
                reg_done_m: "تم إنشاء حسابك بنجاح. تحقق من بريدك الإلكتروني لتفعيل الحساب.",
                owner_wait_t: "تحقق من بريدك",
                owner_wait_m: "تم تسليم بيانات محطتك. اضغط رابط التحقق في الإيميل لتفعيل الحساب، وسيبقى طلب المحطة قيد المراجعة.",
                btn_login_now: "تسجيل الدخول",
                pass_hint: "يجب أن تحتوي على 8 رموز، تشمل حرفاً كبيراً، صغيراً، رقماً ورمزاً خاصاً.",
                error_title: "تنبيه", btn_ok: "حسناً"
            },
            en: {
                register_title: "Register | Clevo",
                create_acc: "Create your account",
                i_am: "I am a...",
                role_user: "Customer",
                role_owner: "Owner",
                st_name: "Station Name",
                st_doc: "Doc",
                full_name: "Full Name",
                email_label: "Email",
                phone: "Phone",
                pass: "Password",
                confirm_pass: "Confirm",
                btn_reg: "Register",
                already_have_acc: "Have account?",
                login_here: "Login",
                back_to_home: "Back to Home",
                phone_exists: "Phone or Email already exists",
                weak_password: "Weak password (Need Upper, Lower, Num, Special)",
                pass_mismatch: "Passwords do not match",
                owner_docs_required: "Station name and doc required",
                system_error: "System error, try again",
                reg_done_t: "Success!",
                reg_done_m: "Account created. Please verify your email to activate it.",
                owner_wait_t: "Check Your Email",
                owner_wait_m: "Your station data was submitted. Verify your email to activate the account while the station request remains under review.",
                btn_login_now: "Login Now",
                pass_hint: "Must be 8 characters, include uppercase, lowercase, number and special symbol.",
                error_title: "Alert", btn_ok: "OK"
            }
        };

        function toggleOwner() {
            const role = document.getElementById('roleSelect').value;
            document.getElementById('ownerSection').style.display = (role === 'owner' ? 'block' : 'none');
        }

        function togglePass(id) {
            const el = document.getElementById(id);
            el.type = el.type === 'password' ? 'text' : 'password';
        }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });
            if (lang === 'en') document.getElementById('backIcon').style.transform = 'rotate(180deg)';

            <?php if ($registration_success): ?>
                const role = "<?= $registered_role ?>";
                const modalTitle = document.getElementById('modalTitle');
                const modalMsg = document.getElementById('modalMsg');
                const iconDiv = document.getElementById('modalIcon');

                if (role === 'owner') {
                    modalTitle.innerText = translations[lang].owner_wait_t;
                    modalMsg.innerText = translations[lang].owner_wait_m;
                    iconDiv.innerHTML = '<i class="fas fa-clock fa-4x text-warning mb-3"></i>';
                } else {
                    modalTitle.innerText = translations[lang].reg_done_t;
                    modalMsg.innerText = translations[lang].reg_done_m;
                }
                new bootstrap.Modal(document.getElementById('successModal')).show();
            <?php elseif ($error): ?>
                const errorKey = <?= json_encode($error) ?>;
                document.getElementById('errorModalTitle').innerText = translations[lang].error_title;
                document.getElementById('errorModalMsg').innerText = translations[lang][errorKey] || errorKey;
                new bootstrap.Modal(document.getElementById('errorModal')).show();
            <?php endif; ?>
        };
    </script>
</body>

</html>
