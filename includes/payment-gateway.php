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
$planID = (int) ($_GET['plan_id'] ?? 0);
cancelExpiredBookings($conn, $userID);
clevoEnsurePlanColumns($conn);
clevoEnsureBookingPaymentColumns($conn);
$usePoints = ($_GET['use_points'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='mark_visa_paid') {
    // A browser acknowledgement is not gateway settlement evidence in any environment.
    throw new ClevoSecurityException('payment_gateway_not_configured',503);
}

if ($usePoints) throw new ClevoSecurityException('online_points_not_approved',503);
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='start_checkout') {
    $result=clevo_checkout_create($conn,$userID,$bookingID>0?['purpose'=>'booking','booking_id'=>$bookingID]:['purpose'=>'subscription','plan_id'=>$planID],(string)($_POST['idempotency_key']??''));
    clevo_json_response('success',$result);
}
if ($bookingID > 0) {
    $stmt = $conn->prepare("SELECT Total_Price, Status, Payment_Method FROM booking WHERE BookingID = ? AND UserID = ?");
    $stmt->bind_param("ii", $bookingID, $userID);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking || $booking['Status'] !== 'Accepted' || !empty($booking['Payment_Method'])) {
        header("Location: ../user/booking-history.php");
        exit();
    }
    $preview = clevoPreviewPointsDiscount($conn, $userID, (float) $booking['Total_Price'], $usePoints);
    $final_price = number_format((float) $preview['payable'], 2);
} elseif ($planID > 0) {
    $stmt = $conn->prepare("SELECT price, name_ar, name_en FROM plans WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("i", $planID);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    if (!$plan) {
        header("Location: ../user/customer-plans.php");
        exit();
    }
    $booking = null;
    $final_price = number_format((float) $plan['price'], 2);
} else {
    $booking = null;
    $final_price = "0.00";
}
$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Clevo | Payment</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root { 
            --bg-body: #f8f9fa; --bg-card: #ffffff; --text-main: #111827; 
            --text-muted: #6b7280; --border-color: #e5e7eb; --input-bg: #ffffff;
            --visa-grad: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-card: #0f172a; --text-main: #f9fafb; 
            --text-muted: #9ca3af; --border-color: #334155; --input-bg: #1e293b;
            --visa-grad: linear-gradient(135deg, #0f172a 0%, #3b82f6 100%);
        }

        body { 
            font-family: 'Cairo', sans-serif; background-color: var(--bg-body); 
            color: var(--text-main); min-height: 100vh; transition: 0.3s ease; 
        }

        .back-nav { position: fixed; top: 20px; z-index: 1000; }
        [dir="rtl"] .back-nav { right: 20px; }
        [dir="ltr"] .back-nav { left: 20px; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }

        .circle-btn { 
            background: var(--bg-card); width: 45px; height: 45px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); border: 1px solid var(--border-color); 
            color: var(--text-main); text-decoration: none; cursor: pointer;
        }

        .payment-container { max-width: 450px; margin: 80px auto 40px; padding: 0 15px; }

        .payment-card { 
            background: var(--bg-card); border-radius: 30px; padding: 25px; 
            border: 1px solid var(--border-color); box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        h5, label, strong { color: var(--text-main) !important; }
        .text-muted-custom { color: var(--text-muted) !important; }

        .credit-card-view { 
            background: var(--visa-grad); border-radius: 20px; padding: 20px; 
            color: white !important; height: 190px; direction: ltr; margin-bottom: 25px; 
            display: flex; flex-direction: column; justify-content: space-between; 
            box-shadow: 0 10px 25px rgba(59, 130, 246, 0.4);
        }

        .form-control { 
            border-radius: 12px; padding: 12px; background-color: var(--input-bg) !important; 
            border: 2px solid var(--border-color) !important; color: var(--text-main) !important;
            font-weight: 600;
        }
        .form-control::placeholder { color: var(--text-muted); opacity: 0.7; }

        .btn-pay { 
            border-radius: 50px; padding: 15px; font-weight: 800; background: #10b981; 
            color: white; border: none; width: 100%; font-size: 1.1rem;
        }

        .modal-content { 
            background: var(--bg-card); color: var(--text-main); border-radius: 25px; 
            border: 1px solid var(--border-color);
        }
    </style>
</head>

<body>

    <div class="back-nav">
        <div onclick="history.back()" class="circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </div>
    </div>

    <div class="container payment-container">
        <div class="payment-card">
            <div class="text-center mb-4">
                <h5 class="fw-bold" id="pageTitle">بوابة الدفع الآمن</h5>
                <div class="badge bg-primary-subtle text-primary p-2 px-3 rounded-pill mt-2 border">
                    <span id="priceLabel">المبلغ المطلوب:</span>
                    <span class="fw-bold" id="displayPrice"><?php echo $final_price; ?> JOD</span>
                </div>
            </div>

            <p class="text-center" id="hostedNotice">سيتم إدخال بيانات البطاقة لدى مزود الدفع. لا تجمع CLEVO رقم البطاقة أو رمز الأمان.</p>
            <button type="button" id="payBtn" class="btn btn-pay shadow-sm" onclick="handlePay(this)"><span id="btnPayText">متابعة إلى الدفع الآمن</span></button>

        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center shadow-lg">
                <div id="modalIcon" class="mb-3"></div>
                <h6 id="modalTitle" class="fw-bold"></h6>
                <p id="modalBody" class="small text-muted-custom mb-3"></p>
                <button class="btn btn-primary w-100 rounded-pill py-2" data-bs-dismiss="modal" id="modalBtn">فهمت</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                title: "بوابة الدفع الآمن",
                price: "المبلغ المطلوب:",
                labelName: "اسم صاحب البطاقة",
                labelCard: "رقم البطاقة",
                labelDate: "تاريخ الانتهاء",
                btnPay: "تأكيد الدفع",
                errorTitle: "تنبيه",
                errorMsg: "يرجى تعبئة جميع الخانات بشكل صحيح",
                successTitle: "تم بنجاح",
                successMsg: "تمت عملية الدفع بنجاح وجاري إتمام الحجز!",
                planSuccessMsg: "تم الاشتراك بالباقة بنجاح!",
                btnOk: "فهمت"
            },
            en: {
                title: "Secure Payment Gateway",
                price: "Amount Required:",
                labelName: "Card Holder Name",
                labelCard: "Card Number",
                labelDate: "Expiry Date",
                btnPay: "Confirm Payment",
                errorTitle: "Alert",
                errorMsg: "Please fill all fields correctly",
                successTitle: "Success",
                successMsg: "Payment processed successfully!",
                planSuccessMsg: "Plan subscription completed successfully!",
                btnOk: "Got it"
            }
        };

        const lang = localStorage.getItem('app_lang') || 'ar';
        const theme = localStorage.getItem('global_theme') || 'dark';
        const csrfToken = <?= json_encode($csrf_token) ?>;
        const bookingID = <?= (int) $bookingID ?>;
        const planID = <?= (int) $planID ?>;

        window.onload = () => {
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
            document.title = `${translations[lang].title} | Clevo`;

            applyLanguage();
        };

        function applyLanguage() {
            const t = translations[lang];
            document.getElementById('pageTitle').innerText = t.title;
            document.getElementById('priceLabel').innerText = t.price;



            document.getElementById('btnPayText').innerText = t.btnPay;
            document.getElementById('modalBtn').innerText = t.btnOk;
            document.getElementById('btnPayText').innerText = lang==='ar'?'متابعة إلى الدفع الآمن':'Continue to secure checkout';
            document.getElementById('hostedNotice').innerText = lang==='ar'?'سيتم إدخال بيانات البطاقة لدى مزود الدفع. لا تجمع CLEVO رقم البطاقة أو رمز الأمان.':'Enter card details only on the provider hosted page. CLEVO does not collect card numbers or security codes.';
        }

        function showModal(icon, title, body) {
            document.getElementById('modalIcon').innerHTML = icon;
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalBody').innerText = body;
            new bootstrap.Modal(document.getElementById('statusModal')).show();
        }

        const checkoutKey = <?= clevo_js(bin2hex(random_bytes(24))) ?>;
        function handlePay(btn) {
            btn.disabled=true;
            const params=new URLSearchParams({action:'start_checkout',csrf_token:csrfToken,idempotency_key:checkoutKey});
            fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:params.toString()})
            .then(r=>r.json()).then(data=>{
                const order=data.data?.payment_order;
                if(data.status==='success'&&order?.checkout_url){window.location.assign(order.checkout_url);return;}
                const message=lang==='ar'?'لم يُؤكد الدفع. قد تكون الخدمة غير مهيأة أو تحتاج العملية إلى التحقق. افتح الحجز لمعرفة حالته.':'Payment is not confirmed. Checkout may be unavailable or require reconciliation. Check your booking status.';
                showModal('<i class="fas fa-info-circle text-primary fa-3x"></i>',lang==='ar'?'حالة الدفع':'Payment status',message);
            }).catch(()=>showModal('<i class="fas fa-info-circle text-primary fa-3x"></i>',lang==='ar'?'حالة الدفع':'Payment status',lang==='ar'?'تعذر التحقق من النتيجة. لا تعِد إنشاء دفعة أخرى؛ تحقق من الحجز.':'Unable to verify the outcome. Check your booking before starting another payment.'))
            .finally(()=>{btn.disabled=false;});
        }
    </script>
    <script>window.clevoCsrfToken = <?= clevo_js(generate_csrf_token()) ?>;</script>
    <script src="../assets/lamea.js?v=20260605b"></script>
</body>
</html>
