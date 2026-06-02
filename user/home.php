<?php

session_start();

include_once(__DIR__ . "/../includes/config.php");
include_once(__DIR__ . "/../includes/booking-helpers.php");
include_once(__DIR__ . "/../includes/app-helpers.php");



$userID = clevo_require_role(['customer', 'user'], '../general/login.php');
cancelExpiredBookings($conn, (int) $userID);





$cancel_stmt = $conn->prepare("UPDATE `booking` SET Status = 'Cancelled_Timeout' 
                               WHERE UserID = ? AND Status = 'Pending' 

                               AND created_at < (NOW() - INTERVAL 10 MINUTE)");

$cancel_stmt->bind_param("i", $userID);
$cancel_stmt->execute();

$pending_stmt = $conn->prepare("SELECT b.*, s.Name as station_name, serv.Name as service_name
                                FROM `booking` b
                                JOIN stations s ON b.StationID = s.StationID
                                JOIN services serv ON b.ServiceID = serv.ServiceID
                                WHERE b.UserID = ? AND b.Status IN ('Pending', 'Accepted', 'Arrived', 'In Progress', 'Pickup', 'Washing', 'Returning')
                                ORDER BY b.BookingID DESC LIMIT 1");

$pending_stmt->bind_param("i", $userID);
$pending_stmt->execute();
$pending_booking = $pending_stmt->get_result()->fetch_assoc();

$activeOrderStatusLabels = [

    'Pending' => 'بانتظار الموافقة',
    'Accepted' => 'تمت الموافقة',
    'Arrived' => 'وصل السائق',
    'In Progress' => 'قيد التنفيذ',
    'Pickup' => 'قيد الاستلام',
    'Washing' => 'قيد الغسيل',
    'Returning' => 'قيد الإرجاع',
];

$activeOrderStatusClass = $pending_booking && $pending_booking['Status'] === 'Pending' ? 'status-pending' : 'status-accepted';
$paymentRequiredFirst = $pending_booking

    && $pending_booking['Status'] === 'Accepted'

    && in_array($pending_booking['Service_Location'], ['home', 'delivery'], true)

    && empty($pending_booking['Payment_Method']);

$canTrackDelivery = $pending_booking

    && $pending_booking['Service_Location'] === 'delivery'

    && in_array($pending_booking['Status'], ['Accepted', 'Arrived', 'In Progress', 'Pickup', 'Washing', 'Returning'], true)

    && !empty($pending_booking['Payment_Method'])

    && !$paymentRequiredFirst;





$notif_stmt = $conn->prepare("SELECT COUNT(*) as unread FROM `notifications` WHERE user_id = ? AND is_read = 0");

$notif_stmt->bind_param("i", $userID);

$notif_stmt->execute();

$unread_count = $notif_stmt->get_result()->fetch_assoc()['unread'];





$loyalty_stmt = $conn->prepare("SELECT COALESCE(SUM(PointsBalance), 0) AS PointsBalance FROM `loyalty` WHERE UserID = ?");

$loyalty_stmt->bind_param("i", $userID);

$loyalty_stmt->execute();

$loyalty_data = $loyalty_stmt->get_result()->fetch_assoc();

$user_points = $loyalty_data['PointsBalance'] ?? 0;





$stmt = $conn->prepare("SELECT u.UserName, u.ProfilePic

                        FROM `user` u

                        WHERE u.UserID = ?

                        LIMIT 1");

$stmt->bind_param("i", $userID);

$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();





if (!$user) {

    session_destroy();

    header("Location: login.php");

    exit();
}



$activePlan = clevoGetActiveUserPlan($conn, (int) $userID);

$is_subscribed = (bool) $activePlan;

$washes_left = $activePlan['remaining_washes'] ?? 0;

$plan_total_washes = $activePlan ? max((int) $washes_left, clevoPlanWashCount($activePlan)) : 0;

$plan_features_ar = $activePlan ? clevoPlanFeatures($activePlan, 'ar') : [];

$plan_features_en = $activePlan ? clevoPlanFeatures($activePlan, 'en') : [];

?>



<!DOCTYPE html>

<html lang="ar" dir="rtl">



<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title data-i18n="dash_page_title">Clevo | الرئيسية</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root {

            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);

            --glass: rgba(255, 255, 255, 0.7);

            --text: #0369a1;

            --accent: #0ea5e9;

            --modal-bg: #ffffff;

            --modal-text: #0369a1;

            --cancel-btn-bg: #f8fafc;

            --cancel-btn-text: #334155;

            --cancel-btn-border: #e2e8f0;

        }



        [data-theme="dark"] {

            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);

            --glass: rgba(30, 41, 59, 0.5);
            --text: #f1f5f9;
            --accent: #38bdf8;
            --modal-bg: #1e293b;
            --modal-text: #f1f5f9;
            --cancel-btn-bg: #334155;
            --cancel-btn-text: #f1f5f9;
            --cancel-btn-border: #475569;
        }

        [data-theme="dark"] .weather-banner .weather-main,
        [data-theme="dark"] .weather-banner .weather-tip,
        [data-theme="dark"] .weather-banner .weather-dot,
        [data-theme="dark"] #tempVal {
            color: #0f172a !important;
        }
        [data-theme="dark"] .weather-banner * {
    color: #0f172a !important;
}

        body {

            font-family: 'Cairo', sans-serif;

            background: var(--main-bg);

            color: var(--text);

            min-height: 100vh;

            margin: 0;

            padding-bottom: 80px;
            /* يعطي مساحة مريحة تحت لمنع تداخل العناصر مع المساعد العائم */

        }



        .hero-banner {

            position: relative;

            width: 100%;

            height: 200px;

            background-image: url('../assets/Images/header-bg.jpg');

            background-size: cover;

            background-position: center;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 20px;

            overflow: hidden;

        }



        .hero-banner::before {

            content: "";

            position: absolute;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            background: rgba(0, 0, 0, 0.2);

            backdrop-filter: blur(10px);

            z-index: 1;

        }



        .glass-box-container {

            position: relative;

            z-index: 2;

            width: 100%;

            max-width: 600px;

            background: rgba(255, 255, 255, 0.8);

            backdrop-filter: blur(5px);

            border-radius: 20px;

            padding: 10px 15px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);

            border: 1px solid rgba(255, 255, 255, 0.4);

            gap: 10px;

        }



        [data-theme="dark"] .glass-box-container {

            background: rgba(30, 41, 59, 0.8);

            color: white;

        }



        .btn-group-nav {

            display: flex;

            gap: 6px;

            align-items: center;

            flex-wrap: nowrap;
            /* يمنع نزول الأزرار لسطر جديد */

        }



        .nav-btn {

            width: 36px;

            height: 36px;

            border-radius: 50%;

            border: 1.5px solid var(--text);

            display: flex;

            align-items: center;

            justify-content: center;

            background: transparent;

            color: var(--text);

            cursor: pointer;

            font-weight: bold;

            font-size: 0.85rem;

            transition: 0.3s;

            text-decoration: none;

            flex-shrink: 0;

        }



        .logout-rect {

            border: 1px solid #0ea5e9;

            color: #0ea5e9;

            background: transparent;

            border-radius: 10px;

            padding: 0 15px;

            height: 36px;

            font-weight: 600;

            width: auto;

            white-space: nowrap;
            /* يمنع انكسار كلمة خروج */

        }



        .user-avatar {

            width: 50px;

            height: 50px;

            border-radius: 50%;

            border: 2px solid var(--accent);

            object-fit: cover;

            flex-shrink: 0;

        }



        .weather-banner {

            margin: 20px;

            padding: 18px;

            display: flex;

            align-items: center;

            justify-content: space-between;

            font-weight: 700;

            border-radius: 20px;

            position: relative;

            overflow: hidden;

            z-index: 1;

            border: 1px solid rgba(255, 255, 255, 0.2);

            transition: 0.5s;

            background: url('../assets/Images/Rainy.jpg') center/cover no-repeat;

        }



        /* تعديل رائع للويدجتس لمنع التداخل نهائياً وعلى كل الأجهزة */

        .top-widgets {

            display: flex;

            gap: 15px;

            padding: 0 20px;

            align-items: stretch;

        }



        .top-widgets>a,

        .top-widgets>div {

            flex: 1;
            /* توزيع المساحة بالتساوي 50% لكل بوكس */

            min-width: 0;
            /* منع الخروج عن الحاوية */

        }



        .points-circle {

            width: 65px;

            height: 65px;

            border: 4px solid transparent;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            margin: 0 auto 8px;

            font-weight: 900;

            font-size: 1.1rem;

            background: linear-gradient(white, white) padding-box, linear-gradient(135deg, #0ea5e9, #38bdf8) border-box;

            color: #0ea5e9;

        }



        [data-theme="dark"] .points-circle {

            background: linear-gradient(#1e293b, #1e293b) padding-box, linear-gradient(135deg, #0ea5e9, #38bdf8) border-box;

        }



        .timer-container {

            font-size: 0.75rem;

            color: #ef4444;

            margin-top: 5px;

            font-weight: 700;

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 4px;

        }



        .btn-subscribe {

            background: linear-gradient(135deg, #0ea5e9, #38bdf8);

            color: white;

            border: none;

            border-radius: 30px;

            padding: 8px 14px;

            font-size: 0.85rem;

            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.3);

            position: relative;

            overflow: hidden;

            width: 100%;

        }



        .subscription-widget {

            padding: 12px 10px !important;

            display: flex;

            flex-direction: column;

            justify-content: center;

            align-items: center;

        }



        .car-promo-img {

            width: 100px;
            /* حجم مناسب لمنع التضخم ومضايقة البوكس الثاني */

            max-width: 100%;

            height: auto;

            margin-bottom: -5px;

            filter: drop-shadow(0 10px 15px rgba(0, 0, 0, 0.2));

        }



        .washes-text {

            font-weight: 900;

            font-size: 0.95rem;

            color: var(--text);

            margin-bottom: 2px;

            white-space: nowrap;

        }



        .washes-count {

            font-size: 1.8rem;

            font-weight: 900;

            color: #a855f7 !important;

            line-height: 1.1;

        }



        .plan-mini-rail {

            display: flex;

            gap: 6px;

            max-width: 100%;

            overflow-x: auto;

            padding: 6px 2px 0;

            scrollbar-width: none;

        }



        .plan-mini-rail::-webkit-scrollbar {

            display: none;

        }



        .plan-mini-chip {

            flex: 0 0 auto;

            padding: 4px 8px;

            border-radius: 999px;

            background: rgba(14, 165, 233, 0.12);

            border: 1px solid rgba(14, 165, 233, 0.18);

            font-size: .68rem;

            font-weight: 800;

            white-space: nowrap;

        }



        .services-grid {

            display: grid;

            grid-template-columns: repeat(3, 1fr);

            gap: 12px;

            padding: 0 20px;

            margin-top: 15px;

        }



        .service-item {

            background: var(--glass);

            border-radius: 25px;

            padding: 20px 10px;

            text-align: center;

            text-decoration: none;

            color: inherit;

            border: 1px solid rgba(255, 255, 255, 0.1);

        }



        .service-item i {

            font-size: 1.6rem;

            margin-bottom: 10px;

            color: var(--accent);

            display: block;

        }



        .modal-content.glass {

            background: var(--modal-bg) !important;

            color: var(--modal-text) !important;

            border: 1px solid var(--accent);

            border-radius: 30px !important;

        }



        .logout-modal-content {

            background: var(--modal-bg) !important;

            color: var(--modal-text) !important;

            border-radius: 30px !important;

            border: none !important;

        }



        .logout-modal-title {

            color: var(--modal-text) !important;

            font-weight: 700;

        }



        .logout-modal-body {

            color: var(--modal-text) !important;

            opacity: 0.85;

        }



        .logout-confirm-btn {

            background: #dc3545 !important;

            color: #ffffff !important;

            border: none !important;

            border-radius: 50px !important;

            padding: 12px 0 !important;

            font-weight: 700;

            width: 100%;

            display: block;

            text-decoration: none;

            text-align: center;

        }



        .logout-cancel-btn {

            background: var(--cancel-btn-bg) !important;

            color: var(--cancel-btn-text) !important;

            border: 1px solid var(--cancel-btn-border) !important;

            border-radius: 50px !important;

            padding: 12px 0 !important;

            font-weight: 700;

            width: 100%;

        }



        .order-status-card {

            margin: 0 20px 20px;

            padding: 16px;

            background: var(--glass);

            border: 1px solid rgba(255, 255, 255, .16);

            border-radius: 20px;

            backdrop-filter: blur(15px);

        }



        .status-pill {

            border-radius: 50px;

            padding: 5px 12px;

            font-size: .75rem;

            font-weight: 900;

        }



        .status-pending {

            background: #fef3c7;

            color: #92400e;
        }

        .status-accepted {

            background: #dcfce7;

            color: #166534;

        }



        .order-next-btn {

            border: 0;

            border-radius: 50px;

            padding: 10px 14px;

            background: var(--accent);

            color: white;

            font-weight: 900;

            text-decoration: none;

            display: inline-flex;

            align-items: center;

            gap: 8px;

        }



        .weather-main,

        .weather-tip {

            font-size: 1.05rem;

            font-weight: 800;

        }



        .user-section {

            display: flex;

            align-items: center;

            gap: 12px;

        }



        .user-section .text-end {

            display: flex;

            flex-direction: column;

            justify-content: center;

        }



        /* تنسيق خاص بـلامع العائم لضمان استقراره في زاوية مخصصة ومنع العجقة */

        #lamea-container,
        .lamea-bot-icon,
        [src*="lamea"] {

            position: fixed !important;

            bottom: 20px !important;

            left: 20px !important;

            z-index: 9999 !important;

        }
    </style>

</head>



<body>



    <div class="hero-banner shadow-sm">

        <div class="glass-box-container">

            <div class="btn-group-nav">

                <button class="nav-btn logout-rect" onclick="showM('logoutModal')" data-i18n="serv_logout">خروج</button>

                <button class="nav-btn" onclick="toggleLanguage()" id="langBtn">EN</button>

                <button class="nav-btn" onclick="toggleTheme()"><i id="themeIcon" class="fas fa-moon"></i></button>

                <a href="notifications.php" class="nav-btn" style="position: relative;">

                    <i class="fas fa-bell"></i>

                    <?php if ($unread_count > 0): ?><span style="position:absolute; top:5px; right:5px; width:8px; height:8px; background:red; border-radius:50%;"></span><?php endif; ?>

                </a>

            </div>



            <div class="user-section">

                <div class="text-end">

                    <span id="welcomeText"

                        class="small opacity-75"

                        data-i18n="hello_weather">

                        مرحبا بك..

                    </span>

                    <h5 class="fw-bold m-0"><?= htmlspecialchars($user['UserName']) ?></h5>

                </div>



                <a href="profile.php">

                    <?php

                    $pImg = clevoProfileImageUrl($user['ProfilePic'] ?? '', $user['UserName'] ?? 'User');

                    ?>

                    <img src="<?= $pImg ?>" class="user-avatar shadow-sm">

                </a>

            </div>

        </div>

    </div>



    <div class="top-widgets mt-3">

        <a href="loyalty.php" class="service-item loyalty-widget shadow-sm text-decoration-none" style="padding: 20px; text-align: center;">

            <div class="points-circle"><?= number_format($user_points) ?></div>

            <span class="small fw-bold" data-i18n="loyalty_title">نقاط الولاء</span>

        </a>



        <div class="service-item subscription-widget shadow-sm text-center">

            <?php if (!$is_subscribed): ?>

                <h6 class="fw-bold mb-2 d-flex align-items-center gap-2">

                    <i class="fas fa-car"></i>

                    <span data-i18n="save_every_wash">غسيل احترافي بأسعار أوفر</span>

                </h6>

                <button class="btn-subscribe" onclick="location.href='customer-plans.php'" data-i18n="subscribe_now">اشترك الآن</button>

            <?php else: ?>

                <img src="../assets/Images/car.png" class="car-promo-img" alt="Car">

                <div class="washes-text" data-i18n="washes_left">غسلات متبقية</div>

                <div class="washes-count"><?= (int) $washes_left ?> / <?= (int) $plan_total_washes ?></div>

                <div class="plan-mini-rail"

                    data-features-ar="<?= htmlspecialchars(json_encode($plan_features_ar, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"

                    data-features-en="<?= htmlspecialchars(json_encode($plan_features_en, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>"></div>

            <?php endif; ?>

        </div>

    </div>



    <div id="weatherContainer" class="weather-banner shadow-sm sunny-mode">

        <div class="d-flex align-items-center gap-3">

            <i id="weatherIcon" class="fas fa-sun fa-2x"></i>

            <span id="weatherText" data-i18n="hello_weather">مرحباً بك..</span>

        </div>

        <div id="tempVal" class="fw-black fs-4">--°C</div>

    </div>



    <?php if ($pending_booking): ?>

        <div class="order-status-card shadow-sm">

            <div class="d-flex justify-content-between align-items-start gap-3">

                <div>

                    <span class="status-pill <?= $activeOrderStatusClass ?>" data-order-status="<?= htmlspecialchars($pending_booking['Status']) ?>">

                        <?= htmlspecialchars($activeOrderStatusLabels[$pending_booking['Status']] ?? $pending_booking['Status']) ?>

                    </span>

                    <h6 class="fw-bold mt-2 mb-1" data-service-name="<?= htmlspecialchars($pending_booking['service_name']) ?>"><?= htmlspecialchars($pending_booking['service_name']) ?></h6>

                    <p class="small opacity-75 mb-1"><?= htmlspecialchars($pending_booking['station_name']) ?> | #<?= htmlspecialchars($pending_booking['Order_Number']) ?></p>

                    <p class="small mb-0"><?= $pending_booking['Booking_Date'] ?> <?= date('h:i A', strtotime($pending_booking['Booking_Time'])) ?></p>

                    <?php if ($pending_booking['Status'] === 'Pending'): ?>

                        <div class="timer-container justify-content-start" id="bookingTimer" data-start="<?= $pending_booking['created_at'] ?>" data-duration="10">

                            <i class="fas fa-hourglass-half"></i>

                            <span data-i18n="auto_cancel_after">إلغاء تلقائي بعد</span>

                            <span id="timerText">--:--</span>

                        </div>

                    <?php elseif ($pending_booking['Status'] === 'Accepted' && in_array($pending_booking['Service_Location'], ['home', 'delivery'], true) && empty($pending_booking['Payment_Method'])): ?>

                        <div class="timer-container justify-content-start" id="bookingTimer" data-start="<?= $pending_booking['Accepted_At'] ?: $pending_booking['created_at'] ?>" data-duration="10">

                            <i class="fas fa-credit-card"></i>

                            <span data-i18n="payment_deadline">مهلة الدفع</span>

                            <span id="timerText">--:--</span>

                        </div>

                    <?php endif; ?>

                </div>

                <?php if ($pending_booking['Status'] === 'Accepted' && empty($pending_booking['Payment_Method'])): ?>

                    <a class="order-next-btn" href="../includes/payment-method.php?id=<?= (int) $pending_booking['BookingID'] ?>"><i class="fas fa-credit-card"></i> <span data-i18n="pay_now">الدفع</span></a>



                <?php elseif ($pending_booking['Status'] === 'Accepted' && $pending_booking['Payment_Method'] === 'plan'): ?>

                    <span class="order-next-btn" style="background:#10b981;"><i class="fas fa-gem"></i> <span data-i18n="paid_from_plan">من الباقة</span></span>

                <?php endif; ?>

                <?php if ($canTrackDelivery): ?>



                    <div class="d-flex justify-content-center mt-3">



                        <a class="order-next-btn"

                            href="live-tracking.php?id=<?= $pending_booking['BookingID'] ?>">



                            <i class="fas fa-location-dot"></i>



                            <span data-i18n="track_driver">تتبع السائق</span>



                        </a>



                    </div>



                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>



    <h6 class="px-4 fw-black mt-4" data-i18n="our_services">خدماتنا المميزة</h6>

    <div class="services-grid">

        <a href="search-stations.php" class="service-item shadow-sm"><i class="fas fa-calendar-check"></i><span data-i18n="serv_book">حجز</span></a>

        <a href="customer-plans.php" class="service-item shadow-sm"><i class="fas fa-gem"></i><span data-i18n="serv_plans">الباقات</span></a>

        <a href="booking-history.php" class="service-item shadow-sm"><i class="fas fa-folder-open"></i><span data-i18n="serv_history">السجلات</span></a>

        <a href="user-wallet.php" class="service-item shadow-sm"><i class="fas fa-wallet"></i><span data-i18n="serv_wallet">المحفظة</span></a>

        <a href="profile.php" class="service-item shadow-sm"><i class="fas fa-user-gear"></i><span data-i18n="serv_profile">ملفي</span></a>

        <a href="support.php" class="service-item shadow-sm"><i class="fas fa-headset"></i><span data-i18n="serv_support">الدعم</span></a>

    </div>



    <div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">

        <div class="modal-dialog modal-dialog-centered modal-sm">

            <div class="modal-content logout-modal-content shadow-lg p-4 text-center">

                <div class="mb-3">

                    <i class="fas fa-right-from-bracket text-danger fa-4x"></i>

                </div>

                <h5 class="logout-modal-title mb-2" data-i18n="logout_confirm_title">تأكيد الخروج</h5>

                <p class="logout-modal-body small mb-4" data-i18n="logout_confirm_msg">هل أنت متأكد من رغبتك في تسجيل الخروج؟</p>

                <div class="d-grid gap-2">

                    <a href="../general/logout.php" class="logout-confirm-btn" data-i18n="btn_yes">نعم، خروج</a>

                    <button type="button" data-bs-dismiss="modal" class="logout-cancel-btn" data-i18n="btn_no">إلغاء</button>

                </div>

            </div>

        </div>

    </div>



    <div class="modal fade" id="locationModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">

        <div class="modal-dialog modal-dialog-centered modal-sm">

            <div class="modal-content glass shadow-lg border-0 p-4 text-center">

                <i class="fas fa-map-marker-alt text-primary fa-4x mb-3"></i>

                <h5 class="fw-bold" data-i18n="loc_title">تفعيل الموقع</h5>

                <p class="small mb-4 opacity-75" data-i18n="loc_msg">يرجى تفعيل الوصول للموقع لخدمتك بشكل أفضل.</p>

                <div class="d-grid gap-2">

                    <button onclick="handleLocationPermission()" class="btn btn-primary rounded-pill fw-bold" data-i18n="btn_allow">سماح بالوصول</button>

                    <button data-bs-dismiss="modal" class="btn btn-light border rounded-pill fw-bold" data-i18n="btn_later">لاحقاً</button>

                </div>

            </div>

        </div>

    </div>



    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/service-translations.js"></script>

    <script>
        const API_KEY = "fe43aec0ea2f78313d0d8a5887ba992b";

        const showM = (id) => new bootstrap.Modal(document.getElementById(id)).show();



        function runBookingTimer() {

            const timerEl = document.getElementById('bookingTimer');

            if (!timerEl) return;

            const startTimeStr = timerEl.getAttribute('data-start');

            const durationMinutes = parseInt(timerEl.getAttribute('data-duration') || '10', 10);

            const bookingTime = new Date(startTimeStr).getTime();

            const expiryTime = bookingTime + (durationMinutes * 60 * 1000);



            const interval = setInterval(() => {

                const now = new Date().getTime();

                const diff = expiryTime - now;

                if (diff <= 0) {

                    clearInterval(interval);

                    document.getElementById('timerText').innerText = "Expired";

                    setTimeout(() => {

                        location.reload();

                    }, 1500);

                    return;

                }

                const minutes = Math.floor(diff / (1000 * 60));

                const seconds = Math.floor((diff % (1000 * 60)) / 1000);

                document.getElementById('timerText').innerText = `${minutes}:${seconds < 10 ? '0' + seconds : seconds}`;

            }, 1000);

        }



        function updateWeatherUI(temp, condition, city) {



            const isAr =

                (localStorage.getItem('app_lang') || 'ar') === 'ar';



            document.getElementById('tempVal').innerText =

                `${Math.round(temp)}°C`;



            let message = '';

            let tip = '';



            if (isAr) {



                if (condition === 'rain' || condition === 'drizzle') {



                    message = `الجو ماطر في ${city}`;

                    tip = 'يفضل تأجيل غسيل السيارة اليوم';



                } else if (condition === 'clouds') {



                    message = `أجواء غائمة في ${city}`;

                    tip = 'يمكنك الغسيل لكن تأكد من الطقس';



                } else if (condition === 'clear') {



                    message = `الجو مشمس في ${city}`;

                    tip = 'الطقس ممتاز لغسيل سيارتك';



                } else if (temp <= 10) {



                    message = `الجو بارد في ${city}`;

                    tip = 'يفضل تأجيل الغسيل بسبب البرد';



                } else {



                    message = `الطقس معتدل في ${city}`;

                    tip = 'وقت مناسب لغسيل السيارة';

                }



            } else {



                if (condition === 'rain' || condition === 'drizzle') {



                    message = `Rainy weather in ${city}`;

                    tip = 'Better to postpone washing your car';



                } else if (condition === 'clouds') {



                    message = `Cloudy weather in ${city}`;

                    tip = 'You can wash your car, but check the weather';



                } else if (condition === 'clear') {



                    message = `Sunny weather in ${city}`;

                    tip = 'Perfect weather for a car wash';



                } else if (temp <= 10) {



                    message = `Cold weather in ${city}`;

                    tip = 'Better to delay washing your car';



                } else {



                    message = `Mild weather in ${city}`;

                    tip = 'Great time for a car wash';

                }

            }



            document.getElementById('weatherText').innerHTML = `

    <div class="weather-info">

        <span class="weather-main">${message}</span>

        <span class="weather-dot">✦</span>

        <span class="weather-tip">${tip}</span>

    </div>

`;



            const icon =

                document.getElementById('weatherIcon');



            switch (condition) {



                case 'clear':

                    icon.className = 'fas fa-sun fa-2x';

                    break;



                case 'clouds':

                    icon.className = 'fas fa-cloud fa-2x';

                    break;



                case 'rain':

                case 'drizzle':

                    icon.className = 'fas fa-cloud-rain fa-2x';

                    break;



                case 'thunderstorm':

                    icon.className = 'fas fa-bolt fa-2x';

                    break;



                case 'snow':

                    icon.className = 'fas fa-snowflake fa-2x';

                    break;



                default:

                    icon.className = 'fas fa-cloud-sun fa-2x';

            }



            icon.style.color = '#0f172a';

        }



        updateWeatherUI = function(temp, condition, city) {
            const isAr = (localStorage.getItem('app_lang') || 'ar') === 'ar';
            const tempEl = document.getElementById('tempVal');
            if (tempEl) tempEl.innerText = `${Math.round(temp)}°C`;

            let message = '';
            let tip = '';
            if (isAr) {
                if (condition === 'rain' || condition === 'drizzle') {
                    message = `الجو ماطر في ${city}`;
                    tip = 'يفضل تأجيل غسيل السيارة اليوم';
                } else if (condition === 'clouds') {
                    message = `أجواء غائمة في ${city}`;
                    tip = 'يمكنك الغسيل لكن تأكد من الطقس';
                } else if (condition === 'clear') {
                    message = `الجو مشمس في ${city}`;
                    tip = 'الطقس ممتاز لغسيل سيارتك';
                } else if (temp <= 10) {
                    message = `الجو بارد في ${city}`;
                    tip = 'يفضل تأجيل الغسيل بسبب البرد';
                } else {
                    message = `الطقس معتدل في ${city}`;
                    tip = 'وقت مناسب لغسيل السيارة';
                }
            } else {
                if (condition === 'rain' || condition === 'drizzle') {
                    message = `Rainy weather in ${city}`;
                    tip = 'Better to postpone washing your car';
                } else if (condition === 'clouds') {
                    message = `Cloudy weather in ${city}`;
                    tip = 'You can wash your car, but check the weather';
                } else if (condition === 'clear') {
                    message = `Sunny weather in ${city}`;
                    tip = 'Perfect weather for a car wash';
                } else if (temp <= 10) {
                    message = `Cold weather in ${city}`;
                    tip = 'Better to delay washing your car';
                } else {
                    message = `Mild weather in ${city}`;
                    tip = 'Great time for a car wash';
                }
            }

            const weatherText = document.getElementById('weatherText');
            if (weatherText) {
                weatherText.innerHTML = `
                    <div class="weather-info">
                        <span class="weather-main">${message}</span>
                        <span class="weather-dot">✦</span>
                        <span class="weather-tip">${tip}</span>
                    </div>
                `;
            }

            const icon = document.getElementById('weatherIcon');
            if (!icon) return;
            switch (condition) {
                case 'clear':
                    icon.className = 'fas fa-sun fa-2x';
                    break;
                case 'clouds':
                    icon.className = 'fas fa-cloud fa-2x';
                    break;
                case 'rain':
                case 'drizzle':
                    icon.className = 'fas fa-cloud-rain fa-2x';
                    break;
                case 'thunderstorm':
                    icon.className = 'fas fa-bolt fa-2x';
                    break;
                case 'snow':
                    icon.className = 'fas fa-snowflake fa-2x';
                    break;
                default:
                    icon.className = 'fas fa-cloud-sun fa-2x';
            }
            icon.style.color = '#0f172a';
        };

        function fetchWeather() {



            const currentLang =

                localStorage.getItem('app_lang') || 'ar';



            if (navigator.geolocation) {



                navigator.geolocation.getCurrentPosition((position) => {



                    const lat = position.coords.latitude;

                    const lon = position.coords.longitude;



                    fetch(`https://api.openweathermap.org/data/2.5/weather?lat=${lat}&lon=${lon}&appid=${API_KEY}&units=metric&lang=${currentLang}`)



                        .then(r => r.json())
                        .then(data => {

                            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}`)

                                .then(r => r.json())

                                .then(loc => {

                                    let city =
                                        loc.address.suburb ||
                                        loc.address.neighbourhood ||
                                        loc.address.city_district ||
                                        loc.address.village ||
                                        loc.address.town ||
                                        loc.address.city ||
                                        data.name;

                                    updateWeatherUI(
                                        data.main.temp,
                                        data.weather[0].main.toLowerCase(),
                                        city
                                    );

                                })

                                .catch(() => {

                                    updateWeatherUI(
                                        data.main.temp,
                                        data.weather[0].main.toLowerCase(),
                                        data.name
                                    );

                                });

                        });
                });



            }



        }





        function handleLocationPermission() {

            if (navigator.geolocation) {

                navigator.geolocation.getCurrentPosition((position) => {

                    sessionStorage.setItem('modal_shown', 'true');

                    const modal = bootstrap.Modal.getInstance(document.getElementById('locationModal'));

                    if (modal) modal.hide();

                    fetchWeather();

                }, () => {

                    sessionStorage.setItem('modal_shown', 'true');

                    const modal = bootstrap.Modal.getInstance(document.getElementById('locationModal'));

                    if (modal) modal.hide();

                });

            }

        }



        function fallbackWeather() {

            const isAr = (localStorage.getItem('app_lang') || 'ar') === 'ar';

            const currentLang = isAr ? 'ar' : 'en';

            fetch(`https://api.openweathermap.org/data/2.5/weather?q=Amman,JO&appid=${API_KEY}&units=metric&lang=${currentLang}`)

                .then(r => r.json())

                .then(data => {

                    let cityName = data.name;



                    const cityTranslations = {



                        amman: {

                            ar: 'عمان',

                            en: 'Amman'

                        },



                        irbid: {

                            ar: 'إربد',

                            en: 'Irbid'

                        },



                        zarqa: {

                            ar: 'الزرقاء',

                            en: 'Zarqa'

                        },



                        aqaba: {

                            ar: 'العقبة',

                            en: 'Aqaba'

                        }



                    };



                    const cityKey =

                        cityName.toLowerCase();



                    if (cityTranslations[cityKey]) {



                        cityName = isAr ?

                            cityTranslations[cityKey].ar :

                            cityTranslations[cityKey].en;

                    }

                    updateWeatherUI(data.main.temp, data.weather[0].main.toLowerCase(), cityName);

                });

        }



        const translations = {

            ar: {

                hello_weather: "مرحباً بك..",

                loyalty_title: "نقاط الولاء",

                save_every_wash: "غسيل أوفر",

                subscribe_now: "اشترك الآن",

                washes_left: "غسلات متبقية",

                our_services: "خدماتنا المميزة",

                serv_book: "حجز",

                serv_plans: "الباقات",

                serv_history: "السجلات",

                serv_wallet: "المحفظة",

                serv_profile: "ملفي",

                serv_support: "الدعم",

                logout_confirm_title: "تأكيد الخروج",

                logout_confirm_msg: "هل أنت متأكد من رغبتك في تسجيل الخروج؟",

                btn_yes: "نعم، خروج",

                btn_no: "إلغاء",

                loc_title: "تفعيل الموقع",

                loc_msg: "يرجى تفعيل الوصول للموقع لخدمتك بشكل أفضل.",

                btn_allow: "سماح بالوصول",

                btn_later: "لاحقاً",

                serv_logout: "خروج",

                paid_from_plan: "من الباقة"

            },

            en: {

                hello_weather: "Welcome..",

                loyalty_title: "Loyalty Points",

                save_every_wash: "Save money",

                subscribe_now: "Subscribe",

                washes_left: "Washes Left",

                our_services: "Our Services",

                serv_book: "Book",

                serv_plans: "Plans",

                serv_history: "History",

                serv_wallet: "Wallet",

                serv_profile: "Profile",

                serv_support: "Support",

                logout_confirm_title: "Logout Confirmation",

                logout_confirm_msg: "Are you sure you want to logout?",

                btn_yes: "Logout",

                btn_no: "Cancel",

                loc_title: "Location Permission",

                loc_msg: "Please enable location to provide better weather and local services.",

                btn_allow: "Allow Access",

                btn_later: "Later",

                serv_logout: "Logout",

                paid_from_plan: "From plan"

            }

        };



        Object.assign(translations.ar, {
            dash_page_title: "Clevo | الرئيسية",
            hello_weather: "مرحباً بك..",
            loyalty_title: "نقاط الولاء",
            save_every_wash: "غسيل احترافي بأسعار أوفر",
            subscribe_now: "اشترك الآن",
            washes_left: "غسلات متبقية",
            our_services: "خدماتنا المميزة",
            serv_book: "حجز",
            serv_plans: "الباقات",
            serv_history: "السجلات",
            serv_wallet: "المحفظة",
            serv_profile: "ملفي",
            serv_support: "الدعم",
            logout_confirm_title: "تأكيد الخروج",
            logout_confirm_msg: "هل أنت متأكد من رغبتك في تسجيل الخروج؟",
            btn_yes: "نعم، خروج",
            btn_no: "إلغاء",
            loc_title: "تفعيل الموقع",
            loc_msg: "يرجى تفعيل الوصول للموقع لخدمتك بشكل أفضل.",
            btn_allow: "سماح بالوصول",
            btn_later: "لاحقاً",
            serv_logout: "خروج",
            paid_from_plan: "من الباقة",
            auto_cancel_after: "إلغاء تلقائي بعد",
            payment_deadline: "مهلة الدفع",
            pay_now: "الدفع",
            track_driver: "تتبع السائق"
        });

        Object.assign(translations.en, {
            dash_page_title: "Clevo | Home",
            save_every_wash: "Professional wash for less",
            auto_cancel_after: "Auto-cancel after",
            payment_deadline: "Payment deadline",
            pay_now: "Pay",
            track_driver: "Track Driver"
        });

        const orderStatusTranslations = {
            ar: {
                Pending: "بانتظار الموافقة",
                Accepted: "تمت الموافقة",
                Arrived: "وصل السائق",
                "In Progress": "قيد التنفيذ",
                Pickup: "قيد الاستلام",
                Washing: "قيد الغسيل",
                Returning: "قيد الإرجاع"
            },
            en: {
                Pending: "Pending Approval",
                Accepted: "Accepted",
                Arrived: "Driver Arrived",
                "In Progress": "In Progress",
                Pickup: "Pickup in Progress",
                Washing: "Washing",
                Returning: "Returning"
            }
        };

        function applyTranslations(lang) {

            document.querySelectorAll('[data-i18n]').forEach(el => {

                const key = el.getAttribute('data-i18n');

                if (translations[lang] && translations[lang][key]) el.innerText = translations[lang][key];

            });

            document.title = translations[lang]?.dash_page_title || document.title;
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.lang = lang;

            const bootstrapLink = document.getElementById('bootstrap-css');
            if (bootstrapLink) {
                bootstrapLink.href = lang === 'ar'
                    ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css"
                    : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            }

            document.querySelectorAll('[data-order-status]').forEach(el => {
                const status = el.getAttribute('data-order-status');
                el.innerText = orderStatusTranslations[lang]?.[status] || status;
            });

            document.getElementById('langBtn').innerText = lang === 'ar' ? 'EN' : 'AR';

            renderPlanMiniRail(lang);

        }



        function renderPlanMiniRail(lang) {

            document.querySelectorAll('.plan-mini-rail').forEach(rail => {

                let features = [];

                try {

                    features = JSON.parse(rail.getAttribute('data-features-' + lang) || '[]');

                } catch (e) {

                    features = [];

                }

                rail.innerHTML = '';

                features.forEach(feature => {

                    const chip = document.createElement('span');

                    chip.className = 'plan-mini-chip';

                    chip.innerText = feature;

                    rail.appendChild(chip);

                });

            });

        }



        function toggleLanguage() {

            const next = (localStorage.getItem('app_lang') || 'ar') === 'ar' ? 'en' : 'ar';

            localStorage.setItem('app_lang', next);

            applyTranslations(next);
            if (window.clevoApplyServiceTranslations) {
                window.clevoApplyServiceTranslations(document, next);
            }

            fetchWeather();

        }



        function toggleTheme() {

            const target = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';

            document.documentElement.setAttribute('data-theme', target);

            localStorage.setItem('global_theme', target);

            document.getElementById('themeIcon').className = target === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

        }



        window.onload = () => {

            const lang = localStorage.getItem('app_lang') || 'ar';

            const theme = localStorage.getItem('global_theme') || 'dark';

            document.documentElement.setAttribute('data-theme', theme);

            const themeIcon = document.getElementById('themeIcon');

            if (themeIcon) themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';



            applyTranslations(lang);

            fetchWeather();

            runBookingTimer();

            if (window.clevoApplyServiceTranslations) {
                window.clevoApplyServiceTranslations(document, lang);
            }



            if (!sessionStorage.getItem('modal_shown')) {

                showM('locationModal');

                sessionStorage.setItem('modal_shown', 'true');

            }

            setTimeout(() => {



                const welcome =

                    document.getElementById('welcomeText');



                if (welcome) {



                    welcome.style.transition = '0.5s';



                    welcome.style.opacity = '0';



                    setTimeout(() => {



                        welcome.style.display = 'none';



                    }, 500);

                }



            }, 2500);

        }
    </script>

    <script src="../assets/lamea.js"></script>

</body>



</html>
