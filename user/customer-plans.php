<?php
session_start();
include(__DIR__ . "/../includes/config.php");

// 1. التحقق من تسجيل الدخول
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['userID'];
$error_msg = "";
$success_msg = "";

// 2. جلب الباقات من قاعدة البيانات
$plans_query = $conn->query("SELECT * FROM `plans` WHERE is_active = 1");

// 3. معالجة الاشتراك عبر المحفظة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_wallet'])) {
    $planID = $_POST['plan_id'];
    
    // جلب بيانات الباقة
    $stmt_p = $conn->prepare("SELECT price, duration FROM `plans` WHERE id = ?");
    $stmt_p->bind_param("i", $planID);
    $stmt_p->execute();
    $plan_data = $stmt_p->get_result()->fetch_assoc();

    if ($plan_data) {
        $price = $plan_data['price'];
        
        // فحص رصيد المحفظة
        $stmt_w = $conn->prepare("SELECT Balance FROM `wallet` WHERE UserID = ?");
        $stmt_w->bind_param("i", $userID);
        $stmt_w->execute();
        $wallet = $stmt_w->get_result()->fetch_assoc();

        if ($wallet && $wallet['Balance'] >= $price) {
            $conn->begin_transaction();
            try {
                // خصم من المحفظة
                $upd_w = $conn->prepare("UPDATE `wallet` SET Balance = Balance - ? WHERE UserID = ?");
                $upd_w->bind_param("di", $price, $userID);
                $upd_w->execute();

                // تسجيل الاشتراك وتحديد الغسلات (مثال: غسلة لكل 5 دنانير)
                $washes = floor($price / 5); 
                $start = date('Y-m-d');
                $end = date('Y-m-d', strtotime('+1 month'));

                $ins_sub = $conn->prepare("INSERT INTO `user_plans` (user_id, plan_id, start_date, end_date, status, remaining_washes) VALUES (?, ?, ?, ?, 'active', ?)");
                $ins_sub->bind_param("iissi", $userID, $planID, $start, $end, $washes);
                $ins_sub->execute();

                $conn->commit();
                $success_msg = "success_sub";
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "system_error";
            }
        } else {
            $error_msg = "insufficient_balance";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="plans_title">باقات الاشتراك | Wash Go</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
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
        
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 40px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; }

        .header-section { 
            padding: 20px; display: flex; align-items: center; justify-content: space-between; 
            position: relative; height: 160px; border-radius: 0 0 40px 40px; 
            background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 40px; 
        }
        
        .back-circle-btn { 
            width: 40px; height: 40px; border-radius: 50%; display: flex; 
            align-items: center; justify-content: center; text-decoration: none; 
            background: rgba(255,255,255,0.2); color: #fff; transition: 0.3s;
        }

        [dir="rtl"] .back-circle-btn { order: 1; } 
        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }
        h4.fw-black { order: 2; flex-grow: 1; text-align: center; color: white !important; margin: 0; }

        .plan-card { padding: 30px 20px; text-align: center; height: 100%; transition: 0.3s; }
        .plan-price { font-size: 2.2rem; font-weight: 900; color: var(--accent); }
        .btn-sub { border-radius: 50px; padding: 10px; font-weight: 700; width: 100%; margin-top: 15px; background: var(--accent); border: none; color: white; }
        
        .modal-content { border-radius: 30px; background: var(--glass); backdrop-filter: blur(20px); border: 1px solid var(--border); color: var(--text); }
        .pay-option { background: rgba(0,0,0,0.05); border-radius: 20px; padding: 15px; cursor: pointer; border: 1px solid var(--border); transition: 0.3s; width: 100%; border: none; color: inherit; }
    </style>
</head>
<body>
    <div class="header-section shadow">
        <div style="width: 40px;" class="spacer-div d-none d-md-block"></div>
        <h4 class="fw-black" data-i18n="plans_title">باقات الاشتراك</h4>
        <a href="home.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <main class="container">
        <?php if($error_msg): ?>
            <div class="alert alert-danger mx-2 rounded-4 text-center shadow-sm">
                <i class="fas fa-exclamation-circle me-1"></i> <span data-i18n="<?= $error_msg ?>"></span>
            </div>
        <?php endif; ?>
        
        <?php if($success_msg): ?>
            <div class="alert alert-success mx-2 rounded-4 text-center shadow-sm">
                <i class="fas fa-check-circle me-1"></i> <span data-i18n="<?= $success_msg ?>"></span>
            </div>
        <?php endif; ?>

        <div class="row g-4 px-2">
            <?php while($plan = $plans_query->fetch_assoc()): ?>
                <div class="col-lg-4">
                    <div class="glass plan-card shadow-sm">
                        <i class="fas fa-gem fs-1 mb-3 text-warning"></i>
                        <h4 class="fw-bold plan-name" 
                            data-name-ar="<?= $plan['name_ar'] ?>" 
                            data-name-en="<?= $plan['name_en'] ?>">
                            <?= $plan['name_ar'] ?>
                        </h4>
                        <div class="plan-price"><?= number_format($plan['price'], 0) ?><span class="fs-6"> JOD</span></div>
                        <p class="small opacity-75 mt-2 plan-desc" 
                           data-desc-ar="<?= $plan['description_ar'] ?>" 
                           data-desc-en="<?= $plan['description_en'] ?>">
                           <?= $plan['description_ar'] ?>
                        </p>
                        <button onclick="openPay(<?= $plan['id'] ?>, <?= $plan['price'] ?>)" class="btn-sub shadow" data-i18n="btn_sub">اشترك الآن</button>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </main>

    <div class="modal fade" id="payModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <h6 class="fw-bold mb-4" data-i18n="choose_pay_method">اختر طريقة الدفع</h6>
                <form method="POST">
                    <input type="hidden" name="plan_id" id="modalPlanID">
                    <button type="submit" name="pay_wallet" class="pay-option d-flex align-items-center mb-3">
                        <i class="fas fa-wallet fa-2x text-success ms-3 me-3"></i>
                        <div class="text-start">
                            <h6 class="fw-bold mb-0 small" data-i18n="app_wallet">المحفظة</h6>
                            <small class="opacity-50" data-i18n="wallet_desc">دفع من رصيدك</small>
                        </div>
                    </button>
                </form>
                <div class="pay-option d-flex align-items-center" onclick="payVisa()">
                    <i class="fas fa-credit-card fa-2x text-primary ms-3 me-3"></i>
                    <div class="text-start">
                        <h6 class="fw-bold mb-0 small" data-i18n="bank_card">البطاقة</h6>
                        <small class="opacity-50" data-i18n="bank_desc">فيزا / ماستركارد</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                plans_title: "باقات الاشتراك", btn_sub: "اشترك الآن", choose_pay_method: "اختر طريقة الدفع", app_wallet: "محفظة التطبيق", wallet_desc: "الدفع من رصيدك الحالي", bank_card: "البطاقة البنكية", bank_desc: "دفع إلكتروني آمن", insufficient_balance: "رصيد المحفظة غير كافٍ!", system_error: "خطأ في النظام، حاول مجدداً.", success_sub: "تم الاشتراك بنجاح!"
            },
            en: {
                plans_title: "Subscription Plans", btn_sub: "Subscribe Now", choose_pay_method: "Choose Payment Method", app_wallet: "App Wallet", wallet_desc: "Pay from your balance", bank_card: "Bank Card", bank_desc: "Secure online payment", insufficient_balance: "Insufficient wallet balance!", system_error: "System error, try again.", success_sub: "Subscribed Successfully!"
            }
        };

        let currentPrice = 0;
        const pModal = new bootstrap.Modal(document.getElementById('payModal'));

        function openPay(id, price) {
            currentPrice = price;
            document.getElementById('modalPlanID').value = id;
            pModal.show();
        }

        function payVisa() {
            // تخزين السعر الحالي لإتمام العملية في بوابة الدفع
            localStorage.setItem('finalPrice', currentPrice);
            // التوجيه لصفحة بوابة الدفع الموجودة في ملف includes
            window.location.href = '../includes/payment-gateway.php';
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

            document.querySelectorAll('.plan-name').forEach(el => {
                el.innerText = lang === 'ar' ? el.getAttribute('data-name-ar') : el.getAttribute('data-name-en');
            });
            document.querySelectorAll('.plan-desc').forEach(el => {
                el.innerText = lang === 'ar' ? el.getAttribute('data-desc-ar') : el.getAttribute('data-desc-en');
            });
        };
    </script>
    <script src="/Clevo/assets/lamea.js"></script>
</body>
</html>
