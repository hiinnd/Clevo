<?php
session_start();
include_once(__DIR__ . "/../includes/config.php");
include_once(__DIR__ . "/../includes/app-helpers.php");
include_once(__DIR__ . "/../includes/booking-helpers.php");

if (!isset($_SESSION['userID'])) {
    header("Location: ../general/login.php");
    exit();
}

$userID = (int) $_SESSION['userID'];
$bookingID = (int) ($_GET['id'] ?? 0);
cancelExpiredBookings($conn, $userID);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    clevo_require_csrf_token(true);

    $action = (string) ($_POST['action'] ?? '');
    $postBookingID = (int) ($_POST['booking_id'] ?? $bookingID);

    if (in_array($action, ['cancel_booking', 'reschedule_booking'], true) && $postBookingID !== $bookingID) {
        echo json_encode(['status' => 'error', 'message' => 'invalid_booking'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'cancel_booking') {
        echo json_encode(clevoCancelBookingForUser($conn, $postBookingID, $userID), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reschedule_booking') {
        $newDate = (string) ($_POST['booking_date'] ?? '');
        $newTime = (string) ($_POST['booking_time'] ?? '');
        echo json_encode(clevoRescheduleBookingForUser($conn, $postBookingID, $userID, $newDate, $newTime), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'submit_rating') {
        $rating = max(1, min(5, (int) ($_POST['rating'] ?? 0)));
        $comment = trim((string) ($_POST['comment'] ?? ''));

        $check = $conn->prepare("SELECT BookingID FROM booking WHERE BookingID = ? AND UserID = ? AND Status = 'Completed'");
        $check->bind_param("ii", $bookingID, $userID);
        $check->execute();
        $result = $check->get_result();

        if (!$rating || !$result->fetch_assoc()) {
            echo json_encode(['status' => 'error']);
            exit;
        }

        $existing = $conn->prepare("SELECT ReviewID FROM reviews WHERE BookingID = ? LIMIT 1");
        $existing->bind_param("i", $bookingID);
        $existing->execute();
        $review = $existing->get_result()->fetch_assoc();

        if ($review) {
            $save = $conn->prepare("UPDATE reviews SET Rating = ?, Comment = ? WHERE ReviewID = ?");
            $save->bind_param("isi", $rating, $comment, $review['ReviewID']);
        } else {
            $save = $conn->prepare("INSERT INTO reviews (BookingID, Rating, Comment) VALUES (?, ?, ?)");
            $save->bind_param("iis", $bookingID, $rating, $comment);
        }
        $ok = $save->execute();

        if ($ok) {
            $ownerStmt = $conn->prepare("SELECT s.UserID AS OwnerID, b.Order_Number FROM booking b JOIN stations s ON b.StationID = s.StationID WHERE b.BookingID = ?");
            $ownerStmt->bind_param("i", $bookingID);
            $ownerStmt->execute();
            $owner = $ownerStmt->get_result()->fetch_assoc();

            if ($owner && (int) $owner['OwnerID'] > 0) {
                $title = "تقييم جديد";
                $body = "وصلك تقييم جديد على الطلب #" . ($owner['Order_Number'] ?: $bookingID);
                $type = "info";
                $notice = $conn->prepare("INSERT INTO notifications (user_id, title, body, type) VALUES (?, ?, ?, ?)");
                $notice->bind_param("isss", $owner['OwnerID'], $title, $body, $type);
                $notice->execute();
            }
        }

        echo json_encode(['status' => $ok ? 'success' : 'error']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'invalid_action'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $conn->prepare("SELECT b.*, s.Name AS station_name, s.Location AS station_address, s.Location_URL AS station_map_url,
        serv.Name AS service_name, u.UserName AS customer_name, u.CarName, u.PhoneNumber,
        d.UserName AS driver_name, d.PhoneNumber AS driver_phone, r.Rating, r.Comment, r.Owner_Reply
    FROM booking b
    JOIN stations s ON b.StationID = s.StationID
    JOIN services serv ON b.ServiceID = serv.ServiceID
    JOIN user u ON b.UserID = u.UserID
    LEFT JOIN user d ON d.UserID = b.DriverID
    LEFT JOIN reviews r ON r.BookingID = b.BookingID
    WHERE b.BookingID = ? AND b.UserID = ? LIMIT 1");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header("Location: booking-history.php");
    exit();
}

$bookingID = (int) $order['BookingID'];
$orderNumber = clevoBookingReference($order, $bookingID);
$status = (string) ($order['Status'] ?? 'Pending');
$canModify = clevoBookingCanBeChanged($status);
$isCompleted = $status === 'Completed';
$currentRating = (int) ($order['Rating'] ?? 0);
$paymentMethod = (string) ($order['Payment_Method'] ?? '');
$paymentLabelAr = clevoPaymentMethodLabel($paymentMethod, 'ar');
$paymentLabelEn = clevoPaymentMethodLabel($paymentMethod, 'en');
$bookingDate = (string) ($order['Booking_Date'] ?? '');
$bookingTime = substr((string) ($order['Booking_Time'] ?? ''), 0, 5);
$serviceLocation = (string) ($order['Service_Location'] ?? 'station');
$csrf_token = generate_csrf_token();

$statusLabels = [
    'Pending' => ['ar' => 'بانتظار موافقة المحطة', 'en' => 'Waiting for station approval', 'class' => 'status-pending'],
    'Accepted' => ['ar' => 'تم قبول الطلب', 'en' => 'Accepted', 'class' => 'status-accepted'],
    'Arrived' => ['ar' => 'وصل السائق', 'en' => 'Driver arrived', 'class' => 'status-active'],
    'In Progress' => ['ar' => 'قيد التنفيذ', 'en' => 'In progress', 'class' => 'status-active'],
    'Pickup' => ['ar' => 'قيد الاستلام', 'en' => 'Pickup', 'class' => 'status-active'],
    'Washing' => ['ar' => 'قيد الغسيل', 'en' => 'Washing', 'class' => 'status-active'],
    'Returning' => ['ar' => 'قيد الإرجاع', 'en' => 'Returning', 'class' => 'status-active'],
    'Rejected' => ['ar' => 'مرفوض', 'en' => 'Rejected', 'class' => 'status-danger'],
    'Cancelled' => ['ar' => 'ملغي', 'en' => 'Cancelled', 'class' => 'status-danger'],
    'Cancelled_Timeout' => ['ar' => 'ملغي لانتهاء المهلة', 'en' => 'Cancelled by timeout', 'class' => 'status-danger'],
    'Completed' => ['ar' => 'مكتمل', 'en' => 'Completed', 'class' => 'status-completed'],
];
$statusMeta = $statusLabels[$status] ?? ['ar' => $status, 'en' => $status, 'class' => 'status-neutral'];

$locationLabels = [
    'station' => ['ar' => 'في المحطة', 'en' => 'At station', 'icon' => 'fa-store'],
    'home' => ['ar' => 'في موقعي', 'en' => 'At my location', 'icon' => 'fa-location-dot'],
    'delivery' => ['ar' => 'استلام وتسليم', 'en' => 'Pickup and delivery', 'icon' => 'fa-truck-fast'],
];
$locationMeta = $locationLabels[$serviceLocation] ?? ['ar' => $serviceLocation, 'en' => $serviceLocation, 'icon' => 'fa-circle-info'];
$canPay = $status === 'Accepted' && $paymentMethod === '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title class="lang-node" data-ar="تفاصيل الطلب | Clevo" data-en="Order Details | Clevo">تفاصيل الطلب | Clevo</title>
    <link rel="stylesheet" id="bootstrap-css" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-body: #f4f7fb;
            --bg-panel: #ffffff;
            --bg-soft: #eef5ff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #dbe3ef;
            --accent: #0f8bdc;
            --accent-dark: #075985;
            --success: #16a34a;
            --warning: #d97706;
            --danger: #dc2626;
            --shadow: 0 18px 48px rgba(15, 23, 42, .08);
        }

        [data-theme="dark"] {
            --bg-body: #07111f;
            --bg-panel: #101b2d;
            --bg-soft: #17243a;
            --text-main: #f8fafc;
            --text-muted: #b7c4d7;
            --border-color: #2b3b55;
            --accent: #38bdf8;
            --accent-dark: #7dd3fc;
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #fb7185;
            --shadow: 0 18px 48px rgba(0, 0, 0, .24);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Cairo', sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
        }

        .page-shell {
            width: min(1120px, calc(100% - 28px));
            margin: 0 auto;
            padding: 22px 0 42px;
        }

        .topbar {
            display: grid;
            grid-template-columns: 44px minmax(0, 1fr) 44px;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .icon-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            border: 1px solid var(--border-color);
            background: var(--bg-panel);
            color: var(--text-main);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 8px 22px rgba(15, 23, 42, .08);
            transition: .2s ease;
        }

        .icon-btn:hover {
            color: var(--accent);
            transform: translateY(-1px);
        }

        .page-title {
            min-width: 0;
            text-align: center;
            font-size: 1.05rem;
            font-weight: 900;
            margin: 0;
            overflow-wrap: anywhere;
        }

        .order-hero,
        .panel {
            background: var(--bg-panel);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
            border-radius: 18px;
        }

        .order-hero {
            padding: 24px;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 22px;
            align-items: center;
            margin-bottom: 18px;
        }

        .eyebrow {
            color: var(--accent-dark);
            font-size: .78rem;
            font-weight: 900;
            letter-spacing: 0;
            margin-bottom: 8px;
        }

        .hero-title {
            font-size: clamp(1.45rem, 2.5vw, 2.25rem);
            font-weight: 900;
            line-height: 1.25;
            margin: 0 0 10px;
            overflow-wrap: anywhere;
        }

        .hero-subtitle {
            color: var(--text-muted);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .status-block {
            min-width: 250px;
            padding: 16px;
            border-radius: 16px;
            background: var(--bg-soft);
            border: 1px solid var(--border-color);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: .82rem;
            font-weight: 900;
            border: 1px solid transparent;
            white-space: normal;
        }

        .status-pending {
            background: rgba(217, 119, 6, .13);
            color: var(--warning);
            border-color: rgba(217, 119, 6, .26);
        }

        .status-accepted,
        .status-active {
            background: rgba(14, 165, 233, .14);
            color: var(--accent-dark);
            border-color: rgba(14, 165, 233, .28);
        }

        .status-completed {
            background: rgba(22, 163, 74, .13);
            color: var(--success);
            border-color: rgba(22, 163, 74, .26);
        }

        .status-danger {
            background: rgba(220, 38, 38, .12);
            color: var(--danger);
            border-color: rgba(220, 38, 38, .24);
        }

        .status-neutral {
            background: rgba(100, 116, 139, .14);
            color: var(--text-muted);
            border-color: rgba(100, 116, 139, .2);
        }

        .status-meta {
            color: var(--text-muted);
            font-size: .88rem;
            margin-top: 12px;
            display: flex;
            gap: 8px;
            align-items: center;
            overflow-wrap: anywhere;
        }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(300px, .8fr);
            gap: 18px;
            align-items: start;
        }

        .stack {
            display: grid;
            gap: 18px;
        }

        .panel {
            padding: 20px;
        }

        .panel-title {
            font-size: 1rem;
            font-weight: 900;
            margin: 0 0 16px;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .detail-list {
            display: grid;
            gap: 0;
        }

        .detail-row {
            display: grid;
            grid-template-columns: minmax(120px, .7fr) minmax(0, 1fr);
            gap: 16px;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .detail-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .detail-label {
            color: var(--text-muted);
            font-size: .86rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            font-weight: 900;
            text-align: end;
            overflow-wrap: anywhere;
        }

        html[dir="ltr"] .detail-value {
            text-align: right;
        }

        .total-value {
            color: var(--success);
            font-size: 1.25rem;
        }

        .action-note {
            color: var(--text-muted);
            font-size: .88rem;
            line-height: 1.8;
            margin-bottom: 14px;
        }

        .action-grid {
            display: grid;
            gap: 10px;
        }

        .btn-main,
        .btn-soft,
        .btn-danger-soft {
            width: 100%;
            min-height: 46px;
            border-radius: 999px;
            border: 1px solid transparent;
            padding: 11px 16px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            transition: .2s ease;
        }

        .btn-main {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 10px 22px rgba(14, 139, 220, .24);
        }

        .btn-soft {
            background: var(--bg-soft);
            color: var(--text-main);
            border-color: var(--border-color);
        }

        .btn-danger-soft {
            background: rgba(220, 38, 38, .1);
            color: var(--danger);
            border-color: rgba(220, 38, 38, .22);
        }

        .btn-main:hover,
        .btn-soft:hover,
        .btn-danger-soft:hover {
            transform: translateY(-1px);
            color: inherit;
        }

        .btn-main:hover {
            color: #fff;
        }

        .message-box {
            border-radius: 16px;
            background: var(--bg-soft);
            border: 1px solid var(--border-color);
            padding: 14px;
            color: var(--text-muted);
            line-height: 1.8;
            margin: 0;
        }

        .rating-stars {
            direction: ltr;
            display: inline-flex;
            gap: 7px;
            color: #94a3b8;
            font-size: 1.9rem;
        }

        .rating-stars i {
            cursor: pointer;
            transition: .15s ease;
        }

        .rating-stars i.active {
            color: #f59e0b;
        }

        .feedback-box,
        .form-control {
            width: 100%;
            border-radius: 14px;
            border: 1px solid var(--border-color);
            background: var(--bg-body);
            color: var(--text-main);
            padding: 12px 14px;
        }

        .feedback-box {
            min-height: 110px;
            resize: vertical;
            margin-top: 12px;
        }

        .feedback-box:focus,
        .form-control:focus {
            border-color: var(--accent);
            background: var(--bg-body);
            color: var(--text-main);
            box-shadow: 0 0 0 .2rem rgba(14, 139, 220, .12);
            outline: 0;
        }

        .modal-content {
            background: var(--bg-panel);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }

        .modal-muted {
            color: var(--text-muted);
        }

        .divider {
            height: 1px;
            background: var(--border-color);
            margin: 14px 0;
        }

        @media (max-width: 820px) {
            .order-hero,
            .content-grid {
                grid-template-columns: 1fr;
            }

            .status-block {
                min-width: 0;
            }
        }

        @media (max-width: 540px) {
            .page-shell {
                width: min(100% - 20px, 1120px);
                padding-top: 14px;
            }

            .order-hero,
            .panel {
                border-radius: 16px;
                padding: 16px;
            }

            .detail-row {
                grid-template-columns: 1fr;
                gap: 6px;
            }

            .detail-value,
            html[dir="ltr"] .detail-value {
                text-align: start;
            }

            .hero-title {
                font-size: 1.35rem;
            }
        }
    </style>
</head>

<body>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>

    <main class="page-shell">
        <div class="topbar">
            <a href="booking-history.php" class="icon-btn" aria-label="Back">
                <i id="backIcon" class="fas fa-arrow-right"></i>
            </a>
            <h1 class="page-title">
                <span class="lang-node" data-ar="تفاصيل الطلب" data-en="Order Details">تفاصيل الطلب</span>
                #<?= e($orderNumber) ?>
            </h1>
            <span></span>
        </div>

        <section class="order-hero">
            <div>
                <div class="eyebrow">
                    <span class="lang-node" data-ar="طلب حجز" data-en="Booking order">طلب حجز</span>
                    #<?= e($orderNumber) ?>
                </div>
                <h2 class="hero-title"><?= e($order['station_name'] ?? '-') ?></h2>
                <p class="hero-subtitle">
                    <span><i class="fas fa-soap"></i> <?= e($order['service_name'] ?? '-') ?></span>
                    <span><i class="fas <?= e($locationMeta['icon']) ?>"></i> <span data-location-label data-ar="<?= e($locationMeta['ar']) ?>" data-en="<?= e($locationMeta['en']) ?>"><?= e($locationMeta['ar']) ?></span></span>
                </p>
            </div>
            <div class="status-block">
                <span class="status-pill <?= e($statusMeta['class']) ?>" data-status-label data-ar="<?= e($statusMeta['ar']) ?>" data-en="<?= e($statusMeta['en']) ?>">
                    <i class="fas fa-circle-dot"></i>
                    <?= e($statusMeta['ar']) ?>
                </span>
                <div class="status-meta">
                    <i class="far fa-calendar-alt"></i>
                    <span><?= e($bookingDate ?: '-') ?> | <?= e($bookingTime ?: '-') ?></span>
                </div>
            </div>
        </section>

        <div class="content-grid">
            <div class="stack">
                <section class="panel">
                    <h3 class="panel-title">
                        <i class="fas fa-clipboard-list text-primary"></i>
                        <span class="lang-node" data-ar="ملخص الحجز" data-en="Booking Summary">ملخص الحجز</span>
                    </h3>
                    <div class="detail-list">
                        <div class="detail-row">
                            <span class="detail-label"><i class="fas fa-car"></i><span class="lang-node" data-ar="نوع السيارة" data-en="Car model">نوع السيارة</span></span>
                            <span class="detail-value"><?= e($order['CarName'] ?: '-') ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label"><i class="fas fa-credit-card"></i><span class="lang-node" data-ar="طريقة الدفع" data-en="Payment method">طريقة الدفع</span></span>
                            <span class="detail-value" data-payment-label data-ar="<?= e($paymentLabelAr) ?>" data-en="<?= e($paymentLabelEn) ?>"><?= e($paymentLabelAr) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label"><i class="fas fa-receipt"></i><span class="lang-node" data-ar="الإجمالي" data-en="Total">الإجمالي</span></span>
                            <span class="detail-value total-value">
                                <?php if ($paymentMethod === 'plan'): ?>
                                    <span class="lang-node" data-ar="مغطى من الباقة" data-en="Covered by plan">مغطى من الباقة</span>
                                <?php else: ?>
                                    <?= number_format((float) ($order['Total_Price'] ?? 0), 2) ?> JOD
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (!empty($order['station_address'])): ?>
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-map-marker-alt"></i><span class="lang-node" data-ar="منطقة المحطة" data-en="Station area">منطقة المحطة</span></span>
                                <span class="detail-value"><?= e($order['station_address']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($order['Address_Details'])): ?>
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-location-crosshairs"></i><span class="lang-node" data-ar="ملاحظات الموقع" data-en="Location notes">ملاحظات الموقع</span></span>
                                <span class="detail-value"><?= e($order['Address_Details']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($order['driver_name'])): ?>
                            <div class="detail-row">
                                <span class="detail-label"><i class="fas fa-id-card"></i><span class="lang-node" data-ar="السائق" data-en="Driver">السائق</span></span>
                                <span class="detail-value"><?= e($order['driver_name']) ?><?= !empty($order['driver_phone']) ? ' - ' . e($order['driver_phone']) : '' ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <?php if ($canPay): ?>
                    <section class="panel">
                        <h3 class="panel-title">
                            <i class="fas fa-wallet text-primary"></i>
                            <span class="lang-node" data-ar="الدفع مطلوب" data-en="Payment Required">الدفع مطلوب</span>
                        </h3>
                        <p class="action-note mb-3 lang-node"
                           data-ar="<?= e($serviceLocation === 'station' ? 'خدمة المحطة تقبل كاش، فيزا، محفظة، أو نقاط ولاء.' : 'هذه الخدمة تقبل فيزا، محفظة، أو نقاط ولاء فقط.') ?>"
                           data-en="<?= e($serviceLocation === 'station' ? 'Station service accepts Cash, Visa, Wallet, or Loyalty Points.' : 'This service accepts Visa, Wallet, or Loyalty Points only.') ?>">
                            <?= e($serviceLocation === 'station' ? 'خدمة المحطة تقبل كاش، فيزا، محفظة، أو نقاط ولاء.' : 'هذه الخدمة تقبل فيزا، محفظة، أو نقاط ولاء فقط.') ?>
                        </p>
                        <a href="../includes/payment-method.php?id=<?= (int) $bookingID ?>" class="btn-main">
                            <span class="lang-node" data-ar="اختيار طريقة الدفع" data-en="Select Payment Method">اختيار طريقة الدفع</span>
                            <i class="fas fa-arrow-left"></i>
                        </a>
                    </section>
                <?php endif; ?>

                <?php if (!empty($order['Owner_Reply'])): ?>
                    <section class="panel">
                        <h3 class="panel-title">
                            <i class="fas fa-reply text-primary"></i>
                            <span class="lang-node" data-ar="رد صاحب المحطة" data-en="Station Owner Reply">رد صاحب المحطة</span>
                        </h3>
                        <p class="message-box"><?= nl2br(e($order['Owner_Reply'])) ?></p>
                    </section>
                <?php endif; ?>

                <?php if ($isCompleted): ?>
                    <section class="panel">
                        <h3 class="panel-title">
                            <i class="fas fa-star text-warning"></i>
                            <span class="lang-node" data-ar="تقييم الخدمة" data-en="Service Rating">تقييم الخدمة</span>
                        </h3>
                        <div class="rating-stars" id="ratingStars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star <?= $currentRating >= $i ? 'active' : '' ?>" data-value="<?= $i ?>" onclick="setRating(<?= $i ?>)"></i>
                            <?php endfor; ?>
                        </div>
                        <textarea id="feedbackInput" class="feedback-box" data-placeholder-ar="اكتب ملاحظاتك هنا..." data-placeholder-en="Write your comments here..." placeholder="اكتب ملاحظاتك هنا..."><?= e($order['Comment'] ?? '') ?></textarea>
                        <button onclick="submitRating(this)" class="btn-main mt-3">
                            <span class="lang-node" data-ar="حفظ التقييم" data-en="Save Rating">حفظ التقييم</span>
                            <i class="fas fa-check"></i>
                        </button>
                    </section>
                <?php endif; ?>
            </div>

            <aside class="stack">
                <section class="panel">
                    <h3 class="panel-title">
                        <i class="fas fa-pen-to-square text-primary"></i>
                        <span class="lang-node" data-ar="إدارة الحجز" data-en="Manage Booking">إدارة الحجز</span>
                    </h3>
                    <?php if ($canModify): ?>
                        <p class="action-note lang-node" data-ar="تقدر تعدل الموعد أو تلغي الحجز طالما المحطة لسا ما وافقت على الطلب." data-en="You can reschedule or cancel while the station has not approved the request yet.">تقدر تعدل الموعد أو تلغي الحجز طالما المحطة لسا ما وافقت على الطلب.</p>
                        <div class="action-grid" id="bookingActions">
                            <button type="button" class="btn-soft" onclick="openRescheduleModal()">
                                <i class="fas fa-calendar-days"></i>
                                <span class="lang-node" data-ar="تعديل الموعد" data-en="Reschedule">تعديل الموعد</span>
                            </button>
                            <button type="button" class="btn-danger-soft" onclick="openCancelModal()">
                                <i class="fas fa-ban"></i>
                                <span class="lang-node" data-ar="إلغاء الحجز" data-en="Cancel Booking">إلغاء الحجز</span>
                            </button>
                        </div>
                    <?php else: ?>
                        <p class="message-box lang-node" data-ar="التعديل والإلغاء متاحان فقط قبل موافقة المحطة على الطلب." data-en="Rescheduling and cancellation are available only before the station approves the request.">التعديل والإلغاء متاحان فقط قبل موافقة المحطة على الطلب.</p>
                    <?php endif; ?>
                </section>

                <section class="panel">
                    <h3 class="panel-title">
                        <i class="fas fa-user text-primary"></i>
                        <span class="lang-node" data-ar="معلومات التواصل" data-en="Contact Information">معلومات التواصل</span>
                    </h3>
                    <div class="detail-list">
                        <div class="detail-row">
                            <span class="detail-label"><i class="fas fa-user-circle"></i><span class="lang-node" data-ar="العميل" data-en="Customer">العميل</span></span>
                            <span class="detail-value"><?= e($order['customer_name'] ?: ($_SESSION['userName'] ?? '-')) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label"><i class="fas fa-phone"></i><span class="lang-node" data-ar="الهاتف" data-en="Phone">الهاتف</span></span>
                            <span class="detail-value"><?= e($order['PhoneNumber'] ?: '-') ?></span>
                        </div>
                    </div>
                    <div class="divider"></div>
                    <a href="support.php?subject=<?= rawurlencode('مشكلة في الطلب #' . $orderNumber) ?>" class="btn-soft">
                        <i class="fas fa-headset"></i>
                        <span class="lang-node" data-ar="طلب مساعدة" data-en="Get Support">طلب مساعدة</span>
                    </a>
                </section>

                <section class="panel">
                    <h3 class="panel-title">
                        <i class="fas fa-clock-rotate-left text-primary"></i>
                        <span class="lang-node" data-ar="خيارات سريعة" data-en="Quick Actions">خيارات سريعة</span>
                    </h3>
                    <div class="action-grid">
                        <a href="booking-history.php" class="btn-soft">
                            <i class="fas fa-list"></i>
                            <span class="lang-node" data-ar="سجل الحجوزات" data-en="Booking History">سجل الحجوزات</span>
                        </a>
                        <?php if ($serviceLocation === 'delivery' && !empty($order['DriverID']) && in_array($status, ['Accepted', 'Arrived', 'In Progress', 'Pickup', 'Washing', 'Returning'], true)): ?>
                            <a href="live-tracking.php?id=<?= (int) $bookingID ?>" class="btn-main">
                                <i class="fas fa-map-location-dot"></i>
                                <span class="lang-node" data-ar="تتبع الآن" data-en="Track Now">تتبع الآن</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </section>
            </aside>
        </div>
    </main>

    <div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4">
                <h5 class="fw-bold text-center mb-3 lang-node" data-ar="تعديل موعد الحجز" data-en="Reschedule Booking">تعديل موعد الحجز</h5>
                <div class="mb-3">
                    <label class="small fw-bold mb-1 lang-node" data-ar="التاريخ" data-en="Date">التاريخ</label>
                    <input type="date" id="newBookingDate" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= e($bookingDate) ?>">
                </div>
                <div class="mb-4">
                    <label class="small fw-bold mb-1 lang-node" data-ar="الوقت" data-en="Time">الوقت</label>
                    <input type="time" id="newBookingTime" class="form-control" value="<?= e($bookingTime) ?>">
                </div>
                <button type="button" class="btn-main" onclick="submitReschedule(this)">
                    <span class="lang-node" data-ar="حفظ الموعد" data-en="Save Appointment">حفظ الموعد</span>
                    <i class="fas fa-check"></i>
                </button>
                <button type="button" class="btn-soft mt-2" data-bs-dismiss="modal">
                    <span class="lang-node" data-ar="إغلاق" data-en="Close">إغلاق</span>
                </button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <div class="fs-1 text-danger mb-3"><i class="fas fa-ban"></i></div>
                <h5 class="fw-bold mb-2 lang-node" data-ar="إلغاء الحجز" data-en="Cancel Booking">إلغاء الحجز</h5>
                <p class="modal-muted small mb-4 lang-node" data-ar="هل أنت متأكد من إلغاء هذا الحجز؟ لا يمكن التراجع بعد التأكيد." data-en="Are you sure you want to cancel this booking? This cannot be undone.">هل أنت متأكد من إلغاء هذا الحجز؟ لا يمكن التراجع بعد التأكيد.</p>
                <div class="d-grid gap-2">
                    <button type="button" id="confirmCancelBtn" class="btn-danger-soft">
                        <span class="lang-node" data-ar="نعم، إلغاء" data-en="Yes, cancel">نعم، إلغاء</span>
                    </button>
                    <button type="button" class="btn-soft" data-bs-dismiss="modal">
                        <span class="lang-node" data-ar="رجوع" data-en="Back">رجوع</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <div id="statusIcon" class="fs-1 mb-3"></div>
                <h5 id="statusTitle" class="fw-bold mb-2"></h5>
                <p id="statusBody" class="modal-muted small mb-0"></p>
                <button class="btn-main mt-3" data-bs-dismiss="modal">
                    <span class="lang-node" data-ar="موافق" data-en="OK">موافق</span>
                </button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentRating = <?= (int) $currentRating ?>;
        const csrfToken = <?= json_encode($csrf_token) ?>;
        const bookingID = <?= (int) $bookingID ?>;
        const lang = localStorage.getItem('app_lang') || 'ar';
        const theme = localStorage.getItem('global_theme') || 'light';
        const rescheduleModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
        const cancelModal = new bootstrap.Modal(document.getElementById('cancelModal'));
        const statusModalEl = document.getElementById('statusModal');
        const statusModal = new bootstrap.Modal(statusModalEl);

        const messages = {
            ar: {
                loading: 'جاري المعالجة...',
                success: 'تم بنجاح',
                ratingSaved: 'تم حفظ تقييمك بنجاح.',
                cancelSuccess: 'تم إلغاء الحجز بنجاح.',
                rescheduleSuccess: 'تم حفظ الموعد الجديد وإرسال الطلب للمحطة للمراجعة.',
                warning: 'تنبيه',
                selectStars: 'يرجى اختيار عدد النجوم.',
                error: 'تعذر تنفيذ الطلب',
                failed: 'حاول مرة أخرى بعد قليل.',
                booking_locked: 'التعديل والإلغاء متاحان فقط قبل موافقة المحطة على الطلب.',
                invalid_datetime: 'اختر تاريخا ووقتا صحيحين.'
            },
            en: {
                loading: 'Processing...',
                success: 'Success',
                ratingSaved: 'Your rating was saved successfully.',
                cancelSuccess: 'Booking cancelled successfully.',
                rescheduleSuccess: 'The new appointment was saved and sent to the station for review.',
                warning: 'Warning',
                selectStars: 'Please select a rating star.',
                error: 'Action failed',
                failed: 'Please try again shortly.',
                booking_locked: 'Changes are available only before the station approves the request.',
                invalid_datetime: 'Choose a valid date and time.'
            }
        };

        function t(key) {
            return (messages[lang] && messages[lang][key]) || key;
        }

        function applyLanguage() {
            const isRtl = lang === 'ar';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
            document.getElementById('bootstrap-css').href = isRtl ?
                'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css' :
                'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
            document.getElementById('backIcon').className = isRtl ? 'fas fa-arrow-right' : 'fas fa-arrow-left';

            document.querySelectorAll('.lang-node').forEach(node => {
                const value = node.getAttribute('data-' + lang);
                if (!value) return;
                if (node.tagName === 'TITLE') {
                    document.title = value;
                } else {
                    node.innerText = value;
                }
            });

            document.querySelectorAll('[data-status-label], [data-payment-label], [data-location-label]').forEach(node => {
                const value = node.getAttribute('data-' + lang);
                if (value) node.innerText = value;
            });

            document.querySelectorAll('[data-placeholder-ar]').forEach(node => {
                const value = node.getAttribute('data-placeholder-' + lang);
                if (value) node.setAttribute('placeholder', value);
            });
        }

        function showStatus(type, title, body, afterClose) {
            document.getElementById('statusIcon').innerHTML = type === 'success' ?
                '<i class="fas fa-check-circle text-success"></i>' :
                '<i class="fas fa-circle-exclamation text-warning"></i>';
            document.getElementById('statusTitle').innerText = title;
            document.getElementById('statusBody').innerText = body;
            if (afterClose) {
                statusModalEl.addEventListener('hidden.bs.modal', afterClose, { once: true });
            }
            statusModal.show();
        }

        function withLoading(btn, work) {
            const original = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<span class="spinner-border spinner-border-sm"></span>${t('loading')}`;
            }
            return work().finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = original;
                }
            });
        }

        function postBookingAction(action, extra = {}) {
            const params = new URLSearchParams();
            params.append('action', action);
            params.append('booking_id', bookingID);
            params.append('csrf_token', csrfToken);
            Object.entries(extra).forEach(([key, value]) => params.append(key, value));

            return fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(res => res.json());
        }

        function errorBody(message) {
            if (message === 'booking_locked') return t('booking_locked');
            if (message === 'invalid_datetime') return t('invalid_datetime');
            return t('failed');
        }

        function openCancelModal() {
            cancelModal.show();
        }

        function openRescheduleModal() {
            rescheduleModal.show();
        }

        function submitReschedule(btn) {
            const booking_date = document.getElementById('newBookingDate').value;
            const booking_time = document.getElementById('newBookingTime').value;

            withLoading(btn, () => postBookingAction('reschedule_booking', { booking_date, booking_time })
                .then(data => {
                    if (data.status === 'success') {
                        rescheduleModal.hide();
                        showStatus('success', t('success'), t('rescheduleSuccess'), () => location.reload());
                    } else {
                        showStatus('error', t('error'), errorBody(data.message));
                    }
                })
                .catch(() => showStatus('error', t('error'), t('failed'))));
        }

        function setRating(value) {
            currentRating = value;
            document.querySelectorAll('#ratingStars i').forEach((star, index) => {
                star.classList.toggle('active', index < value);
            });
        }

        function submitRating(btn) {
            if (currentRating <= 0) {
                showStatus('warning', t('warning'), t('selectStars'));
                return;
            }
            const params = new URLSearchParams();
            params.append('action', 'submit_rating');
            params.append('rating', currentRating);
            params.append('comment', document.getElementById('feedbackInput').value);
            params.append('csrf_token', csrfToken);

            withLoading(btn, () => fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(res => res.json()).then(data => {
                if (data.status === 'success') {
                    showStatus('success', t('success'), t('ratingSaved'), () => location.reload());
                } else {
                    showStatus('warning', t('error'), t('failed'));
                }
            }).catch(() => showStatus('warning', t('error'), t('failed'))));
        }

        document.addEventListener('DOMContentLoaded', () => {
            applyLanguage();
            document.getElementById('confirmCancelBtn').addEventListener('click', function() {
                const btn = this;
                withLoading(btn, () => postBookingAction('cancel_booking')
                    .then(data => {
                        cancelModal.hide();
                        if (data.status === 'success') {
                            showStatus('success', t('success'), t('cancelSuccess'), () => location.href = 'booking-history.php');
                        } else {
                            showStatus('error', t('error'), errorBody(data.message));
                        }
                    })
                    .catch(() => {
                        cancelModal.hide();
                        showStatus('error', t('error'), t('failed'));
                    }));
            });
        });
    </script>
    <script src="../assets/lamea.js"></script>
</body>

</html>
