<?php
session_start();
include(__DIR__ . "/config.php");
include(__DIR__ . "/booking-helpers.php");
include(__DIR__ . "/app-helpers.php");
include_once(__DIR__ . "/plan-helpers.php");

if (!isset($_SESSION['userID'])) {
    header("Location: ../general/login.php");
    exit();
}

$userID = (int) $_SESSION['userID'];
$bookingID = (int) ($_GET['id'] ?? 0);
cancelExpiredBookings($conn, $userID);
clevoEnsurePlanColumns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_payment') {
    header('Content-Type: application/json; charset=utf-8');
    clevo_require_csrf_token(true);
    $method = $_POST['method'] ?? '';
    if (!in_array($method, ['cash', 'wallet', 'points', 'plan'], true)) {
        echo json_encode(['status' => 'error']);
        exit;
    }

    $stmt = $conn->prepare("SELECT BookingID, Total_Price, Service_Location FROM booking WHERE BookingID = ? AND UserID = ? AND Status = 'Accepted' AND (Payment_Method IS NULL OR Payment_Method = '')");
    $stmt->bind_param("ii", $bookingID, $userID);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking) {
        echo json_encode(['status' => 'error']);
        exit;
    }

    if ($method === 'cash' && $booking['Service_Location'] !== 'station') {
        echo json_encode(['status' => 'not_allowed']);
        exit;
    }

    if ($method === 'wallet') {
        $wallet = clevoEnsureWallet($conn, $userID);
        if (!$wallet || (float) $wallet['Balance'] < (float) $booking['Total_Price']) {
            echo json_encode(['status' => 'insufficient']);
            exit;
        }

        $amount = (float) $booking['Total_Price'];
        $walletID = (int) $wallet['WalletID'];
        if (!clevoDebitWallet($conn, $walletID, $amount)) {
            echo json_encode(['status' => 'insufficient']);
            exit;
        }
        $descAr = "دفع حجز #$bookingID";
        $descEn = "Booking payment #$bookingID";
        $type = "debit";
        clevoRecordWalletTransaction($conn, $walletID, $amount, $type, $descAr, $descEn);
    }

    if ($method === 'points') {
        $requiredPoints = (int) ceil(((float) $booking['Total_Price']) * 100);
        if (!clevoSpendLoyaltyPoints($conn, $userID, $requiredPoints)) {
            echo json_encode(['status' => 'insufficient_points']);
            exit;
        }
    }

    if ($method === 'plan') {
        $conn->begin_transaction();
        try {
            $planUse = clevoUsePlanForBooking($conn, $userID);
            if (!$planUse) {
                throw new RuntimeException('insufficient_plan');
            }
            $userPlanID = (int) $planUse['id'];
            $planWashesUsed = 1;
            $update = $conn->prepare("UPDATE booking SET Payment_Method = ?, UserPlanID = ?, Plan_Washes_Used = ? WHERE BookingID = ? AND UserID = ? AND Status = 'Accepted' AND (Payment_Method IS NULL OR Payment_Method = '')");
            $update->bind_param("siiii", $method, $userPlanID, $planWashesUsed, $bookingID, $userID);
            $update->execute();
            if ($update->affected_rows <= 0) {
                throw new RuntimeException('update_failed');
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            echo json_encode(['status' => $e->getMessage() === 'insufficient_plan' ? 'insufficient_plan' : 'error']);
            exit;
        }
    } else {
        $update = $conn->prepare("UPDATE booking SET Payment_Method = ? WHERE BookingID = ? AND UserID = ? AND Status = 'Accepted' AND (Payment_Method IS NULL OR Payment_Method = '')");
        $update->bind_param("sii", $method, $bookingID, $userID);
        $update->execute();
        if ($update->affected_rows <= 0) {
            echo json_encode(['status' => 'error']);
            exit;
        }
        clevoCreditOwnerForBooking($conn, $bookingID);
    }
    echo json_encode(['status' => 'success']);
    exit;
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
$wallet = clevoEnsureWallet($conn, $userID);
$walletBalance = (float) $wallet['Balance'];
$pointsBalance = clevoGetLoyaltyPoints($conn, $userID);
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
        /* ضبط الدارك مود بألوان شديدة الوضوح */
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-card: #0f172a; --text-main: #ffffff; 
            --text-muted: #cbd5e1; --border-color: #334155; --accent: #3b82f6;
        }
        
        body { 
            font-family: 'Cairo', sans-serif; background-color: var(--bg-body); 
            color: var(--text-main); margin: 0; min-height: 100vh; transition: 0.3s;
        }

        .payment-container { max-width: 450px; margin: auto; padding: 20px; }

        /* سهم الرجوع - العودة للخلف تلقائياً */
        .back-circle-btn { 
            background: var(--bg-card); border: 1px solid var(--border-color); 
            color: var(--text-main) !important; width: 42px; height: 42px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; text-decoration: none;
        }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }

        /* بطاقة الخصم */
        .loyalty-card { 
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); 
            border-radius: 20px; padding: 20px; color: #ffffff !important;
        }

        /* خيارات الدفع */
        .method-item { 
            background: var(--bg-card); border: 2px solid var(--border-color); 
            border-radius: 18px; padding: 18px; margin-bottom: 15px; 
            cursor: pointer; transition: 0.2s; color: var(--text-main);
        }
        .method-item.active { border-color: var(--accent); background: rgba(59, 130, 246, 0.1); }

        /* وضوح النصوص في الصناديق */
        .price-box { 
            background: var(--bg-card); border-radius: 20px; padding: 25px; 
            text-align: center; border: 1px solid var(--border-color);
        }
        .text-clear { color: var(--text-main) !important; opacity: 1 !important; font-weight: 700; }
        .text-muted-clear { color: var(--text-muted) !important; font-weight: 400; }

        .btn-confirm { 
            background: var(--accent); color: #ffffff !important; border: none; 
            border-radius: 50px; padding: 16px; width: 100%; font-weight: 900;
        }

        /* المودال */
        .modal-content { border-radius: 30px; background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border-color); }
        .form-control { background: var(--bg-body); color: var(--text-main); border-radius: 12px; border: 1px solid var(--border-color); }
        .form-control:focus { background: var(--bg-body); color: var(--text-main); }
    </style>
</head>

<body>
    <div class="payment-container">
        <!-- Header -->
        <div class="d-flex align-items-center gap-3 mb-4 mt-2">
            <a href="javascript:history.back()" class="back-circle-btn shadow-sm">
                <i class="fas fa-arrow-right" id="backIcon"></i>
            </a>
            <h5 class="mb-0 fw-bold text-clear" data-i18n="pay_confirm_title">تأكيد الدفع والخصم</h5>
        </div>

        <!-- Points Section -->
        <div class="loyalty-card shadow-sm mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-1 fw-bold"><i class="fas fa-coins me-1"></i> <span data-i18n="loyalty_points">نقاط الولاء</span></h6>
                    <p class="mb-0 small" style="color: #e0f2fe;">رصيدك الحالي: <?= number_format($pointsBalance) ?> نقطة</p>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="usePoints" style="transform: scale(1.4); cursor: pointer;" disabled>
                </div>
            </div>
        </div>

        <!-- Price Display -->
        <div class="price-box shadow-sm mb-4">
            <span class="text-muted-clear d-block mb-1 small" data-i18n="final_amount_label">المبلغ المطلوب دفعه</span>
            <h2 class="text-primary mb-0 fw-black" id="finalTotal"><?php echo number_format($base_price, 2); ?> JOD</h2>
            <div id="discountNote" class="text-success small fw-bold mt-2 d-none">
                <i class="fas fa-check-circle me-1"></i> تم تطبيق الخصم!
            </div>
        </div>

        <!-- Methods -->
        <h6 class="mb-3 fw-bold text-clear" data-i18n="payment_methods_label">طريقة الدفع:</h6>
        
        <div id="methodsList">
            <?php if ($isStationService): ?>
            <div class="method-item d-flex align-items-center shadow-sm" onclick="setMethod(this, 'cash')">
                <div class="rounded-circle bg-secondary-subtle p-3 mx-3">
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
                <div class="rounded-circle bg-info-subtle p-3 mx-3">
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
            <div class="method-item d-flex align-items-center shadow-sm" onclick="setMethod(this, 'points')">
                <div class="rounded-circle bg-success-subtle p-3 mx-3">
                    <i class="fas fa-coins text-success fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold text-clear">نقاط Clevo</h6>
                    <small class="text-muted-clear">رصيدك: <?= number_format($pointsBalance) ?> points</small>
                </div>
            </div>

            <div class="method-item d-flex align-items-center shadow-sm" onclick="setMethod(this, 'visa')">
                <div class="rounded-circle bg-primary-subtle p-3 mx-3">
                    <i class="fas fa-credit-card text-primary fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold text-clear" data-i18n="pay_visa">بطاقة بنكية / فيزا</h6>
                </div>
            </div>

            <div class="method-item d-flex align-items-center shadow-sm" onclick="setMethod(this, 'wallet')">
                <div class="rounded-circle bg-warning-subtle p-3 mx-3">
                    <i class="fas fa-wallet text-warning fs-4"></i>
                </div>
                <div class="flex-grow-1">
                    <h6 class="mb-0 fw-bold text-clear" data-i18n="pay_wallet">محفظة Clevo</h6>
                    <small class="text-muted-clear"><span data-i18n="current_balance">الرصيد:</span> <?= number_format($walletBalance, 2) ?> JOD</small>
                </div>
            </div>
        </div>

        <button class="btn-confirm shadow mt-4" onclick="processPayment(this)">
            <span data-i18n="btn_confirm_pay">تأكيد الحجز والدفع</span>
        </button>
    </div>

    <!-- المودال العربي/إنجليزي -->
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
    <script>
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
                pay_confirm_title: "تأكيد الدفع والخصم", loyalty_points: "نقاط الولاء",
                final_amount_label: "المبلغ المطلوب دفعه", payment_methods_label: "طريقة الدفع:",
                pay_cash: "كاش", cash_desc: "متاح لخدمة المحطة", pay_plan: "من الباقة",
                plan_washes_left: "غسلات متبقية", pay_visa: "بطاقة بنكية / فيزا",
                pay_wallet: "محفظة Clevo", current_balance: "الرصيد:", btn_confirm_pay: "تأكيد الحجز والدفع",
                btn_ok: "موافق"
            },
            en: {
                pay_confirm_title: "Confirm Payment", loyalty_points: "Loyalty Points",
                final_amount_label: "Amount to pay", payment_methods_label: "Payment method:",
                pay_cash: "Cash", cash_desc: "Available at station", pay_plan: "From plan",
                plan_washes_left: "washes left", pay_visa: "Bank Card / Visa",
                pay_wallet: "Clevo Wallet", current_balance: "Balance:", btn_confirm_pay: "Confirm Booking & Payment",
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
            if (lang !== 'ar') document.getElementById('backIcon').className = "fas fa-arrow-left";
        };

        document.getElementById('usePoints').addEventListener('change', function() {
            let total = this.checked ? (basePrice - 2.0) : basePrice;
            document.getElementById('finalTotal').innerText = total.toFixed(2) + " JOD";
            document.getElementById('discountNote').classList.toggle('d-none', !this.checked);
        });

        function setMethod(el, m) {
            document.querySelectorAll('.method-item').forEach(i => i.classList.remove('active'));
            el.classList.add('active');
            selectedMethod = m;
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

            if (selectedMethod === 'visa') {
                location.href = 'payment-gateway.php?id=' + bookingID;
            } else {
                const params = new URLSearchParams();
                params.append('action', 'set_payment');
                params.append('method', selectedMethod);
                params.append('csrf_token', csrfToken);
                fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'insufficient') {
                        showMessage('error', 'الرصيد غير كاف', 'Insufficient Balance', 'رصيد المحفظة لا يكفي لإتمام الدفع.', 'Your wallet balance is not enough.');
                        return;
                    }
                    if (data.status === 'insufficient_points') {
                        showMessage('error', 'النقاط غير كافية', 'Insufficient Points', 'رصيد نقاط الولاء لا يكفي لإتمام الدفع.', 'Your loyalty points are not enough.');
                        return;
                    }
                    if (data.status === 'insufficient_plan') {
                        showMessage('error', 'الباقة غير كافية', 'Plan Unavailable', 'لا توجد غسلات متبقية في باقتك.', 'There are no remaining washes in your plan.');
                        return;
                    }
                    if (data.status === 'not_allowed') {
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
    <script src="../assets/lamea.js"></script>
</body>
</html>

