<?php
session_start();
include __DIR__ . "/config.php";
include __DIR__ . "/booking-helpers.php";

$userID = clevo_require_login("../general/login.php");
cancelExpiredBookings($conn, $userID);

$stmt = $conn->prepare("SELECT b.BookingID, b.Order_Number, b.Total_Price, b.Service_Location, b.Accepted_At, s.Name AS station_name
    FROM booking b
    JOIN stations s ON b.StationID = s.StationID
    WHERE b.UserID = ? AND b.Status = 'Accepted' AND (b.Payment_Method IS NULL OR b.Payment_Method = '')
    ORDER BY b.created_at DESC
    LIMIT 1");
$stmt->bind_param("i", $userID);
$stmt->execute();
$due = $stmt->get_result()->fetch_assoc();

if (isset($_GET['json'])) {
    clevo_json_response('success', [
        'has_due' => (bool) $due,
        'due' => $due ?: null
    ], 'ok');
}

if (!$due) {
    header("Location: ../user/home.php");
    exit();
}

$dueAmount = number_format((float) $due['Total_Price'], 2);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="dues_title">المستحقات | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-body: #f8f9fa; --bg-card: #ffffff;
            --text-main: #111827; --text-muted: #4b5563;
            --border-card: #e5e7eb; --amount-bg: #fff5f5;
        }
        
        /* تحسينات قوية للدارك مود لضمان الوضوح */
        [data-theme="dark"] {
            --bg-body: #020617; /* أسود عميق جداً */
            --bg-card: #0f172a; /* كحلي غامق للبطاقة */
            --text-main: #f9fafb; /* أبيض ناصع جداً */
            --text-muted: #cbd5e1; /* رمادي فاتح مقروء */
            --amount-bg: #1e1b4b; /* خلفية المبلغ */
            --border-card: #334155;
        }
        
        body {
            font-family: 'Cairo', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            transition: 0.3s ease;
        }

        /* سهم الرجوع - يعود للخلف تلقائياً */
        .back-nav-fixed { position: fixed; top: 20px; right: 20px; z-index: 1000; }
        [dir="ltr"] .back-nav-fixed { right: auto; left: 20px; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }

        .back-circle-btn { 
            background: var(--bg-card); border: 1px solid var(--border-card); 
            color: var(--text-main); width: 45px; height: 45px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); text-decoration: none;
        }
        
        .due-card {
            background: var(--bg-card);
            border-radius: 35px;
            padding: 40px 25px;
            width: 92%;
            max-width: 450px;
            text-align: center;
            border: 1px solid var(--border-card);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        
        .warning-icon { font-size: 4rem; color: #ef4444; margin-bottom: 15px; }
        
        .amount-box {
            background: var(--amount-bg);
            border-radius: 20px;
            padding: 20px;
            margin: 25px 0;
            border: 2px dashed #ef4444;
        }
        
        .amount-value { font-size: 2.5rem; font-weight: 900; color: #ef4444; }
        
        .btn-custom { border-radius: 50px; padding: 15px; font-weight: 800; width: 100%; border: none; transition: 0.3s; margin-bottom: 12px; }
        .btn-pay { background: #10b981; color: white; }
        .btn-support { background: transparent; border: 1.5px solid var(--border-card); color: var(--text-main); }
        
        .cash-only-label { 
            font-weight: 900; color: #f59e0b; font-size: 1.2rem; 
            padding: 10px; display: block; border: 1px solid #f59e0b; 
            border-radius: 12px; margin-bottom: 15px;
        }

        .modal-content { 
            border-radius: 30px; background-color: var(--bg-card); 
            color: var(--text-main); border: 1px solid var(--border-card); 
        }

        .text-muted { color: var(--text-muted) !important; }
        .uName-hl { color: #3b82f6; font-weight: 900; }
    </style>
</head>

<body>
    <!-- سهم الرجوع الديناميكي -->
    <div class="back-nav-fixed">
        <a href="javascript:history.back()" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="due-card shadow-lg">
        <div class="warning-icon"><i class="fas fa-circle-exclamation pulse"></i></div>
        
        <h4 class="fw-black mb-2" data-i18n="dues_title">مستحقات معلقة</h4>
        <p class="text-muted small">
            <span data-i18n="hello">يا</span> <span class="uName-hl"></span>، 
            <span data-i18n="dues_desc">لديك مبالغ لم تسدد بعد، يرجى تصفية الحساب للمتابعة.</span>
        </p>

        <div class="amount-box">
            <span class="d-block small text-muted mb-1 fw-bold" data-i18n="amount_label">المبلغ المطلوب سداده</span>
            <span class="amount-value"><?= e($dueAmount) ?> <small style="font-size: 1rem;">JOD</small></span>
        </div>

        <!-- كلمة نقداً لحالها وواضحة -->
        <span class="cash-only-label" data-i18n="pay_cash_only">نقداً | CASH</span>

        <button onclick="triggerPayment()" class="btn-custom btn-pay shadow">
            <span data-i18n="btn_pay_now">سداد الآن</span> <i class="fas fa-credit-card ms-2"></i>
        </button>

        <button onclick="location.href='support.php'" class="btn-custom btn-support">
            <span data-i18n="btn_support">تواصل مع الدعم</span> <i class="fas fa-headset ms-2"></i>
        </button>

        <div class="mt-3 small text-muted">
            <span data-i18n="device_id_label">رقم مرجع الجهاز:</span> <span id="deviceId">...</span>
        </div>
    </div>

    <!-- مودال عربي انجليزي -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div id="mIcon" class="mb-3" style="font-size: 3rem;"></div>
                <h5 id="mTitle" class="fw-bold"></h5>
                <p id="mBody" class="text-muted small"></p>
                <button class="btn btn-primary w-100 rounded-pill py-2 mt-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_ok">موافق | OK</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/translations.js"></script>
    <script>
        const msgModal = new bootstrap.Modal(document.getElementById('statusModal'));
        const dueServiceLocation = <?= json_encode($due['Service_Location']) ?>;

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');

            if (typeof applyTranslations === 'function') applyTranslations(lang);
            const dueLabel = document.querySelector('.cash-only-label');
            if (dueLabel) {
                dueLabel.innerText = ['home', 'delivery'].includes(dueServiceLocation)
                    ? (lang === 'ar' ? 'الدفع مطلوب خلال 5 دقائق من الموافقة' : 'Payment is required within 5 minutes of approval')
                    : (lang === 'ar' ? 'الدفع اختياري لخدمة المحطة' : 'Payment is optional for at-station service');
            }
            
            // تخصيص الاسم
            const name = localStorage.getItem('userName') || "صديقنا";
            document.querySelectorAll('.uName-hl').forEach(el => el.innerText = name);
            document.getElementById('deviceId').innerText = "W-CLEVO-" + Math.floor(1000 + Math.random() * 9000);
        };

        function showStatus(type, titleAr, titleEn, bodyAr, bodyEn) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            document.getElementById('mIcon').className = type === 'loading' ? 'fas fa-spinner fa-spin text-primary' : 'fas fa-circle-check text-success';
            document.getElementById('mTitle').innerText = lang === 'ar' ? titleAr : titleEn;
            document.getElementById('mBody').innerText = lang === 'ar' ? bodyAr : bodyEn;
            msgModal.show();
        }

        function triggerPayment() {
            // مودال التحويل المزدوج
            showStatus('loading', 'جاري التحويل', 'Redirecting', 'يرجى الانتظار، جاري تحويلك لبوابة الدفع...', 'Please wait, redirecting to payment gateway...');
            
            setTimeout(() => {
                location.href = 'payment-method.php?id=<?= (int) $due['BookingID'] ?>';
            }, 2000);
        }
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>

