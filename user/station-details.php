<?php
session_start();
include_once(__DIR__ . "/../includes/config.php");
include_once(__DIR__ . "/../includes/booking-helpers.php");
include_once(__DIR__ . "/../includes/app-helpers.php");

$userID = clevo_require_role(['customer', 'user'], '../general/login.php');
cancelExpiredBookings($conn, $userID);
clevoEnsurePlanColumns($conn);
clevoEnsureBookingServicesTable($conn);
clevoEnsureServiceLanguageColumns($conn);

$stationID = isset($_GET['id']) ? intval($_GET['id']) : 0;
$query = "SELECT * FROM `stations` WHERE StationID = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $stationID);
$stmt->execute();
$station = $stmt->get_result()->fetch_assoc();

if (!$station) {
    header("Location: search-stations.php");
    exit();
}

if (($station['Status'] ?? 'Closed') !== 'Active') {
    header("Location: search-stations.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_booking') {
    header('Content-Type: application/json; charset=utf-8');
    clevo_require_csrf_token(true);

    $serviceIDs = json_decode($_POST['service_ids'] ?? '[]', true);
    if (!is_array($serviceIDs) || empty($serviceIDs)) {
        $singleServiceID = (int) ($_POST['service_id'] ?? 0);
        $serviceIDs = $singleServiceID > 0 ? [$singleServiceID] : [];
    }
    $serviceIDs = array_values(array_unique(array_filter(array_map('intval', $serviceIDs))));
    $serviceID = $serviceIDs[0] ?? 0;
    $serviceLocation = $_POST['service_location'] ?? '';
    $bookingDate = $_POST['booking_date'] ?? '';
    $bookingTime = $_POST['booking_time'] ?? '';
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $usePlan = ($_POST['use_plan'] ?? '') === '1';
    $latitude = ($_POST['latitude'] ?? '') !== '' ? (float) $_POST['latitude'] : null;
    $longitude = ($_POST['longitude'] ?? '') !== '' ? (float) $_POST['longitude'] : null;
    $addressDetails = trim($_POST['address_details'] ?? '');
    $allowedLocations = ['station', 'home', 'delivery'];

    if (!$serviceID || !in_array($serviceLocation, $allowedLocations, true) || !$bookingDate || !$bookingTime) {
        echo json_encode(['status' => 'error', 'message' => 'missing']);
        exit;
    }
    if ($serviceLocation !== 'station' && (!$latitude || !$longitude)) {
        echo json_encode(['status' => 'error', 'message' => 'location_required']);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($serviceIDs), '?'));
    $types = str_repeat('i', count($serviceIDs)) . 'i';
    $serviceStmt = $conn->prepare("SELECT ServiceID, Name, Name_ar, Name_en, Price, is_station, is_home, is_pickup FROM services WHERE ServiceID IN ($placeholders) AND StationID = ?");
    $params = array_merge($serviceIDs, [$stationID]);
    $serviceStmt->bind_param($types, ...$params);
    $serviceStmt->execute();
    $servicesResult = $serviceStmt->get_result();
    $selectedDbServices = [];
    while ($serviceRow = $servicesResult->fetch_assoc()) {
        $selectedDbServices[(int) $serviceRow['ServiceID']] = $serviceRow;
    }

    $isSupported = count($selectedDbServices) === count($serviceIDs);
    foreach ($selectedDbServices as $serviceRow) {
        $isSupported = $isSupported
            && (($serviceLocation === 'station' && (int) $serviceRow['is_station'] === 1)
                || ($serviceLocation === 'home' && (int) $serviceRow['is_home'] === 1)
                || ($serviceLocation === 'delivery' && (int) $serviceRow['is_pickup'] === 1));
    }

    if (!$isSupported) {
        echo json_encode(['status' => 'error', 'message' => 'service_unavailable']);
        exit;
    }

    $orderNumber = 'C' . date('ymdHis') . random_int(10, 99);
    $orderedServices = [];
    foreach ($serviceIDs as $id) {
        if (isset($selectedDbServices[$id])) {
            $orderedServices[] = $selectedDbServices[$id];
        }
    }
    $serviceNamesAr = array_map(fn($item) => $item['Name_ar'] ?: $item['Name'], $orderedServices);
    $serviceNamesEn = array_map(fn($item) => $item['Name_en'] ?: $item['Name'], $orderedServices);
    $serviceNames = $serviceNamesEn;
    $totalPrice = array_reduce($orderedServices, fn($sum, $item) => $sum + (float) $item['Price'], 0);
    $adminCommission = clevoCalculateAdminCommission($totalPrice);
    $servicesNote = "الخدمات: " . implode(' + ', $serviceNamesAr);
    $addressForDb = trim($addressDetails) !== '' ? $addressDetails . "\n" . $servicesNote : $servicesNote;

    $userPlanID = null;
    $planWashesUsed = 0;
    $conn->begin_transaction();
    try {
        if ($usePlan) {
            $planUse = clevoUsePlanForBooking($conn, $userID);
            if (!$planUse) {
                throw new RuntimeException('plan_unavailable');
            }
            $paymentMethod = 'plan';
            $userPlanID = (int) $planUse['id'];
            $planWashesUsed = 1;
        }

        $insert = $conn->prepare("INSERT INTO booking
            (Order_Number, UserID, StationID, ServiceID, Service_Location, Latitude, Longitude, Address_Details, Booking_Date, Booking_Time, Status, Total_Price, Admin_Commission, Payment_Method, UserPlanID, Plan_Washes_Used)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?, ?)");
        $insert->bind_param(
            "siiisddsssddsii",
            $orderNumber,
            $userID,
            $stationID,
            $serviceID,
            $serviceLocation,
            $latitude,
            $longitude,
            $addressForDb,
            $bookingDate,
            $bookingTime,
            $totalPrice,
            $adminCommission,
            $paymentMethod,
            $userPlanID,
            $planWashesUsed
        );

        if (!$insert->execute()) {
            throw new RuntimeException('db_error');
        }

        $bookingID = $conn->insert_id;
        clevoSaveBookingServices($conn, $bookingID, $orderedServices);
        $ownerID = (int) $station['UserID'];
        if ($ownerID > 0) {
            $planNoteAr = $paymentMethod === 'plan' ? " الدفع من باقة العميل." : "";
            $planNoteEn = $paymentMethod === 'plan' ? " Payment is from the customer's plan." : "";
            $bodyAr = "وصلك طلب #$orderNumber لخدمات " . implode(' + ', $serviceNamesAr) . " بتاريخ $bookingDate الساعة $bookingTime." . $planNoteAr;
            $bodyEn = "You received order #$orderNumber for services " . implode(' + ', $serviceNamesEn) . " on $bookingDate at $bookingTime." . $planNoteEn;
            clevoCreateNotification($conn, $ownerID, "طلب جديد", $bodyAr, "info", 'incoming-orders.php', "New order", $bodyEn);
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage() === 'plan_unavailable' ? 'plan_unavailable' : 'db_error']);
        exit;
    }

    echo json_encode(['status' => 'success', 'booking_id' => $bookingID, 'order_number' => $orderNumber, 'payment_method' => $paymentMethod]);
    exit;
}


$serv_query = "SELECT * FROM `services` WHERE StationID = ?";
$stmt_s = $conn->prepare($serv_query);
$stmt_s->bind_param("i", $stationID);
$stmt_s->execute();
$services_res = $stmt_s->get_result();
$all_services = [];
while ($row = $services_res->fetch_assoc()) {
    $all_services[] = $row;
}

$hasStationServices = (int)$station['Has_At_Station'] === 1;
$hasHomeServices = (int)$station['Has_Home_Service'] === 1;
$hasDeliveryServices = (int)$station['Has_Delivery'] === 1;
foreach ($all_services as $serviceRow) {
    $hasStationServices = $hasStationServices || (int)$serviceRow['is_station'] === 1;
    $hasHomeServices = $hasHomeServices || (int)$serviceRow['is_home'] === 1;
    $hasDeliveryServices = $hasDeliveryServices || (int)$serviceRow['is_pickup'] === 1;
}
$activePlan = clevoGetActiveUserPlan($conn, $userID);
$activePlanPayload = null;
if ($activePlan) {
    $activePlanPayload = [
        'id' => (int) $activePlan['id'],
        'name_ar' => $activePlan['name_ar'] ?? '',
        'name_en' => $activePlan['name_en'] ?? '',
        'remaining_washes' => (int) $activePlan['remaining_washes'],
        'features_ar' => clevoPlanFeatures($activePlan, 'ar'),
        'features_en' => clevoPlanFeatures($activePlan, 'en'),
    ];
}
$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="st_details_title">تفاصيل المحطة | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.8);
            --text: #0369a1;
            --accent: #0ea5e9;
            --border: rgba(0, 0, 0, 0.1);
        }

        [data-theme="dark"] {
            --main-bg: #020617;
            --glass: rgba(30, 41, 59, 0.9);
            --text: #f8fafc;
            --accent: #38bdf8;
            --border: rgba(255, 255, 255, 0.2);
        }

        body {
            font-family: 'Cairo', sans-serif;
            background: var(--main-bg);
            color: var(--text);
            min-height: 100vh;
            padding-bottom: 180px;
            margin: 0;
            transition: 0.3s ease;
        }

        .station-hero {
            height: 220px;
            background: linear-gradient(to bottom, rgba(0, 0, 0, 0.1), rgba(0, 0, 0, 0.7)), url('<?= htmlspecialchars(clevoStationImageUrl($station['Image'])) ?>');
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: flex-end;
            padding: 25px;
            border-radius: 0 0 40px 40px;
            position: relative;
        }

        .back-circle-btn {
            background: var(--glass);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--accent);
            backdrop-filter: blur(10px);
            text-decoration: none;
            position: absolute;
            top: 20px;
            z-index: 10;
            border: 1px solid var(--border);
        }

        [dir="rtl"] .back-circle-btn {
            right: 20px;
        }

        [dir="ltr"] .back-circle-btn {
            left: 20px;
        }

        .item-card {
            background: var(--glass);
            border-radius: 20px;
            padding: 18px;
            margin-bottom: 15px;
            border: 1px solid var(--border);
            backdrop-filter: blur(15px);
            transition: 0.3s;
            cursor: pointer;
            color: var(--text) !important;
        }

        .item-card small {
            color: var(--text);
            opacity: 0.8;
            font-weight: 600;
        }

        .selected {
            border: 2px solid var(--accent) !important;
            background: rgba(56, 189, 248, 0.15) !important;
            box-shadow: 0 5px 15px rgba(56, 189, 248, 0.2);
        }

        .order-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--glass);
            padding: 25px;
            z-index: 1000;
            backdrop-filter: blur(25px);
            border-radius: 35px 35px 0 0;
            border-top: 1px solid var(--border);
            box-shadow: 0 -10px 30px rgba(0, 0, 0, 0.1);
        }

        .btn-book {
            background: var(--accent);
            border: none;
            color: white !important;
            padding: 16px;
            border-radius: 50px;
            width: 100%;
            font-weight: 900;
            font-size: 1.1rem;
        }

        input {
            background: var(--glass) !important;
            border: 1px solid var(--border) !important;
            color: var(--text) !important;
            padding: 12px;
            border-radius: 15px;
            width: 100%;
            font-weight: 700;
        }

        .modal-content {
            border-radius: 30px;
            background-color: var(--glass) !important;
            color: var(--text) !important;
            border: 1px solid var(--accent);
            backdrop-filter: blur(20px);
        }

        .extra-panel {
            display: none;
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 16px;
            margin-bottom: 18px;
        }

        textarea {
            background: var(--glass) !important;
            border: 1px solid var(--border) !important;
            color: var(--text) !important;
            border-radius: 15px;
            width: 100%;
            padding: 12px;
            font-weight: 700;
            min-height: 74px;
        }

        .location-map-card {
            display: none;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.16);
            margin-bottom: 12px;
        }

        #serviceLocationMap {
            width: 100%;
            height: 170px;
            min-height: 170px;
            z-index: 1;
        }

        .map-confirm-row {
            padding: 10px 12px 12px;
            background: var(--glass);
        }

        .map-confirm-row input {
            padding: 10px 12px;
            border-radius: 13px;
            font-size: .9rem;
        }

        .map-helper {
            color: var(--text);
            opacity: .78;
            font-size: .75rem;
            font-weight: 700;
            margin-top: 6px;
        }

        .google-map-link {
            display: none;
            width: fit-content;
            margin-top: 8px;
            color: var(--accent);
            font-size: .78rem;
            font-weight: 900;
            text-decoration: none;
        }

        .plan-booking-panel {
            background: var(--glass);
            border: 1px solid var(--border);
            border-radius: 22px;
            padding: 16px;
            margin-bottom: 18px;
            backdrop-filter: blur(15px);
        }

        .plan-booking-car {
            width: 110px;
            max-width: 34vw;
            filter: drop-shadow(0 12px 16px rgba(14, 165, 233, 0.25));
        }

        .plan-feature-rail {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 4px 0 2px;
            scrollbar-width: none;
        }

        .plan-feature-rail::-webkit-scrollbar {
            display: none;
        }

        .plan-feature-chip {
            flex: 0 0 auto;
            border-radius: 999px;
            padding: 7px 11px;
            background: rgba(14, 165, 233, 0.12);
            border: 1px solid var(--border);
            font-size: .76rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .plan-switch-row {
            background: rgba(14, 165, 233, 0.10);
            border-radius: 16px;
            padding: 10px 12px;
            border: 1px solid var(--border);
        }

        .form-switch .form-check-input {
            width: 4rem !important;
            height: 2rem !important;
            border: none !important;
            cursor: pointer;

            background-image: none !important;
            background-color: #93c5fd !important;
            border-radius: 999px !important;
            position: relative;
        }

        .form-switch .form-check-input::before {
            content: "";
            position: absolute;
            width: 1.4rem;
            height: 1.4rem;
            background: #fff;
            border-radius: 50%;
            top: 0.3rem;
            left: 0.3rem;
            transition: .3s;
        }

        .form-switch .form-check-input:checked {
            background-color: #1e3a8a !important;
        }

        .form-switch .form-check-input:checked::before {
            transform: translateX(2rem);
        }

        .form-switch .form-check-input:focus {
            box-shadow: none !important;
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

    <div class="station-hero shadow">
        <a href="search-stations.php" class="back-circle-btn shadow"><i class="fas fa-arrow-right" id="backIcon"></i></a>
        <div class="w-100 text-white text-start">
            <h3 class="fw-black mb-1"><?= htmlspecialchars($station['Name']) ?></h3>
            <p class="small mb-0 opacity-90"><i class="fas fa-star text-warning"></i> <?= $station['Rating'] ?> | <?= htmlspecialchars($station['Location']) ?></p>
        </div>
    </div>

    <div class="container mt-4">
        <h6 class="fw-bold mb-3 small" data-i18n="step1_label">1. اختر نوع الخدمة المطلوب</h6>
        <div class="row g-2 mb-4">
            <div class="col-4">
                <div class="item-card text-center p-2 l-opt" id="l-station" onclick="filterServicesByType('station', <?= $hasStationServices ? 1 : 0 ?>)">
                    <i class="fas fa-gas-pump d-block mb-1 fs-4 text-primary"></i> <small data-i18n="opt_at_station">في المحطة</small>
                </div>
            </div>
            <div class="col-4">
                <div class="item-card text-center p-2 l-opt" id="l-home" onclick="filterServicesByType('home', <?= $hasHomeServices ? 1 : 0 ?>)">
                    <i class="fas fa-home d-block mb-1 fs-4 text-primary"></i> <small data-i18n="opt_at_home">في موقعي</small>
                </div>
            </div>
            <div class="col-4">
                <div class="item-card text-center p-2 l-opt" id="l-delivery" onclick="filterServicesByType('delivery', <?= $hasDeliveryServices ? 1 : 0 ?>)">
                    <i class="fas fa-car-side d-block mb-1 fs-4 text-primary"></i> <small data-i18n="opt_delivery">استلام وتسليم</small>
                </div>
            </div>
        </div>

        <div id="services-box-container">
            <h6 class="fw-bold mt-4 mb-3 small" data-i18n="step2_label">2. الخدمات المتاحة لهذا النوع</h6>
            <div class="services-scroll-box" id="services-list">
                <p class="text-center opacity-50 py-3" data-i18n="select_type_first">يرجى اختيار نوع الخدمة أولاً لإظهار الخيارات المتاحة.</p>
            </div>
        </div>

        <div class="plan-booking-panel shadow-sm" id="planBookingPanel">
            <?php if ($activePlanPayload): ?>
                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
                    <div class="text-start flex-grow-1">
                        <span class="badge text-bg-primary rounded-pill mb-2" data-i18n="plan_booking_badge">متاح من الباقة</span>
                        <h6 class="fw-black mb-1" id="activePlanName"></h6>
                        <div class="small fw-bold">
                            <span id="activePlanRemaining"><?= (int) $activePlanPayload['remaining_washes'] ?></span>
                            <span data-i18n="plan_washes_left">غسلات متبقية</span>
                        </div>
                    </div>
                    <img src="../assets/Images/car.png" alt="Car" class="plan-booking-car">
                </div>
                <div class="plan-feature-rail mt-2" id="planFeatureRail"></div>
                <div class="plan-switch-row d-flex justify-content-between align-items-center mt-3">
                    <label for="usePlanSwitch" class="small fw-bold mb-0" data-i18n="use_plan_for_booking">استخدم غسلة من باقتي لهذا الطلب</label>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" id="usePlanSwitch" onchange="updateFooter()">
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center gap-3">
                    <div class="text-start">
                        <h6 class="fw-bold mb-1" data-i18n="no_active_plan">لا توجد باقة نشطة</h6>
                        <p class="small opacity-75 mb-0" data-i18n="subscribe_hint">اشترك بباقة حتى يظهر الحجز من الباقة هنا.</p>
                    </div>
                    <a href="customer-plans.php" class="btn btn-primary rounded-pill fw-bold px-3" data-i18n="btn_subscribe">اشترك</a>
                </div>
            <?php endif; ?>
        </div>

        <h6 class="fw-bold mt-4 mb-3 small" data-i18n="step3_label">3. الموعد المناسب</h6>
        <div class="row g-2 mb-4 text-start">
            <div class="col-6">
                <label class="small fw-bold mb-1" data-i18n="date_label">التاريخ</label>
                <input type="date" id="bDate" class="shadow-sm">
            </div>
            <div class="col-6">
                <label class="small fw-bold mb-1" data-i18n="time_label">الوقت</label>
                <input type="time" id="bTime" class="shadow-sm">
            </div>
        </div>

        <div class="extra-panel shadow-sm" id="locationPanel">
            <h6 class="fw-bold mb-2 small" data-i18n="location_step_label">4. موقع الخدمة</h6>
            <div class="alert alert-info py-2 small rounded-4 border-0" data-i18n="payment_note">
                ملاحظة: الدفع لخدمة المنزل أو الاستلام والتسليم يكون فيزا أو محفظة أو نقاط ولاء فقط.
            </div>
            <button type="button" class="btn btn-outline-primary rounded-pill fw-bold w-100 mb-3" onclick="captureLocation()">
                <i class="fas fa-location-crosshairs me-1"></i> <span data-i18n="capture_location_btn">تحديد موقعي الحالي</span>
            </button>
            <div class="location-map-card shadow-sm" id="locationMapCard">
                <div id="serviceLocationMap"></div>
                <div class="map-confirm-row">
                    <label class="small fw-bold mb-1" for="detectedAddress" data-i18n="street_label">اسم الشارع / الموقع</label>
                    <input type="text" id="detectedAddress" data-i18n-placeholder="street_placeholder" placeholder="اسم الشارع سيظهر هنا ويمكنك تعديله">
                    <div class="map-helper" data-i18n="map_hint">حرّك العلامة على الخريطة لتعديل موقعك بدقة.</div>
                    <a href="#" target="_blank" rel="noopener" id="googleMapLink" class="google-map-link">
                        <i class="fas fa-map-location-dot me-1"></i><span data-i18n="open_google_maps">فتح الموقع على Google Maps</span>
                    </a>
                </div>
            </div>
            <textarea id="addressDetails" data-i18n-placeholder="address_placeholder" placeholder="اكتب وصف الموقع، رقم البناية، أو أي ملاحظة تساعد المحطة"></textarea>
            <input type="hidden" id="latVal">
            <input type="hidden" id="lngVal">
        </div>
    </div>

    <div class="order-footer shadow">
        <div class="d-flex justify-content-between mb-3 fw-bold">
            <span class="small"><span data-i18n="selected_label">المختار:</span> <span id="txtServ" class="fw-bold" style="color: var(--accent);">---</span></span>
            <span id="txtPrice" class="text-success fs-5">0.00 JOD</span>
        </div>
        <button onclick="book(this)" class="btn-book shadow" data-i18n="btn_send_request">إرسال الطلب للمحطة</button>
    </div>

    <div class="modal fade" id="alertModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4">
                <div id="mIcon" class="mb-3 fs-1"></div>
                <h6 id="modalTitle" class="fw-bold"></h6>
                <p id="modalBody" class="small mb-4"></p>
                <button id="modalOkBtn" class="btn btn-primary w-100 rounded-pill fw-bold" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../assets/service-translations.js"></script>
    <script>
        const allServices = <?= json_encode($all_services); ?>;
        const activePlan = <?= json_encode($activePlanPayload, JSON_UNESCAPED_UNICODE); ?>;
        const csrfToken = <?= json_encode($csrf_token); ?>;
        const stationPoint = {
            lat: <?= is_numeric($station['Lat'] ?? null) ? (float) $station['Lat'] : '31.9539' ?>,
            lng: <?= is_numeric($station['Lon'] ?? null) ? (float) $station['Lon'] : '35.9106' ?>
        };
        let selectedServices = [];
        let currentType = "";
        let locationMap = null;
        let locationMarker = null;
        const lang = localStorage.getItem('app_lang') || 'ar';
        const msgModal = new bootstrap.Modal(document.getElementById('alertModal'));

        const translations = {
            ar: {
                st_details_title: "تفاصيل المحطة",
                step1_label: "1. اختر نوع الخدمة المطلوب",
                step2_label: "2. الخدمات المتاحة لهذا النوع",
                step3_label: "3. الموعد المناسب",
                opt_at_station: "في المحطة",
                opt_at_home: "في موقعي",
                opt_delivery: "استلام وتسليم",
                date_label: "التاريخ",
                time_label: "الوقت",
                selected_label: "المختار:",
                btn_send_request: "إرسال الطلب للمحطة",
                btn_ok: "موافق",
                select_type_first: "يرجى اختيار نوع الخدمة أولاً لإظهار الخيارات المتاحة.",
                not_avail_t: "غير متوفر",
                not_avail_m: "عذراً، هذا النوع غير مدعوم حالياً في هذه المحطة.",
                missing_t: "بيانات ناقصة",
                missing_m: "يرجى إكمال جميع الاختيارات والموعد قبل المتابعة.",
                no_serv: "لا يوجد خدمات متاحة حالياً لهذا النوع.",
                success_t: "تم إرسال الطلب",
                success_m: "بانتظار موافقة صاحب المحطة، سيتم إشعارك فور القبول.",
                plan_booking_badge: "متاح من الباقة",
                plan_washes_left: "غسلات متبقية",
                use_plan_for_booking: "استخدم غسلة من باقتي لهذا الطلب",
                paid_by_plan: "من الباقة",
                plan_unavailable_t: "الباقة غير متاحة",
                plan_unavailable_m: "لا توجد غسلات متبقية في باقتك حالياً.",
                no_active_plan: "لا توجد باقة نشطة",
                subscribe_hint: "اشترك بباقة حتى يظهر الحجز من الباقة هنا.",
                btn_subscribe: "اشترك",
                location_step_label: "4. موقع الخدمة",
                payment_note: "ملاحظة: الدفع لخدمة المنزل أو الاستلام والتسليم يكون فيزا أو محفظة أو نقاط ولاء أو باقة فقط.",
                capture_location_btn: "تحديد موقعي الحالي",
                street_label: "اسم الشارع / الموقع",
                street_placeholder: "اسم الشارع سيظهر هنا ويمكنك تعديله",
                map_hint: "حرّك العلامة على الخريطة لتعديل موقعك بدقة.",
                open_google_maps: "فتح الموقع على Google Maps",
                searching_address: "جاري قراءة اسم الشارع...",
                address_placeholder: "اكتب وصف الموقع، رقم البناية، أو أي ملاحظة تساعد المحطة",
                location_title_unavailable: "الموقع غير متاح",
                location_body_unavailable: "اكتب عنوانك يدوياً في خانة وصف الموقع.",
                location_title_saved: "تم تحديد الموقع",
                location_body_saved: "سيظهر الموقع لصاحب المحطة قبل الموافقة على الطلب.",
                location_title_failed: "تعذر تحديد الموقع",
                location_body_failed: "اكتب عنوانك يدوياً حتى تتمكن المحطة من الوصول إليك.",
                send_failed_t: "لم يتم إرسال الطلب",
                send_failed_m: "تأكد من البيانات وحاول مرة أخرى.",
                location_required_m: "يرجى تحديد موقعك من زر الموقع قبل إرسال طلب المنزل أو الاستلام والتسليم.",
                connection_error_t: "خطأ اتصال",
                connection_error_m: "تعذر إرسال الطلب الآن."
            },
            en: {
                st_details_title: "Station Details",
                step1_label: "1. Select Service Type",
                step2_label: "2. Available Services",
                step3_label: "3. Preferred Appointment",
                opt_at_station: "At Station",
                opt_at_home: "At My Location",
                opt_delivery: "Pick & Drop",
                date_label: "Date",
                time_label: "Time",
                selected_label: "Selected:",
                btn_send_request: "Send Booking Request",
                btn_ok: "OK",
                select_type_first: "Please select service type first to show available options.",
                not_avail_t: "Unavailable",
                not_avail_m: "Sorry, this service type is not supported at this station.",
                missing_t: "Missing Data",
                missing_m: "Please complete all selections and time before proceeding.",
                no_serv: "No services available for type currently.",
                success_t: "Request Sent",
                success_m: "Waiting for station owner approval. You will be notified once accepted.",
                plan_booking_badge: "Available from plan",
                plan_washes_left: "washes left",
                use_plan_for_booking: "Use one wash from my plan for this booking",
                paid_by_plan: "From plan",
                plan_unavailable_t: "Plan unavailable",
                plan_unavailable_m: "You do not have remaining plan washes right now.",
                no_active_plan: "No active plan",
                subscribe_hint: "Subscribe to a plan to book from it here.",
                btn_subscribe: "Subscribe",
                location_step_label: "4. Service location",
                payment_note: "Note: home and pickup orders can be paid by Visa, wallet, loyalty points, or plan only.",
                capture_location_btn: "Use current location",
                street_label: "Street / location name",
                street_placeholder: "Street name will appear here and you can edit it",
                map_hint: "Move the pin on the map to adjust your location.",
                open_google_maps: "Open in Google Maps",
                searching_address: "Reading street name...",
                address_placeholder: "Write building number, street, or any note that helps the station",
                location_title_unavailable: "Location unavailable",
                location_body_unavailable: "Write your address manually in the location notes.",
                location_title_saved: "Location saved",
                location_body_saved: "The station owner will see it before accepting the booking.",
                location_title_failed: "Location failed",
                location_body_failed: "Write your address manually so the station can reach you.",
                send_failed_t: "Request not sent",
                send_failed_m: "Check your data and try again.",
                location_required_m: "Please capture your location before sending home or pickup orders.",
                connection_error_t: "Connection error",
                connection_error_m: "Could not send the request right now."
            }
        };

        function filterServicesByType(type, isAvail) {
            if (isAvail == '0') {
                document.getElementById('mIcon').innerHTML = '<i class="fas fa-exclamation-circle text-warning"></i>';
                document.getElementById('modalTitle').innerText = translations[lang].not_avail_t;
                document.getElementById('modalBody').innerText = translations[lang].not_avail_m;
                msgModal.show();
                return;
            }
            currentType = type;
            document.getElementById('locationPanel').style.display = type === 'station' ? 'none' : 'block';
            if (type !== 'station') {
                ensureLocationMap();
                setTimeout(() => document.getElementById('locationPanel').scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                }), 100);
            }
            document.querySelectorAll('.l-opt').forEach(el => el.classList.remove('selected'));
            document.getElementById('l-' + type).classList.add('selected');
            const container = document.getElementById('services-list');
            container.innerHTML = "";
            selectedServices = [];
            updateFooter();
            const filtered = allServices.filter(s => {
                if (type === 'station') return s.is_station == 1;
                if (type === 'home') return s.is_home == 1;
                if (type === 'delivery') return s.is_pickup == 1;
                return false;
            });
            if (filtered.length === 0) {
                container.innerHTML = `<p class="text-center opacity-50 py-3">${translations[lang].no_serv}</p>`;
            } else {
                filtered.forEach(s => {
                    const localizedService = window.clevoLocalizeService
                        ? window.clevoLocalizeService(s, lang)
                        : {
                            name: lang === 'ar' ? (s.Name_ar || s.Name) : (s.Name_en || s.Name),
                            description: lang === 'ar' ? (s.Description_ar || s.Description || '') : (s.Description_en || s.Description || '')
                        };
                    const serviceDisplayName = localizedService.name;
                    const card = document.createElement('div');
                    card.className = 'item-card d-flex justify-content-between align-items-center shadow-sm';
                    card.onclick = () => toggleServ(card, serviceDisplayName, parseFloat(s.Price), parseInt(s.ServiceID, 10));

                    const textWrap = document.createElement('div');
                    textWrap.className = 'text-start';
                    const title = document.createElement('h6');
                    title.className = 'mb-0 fw-bold';
                    title.innerText = serviceDisplayName;
                    const desc = document.createElement('small');
                    desc.className = 'opacity-75';
                    desc.innerText = localizedService.description || '';
                    textWrap.appendChild(title);
                    textWrap.appendChild(desc);

                    const price = document.createElement('div');
                    price.className = 'fw-bold';
                    price.style.color = 'var(--accent)';
                    price.innerText = parseFloat(s.Price).toFixed(2) + ' JOD';

                    card.appendChild(textWrap);
                    card.appendChild(price);
                    container.appendChild(card);
                });
            }
        }

        function toggleServ(el, n, p, id) {
            const index = selectedServices.findIndex(s => s.id === id);
            if (index > -1) {
                selectedServices.splice(index, 1);
                el.classList.remove('selected');
            } else {
                selectedServices.push({
                    id: id,
                    name: n,
                    price: p
                });
                el.classList.add('selected');
            }
            updateFooter();
        }

        function showAlert(icon, title, body) {
            document.getElementById('mIcon').innerHTML = icon;
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalBody').innerText = body;
            msgModal.show();
        }

        function ensureLocationMap() {
            const mapCard = document.getElementById('locationMapCard');
            mapCard.style.display = 'block';
            if (typeof L === 'undefined') return;

            const startLat = parseFloat(document.getElementById('latVal').value) || stationPoint.lat || 31.9539;
            const startLng = parseFloat(document.getElementById('lngVal').value) || stationPoint.lng || 35.9106;
            if (!locationMap) {
                locationMap = L.map('serviceLocationMap', {
                    zoomControl: false
                }).setView([startLat, startLng], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19
                }).addTo(locationMap);
                locationMarker = L.marker([startLat, startLng], {
                    draggable: true
                }).addTo(locationMap);
                locationMarker.on('dragend', () => {
                    const pos = locationMarker.getLatLng();
                    setServiceLocation(pos.lat, pos.lng, true);
                });
                locationMap.on('click', (event) => setServiceLocation(event.latlng.lat, event.latlng.lng, true));
            }
            setTimeout(() => locationMap.invalidateSize(), 80);
        }

        function setServiceLocation(lat, lng, shouldFetchAddress = true) {
            document.getElementById('latVal').value = lat;
            document.getElementById('lngVal').value = lng;
            ensureLocationMap();
            if (locationMarker) locationMarker.setLatLng([lat, lng]);
            if (locationMap) locationMap.setView([lat, lng], Math.max(locationMap.getZoom(), 16));
            const googleLink = document.getElementById('googleMapLink');
            googleLink.href = `https://www.google.com/maps?q=${lat},${lng}`;
            googleLink.style.display = 'inline-flex';
            googleLink.style.alignItems = 'center';
            if (shouldFetchAddress) fetchStreetName(lat, lng);
        }

        function fetchStreetName(lat, lng) {
            const addressInput = document.getElementById('detectedAddress');
            addressInput.value = translations[lang].searching_address;
            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}&accept-language=${lang}`)
                .then(res => res.json())
                .then(data => {
                    const address = data.address || {};
                    const street = address.road || address.neighbourhood || address.suburb || address.city || data.display_name || '';
                    addressInput.value = street;
                })
                .catch(() => {
                    addressInput.value = '';
                });
        }

        function captureLocation() {
            if (!navigator.geolocation) {
                ensureLocationMap();
                showAlert('<i class="fas fa-map-marker-alt text-warning"></i>', translations[lang].location_title_unavailable, translations[lang].location_body_unavailable);
                return;
            }

            navigator.geolocation.getCurrentPosition((pos) => {
                setServiceLocation(pos.coords.latitude, pos.coords.longitude, true);
                showAlert('<i class="fas fa-check-circle text-success"></i>', translations[lang].location_title_saved, translations[lang].location_body_saved);
            }, () => {
                ensureLocationMap();
                showAlert('<i class="fas fa-map-marker-alt text-warning"></i>', translations[lang].location_title_failed, translations[lang].location_body_failed);
            });
        }

        function updateFooter() {
            document.getElementById('txtServ').innerText = selectedServices.map(s => s.name).join(' + ') || "---";
            const usePlan = document.getElementById('usePlanSwitch')?.checked;
            document.getElementById('txtPrice').innerText = usePlan && selectedServices.length ?
                translations[lang].paid_by_plan :
                selectedServices.reduce((sum, s) => sum + parseFloat(s.price), 0).toFixed(2) + " JOD";
        }

        function book(btn) {
            const d = document.getElementById('bDate').value,
                t = document.getElementById('bTime').value;
            if (!currentType || selectedServices.length === 0 || !d || !t) {
                showAlert('<i class="fas fa-info-circle text-primary"></i>', translations[lang].missing_t, translations[lang].missing_m);
                return;
            }
            const originalBtn = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${translations[lang].btn_send_request}`;
            }

            const params = new URLSearchParams();
            params.append('action', 'create_booking');
            params.append('csrf_token', csrfToken);
            params.append('service_id', selectedServices[0].id);
            params.append('service_ids', JSON.stringify(selectedServices.map(s => s.id)));
            params.append('service_location', currentType);
            params.append('booking_date', d);
            params.append('booking_time', t);
            params.append('payment_method', '');
            params.append('use_plan', document.getElementById('usePlanSwitch')?.checked ? '1' : '0');
            params.append('latitude', document.getElementById('latVal').value);
            params.append('longitude', document.getElementById('lngVal').value);
            const streetInputValue = document.getElementById('detectedAddress').value.trim();
            const streetName = streetInputValue === translations[lang].searching_address ? '' : streetInputValue;
            const addressNotes = document.getElementById('addressDetails').value.trim();
            params.append('address_details', [streetName, addressNotes].filter(Boolean).join('\n'));

            fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: params.toString()
                })
                .then(res => res.json())
                .then(data => {
                    if (data.status !== 'success') {
                        const body = data.message === 'location_required' ?
                            translations[lang].location_required_m :
                            (data.message === 'plan_unavailable' ? translations[lang].plan_unavailable_m : translations[lang].send_failed_m);
                        const title = data.message === 'plan_unavailable' ? translations[lang].plan_unavailable_t : translations[lang].send_failed_t;
                        showAlert('<i class="fas fa-exclamation-circle text-danger"></i>', title, body);
                        return;
                    }

                    showAlert('<i class="fas fa-clock text-success"></i>', translations[lang].success_t, translations[lang].success_m);
                    document.getElementById('alertModal').addEventListener('hidden.bs.modal', function() {
                        const bookingId = data.booking_id || '';
                        const orderNum = data.order_number || '';
                        const query = bookingId ?
                            ('?id=' + encodeURIComponent(bookingId)) :
                            (orderNum ? ('?order=' + encodeURIComponent(orderNum)) : '');
                        location.href = 'success.php' + query;
                    }, {
                        once: true
                    });
                })
                .catch(() => showAlert('<i class="fas fa-wifi text-danger"></i>', translations[lang].connection_error_t, translations[lang].connection_error_m))
                .finally(() => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalBtn;
                    }
                });
        }

        function renderActivePlan() {
            if (!activePlan) return;
            const planName = document.getElementById('activePlanName');
            if (planName) {
                planName.innerText = lang === 'ar' ? activePlan.name_ar : activePlan.name_en;
            }
            const rail = document.getElementById('planFeatureRail');
            if (rail) {
                rail.innerHTML = '';
                const features = lang === 'ar' ? activePlan.features_ar : activePlan.features_en;
                (features || []).forEach(feature => {
                    const chip = document.createElement('span');
                    chip.className = 'plan-feature-chip';
                    chip.innerHTML = '<i class="fas fa-check me-1"></i>' + feature;
                    rail.appendChild(chip);
                });
            }
        }

        window.onload = () => {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('bDate').setAttribute('min', today);
            const theme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.setAttribute('lang', lang);
            document.getElementById('backIcon').className = lang === 'ar' ? 'fas fa-arrow-right' : 'fas fa-arrow-left';
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) el.innerText = translations[lang][key];
            });
            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                if (translations[lang] && translations[lang][key]) el.setAttribute('placeholder', translations[lang][key]);

            });
            renderActivePlan();
        };
    </script>
    <script src="../assets/lamea.js?v=20260605b"></script>
</body>

</html>
