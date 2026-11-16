<?php

include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';

clevoEnsureTrackingColumns($conn);

$bookingID = (int) ($_GET['id'] ?? 0);
$viewerID = clevo_current_user_id();
$deliveryView=clevo_delivery_tracking($conn,$viewerID,$bookingID,(int)($_SESSION['driver_booking_id']??0));
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='issue_confirmation'){
    clevo_json_response('success',clevo_delivery_issue_confirmation($conn,$viewerID,$bookingID,(string)($_POST['kind']??''),(string)($_POST['idempotency_key']??'')));
}
if (clevo_current_role() === 'driver' && (int) ($_SESSION['driver_booking_id'] ?? 0) !== $bookingID) throw new ClevoSecurityException('not_found', 404);
if ($viewerID <= 0) {
    header("Location: ../general/login.php");
    exit;
}

$stmt = $conn->prepare("SELECT b.*, u.UserName AS customer_name,
        d.UserName AS driver_name, d.PhoneNumber AS driver_phone,
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
    <title data-i18n="tracking_title">تتبع الطلب | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-rtl">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <style>
        :root {
            --bg-body: #f4f7f6;
            --bg-panel: rgba(255, 255, 255, 0.95);
            --text-main: #0f172a;
            --text-muted: #475569;
            --step-inactive: #e9ecef;
            --border-color: #dee2e6;
        }
        [data-theme="dark"] {
            --bg-body: #121212;
            --bg-panel: rgba(30, 41, 59, 0.95);
            --text-main: #ffffff;
            --text-muted: #cbd5e1;
            --step-inactive: #334155;
            --border-color: #475569;
        }
        body { font-family: 'Cairo', sans-serif; background: var(--bg-body); color: var(--text-main); height: 100vh; display: flex; flex-direction: column; overflow: hidden; margin: 0; }
        #map { flex-grow: 1; width: 100%; z-index: 1; border-bottom: 2px solid var(--border-color); }
        .top-nav { position: absolute; top: 20px; width: 100%; padding: 0 20px; z-index: 1000; display: flex; justify-content: space-between; align-items: flex-start; pointer-events: none; }
        .top-nav > * { pointer-events: auto; }
        .circle-btn { background: var(--bg-panel); width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,.2); border: 1px solid var(--border-color); color: var(--text-main); text-decoration: none; }
        .live-side { display: grid; gap: 8px; justify-items: end; }
        .map-legend { background: var(--bg-panel); color: var(--text-main); border: 1px solid var(--border-color); border-radius: 16px; padding: 10px 12px; box-shadow: 0 8px 22px rgba(0,0,0,.18); font-size: .78rem; }
        .legend-row { display: flex; align-items: center; gap: 8px; white-space: nowrap; margin-bottom: 5px; }
        .legend-row:last-child { margin-bottom: 0; }
        .legend-dot { width: 11px; height: 11px; border-radius: 50%; display: inline-block; }
        .tracking-panel { background: var(--bg-panel); border-radius: 28px 28px 0 0; padding: 22px 18px; box-shadow: 0 -10px 40px rgba(0,0,0,.2); z-index: 100; backdrop-filter: blur(15px); }
        .driver-contact-card { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: var(--bg-panel); border: 1px solid var(--border-color); border-radius: 18px; padding: 12px 14px; margin-bottom: 16px; text-align: start; box-shadow: 0 8px 22px rgba(0,0,0,.08); }
        .driver-contact-meta { min-width: 0; }
        .driver-contact-label { color: var(--text-muted); font-size: .76rem; font-weight: 800; }
        .driver-contact-name { color: var(--text-main); font-weight: 900; overflow-wrap: anywhere; }
        .driver-call-btn { width: 42px; height: 42px; flex: 0 0 42px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: #22c55e; color: #fff; text-decoration: none; box-shadow: 0 8px 18px rgba(34,197,94,.28); }
        .step-icon { width: 38px; height: 38px; background: var(--step-inactive); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; color: #94a3b8; transition: .25s; }
        .step-item { min-width: 58px; }
        .step-item.active .step-icon { background: #3b82f6; color: white; transform: scale(1.08); }
        .step-item.completed .step-icon { background: #22c55e; color: white; }
        .arrival-toast { position: fixed; top: 80px; left: 50%; transform: translateX(-50%); background: #22c55e; color: white; padding: 10px 25px; border-radius: 50px; font-weight: 800; z-index: 2000; display: none; box-shadow: 0 5px 15px rgba(0,0,0,.3); }
        .leaflet-marker-icon { transition: transform .8s linear; }
        @media (max-width: 520px) {
            .top-nav { padding: 0 12px; }
            .tracking-panel { padding: 18px 10px; }
            .step-item span { font-size: .68rem; }
        }
    </style>
</head>
<body>
    <script>
        document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light');
    </script>

    <div id="arrivalToast" class="arrival-toast">
        <i class="fas fa-map-marker-alt me-2"></i><span data-i18n="arrived_toast">وصل السائق للموقع!</span>
    </div>

    <div class="top-nav">
        <a href="booking-history.php" class="circle-btn" aria-label="Back">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
        <div class="live-side">
            <div class="badge bg-danger rounded-pill px-3 py-2 shadow d-flex align-items-center gap-2">
                <span data-i18n="live_label">تتبع مباشر</span><i class="fas fa-circle small fa-beat"></i>
            </div>
            <div class="map-legend">
                <div class="legend-row"><span class="legend-dot" style="background:#3b82f6"></span><span data-i18n="legend_driver">السائق</span></div>
                <div class="legend-row"><span class="legend-dot" style="background:#ef4444"></span><span data-i18n="legend_customer">موقعك</span></div>
                <div class="legend-row"><span class="legend-dot" style="background:#16a34a"></span><span data-i18n="legend_station">المحطة</span></div>
            </div>
        </div>
    </div>

    <div id="map"></div>
    <?php if(clevo_current_role()==='customer'&&in_array($deliveryView['delivery_stage'],['arrived_pickup','returning_vehicle'],true)): ?>
    <button type="button" class="btn btn-primary rounded-pill mx-3" id="handoffCodeButton">رمز تأكيد الاستلام / التسليم — Handoff code</button><output id="handoffCode" class="text-center" aria-live="polite"></output>
    <?php endif; ?>

    <div class="tracking-panel text-center">
        <?php if (in_array($order['Status'],clevo_booking_active_states(),true) && !empty($order['DriverID']) && (!empty($order['driver_name']) || !empty($order['driver_phone']))): ?>
            <div class="driver-contact-card">
                <div class="driver-contact-meta">
                    <div class="driver-contact-label" data-i18n="driver_contact">التواصل مع الدرايفر</div>
                    <div class="driver-contact-name">
                        <?= e($order['driver_name'] ?: '-') ?>
                        <?php if (!empty($order['driver_phone'])): ?>
                            <span class="text-muted small d-block"><?= e($order['driver_phone']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($order['driver_phone'])): ?>
                    <a href="tel:<?= e($order['driver_phone']) ?>" class="driver-call-btn" aria-label="Call driver">
                        <i class="fas fa-phone"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between mb-4">
            <div class="step-item" id="step-Accepted"><div class="step-icon"><i class="fas fa-car"></i></div><span class="small fw-bold" data-i18n="step_pickup">تم القبول</span></div>
            <div class="step-item" id="step-In-Progress"><div class="step-icon"><i class="fas fa-route"></i></div><span class="small fw-bold" data-i18n="step_way">في الطريق</span></div>
            <div class="step-item" id="step-Washing"><div class="step-icon"><i class="fas fa-soap"></i></div><span class="small fw-bold" data-i18n="step_wash">جاري الغسيل</span></div>
            <div class="step-item" id="step-Returning"><div class="step-icon"><i class="fas fa-rotate-left"></i></div><span class="small fw-bold" data-i18n="step_return">راجع إليك</span></div>
            <div class="step-item" id="step-Completed"><div class="step-icon"><i class="fas fa-check"></i></div><span class="small fw-bold" data-i18n="step_done">تم الانتهاء</span></div>
        </div>
        <div id="status-alert" class="alert alert-primary py-2 small mb-0 fw-bold shadow-sm" data-i18n="loading_driver">جاري جلب موقع السائق...</div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const bookingID = <?= (int) $bookingID ?>;
        const lang = localStorage.getItem('app_lang') || 'ar';
        const customerPoint = {
            lat: <?= $order['Latitude'] !== null ? (float) $order['Latitude'] : 'null' ?>,
            lng: <?= $order['Longitude'] !== null ? (float) $order['Longitude'] : 'null' ?>
        };
        const stationPoint = {
            lat: <?= $order['station_lat'] !== null ? (float) $order['station_lat'] : 'null' ?>,
            lng: <?= $order['station_lon'] !== null ? (float) $order['station_lon'] : 'null' ?>
        };
        const fallbackPoint = isPoint(customerPoint) ? customerPoint : (isPoint(stationPoint) ? stationPoint : { lat: 31.985, lng: 35.898 });
        const labels = {
            ar: {
                tracking_title: 'تتبع الطلب', live_label: 'تتبع مباشر', step_pickup: 'تم القبول',
                step_way: 'في الطريق', step_wash: 'جاري الغسيل', step_return: 'راجع إليك',
                step_done: 'تم الانتهاء', legend_driver: 'السائق', legend_customer: 'موقعك',
                legend_station: 'المحطة', customer_popup: 'موقعك', station_popup: 'المحطة',
                returning: 'السائق في طريق العودة إليك', to_customer: 'السائق متجه إلى موقعك',
                to_station: 'السيارة في الطريق إلى المحطة', at_station: 'السيارة في المحطة للغسيل',
                completed: 'تم إنهاء الطلب', loading_driver: 'جاري جلب موقع السائق...',
                arrived_toast: 'وصل السائق للموقع!', driver_contact: 'التواصل مع الدرايفر'
            },
            en: {
                tracking_title: 'Order Tracking', live_label: 'Live tracking', step_pickup: 'Accepted',
                step_way: 'On the way', step_wash: 'Washing', step_return: 'Returning',
                step_done: 'Completed', legend_driver: 'Driver', legend_customer: 'Your location',
                legend_station: 'Station', customer_popup: 'Your location', station_popup: 'Station',
                returning: 'Driver is returning to you', to_customer: 'Driver is heading to your location',
                to_station: 'Car is heading to the station', at_station: 'Car is at the station for washing',
                completed: 'Order completed', loading_driver: 'Fetching driver location...',
                arrived_toast: 'Driver arrived!', driver_contact: 'Contact driver'
            }
        };
        const t = key => (labels[lang] && labels[lang][key]) || key;

        const map = L.map('map', { zoomControl: false }).setView([fallbackPoint.lat, fallbackPoint.lng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        const pointIcon = (color, icon) => L.divIcon({
            html: `<i class="fas ${icon}" style="color:${color};font-size:25px;text-shadow:0 0 10px white;"></i>`,
            className: 'c-icon',
            iconSize: [30, 30],
            iconAnchor: [15, 15]
        });
        const driverIcon = pointIcon('#3b82f6', 'fa-car-side');
        const customerIcon = pointIcon('#ef4444', 'fa-house');
        const stationIcon = pointIcon('#16a34a', 'fa-store');
        const bounds = [];

        if (isPoint(customerPoint)) {
            L.marker([customerPoint.lat, customerPoint.lng], { icon: customerIcon }).addTo(map).bindPopup(t('customer_popup'));
            bounds.push([customerPoint.lat, customerPoint.lng]);
        }
        if (isPoint(stationPoint)) {
            L.marker([stationPoint.lat, stationPoint.lng], { icon: stationIcon }).addTo(map).bindPopup(t('station_popup'));
            bounds.push([stationPoint.lat, stationPoint.lng]);
        }

        let driverMarker = null;
        let routeLine = L.polyline([], { color: '#2563eb', weight: 6, opacity: 0.86, lineCap: 'round', lineJoin: 'round' }).addTo(map);
        let lastRouteKey = '';
        let lastRoutePoints = [];
        if (bounds.length > 1) map.fitBounds(bounds, { padding: [42, 42] });

        function isPoint(point) {
            return point && typeof point.lat === 'number' && typeof point.lng === 'number' && Number.isFinite(point.lat) && Number.isFinite(point.lng) && Math.abs(point.lat)<=90 && Math.abs(point.lng)<=180;
        }

        function targetForStatus(status) {
            if (status === 'Pickup' || status === 'Washing') return stationPoint;
            if (['Accepted', 'Arrived', 'In Progress', 'Returning'].includes(status)) return customerPoint;
            return null;
        }

        function statusText(status) {
            if (status === 'Returning') return t('returning');
            if (status === 'Pickup') return t('to_station');
            if (status === 'Washing') return t('at_station');
            if (status === 'Completed') return t('completed');
            return t('to_customer');
        }

        function routeKey(a, b) {
            return [a.lng, a.lat, b.lng, b.lat].map(v => Number(v).toFixed(4)).join(',');
        }

        function nearestRoutePoint(point, routePoints) {
            if (!routePoints.length) return point;
            let best = routePoints[0];
            let bestDistance = Infinity;
            routePoints.forEach(candidate => {
                const distance = Math.hypot(candidate.lat - point.lat, candidate.lng - point.lng);
                if (distance < bestDistance) {
                    bestDistance = distance;
                    best = candidate;
                }
            });
            return best;
        }

        async function buildRoadRoute(driverPoint, target) {
            // An indicative straight line only. No private coordinates sent to a public routing demo.
            return [driverPoint,target];
        }

        function updateRoute(driverPoint,status){
            if(!isPoint(driverPoint)){clearDriver();return;}
            if(!driverMarker)driverMarker=L.marker([driverPoint.lat,driverPoint.lng],{icon:driverIcon}).addTo(map);
            driverMarker.setLatLng([driverPoint.lat,driverPoint.lng]);
            document.getElementById('status-alert').innerText=statusText(status);
            routeLine.setLatLngs([]); // Actual GPS only; no simulated route or ETA.
        }

        let trackingTimer=null, trackingBusy=false;
        const clearDriver=()=>{if(driverMarker){map.removeLayer(driverMarker);driverMarker=null;}routeLine.setLatLngs([]);};
        function updateTracking() {
            if(document.hidden||trackingBusy)return;
            trackingBusy=true;
            fetch(`get_order_status.php?id=${bookingID}`, { cache: 'no-store' })
                .then(res => {if(!res.ok)throw Error('unavailable');return res.json();})
                .then(data => {
                    if (data.status !== 'success')throw Error('unavailable');
                    const payload=data.data, tracking=payload.tracking;
                    updateUI(payload.orderStatus);
                    if(tracking.location && tracking.location_state==='fresh'){
                        const point={lat:tracking.location.latitude,lng:tracking.location.longitude};
                        updateRoute(point,payload.orderStatus);
                    }else{
                        clearDriver();document.getElementById('status-alert').innerText=lang==='ar'?'موقع السائق غير متاح أو قديم':'Driver location unavailable or stale';
                    }
                    if(!tracking.sharing_allowed)clearDriver();
                    if(['Completed','Cancelled','Cancelled_Timeout','Rejected'].includes(payload.orderStatus)){clearInterval(trackingTimer);clearDriver();document.getElementById('handoffCode')?.replaceChildren();}
                    if (payload.hasArrived && !sessionStorage.getItem(`notified_arrival_${bookingID}`)) {showArrivalNotification();sessionStorage.setItem(`notified_arrival_${bookingID}`,'true');}
                }).catch(()=>{clearDriver();document.getElementById('status-alert').innerText=lang==='ar'?'انقطع الاتصال بالتتبع':'Tracking connection lost';})
                .finally(()=>{trackingBusy=false;});
        }
        const handoffButton=document.getElementById('handoffCodeButton');
        handoffButton?.addEventListener('click',async()=>{
            handoffButton.disabled=true;
            const key=crypto.getRandomValues(new Uint32Array(6)).join('');
            try{
                const response=await fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'issue_confirmation',csrf_token:<?= clevo_js(generate_csrf_token()) ?>,idempotency_key:key,kind:<?= clevo_js($deliveryView['delivery_stage']==='arrived_pickup'?'pickup':'delivery') ?>})});
                const result=await response.json();if(!response.ok||result.status!=='success')throw Error();
                document.getElementById('handoffCode').textContent=result.data.confirmation_code;
                setTimeout(()=>document.getElementById('handoffCode')?.replaceChildren(),600000);
            }catch{document.getElementById('handoffCode').textContent=lang==='ar'?'تعذر إصدار الرمز':'Code unavailable';handoffButton.disabled=false;}
        });
        window.addEventListener('pagehide',()=>{clearInterval(trackingTimer);clearDriver();document.getElementById('handoffCode')?.replaceChildren();});
        document.addEventListener('visibilitychange',()=>{if(document.hidden){clearDriver();document.getElementById('handoffCode')?.replaceChildren();}else updateTracking();});

        function showArrivalNotification() {
            const toast = document.getElementById('arrivalToast');
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 5000);
        }

        function updateUI(status) {
            document.querySelectorAll('.step-item').forEach(el => el.classList.remove('active', 'completed'));
            const visualStatus = status === 'Pickup' ? 'Washing' : status;
            const currentStep = document.getElementById('step-' + visualStatus.replace(' ', '-'));
            if (currentStep) currentStep.classList.add('active');
            const steps = ['Accepted', 'In Progress', 'Washing', 'Returning', 'Completed'];
            let found = false;
            steps.forEach(step => {
                if (step === visualStatus) found = true;
                if (!found) {
                    const el = document.getElementById('step-' + step.replace(' ', '-'));
                    if (el) el.classList.add('completed');
                }
            });
        }

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
        document.title = `${t('tracking_title')} | Clevo`;
        updateTracking();
        trackingTimer=setInterval(updateTracking, 10000);
    </script>
</body>
</html>
