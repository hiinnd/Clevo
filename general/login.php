<?php
session_start();
include_once(__DIR__ . "/../includes/config.php");


function generate_jwt($user_id, $role)
{
    return clevo_generate_jwt((int) $user_id, (string) $role);
}

function get_owner_station_admin_status(mysqli $conn, int $user_id): string
{
    $stmt = $conn->prepare("SELECT admin_status FROM stations WHERE UserID = ? ORDER BY StationID DESC LIMIT 1");
    if (!$stmt) {
        return '';
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $station = $stmt->get_result()->fetch_assoc();

    return strtolower((string) ($station['admin_status'] ?? ''));
}


if (isset($_SESSION['userID']) && !empty($_SESSION['userID'])) {
    $session_role = strtolower((string) ($_SESSION['role'] ?? ''));
    if ($session_role === 'admin') {
        header("Location: ../admin/admin-dashboard.php");
    } elseif ($session_role === 'owner') {
        header("Location: ../owner/owner-dashboard.php");
    } elseif ($session_role === 'driver') {
        header("Location: ../driver/driver-dashboard.php");
    } else {
        header("Location: ../user/home.php");
    }
    exit();
}

$error = "";
$pending_role = "";

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

                    $stmt = $conn->prepare("SELECT UserID, UserName, Password, Role, AccountStatus, CurrentAccessCode FROM `user` WHERE PhoneNumber = ? OR Email = ?");
                    $stmt->bind_param("ss", $login_input, $login_input);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows === 0) {
                        $error = "user_not_found";
                    } else {
                        $user = $result->fetch_assoc();

                        $is_password_correct = false;


                        $stored_password = (string) $user['Password'];
                        $role_lower = strtolower((string) $user['Role']);

                        if (password_verify($password, $stored_password)) {
                            $is_password_correct = true;
                        } elseif (hash_equals($stored_password, $password)) {
                            $is_password_correct = true;
                            $new_hash = password_hash($password, PASSWORD_DEFAULT);
                            $update_pass = $conn->prepare("UPDATE `user` SET Password = ? WHERE UserID = ?");
                            if ($update_pass) {
                                $update_pass->bind_param("si", $new_hash, $user['UserID']);
                                $update_pass->execute();
                            }
                        }


                        $is_driver_code = ($role_lower === 'driver' && !empty($user['CurrentAccessCode']) && hash_equals((string) $user['CurrentAccessCode'], $password));

                        if (!$is_driver_code && !$is_password_correct) {
                            $error = "wrong_pass";
                        } elseif ($user['AccountStatus'] === 'pending' && in_array($role_lower, ['owner', 'customer', 'user'], true)) {
                            $error = "pending_status";
                            $pending_role = $role_lower === 'owner' ? 'owner' : 'user';
                        } elseif ($user['AccountStatus'] === 'rejected' || $user['Role'] === 'Banned') {
                            $error = "user_banned";
                        } elseif ($role_lower === 'owner' && get_owner_station_admin_status($conn, (int) $user['UserID']) === 'pending') {
                            $error = "pending_status";
                            $pending_role = "owner";
                        } else {

                            if ($role_lower === 'admin') {
                                $session_role = 'Admin';
                            } elseif ($role_lower === 'owner') {
                                $session_role = 'owner';
                            } elseif ($role_lower === 'driver') {
                                $session_role = 'driver';
                            } else {
                                $session_role = 'customer';
                            }

                            $token = clevo_generate_jwt((int) $user['UserID'], $session_role);


                            setcookie("access_token", $token, [
                                'expires' => time() + (3600 * 24),
                                'path' => '/',
                                'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                                'httponly' => true,
                                'samesite' => 'Lax'
                            ]);


                            session_regenerate_id(true);
                            $_SESSION['userID']   = $user['UserID'];
                            $_SESSION['userName'] = $user['UserName'];
                            $_SESSION['role']     = $session_role;
                            $_SESSION['jwt_token'] = $token;


                            if ($session_role === 'Admin') {
                                header("Location: ../admin/admin-dashboard.php");
                            } elseif ($session_role === 'owner') {
                                header("Location: ../owner/owner-dashboard.php");
                            } elseif ($session_role === 'driver') {
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
if (isset($_POST['reset_password_action'])) {

    header('Content-Type: application/json');

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode([
            'status' => 'error',
            'message' => 'csrf_failed'
        ]);
        exit;
    }

    $newPassword =
        $_POST['password'] ?? '';

    $userID =
        $_SESSION['reset_user_id'] ?? null;

    if (!$userID || empty($newPassword)) {

        echo json_encode([
            'status' => 'error'
        ]);

        exit;
    }

    $hashedPassword =
        password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
    UPDATE user
    SET Password = ?,
        reset_otp = NULL,
        reset_otp_expiry = NULL
    WHERE UserID = ?
    ");

    $stmt->bind_param(
        "si",
        $hashedPassword,
        $userID
    );

    $stmt->execute();

    unset($_SESSION['reset_user_id']);

    echo json_encode([
        'status' => 'success'
    ]);

    exit;
}
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="login_title">تسجيل الدخول | Clevo</title>
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
            --modal-bg: #ffffff;
            --brand-blue-soft: rgba(0, 123, 255, 0.12);
        }

        [data-theme="dark"] {
            --bg-body: #020617;
            --card-bg: #1e293b;
            --text-main: #f9fafb;
            --input-border: #334155;
            --modal-bg: #1e293b;
            --brand-blue-soft: rgba(37, 99, 235, 0.22);
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

        .login-card {
            background: var(--card-bg);
            border-radius: 25px;
            padding: 40px 30px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
            border: 1px solid var(--input-border);
            text-align: center;
            color: var(--text-main);
        }

        .form-control {
            border-radius: 12px;
            padding: 12px;
            border: 2px solid var(--input-border);
            background: transparent !important;
            color: var(--text-main) !important;
        }

        .btn-login {
            background: var(--brand-blue);
            color: white;
            border-radius: 50px;
            padding: 14px;
            font-weight: 700;
            border: none;
            width: 100%;
            margin-top: 10px;
            transition: 0.3s;
        }

        .modal-content {
            background-color: var(--modal-bg);
            color: var(--text-main);
            border-radius: 25px;
            border: 1px solid var(--input-border);
        }

        .msg-status-icon {
            width: 76px;
            height: 76px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--brand-blue-soft);
            color: var(--brand-blue);
        }

        .msg-status-icon i {
            font-size: 2rem;
        }

        .msg-status-icon.success {
            background: rgba(25, 135, 84, 0.12);
            color: #198754;
        }

        .msg-status-icon.error {
            background: rgba(220, 53, 69, 0.12);
            color: #dc3545;
        }

        #msgText {
            line-height: 1.7;
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

        .btn-back-home {
            display: block;
            margin-top: 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.9rem;
            transition: 0.3s;
        }

        .otp-input {
            width: 55px !important;
            height: 60px;
            font-size: 24px;
            font-weight: bold;
            border-radius: 16px;
        }

        .clevo-icon {
            color: #2563eb;
        }


        .otp-header {
            background: linear-gradient(135deg, #007bff, #2563eb);
            margin: -16px -16px 25px -16px;
            padding: 35px 20px 25px;
            border-radius: 25px 25px 0 0;
            color: white;
        }

        .clevo-otp-icon {
            width: 70px;
            height: 70px;
            margin: auto;
            border-radius: 50%;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2563eb;
        }

        .reset-header-icon {
            width: 80px;
            height: 80px;
            margin: auto;
            border-radius: 50%;
            background: #eff6ff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1d4ed8;
        }

        .btn-clevo {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border: none;
            color: white;
        }

        .btn-clevo:hover {
            opacity: 0.9;
        }
    </style>
</head>

<body>
    <script>
        const savedLang =
            localStorage.getItem('app_lang') || 'en';

        document.documentElement.lang = savedLang;

        document.documentElement.dir =
            savedLang === 'ar' ? 'rtl' : 'ltr';
        window.onload = () => {

            const lang =
                localStorage.getItem('app_lang') || 'en';

            document.documentElement.lang = lang;

            document.documentElement.dir =
                lang === 'ar' ? 'rtl' : 'ltr';

            document.querySelectorAll('[data-i18n]').forEach(el => {

                const key =
                    el.getAttribute('data-i18n');

                if (
                    translations[lang] &&
                    translations[lang][key]
                ) {

                    el.innerText =
                        translations[lang][key];
                }
            });
        };
    </script>
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
            <div class="modal-content p-4 shadow-lg text-center">
                <div class="modal-body">
                    <div class="reset-header-icon mb-4">
                        <i class="fas fa-envelope fa-2x"></i>
                    </div>
                    <h5 class="fw-bold mb-3" data-i18n="forgot_pass">استعادة كلمة السر</h5>
                    <p class="small opacity-75 mb-4" data-i18n="forgot_pass_desc">أدخل بريدك الإلكتروني لإرسال كود التحقق.</p>
                    <input type="email" id="reset_email" class="form-control mb-3 text-center" placeholder="example@email.com">
                    <div id="resetError"
                        class="text-danger small fw-bold mb-3 d-none">
                    </div>
                    <button class="btn btn-clevo rounded-pill w-100 fw-bold" onclick="sendResetOTP()" data-i18n="btn_send">
                        إرسال الكود
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="forgotStep2" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3 shadow-lg text-center">
                <div class="otp-header mb-4">

                    <div class="clevo-otp-icon mb-3">
                        <i class="fas fa-shield-alt fa-2x"></i>
                    </div>

                    <h5 class="fw-bold"
                        data-i18n="enter_code">
                        أدخل كود التحقق
                    </h5>

                </div>
                <div class="d-flex justify-content-center gap-2 mb-3"
                    dir="ltr">
                    <input type="text"
                        maxlength="1"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        class="form-control otp-input text-center"
                        oninput="moveNext(this, 'otp2')"
                        id="otp1">

                    <input type="text"
                        maxlength="1"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        class="form-control otp-input text-center"
                        oninput="moveNext(this, 'otp3')"
                        id="otp2">

                    <input type="text"
                        maxlength="1"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        class="form-control otp-input text-center"
                        oninput="moveNext(this, 'otp4')"
                        id="otp3">

                    <input type="text"
                        maxlength="1"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        class="form-control otp-input text-center"
                        oninput="moveNext(this, 'otp5')"
                        id="otp4">

                    <input type="text"
                        maxlength="1"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        class="form-control otp-input text-center"
                        oninput="moveNext(this, 'otp6')"
                        id="otp5">

                    <input type="text"
                        maxlength="1"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        class="form-control otp-input text-center"
                        oninput="moveNext(this)"
                        id="otp6">
                </div>

                <div id="otpError"
                    class="text-danger small fw-bold mt-2 d-none">
                </div>
                <button class="btn btn-clevo rounded-pill w-100 fw-bold py-3" onclick="verifyOTP()" data-i18n="btn_verify">تحقق</button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="forgotStep3" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-3 shadow-lg text-center">

                <div class="modal-body">

                    <div class="mb-3 clevo-icon">
                        <i class="fas fa-lock-open fa-3x"></i>
                    </div>


                    <h5 class="fw-bold mb-4"
                        data-i18n="reset_pass_title">
                        كلمة سر جديدة
                    </h5>

                    <div class="password-wrapper mb-3">

                        <input type="password"
                            id="new_pw"
                            class="form-control"
                            placeholder="كلمة السر الجديدة">

                        <i onclick="togglePassword('new_pw', 'toggleNewPw')"
                            id="toggleNewPw"
                            class="fas fa-eye password-toggle"></i>

                    </div>

                    <div class="password-wrapper mb-3">

                        <input type="password"
                            id="confirm_new_pw"
                            class="form-control"
                            placeholder="تأكيد كلمة السر">

                        <i onclick="togglePassword('confirm_new_pw', 'toggleConfirmPw')"
                            id="toggleConfirmPw"
                            class="fas fa-eye password-toggle"></i>

                    </div>

                    <button class="btn btn-clevo rounded-pill w-100 fw-bold"
                        onclick="finishReset()"
                        data-i18n="btn_save">

                        حفظ كلمة السر

                    </button>
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
                <button type="button" class="btn btn-clevo rounded-pill px-5 fw-bold" data-bs-dismiss="modal" data-i18n="btn_ok">OK</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = <?= json_encode(generate_csrf_token()) ?>;
        const translations = {
            ar: {
                login_title: "تسجيل الدخول | Clevo",
                welcome_back: "مرحباً بعودتك!",
                login_label: "رقم الهاتف أو البريد الإلكتروني",
                pass_label: "كلمة السر أو كود الدخول",
                btn_login: "تسجيل الدخول",
                forgot_pass: "نسيت كلمة السر؟",
                btn_send: "إرسال الكود",
                enter_code: "أدخل كود التحقق",
                btn_verify: "تحقق",
                reset_pass_title: "تعيين كلمة سر جديدة",
                new_pass: "كلمة السر الجديدة",
                confirm_new_pass: "تأكيد كلمة السر",
                btn_save: "حفظ",
                success_title: "تم بنجاح",
                reset_final_msg: "تم تغيير كلمة السر بنجاح، يمكنك الآن تسجيل الدخول.",
                fill_all: "يرجى تعبئة جميع الحقول",
                wrong_pass: "بيانات الدخول خاطئة",
                back_to_home: "العودة للرئيسية",
                no_account: "ليس لديك حساب؟",
                create_account: "إنشاء حساب جديد",
                user_not_found: "الحساب غير موجود",
                pending_status: "بانتظار الموافقة",
                login_failed_title: "\u0641\u0634\u0644 \u062a\u0633\u062c\u064a\u0644 \u0627\u0644\u062f\u062e\u0648\u0644",
                login_failed_body: "\u0627\u0644\u0628\u0631\u064a\u062f \u0627\u0644\u0625\u0644\u0643\u062a\u0631\u0648\u0646\u064a \u0623\u0648 \u0643\u0644\u0645\u0629 \u0627\u0644\u0645\u0631\u0648\u0631 \u063a\u064a\u0631 \u0635\u062d\u064a\u062d\u0629",
                pending_owner_title: "\u0637\u0644\u0628 \u0635\u0627\u062d\u0628 \u0627\u0644\u0645\u062d\u0637\u0629 \u0642\u064a\u062f \u0627\u0644\u0645\u0631\u0627\u062c\u0639\u0629",
                pending_owner_body: "\u062d\u0633\u0627\u0628\u0643 \u0623\u0648 \u0628\u064a\u0627\u0646\u0627\u062a \u0627\u0644\u0645\u062d\u0637\u0629 \u0628\u0627\u0646\u062a\u0638\u0627\u0631 \u0645\u0648\u0627\u0641\u0642\u0629 \u0627\u0644\u0625\u062f\u0627\u0631\u0629. \u0633\u0646\u0628\u0644\u063a\u0643 \u0639\u0646\u062f \u062a\u0641\u0639\u064a\u0644 \u0627\u0644\u062f\u062e\u0648\u0644.",
                pending_user_title: "\u062d\u0633\u0627\u0628\u0643 \u0642\u064a\u062f \u0627\u0644\u0645\u0631\u0627\u062c\u0639\u0629",
                pending_user_body: "\u062d\u0633\u0627\u0628\u0643 \u0628\u0627\u0646\u062a\u0638\u0627\u0631 \u0627\u0644\u062a\u0641\u0639\u064a\u0644 \u0623\u0648 \u0645\u0648\u0627\u0641\u0642\u0629 \u0627\u0644\u0625\u062f\u0627\u0631\u0629. \u064a\u0631\u062c\u0649 \u0627\u0644\u062a\u062d\u0642\u0642 \u0645\u0646 \u0628\u0631\u064a\u062f\u0643 \u0623\u0648 \u0627\u0644\u0645\u062d\u0627\u0648\u0644\u0629 \u0644\u0627\u062d\u0642\u0627.",
                device_banned: "الجهاز محظور",
                user_banned: "الحساب محظور",
                invalid_phone_format: "رقم الهاتف يجب أن يبدأ بـ 07 ويتكون من 10 أرقام",
                error_title: "تنبيه النظام",
                btn_ok: "\u062d\u0633\u0646\u0627"
            },
            en: {
                login_title: "Login | Clevo",
                welcome_back: "Welcome Back!",
                login_label: "Phone or Email",
                pass_label: "Password or Access Code",
                btn_login: "Login",
                forgot_pass: "Forgot Password?",
                btn_send: "Send Code",
                enter_code: "Enter Code",
                btn_verify: "Verify",
                reset_pass_title: "Reset Password",
                new_pass: "New Password",
                confirm_new_pass: "Confirm Password",
                btn_save: "Save",
                success_title: "Success",
                reset_final_msg: "Password changed successfully.",
                fill_all: "Fill all fields",
                wrong_pass: "Invalid credentials",
                back_to_home: "Back to Home",
                no_account: "No account?",
                create_account: "Create one",
                user_not_found: "Account not found",
                pending_status: "Pending approval",
                login_failed_title: "Login Failed",
                login_failed_body: "Wrong email or password",
                pending_owner_title: "Station owner request is pending",
                pending_owner_body: "Your account or station details are waiting for admin approval. We will notify you when access is activated.",
                pending_user_title: "Your account is pending",
                pending_user_body: "Your account is waiting for activation or admin approval. Please check your email or try again later.",
                device_banned: "Device banned",
                user_banned: "Account banned",
                invalid_phone_format: "Phone must start with 07 and be 10 digits",
                error_title: "System Alert",
                btn_ok: "OK"
            }
        };

        function showMessageModal(type, title, message) {
            const iconMap = {
                success: { tone: 'success', icon: 'fa-check-circle' },
                pending: { tone: '', icon: 'fa-clock' },
                error: { tone: 'error', icon: 'fa-times-circle' }
            };
            const config = iconMap[type] || iconMap.error;

            document.getElementById('msgIcon').innerHTML =
                `<span class="msg-status-icon ${config.tone}"><i class="fas ${config.icon}"></i></span>`;
            document.getElementById('msgTitle').innerText = title;
            document.getElementById('msgText').innerText = message;
            new bootstrap.Modal(document.getElementById('msgModal')).show();
        }

        function goToStep(current, next) {
            bootstrap.Modal.getInstance(document.getElementById('forgotStep' + current)).hide();
            setTimeout(() => {
                new bootstrap.Modal(document.getElementById('forgotStep' + next)).show();
            }, 400);
        }

        function finishReset() {
            document.getElementById('resetError')
                .classList.add('d-none');
            const newPw =
                document.getElementById('new_pw').value;

            const confirmPw =
                document.getElementById('confirm_new_pw').value;

            const lang =
                localStorage.getItem('app_lang') || 'ar';

            if (!newPw || !confirmPw) {

                const errorBox =
                    document.getElementById('resetError');

                errorBox.classList.remove('d-none');

                errorBox.innerText =
                    lang === 'ar' ?
                    'يرجى تعبئة جميع الحقول' :
                    'Fill all fields';

                return;
            }

            if (newPw !== confirmPw) {

                const errorBox =
                    document.getElementById('resetError');

                errorBox.classList.remove('d-none');

                errorBox.innerText =
                    lang === 'ar' ?
                    'كلمتا المرور غير متطابقتين' :
                    'Passwords do not match';

                return;
            }

            fetch('login.php', {

                    method: 'POST',

                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },

                    body: 'reset_password_action=1&password=' +
                        encodeURIComponent(newPw) +
                        '&csrf_token=' + encodeURIComponent(csrfToken)

                })

                .then(response => response.json())

                .then(data => {

                    if (data.status === 'success') {

                        bootstrap.Modal.getInstance(
                            document.getElementById('forgotStep3')
                        ).hide();

                        const msgIcon =
                            document.getElementById('msgIcon');

                        msgIcon.innerHTML =
                            '<i class="fas fa-check-circle fa-4x text-success"></i>';

                        document.getElementById('msgTitle').innerText =
                            translations[lang].success_title;

                        document.getElementById('msgText').innerText =
                            translations[lang].reset_final_msg;

                        new bootstrap.Modal(
                            document.getElementById('msgModal')
                        ).show();

                    } else {

                        showMessageModal('error', translations[lang].error_title, lang === 'ar' ? 'حدث خطأ' : 'Error');
                    }
                });
        }

        function togglePassword(id, iconId) {
            const pw = document.getElementById(id);
            const icon = document.getElementById(iconId);
            pw.type = pw.type === 'password' ? 'text' : 'password';
            icon.className = `fas fa-eye${pw.type === 'password' ? '' : '-slash'} password-toggle`;
        }

        function prepareLogin() {
            document.getElementById('login_input').value = document.getElementById('raw_input').value.trim();
        }

        function sendResetOTP() {

            const email = document.getElementById('reset_email').value;

            fetch('send-reset-otp.php', {

                    method: 'POST',

                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },

                    body: 'email=' + encodeURIComponent(email) +
                        '&csrf_token=' + encodeURIComponent(csrfToken)

                })

                .then(response => response.json())

                .then(data => {

                    if (data.status === 'success') {

                        bootstrap.Modal.getInstance(
                            document.getElementById('forgotStep1')
                        ).hide();

                        setTimeout(() => {

                            new bootstrap.Modal(
                                document.getElementById('forgotStep2')
                            ).show();

                        }, 400);

                    } else {

                        showMessageModal('error', translations[lang].error_title, data.message || 'Error');
                    }
                });
        }

        function moveNext(current, nextFieldID = null) {

            current.value =
                current.value.replace(/[^0-9]/g, '');

            if (current.value.length >= 1 && nextFieldID) {

                setTimeout(() => {

                    const next =
                        document.getElementById(nextFieldID);

                    if (next) {
                        next.focus();
                    }

                }, 10);
            }
        }

        function verifyOTP() {

            const otp =
                document.getElementById('otp1').value +
                document.getElementById('otp2').value +
                document.getElementById('otp3').value +
                document.getElementById('otp4').value +
                document.getElementById('otp5').value +
                document.getElementById('otp6').value;
            document.getElementById('otpError')
                .classList.add('d-none');

            fetch('verify-reset-otp.php', {

                    method: 'POST',

                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },

                    body: 'otp=' + encodeURIComponent(otp) +
                        '&csrf_token=' + encodeURIComponent(csrfToken)

                })

                .then(response => response.json())

                .then(data => {

                    if (data.status === 'success') {

                        bootstrap.Modal.getInstance(
                            document.getElementById('forgotStep2')
                        ).hide();

                        setTimeout(() => {

                            new bootstrap.Modal(
                                document.getElementById('forgotStep3')
                            ).show();

                        }, 400);
                    } else {

                        const lang =
                            localStorage.getItem('app_lang') || 'ar';

                        const errorBox =
                            document.getElementById('otpError');

                        errorBox.classList.remove('d-none');

                        errorBox.innerText =
                            lang === 'ar' ?
                            'رمز التحقق خاطئ' :
                            'Wrong OTP';
                    }

                });
        }
    </script>
    <?php if (in_array($error, ["wrong_pass", "user_not_found", "pending_status"], true)) : ?>
        <script>
            window.addEventListener('load', () => {

                const lang = localStorage.getItem('app_lang') || 'ar';
                const errorKey = <?= json_encode($error) ?>;
                const pendingRole = <?= json_encode($pending_role ?: 'user') ?>;
                let modalType = 'error';
                let title = translations[lang].error_title;
                let message = translations[lang][errorKey] || errorKey;

                if (errorKey === 'wrong_pass' || errorKey === 'user_not_found') {
                    title = translations[lang].login_failed_title;
                    message = translations[lang].login_failed_body;
                }

                if (errorKey === 'pending_status') {
                    const roleKey = pendingRole === 'owner' ? 'owner' : 'user';
                    modalType = 'pending';
                    title = translations[lang][`pending_${roleKey}_title`];
                    message = translations[lang][`pending_${roleKey}_body`];
                }

                showMessageModal(modalType, title, message);
            });
        </script>
    <?php endif; ?>
</body>

</html>
