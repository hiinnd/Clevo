<?php
session_start();
include_once(__DIR__ . "/../includes/config.php");
include_once(__DIR__ . "/../includes/app-helpers.php");
include_once(__DIR__ . "/../includes/plan-helpers.php");

$userID = clevo_require_role(['customer', 'user'], '../general/login.php');
$message_key = isset($_GET['subscribed']) ? "success_sub" : "";
$message_type = $message_key ? "success" : "";

clevoEnsurePlanColumns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_wallet'])) {
    clevo_require_csrf_token();
    $planID = (int) ($_POST['plan_id'] ?? 0);

    $stmt_p = $conn->prepare("SELECT * FROM plans WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt_p->bind_param("i", $planID);
    $stmt_p->execute();
    $plan_data = $stmt_p->get_result()->fetch_assoc();

    if (!$plan_data) {
        $message_key = "system_error";
        $message_type = "danger";
    } else {
        $price = (float) $plan_data['price'];
        $wallet = clevoEnsureWallet($conn, $userID);
        if ((float) $wallet['Balance'] < $price) {
            $message_key = "insufficient_balance";
            $message_type = "danger";
        } else {
            $conn->begin_transaction();
            try {
                $walletID = (int) $wallet['WalletID'];
                if (!clevoDebitWallet($conn, $walletID, $price)) {
                    throw new RuntimeException('insufficient_balance');
                }

                $descAr = "اشتراك باقة " . ($plan_data['name_ar'] ?: "#" . $planID);
                $descEn = "Plan subscription " . ($plan_data['name_en'] ?: "#" . $planID);
                clevoRecordWalletTransaction($conn, $walletID, $price, "debit", $descAr, $descEn);

                $subscription = clevoCreateUserPlanSubscription($conn, $userID, $planID);
                if (!$subscription) {
                    throw new RuntimeException('subscribe_failed');
                }

                $conn->commit();
                $message_key = "success_sub";
                $message_type = "success";
            } catch (Throwable $e) {
                $conn->rollback();
                $message_key = $e->getMessage() === 'insufficient_balance' ? "insufficient_balance" : "system_error";
                $message_type = "danger";
            }
        }
    }
}

$activePlan = clevoGetActiveUserPlan($conn, $userID);
$activeFeaturesAr = $activePlan ? clevoPlanFeatures($activePlan, 'ar') : [];
$activeFeaturesEn = $activePlan ? clevoPlanFeatures($activePlan, 'en') : [];
$activeTotalWashes = $activePlan ? max((int) $activePlan['remaining_washes'], clevoPlanWashCount($activePlan)) : 0;

$plans_stmt = $conn->query("SELECT id, name_ar, name_en, price, duration, duration_type, COALESCE(wash_count, 0) AS wash_count, description_ar, description_en, COALESCE(included_services_ar, '') AS included_services_ar, COALESCE(included_services_en, '') AS included_services_en FROM plans WHERE is_active = 1 ORDER BY price ASC, id DESC");
$plans = $plans_stmt ? $plans_stmt->fetch_all(MYSQLI_ASSOC) : [];
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="plans_title">باقات الاشتراك | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.72);
            --text: #0369a1;
            --accent: #0ea5e9;
            --border: rgba(3, 105, 161, 0.14);
            --soft: rgba(14, 165, 233, 0.10);
        }
        [data-theme="dark"] {
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.70);
            --text: #f1f5f9;
            --accent: #38bdf8;
            --border: rgba(255, 255, 255, 0.10);
            --soft: rgba(56, 189, 248, 0.12);
        }

        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 40px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; color: var(--text); }
        .header-section { padding: 20px; display: flex; align-items: center; justify-content: center; position: relative; height: 150px; border-radius: 0 0 40px 40px; background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 28px; }
        .back-circle-btn { position: absolute; top: 20px; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; background: rgba(255,255,255,0.2); color: #fff; transition: 0.3s; }
        [dir="rtl"] .back-circle-btn { right: 20px; }
        [dir="ltr"] .back-circle-btn { left: 20px; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }
        h4.fw-black { flex-grow: 1; text-align: center; color: white !important; margin: 0; font-weight: 900; }
        .active-plan { padding: 18px; margin-bottom: 22px; overflow: hidden; }
        .car-plan-img { width: 145px; max-width: 42vw; filter: drop-shadow(0 15px 20px rgba(14, 165, 233, 0.28)); }
        .wash-number { font-size: 2.4rem; line-height: 1; font-weight: 900; color: var(--accent); }
        .feature-rail { display: flex; gap: 10px; overflow-x: auto; padding: 4px 2px 8px; scrollbar-width: none; scroll-snap-type: x proximity; }
        .feature-rail::-webkit-scrollbar { display: none; }
        .feature-pill { flex: 0 0 auto; scroll-snap-align: start; background: var(--soft); border: 1px solid var(--border); color: var(--text); border-radius: 999px; padding: 8px 13px; font-size: 0.78rem; font-weight: 800; white-space: nowrap; }
        .plan-card { padding: 24px 20px; text-align: center; height: 100%; transition: 0.3s; display: flex; flex-direction: column; }
        .plan-card:hover { transform: translateY(-3px); }
        .plan-price { font-size: 2.15rem; font-weight: 900; color: var(--accent); }
        .wash-chip { display: inline-flex; align-items: center; gap: 6px; background: var(--soft); border: 1px solid var(--border); border-radius: 999px; padding: 7px 12px; font-weight: 900; color: var(--text); }
        .btn-sub { border-radius: 50px; padding: 11px; font-weight: 800; width: 100%; margin-top: auto; background: var(--accent); border: none; color: white; }
        .modal-content { border-radius: 30px; background: var(--glass); backdrop-filter: blur(20px); border: 1px solid var(--border); color: var(--text); }
        .pay-option { background: var(--soft); border-radius: 20px; padding: 15px; cursor: pointer; border: 1px solid var(--border); transition: 0.3s; width: 100%; color: inherit; }
        .pay-option:hover { border-color: var(--accent); transform: translateY(-2px); }
        @media (max-width: 576px) {
            .active-plan .d-flex { align-items: center !important; }
            .wash-number { font-size: 2rem; }
        }
    </style>
</head>
<body>
    <div class="header-section shadow">
        <h4 class="fw-black" data-i18n="plans_title">باقات الاشتراك</h4>
        <a href="home.php" class="back-circle-btn"><i class="fas fa-arrow-right" id="backIcon"></i></a>
    </div>

    <main class="container">
        <?php if ($activePlan): ?>
            <section class="glass active-plan shadow-sm">
                <div class="d-flex justify-content-between gap-3 flex-wrap">
                    <div class="text-start flex-grow-1">
                        <span class="badge rounded-pill text-bg-primary mb-2" data-i18n="active_plan_badge">باقتك الحالية</span>
                        <h5 class="fw-black mb-1 plan-name-live"
                            data-name-ar="<?= htmlspecialchars($activePlan['name_ar'] ?? '', ENT_QUOTES) ?>"
                            data-name-en="<?= htmlspecialchars($activePlan['name_en'] ?? '', ENT_QUOTES) ?>">
                            <?= htmlspecialchars($activePlan['name_ar'] ?? '') ?>
                        </h5>
                        <div class="d-flex align-items-end gap-2 mt-2">
                            <div class="wash-number"><?= (int) $activePlan['remaining_washes'] ?></div>
                            <div class="small fw-bold mb-1">
                                <span data-i18n="remaining_washes">غسلات متبقية</span>
                                <span class="opacity-75">/ <?= (int) $activeTotalWashes ?></span>
                            </div>
                        </div>
                        <div class="small opacity-75 mt-1">
                            <i class="far fa-calendar-check me-1"></i>
                            <span data-i18n="active_until">صالحة لغاية</span>
                            <?= htmlspecialchars($activePlan['end_date'] ?? '-') ?>
                        </div>
                    </div>
                    <img src="../assets/Images/car.png" class="car-plan-img" alt="Car">
                </div>
                <div class="mt-3">
                    <div class="small fw-bold mb-2" data-i18n="included_services">الخدمات المشمولة</div>
                    <div class="feature-rail plan-feature-live"
                         data-features-ar="<?= htmlspecialchars(json_encode($activeFeaturesAr, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                         data-features-en="<?= htmlspecialchars(json_encode($activeFeaturesEn, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"></div>
                </div>
            </section>
        <?php endif; ?>

        <div class="row g-4 px-2">
            <?php foreach ($plans as $plan): ?>
                <?php
                    $featuresAr = clevoPlanFeatures($plan, 'ar');
                    $featuresEn = clevoPlanFeatures($plan, 'en');
                    $washCount = clevoPlanWashCount($plan);
                ?>
                <div class="col-lg-4 col-md-6">
                    <div class="glass plan-card shadow-sm">
                        <i class="fas fa-gem fs-1 mb-3 text-warning"></i>
                        <h4 class="fw-bold plan-name"
                            data-name-ar="<?= htmlspecialchars($plan['name_ar'] ?? '', ENT_QUOTES) ?>"
                            data-name-en="<?= htmlspecialchars($plan['name_en'] ?? '', ENT_QUOTES) ?>">
                            <?= htmlspecialchars($plan['name_ar'] ?? '') ?>
                        </h4>
                        <div class="plan-price"><?= number_format((float) $plan['price'], 0) ?><span class="fs-6"> JOD</span></div>
                        <div class="my-2">
                            <span class="wash-chip"><i class="fas fa-car-side"></i> <?= $washCount ?> <span data-i18n="wash_unit">غسلات</span></span>
                        </div>
                        <p class="small opacity-75 mt-2 plan-desc"
                           data-desc-ar="<?= htmlspecialchars($plan['description_ar'] ?? '', ENT_QUOTES) ?>"
                           data-desc-en="<?= htmlspecialchars($plan['description_en'] ?? '', ENT_QUOTES) ?>">
                           <?= htmlspecialchars($plan['description_ar'] ?? '') ?>
                        </p>
                        <div class="feature-rail mb-3 plan-features"
                             data-features-ar="<?= htmlspecialchars(json_encode($featuresAr, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"
                             data-features-en="<?= htmlspecialchars(json_encode($featuresEn, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"></div>
                        <button onclick="openPay(<?= (int) $plan['id'] ?>, <?= (float) $plan['price'] ?>)" class="btn-sub shadow" data-i18n="btn_sub">اشترك الآن</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <div class="modal fade" id="payModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <h6 class="fw-bold mb-4" data-i18n="choose_pay_method">اختر طريقة الدفع</h6>
                <form method="POST">
                    <?= csrf_input(); ?>
                    <input type="hidden" name="plan_id" id="modalPlanID">
                    <button type="submit" name="pay_wallet" class="pay-option d-flex align-items-center mb-3">
                        <i class="fas fa-wallet fa-2x text-success ms-3 me-3"></i>
                        <div class="text-start">
                            <h6 class="fw-bold mb-0 small" data-i18n="app_wallet">محفظة التطبيق</h6>
                            <small class="opacity-75" data-i18n="wallet_desc">الدفع من رصيدك الحالي</small>
                        </div>
                    </button>
                </form>
                <button type="button" class="pay-option d-flex align-items-center" onclick="payVisa()">
                    <i class="fas fa-credit-card fa-2x text-primary ms-3 me-3"></i>
                    <div class="text-start">
                        <h6 class="fw-bold mb-0 small" data-i18n="bank_card">البطاقة البنكية</h6>
                        <small class="opacity-75" data-i18n="bank_desc">دفع إلكتروني آمن</small>
                    </div>
                </button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4">
                <div id="statusIcon" class="mb-3 fs-1"></div>
                <h6 id="statusTitle" class="fw-bold"></h6>
                <p id="statusBody" class="small opacity-75 mb-0"></p>
                <button type="button" class="btn btn-primary rounded-pill w-100 mt-3" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                plans_title: "باقات الاشتراك", btn_sub: "اشترك الآن", choose_pay_method: "اختر طريقة الدفع",
                app_wallet: "محفظة التطبيق", wallet_desc: "الدفع من رصيدك الحالي", bank_card: "البطاقة البنكية",
                bank_desc: "دفع إلكتروني آمن", insufficient_balance: "رصيد المحفظة غير كافٍ لإتمام الاشتراك.",
                system_error: "حدث خطأ في النظام، حاول مجدداً.", success_sub: "تم الاشتراك بنجاح!",
                active_plan_badge: "باقتك الحالية", remaining_washes: "غسلات متبقية", active_until: "صالحة لغاية",
                included_services: "الخدمات المشمولة", wash_unit: "غسلات", btn_ok: "موافق",
                success_title: "تم بنجاح", alert_title: "تنبيه"
            },
            en: {
                plans_title: "Subscription Plans", btn_sub: "Subscribe Now", choose_pay_method: "Choose Payment Method",
                app_wallet: "App Wallet", wallet_desc: "Pay from your current balance", bank_card: "Bank Card",
                bank_desc: "Secure online payment", insufficient_balance: "Your wallet balance is not enough.",
                system_error: "System error, please try again.", success_sub: "Subscribed successfully!",
                active_plan_badge: "Current Plan", remaining_washes: "Washes left", active_until: "Valid until",
                included_services: "Included services", wash_unit: "washes", btn_ok: "OK",
                success_title: "Success", alert_title: "Alert"
            }
        };

        let currentPlanID = 0;
        const pModal = new bootstrap.Modal(document.getElementById('payModal'));
        const statusModal = new bootstrap.Modal(document.getElementById('statusModal'));

        function openPay(id, price) {
            currentPlanID = id;
            document.getElementById('modalPlanID').value = id;
            localStorage.setItem('finalPrice', price);
            pModal.show();
        }

        function payVisa() {
            if (!currentPlanID) return;
            window.location.href = '../includes/payment-gateway.php?plan_id=' + encodeURIComponent(currentPlanID);
        }

        function renderFeatures(el, lang) {
            const raw = el.getAttribute('data-features-' + lang) || '[]';
            let features = [];
            try { features = JSON.parse(raw); } catch (e) { features = []; }
            el.innerHTML = '';
            features.forEach(feature => {
                const chip = document.createElement('span');
                chip.className = 'feature-pill';
                chip.innerHTML = '<i class="fas fa-check me-1"></i>' + feature;
                el.appendChild(chip);
            });
        }

        function showStatus(type, key) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            document.getElementById('statusIcon').innerHTML = type === 'success'
                ? '<i class="fas fa-check-circle text-success"></i>'
                : '<i class="fas fa-circle-exclamation text-warning"></i>';
            document.getElementById('statusTitle').innerText = type === 'success' ? translations[lang].success_title : translations[lang].alert_title;
            document.getElementById('statusBody').innerText = translations[lang][key] || key;
            statusModal.show();
        }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.getElementById('bootstrap-css').href = lang === 'ar'
                ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css"
                : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });

            document.querySelectorAll('.plan-name, .plan-name-live').forEach(el => {
                el.innerText = lang === 'ar' ? el.getAttribute('data-name-ar') : el.getAttribute('data-name-en');
            });
            document.querySelectorAll('.plan-desc').forEach(el => {
                el.innerText = lang === 'ar' ? el.getAttribute('data-desc-ar') : el.getAttribute('data-desc-en');
            });
            document.querySelectorAll('.plan-features, .plan-feature-live').forEach(el => renderFeatures(el, lang));

            <?php if ($message_key): ?>
                showStatus(<?= json_encode($message_type === 'success' ? 'success' : 'warning') ?>, <?= json_encode($message_key) ?>);
            <?php endif; ?>
        };
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>

