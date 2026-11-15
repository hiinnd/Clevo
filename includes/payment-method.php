<?php

include_once(__DIR__ . "/config.php");
include_once(__DIR__ . "/booking-helpers.php");
include_once(__DIR__ . "/app-helpers.php");
include_once(__DIR__ . "/plan-helpers.php");

if (!isset($_SESSION['userID'])) {
    header("Location: ../general/login.php");
    exit();
}

$userID = (int) $_SESSION['userID'];
$bookingID = (int) ($_GET['id'] ?? 0);
cancelExpiredBookings($conn, $userID);
clevoEnsurePlanColumns($conn);
clevoEnsureBookingPaymentColumns($conn);
clevoEnsureBookingServicesTable($conn);

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='set_payment') {
    if(($_POST['method']??'')==='visa'){
        clevo_payment_provider();
        if(($_POST['use_points']??'')==='1')throw new ClevoSecurityException('online_points_not_approved',503);
        $row=clevo_stmt($conn,"SELECT BookingID FROM booking WHERE BookingID=? AND UserID=? AND Status='Accepted' AND (Payment_Method IS NULL OR Payment_Method='')",'ii',[$bookingID,$userID])->get_result()->fetch_assoc();
        if(!$row)throw new ClevoSecurityException('not_found',404);
        header('Content-Type: application/json');echo json_encode(['status'=>'redirect_visa','use_points'=>0]);exit;
    }
    $result=clevo_pay_booking($conn,$userID,$bookingID,(string)($_POST['method']??''),($_POST['use_points']??'')==='1',(string)($_POST['idempotency_key']??''));
    header('Content-Type: application/json'); echo json_encode($result); exit;
}

$stmt = $conn->prepare("SELECT b.*, s.Name as station_name, serv.Name as service_name
                        FROM booking b
                        JOIN stations s ON b.StationID = s.StationID
                        JOIN services serv ON b.ServiceID = serv.ServiceID
                        WHERE b.BookingID = ? AND b.UserID = ?");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
if (!$booking) {
    header("Location: ../user/home.php");
    exit();
}

if ($booking['Status'] !== 'Accepted' || !empty($booking['Payment_Method'])) {
    header("Location: ../user/booking-history.php");
    exit();
}

$base_price = (float) $booking['Total_Price'];
$pointsRequired = (int) ceil($base_price * 40);
$wallet = clevoEnsureWallet($conn, $userID);
$walletBalance = (float) $wallet['Balance'];
$pointsBalance = clevoGetLoyaltyPoints($conn, $userID);
$maxUsablePoints = (int) min(floor($pointsBalance), ceil($base_price * 40));
$maxPointsDiscount = min($base_price, $maxUsablePoints / 40);
$servicesSummary = clevoBookingServicesSummary($conn, $booking);
$isStationService = $booking['Service_Location'] === 'station';
$activePlan = clevoGetActiveUserPlan($conn, $userID);
$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>تأكيد الدفع | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root { 
            --bg-body: #f8f9fa; --bg-card: #ffffff; --text-main: #111827; 
            --text-muted: #4b5563; --border-color: #dee2e6; --accent: #3b82f6;
        }

        [data-theme="dark"] { 
            --bg-body: #020617; --bg-card: #0f172a; --text-main: #ffffff; 
            --text-muted: #cbd5e1; --border-color: #334155; --accent: #3b82f6;
        }
        
        body { 
            font-family: 'Cairo', sans-serif; background-color: var(--bg-body); 
            color: var(--text-main); margin: 0; min-height: 100vh; transition: 0.3s;
        }

        .payment-container { width: min(100%, 430px); margin: auto; padding: 14px; }


        .back-circle-btn { 
            background: var(--bg-card); border: 1px solid var(--border-color); 
            color: var(--text-main) !important; width: 38px; height: 38px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; text-decoration: none;
            flex: 0 0 38px;
        }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }


        .loyalty-card { 
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); 
            border: 2px solid transparent; border-radius: 16px; padding: 14px; color: #ffffff !important;
            cursor: pointer; transition: 0.2s;
        }
        .loyalty-card.active {
            border-color: #bfdbfe;
            box-shadow: 0 0 0 3px rgba(191, 219, 254, .22);
        }


        .method-item { 
            background: var(--bg-card); border: 2px solid var(--border-color); 
            border-radius: 14px; padding: 12px 14px; margin-bottom: 10px;
            cursor: pointer; transition: 0.2s; color: var(--text-main);
        }
        .method-item.active { border-color: var(--accent); background: rgba(59, 130, 246, 0.1); }
        .method-icon {
            width: 42px; height: 42px; flex: 0 0 42px;
            display: flex; align-items: center; justify-content: center;
            margin-inline-end: 12px;
        }


        .summary-card {
            background: var(--bg-card); border-radius: 16px; padding: 14px;
            border: 1px solid var(--border-color);
        }
        .summary-row {
            display: flex; justify-content: space-between; align-items: center;
            gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border-color);
        }
        .summary-row:last-child { border-bottom: 0; }
        .summary-label { color: var(--text-muted); font-size: .84rem; }
        .summary-value { color: var(--text-main); font-weight: 800; text-align: end; overflow-wrap: anywhere; }
        .summary-total { color: #22c55e; font-size: 1.15rem; font-weight: 900; }
        .method-hint { color: var(--text-muted); font-size: .78rem; display: block; margin-top: 2px; }
        .text-clear { color: var(--text-main) !important; opacity: 1 !important; font-weight: 700; }
        .text-muted-clear { color: var(--text-muted) !important; font-weight: 400; }

        .btn-confirm { 
            background: var(--accent); color: #ffffff !important; border: none; 
            border-radius: 50px; padding: 13px; width: 100%; font-weight: 900;
        }

        @media (max-width: 380px) {
            .payment-container { padding: 10px; }
            .summary-card, .loyalty-card { padding: 12px; }
            .method-item { padding: 10px 12px; }
            .method-icon { width: 38px; height: 38px; flex-basis: 38px; margin-inline-end: 10px; }
            .method-icon i { font-size: 1rem !important; }
            .summary-total { font-size: 1.05rem; }
            h5 { font-size: 1rem; }
        }


        .modal-content { border-radius: 30px; background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border-color); }
        .form-control { background: var(--bg-body); color: var(--text-main); border-radius: 12px; border: 1px solid var(--border-color); }
        .form-control:focus { background: var(--bg-body); color: var(--text-main); }
    </style>
</head>

<body>
    <div class="payment-container">
        <!-- Header -->
        <div class="d-flex align-items-center gap-3 mb-3 mt-1">
            <a href="javascript:history.back()" class="back-circle-btn shadow-sm">
                <i class="fas fa-arrow-right" id="backIcon"></i>
            </a>
            <h5 class="mb-0 fw-bold text-clear" data-i18n="pay_confirm_title">تأكيد الدفع والخصم</h5>
        </div>

        <div class="summary-card shadow-sm mb-3">
            <h6 class="fw-bold text-clear mb-2"><i class="fas fa-receipt me-1"></i> <span data-i18n="booking_details">تفاصيل الحجز</span></h6>
            <div class="summary-row">
                <span class="summary-label" data-i18n="booking_number">رقم الطلب</span>
                <span class="summary-value">#<?= e($booking['Order_Number'] ?: $bookingID) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label" data-i18n="station_label">المحطة</span>
                <span class="summary-value"><?= e($booking['station_name']) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label" data-i18n="service_label">الخدمة</span>
                <span class="summary-value" data-service-name="<?= e($servicesSummary) ?>"><?= e($servicesSummary) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label" data-i18n="appointment_label">الموعد</span>
                <span class="summary-value"><?= e($booking['Booking_Date']) ?> | <?= e(substr((string) $booking['Booking_Time'], 0, 5)) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label fw-bold" data-i18n="total_due">الإجمالي المطلوب</span>
                <span class="summary-total"><?= number_format($base_price, 2) ?> JOD</span>
            </div>
        </div>

        <!-- Points Section -->
        <div class="loyalty-card shadow-sm mb-3" id="pointsCard">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-1 fw-bold"><i class="fas fa-coins me-1"></i> <span data-i18n="loyalty_points">نقاط Clevo</span></h6>
                    <p class="mb-0 small" style="color: #e0f2fe;">رصيدك الحالي: <?= number_format($pointsBalance) ?> نقطة</p>
                    <p class="mb-0 small" style="color: #e0f2fe;">أقصى خصم متاح: <?= number_format($maxPointsDiscount, 2) ?> JOD (<?= number_format($maxUsablePoints) ?> نقطة)</p>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="usePoints" style="transform: scale(1.4); cursor: pointer;">
                </div>
            </div>
        </div>

        <!-- Methods -->
        <h6 class="mb-2 fw-bold text-clear" data-i18n="payment_methods_label">طريقة الدفع:</h6>
        
        <div id="methodsList">
            <?php if ($isStationService): ?>
            <div class="method-item d-flex align-items-center shadow-sm" onclick="setMethod(this, 'cash')">
                <div class="method-icon rounded-circle bg-secondary-subtle">
                    <i class="fas fa-money-bill-wave text-secondary fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold text-clear" data-i18n="pay_cash">كاش</h6>
                    <small class="text-muted-clear" data-i18n="cash_desc">متاح لخدمة المحطة</small>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($activePlan): ?>
            <div class="method-item d-flex align-items-center shadow-sm" onclick="setMethod(this, 'plan')">
                <div class="method-icon rounded-circle bg-info-subtle">
                    <i class="fas fa-car-side text-info fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold text-clear" data-i18n="pay_plan">من الباقة</h6>
                    <small class="text-muted-clear">
                        <span><?= (int) $activePlan['remaining_washes'] ?></span>
                        <span data-i18n="plan_washes_left">غسلات متبقية</span>
                    </small>
                </div>
            </div>
            <?php endif; ?>
            <div class="method-item d-flex align-items-center shadow-sm" onclick="setMethod(this, 'visa')">
                <div class="method-icon rounded-circle bg-primary-subtle">
                    <i class="fas fa-credit-card text-primary fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold text-clear" data-i18n="pay_visa">بطاقة بنكية / فيزا</h6>
                </div>
            </div>

            <div class="method-item d-flex align-items-center shadow-sm" onclick="setMethod(this, 'wallet')">
                <div class="method-icon rounded-circle bg-warning-subtle">
                    <i class="fas fa-wallet text-warning fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold text-clear" data-i18n="pay_wallet">محفظة Clevo</h6>
                    <small class="text-muted-clear"><span data-i18n="current_balance">الرصيد:</span> <?= number_format($walletBalance, 2) ?> JOD</small>
                </div>
            </div>
        </div>

        <button class="btn-confirm shadow mt-3" onclick="processPayment(this)">
            <span data-i18n="btn_confirm_pay">تأكيد الحجز والدفع</span>
        </button>
    </div>


    <div class="modal fade" id="msgModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div id="mIcon" class="mb-3 fs-1"></div>
                <h5 id="mTitle" class="fw-bold text-clear"></h5>
                <p id="mBody" class="text-muted-clear small"></p>
                <button class="btn btn-primary w-100 rounded-pill py-2 mt-3 fw-bold" data-bs-dismiss="modal" id="mBtn">حسناً</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/service-translations.js"></script>
    <script>
        const paymentRequestKey = <?= clevo_js(bin2hex(random_bytes(24))) ?>;
        const lang = localStorage.getItem('app_lang') || 'ar';
        const theme = localStorage.getItem('global_theme') || 'dark';
        let basePrice = <?= number_format($base_price, 2, '.', '') ?>;
        let selectedMethod = "";
        const bookingID = <?= (int) $bookingID ?>;
        const serviceLocation = <?= json_encode($booking['Service_Location']) ?>;
        const csrfToken = <?= json_encode($csrf_token) ?>;
        const msgModal = new bootstrap.Modal(document.getElementById('msgModal'));
        const paymentTranslations = {
            ar: {
                pay_confirm_title: "تأكيد الدفع والخصم", loyalty_points: "نقاط Clevo",
                payment_methods_label: "طريقة الدفع:",
                pay_cash: "كاش", cash_desc: "متاح لخدمة المحطة", pay_plan: "من الباقة",
                plan_washes_left: "غسلات متبقية", pay_visa: "بطاقة بنكية / فيزا",
                pay_wallet: "محفظة Clevo", current_balance: "الرصيد:", btn_confirm_pay: "تأكيد الحجز والدفع",
                booking_details: "تفاصيل الحجز", booking_number: "رقم الطلب", station_label: "المحطة", service_label: "الخدمة",
                appointment_label: "الموعد",
                total_due: "الإجمالي المطلوب",
                btn_ok: "موافق"
            },
            en: {
                pay_confirm_title: "Confirm Payment", loyalty_points: "Clevo Points",
                payment_methods_label: "Payment method:",
                pay_cash: "Cash", cash_desc: "Available at station", pay_plan: "From plan",
                plan_washes_left: "washes left", pay_visa: "Bank Card / Visa",
                pay_wallet: "Clevo Wallet", current_balance: "Balance:", btn_confirm_pay: "Confirm Booking & Payment",
                booking_details: "Booking details", booking_number: "Order number", station_label: "Station", service_label: "Service",
                appointment_label: "Appointment",
                total_due: "Total due",
                btn_ok: "OK"
            }
        };

        function applyPaymentTranslations() {
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (paymentTranslations[lang] && paymentTranslations[lang][key]) {
                    el.innerText = paymentTranslations[lang][key];
                }
            });
        }

        window.onload = () => {
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');

            if (typeof applyTranslations === 'function') applyTranslations(lang);
            applyPaymentTranslations();
            if (window.clevoApplyServiceTranslations) {
                window.clevoApplyServiceTranslations(document, lang);
            }
            if (lang !== 'ar') document.getElementById('backIcon').className = "fas fa-arrow-left";
        };

        const pointsCard = document.getElementById('pointsCard');
        const usePoints = document.getElementById('usePoints');

        pointsCard.addEventListener('click', function(event) {
            if (event.target === usePoints) return;
            togglePointsMethod(!usePoints.checked);
        });

        usePoints.addEventListener('change', function() {
            togglePointsMethod(this.checked);
        });

        function setMethod(el, m) {
            document.querySelectorAll('.method-item').forEach(i => i.classList.remove('active'));
            el.classList.add('active');
            selectedMethod = m;
            togglePointsMethod(false, false);
        }

        function togglePointsMethod(active, clearSelected = true) {
            usePoints.checked = active;
            pointsCard.classList.toggle('active', active);
            if (active && selectedMethod === 'plan') {
                selectedMethod = "";
                document.querySelectorAll('.method-item').forEach(i => i.classList.remove('active'));
            }
        }

        function showMessage(type, titleAr, titleEn, bodyAr, bodyEn, callback) {
            const iconEl = document.getElementById('mIcon');
            iconEl.className = type === 'success' ? 'fas fa-check-circle text-success' : 'fas fa-exclamation-circle text-warning';
            
            document.getElementById('mTitle').innerText = (lang === 'ar' ? titleAr : titleEn);
            document.getElementById('mBody').innerText = (lang === 'ar' ? bodyAr : bodyEn);
            document.getElementById('mBtn').innerText = (lang === 'ar' ? 'موافق' : 'OK');
            
            const btn = document.getElementById('mBtn');
            btn.onclick = () => {
                msgModal.hide();
                if (callback) callback();
            };
            msgModal.show();
        }

        function processPayment(btn) {
            if (!selectedMethod) {
                showMessage('error', 'تنبيه', 'Alert', 'يرجى اختيار طريقة الدفع أولاً', 'Please select a payment method');
                return;
            }
            const originalBtn = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${lang === 'ar' ? 'جاري المعالجة...' : 'Processing...'}`;
            }

            if (false && selectedMethod === 'visa') {
                location.href = 'payment-gateway.php?id=' + bookingID;
            } else {
                const params = new URLSearchParams();
                params.append('action', 'set_payment');
                params.append('idempotency_key', paymentRequestKey + '_' + selectedMethod + '_' + (usePoints.checked ? '1' : '0'));
                params.append('method', selectedMethod);
                params.append('use_points', usePoints.checked ? '1' : '0');
                params.append('csrf_token', csrfToken);
                fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'redirect_visa') {
                        location.href = 'payment-gateway.php?id=' + bookingID + '&use_points=' + (usePoints.checked ? '1' : '0');
                        return;
                    }
                    if (data.status === 'insufficient' || data.message === 'insufficient') {
                        showMessage('error', 'الرصيد غير كاف', 'Insufficient Balance', 'رصيد المحفظة لا يكفي لإتمام الدفع.', 'Your wallet balance is not enough.');
                        return;
                    }
                    if (data.status === 'insufficient_points' || data.message === 'insufficient_points') {
                        showMessage('error', 'النقاط غير كافية', 'Insufficient Points', 'رصيد نقاط الولاء لا يكفي لإتمام الدفع.', 'Your loyalty points are not enough.');
                        return;
                    }
                    if (data.status === 'insufficient_plan' || data.message === 'insufficient_plan') {
                        showMessage('error', 'الباقة غير كافية', 'Plan Unavailable', 'لا توجد غسلات متبقية في باقتك.', 'There are no remaining washes in your plan.');
                        return;
                    }
                    if (data.status === 'not_allowed' || data.message === 'not_allowed') {
                        showMessage('error', 'طريقة غير متاحة', 'Not Allowed', 'هذه الطريقة غير متاحة لهذا النوع من الخدمة.', 'This payment method is not available for this service.');
                        return;
                    }
                    if (data.status !== 'success') {
                        showMessage('error', 'تعذر الدفع', 'Payment Failed', 'حاول مرة أخرى.', 'Please try again.');
                        return;
                    }
                    showMessage('success', 'تم الدفع!', 'Paid!', 'تم حفظ طريقة الدفع بنجاح', 'Payment method saved', () => {
                        location.href = '../user/booking-history.php';
                    });
                }).finally(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalBtn;
                    }
                });
            }
        }
    </script>
    <script>window.clevoCsrfToken = <?= clevo_js(generate_csrf_token()) ?>;</script>
    <script src="../assets/lamea.js?v=20260605b"></script>
</body>
</html>
