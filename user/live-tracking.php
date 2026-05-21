<?php
session_start();
include __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/app-helpers.php';

clevoEnsureTrackingColumns($conn);

// الحصول على معرف الحجز من الرابط
$bookingID = (int) ($_GET['id'] ?? 0);
$viewerID = clevo_current_user_id();
if ($viewerID <= 0) {
    header("Location: ../general/login.php");
    exit;
}

// جلب بيانات الحجز الأولية (السائق، الزبون، حالة الطلب)
$stmt = $conn->prepare("SELECT b.*, u.UserName as customer_name, d.UserName as driver_name, d.ProfilePic as driver_pic, d.PhoneNumber as driver_phone
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

        .arrival-toast {
            position: fixed; top: 80px; left: 50%; transform: translateX(-50%);
            background: #22c55e; color: white; padding: 10px 25px; border-radius: 50px;
            font-weight: bold; z-index: 2000; display: none; box-shadow: 0 5px 15px rgba(0,0,0,0.3);
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

    <div id="map"></div>

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
            <div class="step-item" id="step-Completed">
                <div class="step-icon"><i class="fas fa-check"></i></div>
                <span class="small fw-bold" data-i18n="step_done">تم الانتهاء</span>
            </div>
        </div>

        <div id="status-alert" class="alert alert-primary py-2 small mb-3 fw-bold shadow-sm">جاري جلب موقع السائق...</div>

        <!-- بطاقة بيانات السائق -->
        <div class="worker-card mb-2">
            <div class="d-flex align-items-center">
                <img src="<?= e(clevoProfileImageUrl($order['driver_pic'] ?? '', $order['driver_name'] ?? 'Driver')) ?>" class="rounded-circle" style="width: 45px; height: 45px; object-fit: cover;">
                <div class="mx-2 text-start">
                    <h6 class="mb-0 small fw-bold"><?= e($order['driver_name'] ?? '-') ?></h6>
                    <small class="text-muted fw-bold" data-i18n="captain_title">كابتن Clevo المعتمد</small>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="tel:<?= e($order['driver_phone'] ?? '') ?>" class="btn btn-sm btn-primary rounded-pill"><i class="fas fa-phone"></i></a>
                <a href="https://wa.me/<?= e($order['driver_phone'] ?? '') ?>" class="btn btn-sm btn-success rounded-pill"><i class="fab fa-whatsapp"></i></a>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const bookingID = <?= (int)$bookingID ?>;
        const customerLat = <?= $order['Latitude'] ?: '31.985' ?>;
        const customerLng = <?= $order['Longitude'] ?: '35.898' ?>;

        const map = L.map('map', { zoomControl: false }).setView([customerLat, customerLng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        L.marker([customerLat, customerLng]).addTo(map).bindPopup("موقع الخدمة");

        const driverMarker = L.marker([customerLat, customerLng], {
            icon: L.divIcon({
                html: `<i class="fas fa-car-side" style="color:#3b82f6;font-size:26px;text-shadow: 0 0 10px white;"></i>`,
                className: 'c-icon'
            })
        }).addTo(map);

        // وظيفة جلب البيانات اللحظية (تحديث كل 10 ثوانٍ)
        function updateTracking() {
            fetch(`get_order_status.php?id=${bookingID}`, { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if(data.status === 'success') {
                    const payload = data.data || {};
                    const newPos = [payload.lat, payload.lng];
                    driverMarker.setLatLng(newPos);
                    
                    updateUI(payload.orderStatus);

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
            
            let steps = ['Accepted', 'In Progress', 'Washing', 'Completed'];
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
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>

