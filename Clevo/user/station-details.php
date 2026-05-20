<?php
session_start();
include(__DIR__ . "/../includes/config.php");
include(__DIR__ . "/../includes/booking-helpers.php");
include(__DIR__ . "/../includes/app-helpers.php");

if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit();
}

$userID = (int) $_SESSION['userID'];
cancelExpiredBookings($conn, $userID);

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
    $serviceStmt = $conn->prepare("SELECT ServiceID, Name, Price, is_station, is_home, is_pickup FROM services WHERE ServiceID IN ($placeholders) AND StationID = ?");
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
    $serviceNames = array_map(fn($item) => $item['Name'], $orderedServices);
    $totalPrice = array_reduce($orderedServices, fn($sum, $item) => $sum + (float) $item['Price'], 0);
    $adminCommission = clevoCalculateAdminCommission($totalPrice);
    $servicesNote = "الخدمات: " . implode(' + ', $serviceNames);
    $addressForDb = trim($addressDetails) !== '' ? $addressDetails . "\n" . $servicesNote : $servicesNote;

    $insert = $conn->prepare("INSERT INTO booking
        (Order_Number, UserID, StationID, ServiceID, Service_Location, Latitude, Longitude, Address_Details, Booking_Date, Booking_Time, Status, Total_Price, Admin_Commission, Payment_Method)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?)");
    $insert->bind_param(
        "siiisddsssdds",
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
        $paymentMethod
    );

    if (!$insert->execute()) {
        echo json_encode(['status' => 'error', 'message' => 'db_error']);
        exit;
    }

    $bookingID = $conn->insert_id;
    $ownerID = (int) $station['UserID'];
    if ($ownerID > 0) {
        $title = "طلب جديد";
        $body = "وصلك طلب #$orderNumber لخدمات " . implode(' + ', $serviceNames) . " بتاريخ $bookingDate الساعة $bookingTime.";
        $type = "info";
        $notice = $conn->prepare("INSERT INTO notifications (user_id, title, body, type) VALUES (?, ?, ?, ?)");
        $notice->bind_param("isss", $ownerID, $title, $body, $type);
        $notice->execute();
    }

    echo json_encode(['status' => 'success', 'booking_id' => $bookingID, 'order_number' => $orderNumber]);
    exit;
}

// جلب الخدمات المضافة فعلياً لهذه المحطة من قاعدة البيانات
$serv_query = "SELECT * FROM `services` WHERE StationID = ?";
$stmt_s = $conn->prepare($serv_query);
$stmt_s->bind_param("i", $stationID);
$stmt_s->execute();
$services_res = $stmt_s->get_result();
$all_services = [];
while($row = $services_res->fetch_assoc()) {
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
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.8);
            --text: #0369a1; --accent: #0ea5e9; --border: rgba(0, 0, 0, 0.1);
        }
        [data-theme="dark"] { 
            --main-bg: #020617; 
            --glass: rgba(30, 41, 59, 0.9);
            --text: #f8fafc; --accent: #38bdf8; --border: rgba(255, 255, 255, 0.2);
        }
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; padding-bottom: 180px; margin: 0; transition: 0.3s ease; }
        
        .station-hero { height: 220px; background: linear-gradient(to bottom, rgba(0,0,0,0.1), rgba(0,0,0,0.7)), url('<?= htmlspecialchars(clevoStationImageUrl($station['Image'])) ?>'); background-size: cover; background-position: center; display: flex; align-items: flex-end; padding: 25px; border-radius: 0 0 40px 40px; position: relative; }
        
        .back-circle-btn { background: var(--glass); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: var(--accent); backdrop-filter: blur(10px); text-decoration: none; position: absolute; top: 20px; z-index: 10; border: 1px solid var(--border); }
        [dir="rtl"] .back-circle-btn { right: 20px; }
        [dir="ltr"] .back-circle-btn { left: 20px; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }

        .item-card { background: var(--glass); border-radius: 20px; padding: 18px; margin-bottom: 15px; border: 1px solid var(--border); backdrop-filter: blur(15px); transition: 0.3s; cursor: pointer; color: var(--text) !important; }
        .item-card small { color: var(--text); opacity: 0.8; font-weight: 600; }
        .selected { border: 2px solid var(--accent) !important; background: rgba(56, 189, 248, 0.15) !important; box-shadow: 0 5px 15px rgba(56, 189, 248, 0.2); }
        
        .order-footer { position: fixed; bottom: 0; left: 0; right: 0; background: var(--glass); padding: 25px; z-index: 1000; backdrop-filter: blur(25px); border-radius: 35px 35px 0 0; border-top: 1px solid var(--border); box-shadow: 0 -10px 30px rgba(0,0,0,0.1); }
        .btn-book { background: var(--accent); border: none; color: white !important; padding: 16px; border-radius: 50px; width: 100%; font-weight: 900; font-size: 1.1rem; }
        
        input { background: var(--glass) !important; border: 1px solid var(--border) !important; color: var(--text) !important; padding: 12px; border-radius: 15px; width: 100%; font-weight: 700; }
        .modal-content { border-radius: 30px; background-color: var(--glass) !important; color: var(--text) !important; border: 1px solid var(--accent); backdrop-filter: blur(20px); }
        .extra-panel { display: none; background: var(--glass); border: 1px solid var(--border); border-radius: 20px; padding: 16px; margin-bottom: 18px; }
        textarea { background: var(--glass) !important; border: 1px solid var(--border) !important; color: var(--text) !important; border-radius: 15px; width: 100%; padding: 12px; font-weight: 700; min-height: 74px; }
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
            <h6 class="fw-bold mb-2 small">4. موقع الخدمة</h6>
            <div class="alert alert-info py-2 small rounded-4 border-0">
                ملاحظة: الدفع لخدمة المنزل أو الاستلام والتسليم يكون فيزا أو محفظة أو نقاط ولاء فقط.
            </div>
            <button type="button" class="btn btn-outline-primary rounded-pill fw-bold w-100 mb-3" onclick="captureLocation()">
                <i class="fas fa-location-crosshairs me-1"></i> تحديد موقعي الحالي
            </button>
            <textarea id="addressDetails" placeholder="اكتب وصف الموقع، رقم البناية، أو أي ملاحظة تساعد المحطة"></textarea>
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
    <script>
        const allServices = <?= json_encode($all_services); ?>;
        const csrfToken = <?= json_encode($csrf_token); ?>;
        let selectedServices = [];
        let currentType = "";
        const lang = localStorage.getItem('app_lang') || 'ar';
        const msgModal = new bootstrap.Modal(document.getElementById('alertModal'));

        const translations = {
            ar: {
                st_details_title: "تفاصيل المحطة", step1_label: "1. اختر نوع الخدمة المطلوب", step2_label: "2. الخدمات المتاحة لهذا النوع", step3_label: "3. الموعد المناسب", opt_at_station: "في المحطة", opt_at_home: "في موقعي", opt_delivery: "استلام وتسليم", date_label: "التاريخ", time_label: "الوقت", selected_label: "المختار:", btn_send_request: "إرسال الطلب للمحطة", btn_ok: "موافق", select_type_first: "يرجى اختيار نوع الخدمة أولاً لإظهار الخيارات المتاحة.", not_avail_t: "غير متوفر", not_avail_m: "عذراً، هذا النوع غير مدعوم حالياً في هذه المحطة.", missing_t: "بيانات ناقصة", missing_m: "يرجى إكمال جميع الاختيارات والموعد قبل المتابعة.", no_serv: "لا يوجد خدمات متاحة حالياً لهذا النوع.", success_t: "تم إرسال الطلب", success_m: "بانتظار موافقة صاحب المحطة، سيتم إشعارك فور القبول."
            },
            en: {
                st_details_title: "Station Details", step1_label: "1. Select Service Type", step2_label: "2. Available Services", step3_label: "3. Preferred Appointment", opt_at_station: "At Station", opt_at_home: "At My Location", opt_delivery: "Pick & Drop", date_label: "Date", time_label: "Time", selected_label: "Selected:", btn_send_request: "Send Booking Request", btn_ok: "OK", select_type_first: "Please select service type first to show available options.", not_avail_t: "Unavailable", not_avail_m: "Sorry, this service type is not supported at this station.", missing_t: "Missing Data", missing_m: "Please complete all selections and time before proceeding.", no_serv: "No services available for type currently.", success_t: "Request Sent", success_m: "Waiting for station owner approval. You will be notified once accepted."
            }
        };

        function filterServicesByType(type, isAvail) {
            if (isAvail == '0') {
                document.getElementById('mIcon').innerHTML = '<i class="fas fa-exclamation-circle text-warning"></i>';
                document.getElementById('modalTitle').innerText = translations[lang].not_avail_t;
                document.getElementById('modalBody').innerText = translations[lang].not_avail_m;
                msgModal.show(); return;
            }
            currentType = type;
            document.getElementById('locationPanel').style.display = type === 'station' ? 'none' : 'block';
            if (type !== 'station') {
                setTimeout(() => document.getElementById('locationPanel').scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
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
                    container.innerHTML += `
                        <div class="item-card d-flex justify-content-between align-items-center shadow-sm" onclick="toggleServ(this, '${s.Name}', ${s.Price}, ${s.ServiceID})">
                            <div class="text-start">
                                <h6 class="mb-0 fw-bold">${s.Name}</h6>
                                <small class="opacity-75">${s.Description || ''}</small>
                            </div>
                            <div class="fw-bold" style="color: var(--accent);">${parseFloat(s.Price).toFixed(2)} JOD</div>
                        </div>`;
                });
            }
        }

        function toggleServ(el, n, p, id) {
            const index = selectedServices.findIndex(s => s.id === id);
            if (index > -1) { 
                selectedServices.splice(index, 1); 
                el.classList.remove('selected'); 
            } else { 
                selectedServices.push({ id: id, name: n, price: p }); 
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

        function captureLocation() {
            if (!navigator.geolocation) {
                showAlert('<i class="fas fa-map-marker-alt text-warning"></i>', 'الموقع غير متاح', 'اكتب عنوانك يدوياً في خانة وصف الموقع.');
                return;
            }

            navigator.geolocation.getCurrentPosition((pos) => {
                document.getElementById('latVal').value = pos.coords.latitude;
                document.getElementById('lngVal').value = pos.coords.longitude;
                showAlert('<i class="fas fa-check-circle text-success"></i>', 'تم تحديد الموقع', 'سيظهر الموقع لصاحب المحطة قبل الموافقة على الطلب.');
            }, () => {
                showAlert('<i class="fas fa-map-marker-alt text-warning"></i>', 'تعذر تحديد الموقع', 'اكتب عنوانك يدوياً حتى تتمكن المحطة من الوصول إليك.');
            });
        }

        function updateFooter() {
            document.getElementById('txtServ').innerText = selectedServices.map(s => s.name).join(' + ') || "---";
            document.getElementById('txtPrice').innerText = selectedServices.reduce((sum, s) => sum + parseFloat(s.price), 0).toFixed(2) + " JOD";
        }

        function book(btn) {
            const d = document.getElementById('bDate').value, t = document.getElementById('bTime').value;
            if (!currentType || selectedServices.length === 0 || !d || !t) {
                showAlert('<i class="fas fa-info-circle text-primary"></i>', translations[lang].missing_t, translations[lang].missing_m); return;
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
            params.append('latitude', document.getElementById('latVal').value);
            params.append('longitude', document.getElementById('lngVal').value);
            params.append('address_details', document.getElementById('addressDetails').value);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'success') {
                    const locationMsg = data.message === 'location_required' ? 'يرجى تحديد موقعك من زر الموقع قبل إرسال طلب المنزل أو الاستلام والتسليم.' : 'تأكد من البيانات وحاول مرة أخرى.';
                    showAlert('<i class="fas fa-exclamation-circle text-danger"></i>', 'لم يتم إرسال الطلب', locationMsg);
                    return;
                }

                showAlert('<i class="fas fa-clock text-success"></i>', translations[lang].success_t, translations[lang].success_m);
                document.getElementById('alertModal').addEventListener('hidden.bs.modal', function () {
                    const orderNum = data.order_number || '';
                    location.href = 'success.php' + (orderNum ? ('?order=' + encodeURIComponent(orderNum)) : '');
                }, { once: true });
            })
            .catch(() => showAlert('<i class="fas fa-wifi text-danger"></i>', 'خطأ اتصال', 'تعذر إرسال الطلب الآن.'))
            .finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalBtn;
                }
            });
        }

        window.onload = () => {
            const theme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) el.innerText = translations[lang][key];
            });
        };
    </script>
    <script src="/Clevo/assets/lamea.js"></script>
</body>
</html>
