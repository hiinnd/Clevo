<?php
session_start();
include __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/app-helpers.php';

clevoEnsureTrackingColumns($conn);


$bookingID = (int) ($_GET['id'] ?? 0);
$viewerID = clevo_current_user_id();
if ($viewerID <= 0) {
    header("Location: ../general/login.php");
    exit;
}


$stmt = $conn->prepare("SELECT b.*, u.UserName as customer_name, d.UserName as driver_name, d.ProfilePic as driver_pic, d.PhoneNumber as driver_phone,
        s.Name AS station_name, s.Location AS station_location, s.Lat AS station_lat, s.Lon AS station_lon
          FROM booking b
          JOIN user u ON b.UserID = u.UserID
          JOIN stations s ON b.StationID = s.StationID
          LEFT JOIN user d ON b.DriverID = d.UserID
          WHERE b.BookingID = ? AND (b.UserID = ? OR s.UserID = ? OR b.DriverID = ?) LIMIT 1");
$stmt->bind_param("iiii", $bookingID, $viewerID, $viewerID, $viewerID);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    echo "الطلب غير موجود أو غير متاح للتتبع حالياً.";
    exit;
}

if (
    (int) $order['UserID'] === (int) $viewerID
    && ($order['Service_Location'] ?? '') === 'delivery'
    && empty($order['Payment_Method'])
) {
    header("Location: ../includes/payment-method.php?id=" . (int) $bookingID);
    exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="tracking_title">تتبع الطلب | Clevo </title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-rtl">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <!-- مكتبة الخرائط Leaflet المفتوحة -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <style>
        :root { 
            --bg-body: #f4f7f6; --bg-panel: rgba(255, 255, 255, 0.95); 
            --text-main: #000000; --text-muted: #1a1a1a; --card-bg: rgba(248, 249, 250, 0.8); 
            --step-inactive: #e9ecef; --border-color: #dee2e6; 
        }
        [data-theme="dark"] { 
            --bg-body: #121212; --bg-panel: rgba(30, 41, 59, 0.95); 
            --text-main: #ffffff; --text-muted: #cbd5e1; --card-bg: rgba(37, 37, 37, 0.8); 
            --step-inactive: #334155; --border-color: #475569; 
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); height: 100vh; display: flex; flex-direction: column; overflow: hidden; margin: 0; transition: 0.3s ease; }
        #map { flex-grow: 1; width: 100%; z-index: 1; border-bottom: 2px solid var(--border-color); }
        /* تصميم الخريطة للوضع الليلي */
        [data-theme="dark"] #map { filter: brightness(0.6) invert(1) contrast(3) hue-rotate(200deg) saturate(0.3); }
        
        .tracking-panel { background: var(--bg-panel); border-radius: 35px 35px 0 0; padding: 25px 20px; box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.2); z-index: 100; backdrop-filter: blur(15px); }
        .step-icon { width: 38px; height: 38px; background: var(--step-inactive); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; color: #adb5bd; transition: 0.3s; }
        .step-item.active .step-icon { background: #3b82f6; color: white; transform: scale(1.1); }
        .step-item.completed .step-icon { background: #22c55e; color: white; }
        
        .worker-card { background: var(--card-bg); border-radius: 18px; padding: 15px; display: flex; align-items: center; justify-content: space-between; border: 1px solid var(--border-color); }
        .top-nav { position: absolute; top: 20px; width: 100%; padding: 0 20px; z-index: 1000; display: flex; justify-content: space-between; align-items: center; }
        .circle-btn { background: var(--bg-panel); width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); border: 1px solid var(--border-color); color: var(--text-main); text-decoration: none; }
        .live-info-stack { position: absolute; top: 72px; left: 50%; transform: translateX(-50%); width: min(92vw, 430px); z-index: 950; display: grid; gap: 8px; }
        .live-mini-card { background: var(--bg-panel); color: var(--text-main); border: 1px solid var(--border-color); border-radius: 18px; padding: 10px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: 0 8px 22px rgba(0,0,0,.16); backdrop-filter: blur(15px); }
        .live-mini-icon { width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 40px; }
        .live-mini-title { font-size: .82rem; font-weight: 900; margin: 0; }
        .live-mini-sub { font-size: .72rem; color: var(--text-muted); margin: 0; font-weight: 700; }
        .map-legend { position: absolute; left: 14px; bottom: 245px; z-index: 900; background: var(--bg-panel); color: var(--text-main); border: 1px solid var(--border-color); border-radius: 16px; padding: 10px 12px; box-shadow: 0 8px 22px rgba(0,0,0,.18); font-size: .78rem; }
        [dir="rtl"] .map-legend { left: auto; right: 14px; }
        .legend-row { display: flex; align-items: center; gap: 8px; white-space: nowrap; margin-bottom: 5px; }
        .legend-row:last-child { margin-bottom: 0; }
        .legend-dot { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }

        .arrival-toast {
            position: fixed; top: 80px; left: 50%; transform: translateX(-50%);
            background: #22c55e; color: white; padding: 10px 25px; border-radius: 50px;
            font-weight: bold; z-index: 2000; display: none; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
        }
        @media (max-height: 680px) {
            .live-info-stack { position: fixed; top: 68px; }
            .live-mini-card { padding: 8px 10px; }
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

    <div id="arrivalToast" class="arrival-toast">
        <i class="fas fa-map-marker-alt me-2"></i> <span id="toastMsg">وصل السائق للموقع!</span>
    </div>

    <div class="top-nav">
        <a href="booking-history.php" class="circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
        <div class="badge bg-danger rounded-pill px-3 py-2 shadow d-flex align-items-center gap-2">
            <span data-i18n="live_label">تتبع مباشر</span> <i class="fas fa-circle small fa-beat"></i>
        </div>
    </div>

    <div class="live-info-stack">
        <div class="live-mini-card">
            <div class="d-flex align-items-center min-w-0">
                <img src="<?= e(clevoProfileImageUrl($order['driver_pic'] ?? '', $order['driver_name'] ?? 'Driver')) ?>" class="rounded-circle live-mini-icon" style="object-fit: cover;">
                <div class="mx-2 text-start min-w-0">
                    <p class="live-mini-title text-truncate"><?= e($order['driver_name'] ?? '-') ?></p>
                    <p class="live-mini-sub" data-i18n="captain_title">كابتن Clevo المعتمد</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="tel:<?= e($order['driver_phone'] ?? '') ?>" class="btn btn-sm btn-primary rounded-pill"><i class="fas fa-phone"></i></a>
                <a href="https://wa.me/<?= e($order['driver_phone'] ?? '') ?>" class="btn btn-sm btn-success rounded-pill"><i class="fab fa-whatsapp"></i></a>
            </div>
        </div>
        <div class="live-mini-card">
            <div class="d-flex align-items-center min-w-0">
                <span class="live-mini-icon bg-success-subtle text-success"><i class="fas fa-store"></i></span>
                <div class="mx-2 text-start min-w-0">
                    <p class="live-mini-title text-truncate"><?= e($order['station_name'] ?? '-') ?></p>
                    <p class="live-mini-sub text-truncate"><?= e($order['station_location'] ?? '') ?></p>
                </div>
            </div>
            <?php if ($order['station_lat'] !== null && $order['station_lon'] !== null): ?>
                <a href="https://www.google.com/maps?q=<?= e($order['station_lat']) ?>,<?= e($order['station_lon']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success rounded-pill"><i class="fas fa-map-location-dot"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <div id="map"></div>
    <div class="map-legend">
        <div class="legend-row"><span class="legend-dot" style="background:#3b82f6"></span><span data-i18n="legend_driver">السائق</span></div>
        <div class="legend-row"><span class="legend-dot" style="background:#ef4444"></span><span data-i18n="legend_customer">موقعك</span></div>
        <div class="legend-row"><span class="legend-dot" style="background:#16a34a"></span><span data-i18n="legend_station">المحطة</span></div>
    </div>

    <div class="tracking-panel text-center">
        <!-- الرسم البياني الزمني Progress Steps -->
        <div class="d-flex justify-content-between mb-4">
            <div class="step-item" id="step-Accepted">
                <div class="step-icon"><i class="fas fa-car"></i></div>
                <span class="small fw-bold" data-i18n="step_pickup">تم القبول</span>
            </div>
            <div class="step-item" id="step-In-Progress">
                <div class="step-icon"><i class="fas fa-route"></i></div>
                <span class="small fw-bold" data-i18n="step_way">في الطريق</span>
            </div>
            <div class="step-item" id="step-Washing">
                <div class="step-icon"><i class="fas fa-soap"></i></div>
                <span class="small fw-bold" data-i18n="step_wash">جاري الغسيل</span>
            </div>
            <div class="step-item" id="step-Returning">
                <div class="step-icon"><i class="fas fa-rotate-left"></i></div>
                <span class="small fw-bold" data-i18n="step_return">راجع إليك</span>
            </div>
            <div class="step-item" id="step-Completed">
                <div class="step-icon"><i class="fas fa-check"></i></div>
                <span class="small fw-bold" data-i18n="step_done">تم الانتهاء</span>
            </div>
        </div>

        <div id="status-alert" class="alert alert-primary py-2 small mb-3 fw-bold shadow-sm">جاري جلب موقع السائق...</div>

    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const bookingID = <?= (int)$bookingID ?>;
        const lang = localStorage.getItem('app_lang') || 'ar';
        const customerPoint = {
            lat: <?= $order['Latitude'] !== null ? (float) $order['Latitude'] : 'null' ?>,
            lng: <?= $order['Longitude'] !== null ? (float) $order['Longitude'] : 'null' ?>
        };
        const stationPoint = {
            lat: <?= $order['station_lat'] !== null ? (float) $order['station_lat'] : 'null' ?>,
            lng: <?= $order['station_lon'] !== null ? (float) $order['station_lon'] : 'null' ?>
        };
        const fallbackPoint = customerPoint.lat && customerPoint.lng ? customerPoint : (stationPoint.lat && stationPoint.lng ? stationPoint : { lat: 31.985, lng: 35.898 });
        const labels = {
            ar: {
                tracking_title: 'تتبع الطلب', live_label: 'تتبع مباشر', step_pickup: 'تم القبول',
                step_way: 'في الطريق', step_wash: 'جاري الغسيل', step_return: 'راجع إليك',
                step_done: 'تم الانتهاء', captain_title: 'كابتن Clevo المعتمد',
                legend_driver: 'السائق', legend_customer: 'موقعك', legend_station: 'المحطة',
                customer_popup: 'موقع المنزل / الخدمة', station_popup: 'موقع المحطة',
                returning: 'السائق في طريق العودة إليك', to_customer: 'السائق متجه إلى موقعك',
                to_station: 'السيارة في الطريق إلى المحطة', at_station: 'السيارة في المحطة للغسيل',
                completed: 'تم إنهاء الطلب'
            },
            en: {
                tracking_title: 'Order Tracking', live_label: 'Live tracking', step_pickup: 'Accepted',
                step_way: 'On the way', step_wash: 'Washing', step_return: 'Returning',
                step_done: 'Completed', captain_title: 'Certified Clevo Captain',
                legend_driver: 'Driver', legend_customer: 'Your location', legend_station: 'Station',
                customer_popup: 'Home / service location', station_popup: 'Station location',
                returning: 'Driver is returning to you', to_customer: 'Driver is heading to your location',
                to_station: 'Car is heading to the station', at_station: 'Car is at the station for washing',
                completed: 'Order completed'
            }
        };
        const t = key => (labels[lang] && labels[lang][key]) || key;

        const map = L.map('map', { zoomControl: false }).setView([fallbackPoint.lat, fallbackPoint.lng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        const pointIcon = (color, icon) => L.divIcon({
            html: `<i class="fas ${icon}" style="color:${color};font-size:25px;text-shadow:0 0 10px white;"></i>`,
            className: 'c-icon'
        });
        const driverIcon = pointIcon('#3b82f6', 'fa-car-side');
        const customerIcon = pointIcon('#ef4444', 'fa-house');
        const stationIcon = pointIcon('#16a34a', 'fa-store');
        const bounds = [];
        if (customerPoint.lat && customerPoint.lng) {
            L.marker([customerPoint.lat, customerPoint.lng], { icon: customerIcon }).addTo(map).bindPopup(t('customer_popup'));
            bounds.push([customerPoint.lat, customerPoint.lng]);
        }
        if (stationPoint.lat && stationPoint.lng) {
            L.marker([stationPoint.lat, stationPoint.lng], { icon: stationIcon }).addTo(map).bindPopup(t('station_popup'));
            bounds.push([stationPoint.lat, stationPoint.lng]);
        }
        const driverMarker = L.marker([fallbackPoint.lat, fallbackPoint.lng], { icon: driverIcon }).addTo(map);
        let routeLine = L.polyline([], { color: '#3b82f6', weight: 5, opacity: 0.78, dashArray: '8, 10' }).addTo(map);
        if (bounds.length > 1) map.fitBounds(bounds, { padding: [40, 40] });

        function isPoint(point) {
            return point && Number.isFinite(Number(point.lat)) && Number.isFinite(Number(point.lng));
        }

        function targetForStatus(status) {
            if (status === 'Pickup' || status === 'Washing') return stationPoint;
            if (status === 'Returning' || status === 'Accepted' || status === 'Arrived' || status === 'In Progress') return customerPoint;
            return null;
        }

        function statusText(status) {
            if (status === 'Returning') return t('returning');
            if (status === 'Pickup') return t('to_station');
            if (status === 'Washing') return t('at_station');
            if (status === 'Completed') return t('completed');
            return t('to_customer');
        }

        function updateRoute(driverPoint, status) {
            const target = targetForStatus(status);
            if (isPoint(driverPoint) && isPoint(target)) {
                routeLine.setLatLngs([[driverPoint.lat, driverPoint.lng], [target.lat, target.lng]]);
                map.fitBounds([[driverPoint.lat, driverPoint.lng], [target.lat, target.lng]], { padding: [50, 50], maxZoom: 15 });
            } else {
                routeLine.setLatLngs([]);
            }
            document.getElementById('status-alert').innerText = statusText(status);
        }

        // وظيفة جلب البيانات اللحظية (تحديث كل 10 ثوانٍ)
        function updateTracking() {
            fetch(`get_order_status.php?id=${bookingID}`, { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    const payload = data.data || {};
                    const newPos = [payload.driver.lat, payload.driver.lng];
                    driverMarker.setLatLng(newPos);
                    
                    updateUI(payload.orderStatus);
                    updateRoute({ lat: payload.driver.lat, lng: payload.driver.lng }, payload.orderStatus);

                    if(payload.hasArrived && !sessionStorage.getItem('notified_arrival')) {
                        showArrivalNotification();
                        sessionStorage.setItem('notified_arrival', 'true');
                    }
                }
            });
        }

        function showArrivalNotification() {
            const toast = document.getElementById('arrivalToast');
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 5000);
        }

        function updateUI(status) {
            document.querySelectorAll('.step-item').forEach(el => el.classList.remove('active', 'completed'));
            const currentStep = document.getElementById('step-' + status.replace(' ', '-'));
            if(currentStep) currentStep.classList.add('active');
            
            if (status === 'Pickup') status = 'Washing';
            let steps = ['Accepted', 'In Progress', 'Washing', 'Returning', 'Completed'];
            let found = false;
            steps.forEach(s => {
                if(s === status) found = true;
                if(!found) {
                    const el = document.getElementById('step-' + s.replace(' ', '-'));
                    if(el) el.classList.add('completed');
                }
            });
        }

        setInterval(updateTracking, 10000); 
        updateTracking();
        document.documentElement.setAttribute('lang', lang);
        document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
        document.getElementById('bootstrap-rtl').href = lang === 'ar'
            ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css'
            : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
        if (lang !== 'ar') document.getElementById('backIcon').className = 'fas fa-arrow-left';
        document.querySelectorAll('[data-i18n]').forEach(el => {
            const key = el.getAttribute('data-i18n');
            if (labels[lang] && labels[lang][key]) el.innerText = labels[lang][key];
        });
    </script>

</body>
</html>

