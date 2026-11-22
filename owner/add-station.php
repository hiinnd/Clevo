<?php

include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';

$owner_id = clevo_require_role('owner', '../general/login.php');
$existingStation = null;
if ($owner_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM stations WHERE UserID = ? ORDER BY StationID LIMIT 1");
    $stmt->bind_param("i", $owner_id);
    $stmt->execute();
    $existingStation = $stmt->get_result()->fetch_assoc();
}


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_save'])) {
    clevo_require_csrf_token(true);
    $name = clevo_clean_string($_POST['stName'] ?? '', 100);
    $location = clevo_clean_string($_POST['stLocation'] ?? '', 255);
    $lat = trim($_POST['stLat'] ?? '') !== '' ? clevo_clean_string($_POST['stLat'], 30) : ($existingStation['Lat'] ?? '');
    $lon = trim($_POST['stLon'] ?? '') !== '' ? clevo_clean_string($_POST['stLon'], 30) : ($existingStation['Lon'] ?? '');
    $location_url = trim($_POST['stLocationUrl'] ?? '') !== '' ? clevo_clean_string($_POST['stLocationUrl'] ?? '', 255) : ($existingStation['Location_URL'] ?? '');
    $open_time = $_POST['openTime'] ?? '';
    $close_time = $_POST['closeTime'] ?? '';

    if ($name === '' || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $open_time) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?$/D', $close_time)) throw new ClevoSecurityException('invalid_station', 422);
    if ($lat !== '' && (filter_var($lat, FILTER_VALIDATE_FLOAT) === false || abs((float) $lat) > 90)) throw new ClevoSecurityException('invalid_location', 422);
    if ($lon !== '' && (filter_var($lon, FILTER_VALIDATE_FLOAT) === false || abs((float) $lon) > 180)) throw new ClevoSecurityException('invalid_location', 422);
    if ($location_url !== '' && (!filter_var($location_url, FILTER_VALIDATE_URL) || !in_array(parse_url($location_url, PHP_URL_SCHEME), ['http','https'], true))) throw new ClevoSecurityException('invalid_url', 422);
    $image_name = $existingStation['Image'] ?? 'default_station.png';
    if (isset($_FILES['stImage']) && $_FILES['stImage']['error'] == 0) {
        $image_name = clevo_save_upload($_FILES['stImage'], 'stations');
    }

    if ($existingStation) {
        $update = $conn->prepare("UPDATE stations SET Name = ?, Location = ?, Location_URL = ?, Lat = ?, Lon = ?, OpeningTime = ?, ClosingTime = ?, Image = ? WHERE StationID = ? AND UserID = ?");
        $update->bind_param("ssssssssii", $name, $location, $location_url, $lat, $lon, $open_time, $close_time, $image_name, $existingStation['StationID'], $owner_id);
        $success = $update->execute();
    } else {
        $success = false;
        $conn->begin_transaction();
        try {
            $insert = $conn->prepare("INSERT INTO stations (UserID, Name, Location, Location_URL, Lat, Lon, OpeningTime, ClosingTime, Image, Status, admin_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Closed', 'pending')");
            $insert->bind_param("issssssss", $owner_id, $name, $location, $location_url, $lat, $lon, $open_time, $close_time, $image_name);
            $insert->execute();
            $station_id = (int) $conn->insert_id;
            $link = $conn->prepare("UPDATE `user` SET StationID = ?, AccountStatus = 'pending' WHERE UserID = ?");
            $link->bind_param("ii", $station_id, $owner_id);
            $link->execute();
            clevoCreateRoleNotification(
                $conn,
                'Admin',
                'طلب تسجيل محطة جديدة',
                'تم تقديم طلب محطة جديدة: ' . $name . '، يرجى مراجعتها.',
                'warning',
                '../admin/station-approvals.php',
                'New station registration request',
                'A new station request was submitted: ' . $name . '. Please review it.'
            );
            $conn->commit();
            $success = true;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('CLEVO operation failed.');
        }
    }

    if ($success) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'request_failed']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="add_st_page_title">إعداد المغسلة | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --accent: #007bff; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #007bff;
            --border-color: #dee2e6; --input-bg: #ffffff; --text-muted-custom: #4b5563;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #0f172a; --text-main: #ffffff !important; --text-muted: #cbd5e1 !important; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155; --input-bg: #1e293b; --text-muted-custom: #cbd5e1;
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-wrapper { display: flex; min-height: 100vh; }


        .sidebar { width: 260px; background: var(--bg-sidebar); padding: 30px 15px; position: sticky; top: 0; min-height: 100vh; display: flex; flex-direction: column; transition: all 0.3s ease; }
        .nav-link { color: rgba(255,255,255,0.8) !important; padding: 12px; border-radius: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; transition: 0.2s; font-size: 0.95rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent); color: white !important; }
        .btn-ctrl { width: 35px; height: 35px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); color: white; border: 0; display: inline-flex; align-items: center; justify-content: center; }


        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-back-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; }
        
        /* Content Area Setup */
        .content-area { flex: 1; padding: 0; }
        .form-container { background: var(--bg-card); border-radius: 30px; padding: 25px; border: 1px solid var(--border-color); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05); }
        .form-control { border-radius: 12px; background-color: var(--input-bg) !important; border: 1px solid var(--border-color) !important; color: var(--text-main) !important; padding: 12px; font-weight: 600; }
        .form-control::placeholder { color: var(--text-muted) !important; opacity: 0.8 !important; }
        label { color: var(--text-main) !important; font-weight: 800; margin-bottom: 8px; display: block; }
        
        .custom-file-upload { border: 2px dashed var(--border-color); display: inline-block; padding: 30px; cursor: pointer; width: 100%; text-align: center; border-radius: 20px; background: var(--input-bg); transition: 0.3s; }
        .custom-file-upload:hover { border-color: var(--primary-blue); background: rgba(0, 123, 255, 0.05); }

        .modal-content { background-color: var(--bg-card) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); border-radius: 30px !important; }
        #map-container { width: 100%; height: 350px; border-radius: 15px; overflow: hidden; margin-bottom: 15px; border: 1px solid var(--border-color); }
        #stationMap { width: 100%; height: 100%; z-index: 1; }
        [data-theme="dark"] #stationMap { filter: brightness(0.7) invert(1) contrast(2.4) hue-rotate(190deg) saturate(0.45); }
        .map-help-text { color: var(--text-muted-custom); font-size: .78rem; font-weight: 700; margin: -6px 0 12px; }

        @media (max-width: 768px) {
            .main-wrapper { display: block; }
            .sidebar { width: 100%; height: auto; min-height: auto; position: relative; border-radius: 0 0 20px 20px; padding: 15px; }
            .sidebar .nav { flex-direction: row !important; overflow-x: auto; white-space: nowrap; gap: 5px; }
            .sidebar h4 { display: none; }
        }
    </style>
</head>

<body>
    <script>
        document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light');
    </script>

    <div class="main-wrapper">
        <?php include __DIR__ . '/../includes/shared_owner_sidebar.php'; ?>

        <div class="content-area">
            <div class="orders-header">
                <div class="container d-flex justify-content-between align-items-center">
                    <a href="owner-dashboard.php" class="circle-back-btn shadow-sm">
                        <i class="fas fa-arrow-right" id="backIcon"></i>
                    </a>
                    <h6 class="mb-0 fw-bold" data-i18n="add_st_title">إعداد بيانات المغسلة</h6>
                    <div style="width: 40px;"></div>
                </div>
            </div>

            <div class="container mt-4 px-4">
                <div class="mx-auto" style="max-width: 800px; margin-bottom: 50px;">
                    <div class="form-container shadow-sm">
                        <form id="addStationForm" enctype="multipart/form-data">
                            <?= csrf_input(); ?>
                            <input type="hidden" name="stLat" id="stLat" value="<?= htmlspecialchars($existingStation['Lat'] ?? '') ?>">
                            <input type="hidden" name="stLon" id="stLon" value="<?= htmlspecialchars($existingStation['Lon'] ?? '') ?>">
                            <input type="hidden" name="stLocationUrl" id="stLocationUrl" value="<?= htmlspecialchars($existingStation['Location_URL'] ?? '') ?>">
                            
                            <div class="mb-3 text-start">
                                <label data-i18n="label_name">اسم المغسلة التجاري</label>
                                <input type="text" name="stName" id="stName" class="form-control" data-i18n="[placeholder]placeholder_name" value="<?= htmlspecialchars($existingStation['Name'] ?? '') ?>">
                            </div>

                            <div class="mb-3 text-start">
                                <label data-i18n="label_location">الموقع الجغرافي (الشارع)</label>
                                <div class="input-group">
                                    <input type="text" name="stLocation" id="stLocation" class="form-control" data-i18n="[placeholder]placeholder_location" value="<?= htmlspecialchars($existingStation['Location'] ?? '') ?>" readonly>
                                    <button type="button" class="btn btn-primary px-3" onclick="openMapModal()"><i class="fas fa-map-marked-alt"></i></button>
                                </div>
                            </div>

                            <div class="row mb-3 text-start">
                                <div class="col-6"><label data-i18n="label_open_time">من ساعة</label><input type="time" name="openTime" id="openTime" class="form-control" value="<?= htmlspecialchars($existingStation['OpeningTime'] ? substr($existingStation['OpeningTime'],0,5) : '08:00') ?>"></div>
                                <div class="col-6"><label data-i18n="label_close_time">إلى ساعة</label><input type="time" name="closeTime" id="closeTime" class="form-control" value="<?= htmlspecialchars($existingStation['ClosingTime'] ? substr($existingStation['ClosingTime'],0,5) : '22:00') ?>"></div>
                            </div>

                            <div class="mb-4 text-start">
                                <label data-i18n="label_image">صورة الواجهة</label>
                                <label for="stImage" class="custom-file-upload">
                                    <i class="fas fa-cloud-upload-alt fa-3x text-primary mb-3"></i>
                                    <p class="mb-0 fw-bold" id="fileName" data-i18n="file_upload_hint">اسحب الصورة أو اضغط هنا للاختيار</p>
                                    <input type="file" name="stImage" id="stImage" accept="image/*" hidden onchange="setStationImageName(this)">
                                </label>
                            </div>

                            <button type="button" class="btn btn-primary w-100 rounded-pill py-3 shadow-lg fw-bold" onclick="saveData()" data-i18n="save_btn">حفظ البيانات ونشر المغسلة</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="mapModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-4">
                <h6 class="fw-bold mb-3" data-i18n="label_location">تحديد موقع المغسلة</h6>
                <div id="map-container">
                    <div id="stationMap"></div>
                </div>
                <p class="map-help-text" data-i18n="map_help">حرّك العلامة أو اضغط على الخريطة لتعديل موقع المحطة.</p>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary rounded-pill fw-bold" onclick="useCurrentLocation()">
                        <i class="fas fa-location-crosshairs"></i>
                    </button>
                    <button type="button" class="btn btn-success rounded-pill fw-bold flex-fill" onclick="confirmMapLocation()" data-i18n="confirm_location_btn">تأكيد الموقع الحالي</button>
                    <button type="button" class="btn btn-light border rounded-pill fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="modal-body">
                    <div class="text-danger mb-3"><i class="fas fa-sign-out-alt fa-4x"></i></div>
                    <h5 class="fw-bold mb-2" data-i18n="logout_title">تأكيد الخروج</h5>
                    <p class="text-muted-custom small mb-4" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
                    <div class="d-grid gap-2">
                        <form method="POST" action="../general/logout.php" class="m-0"><?= csrf_input(); ?><button type="submit"  class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</button></form>
                        <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content p-4 text-center border-0 shadow-lg"><div id="modalIcon" class="mb-3"></div><h5 id="modalTitle" class="fw-bold mb-2"></h5><p id="modalBody" class="small text-muted-custom mb-3"></p><button class="btn btn-primary w-100 rounded-pill py-2" data-bs-dismiss="modal">موافق</button></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const translations = {
            ar: {
                nav_main: "الرئيسية", nav_orders: "الطلبات", add_st_page_title: "إعداد المغسلة", services_list: "الخدمات", drivers_page_title: "طاقم العمل", recent_reviews: "التقييمات", serv_wallet: "المحفظة", nav_notifications: "الإشعارات", nav_logout: "خروج",
                add_st_title: "إعداد بيانات المغسلة", label_name: "اسم المغسلة التجاري", label_location: "الموقع الجغرافي", label_open_time: "من ساعة", label_close_time: "إلى ساعة", label_image: "صورة الواجهة", file_upload_hint: "اسحب الصورة أو اضغط هنا للاختيار", save_btn: "حفظ البيانات ونشر المغسلة",
                placeholder_name: "أدخل اسم المغسلة هنا", placeholder_location: "اضغط لاختيار وتأكيد موقعك على الخريطة", status_active_now: "نشط الآن", status_closed_now: "مغلق الآن",
                map_help: "حرّك العلامة أو اضغط على الخريطة لتعديل موقع المحطة.", confirm_location_btn: "تأكيد الموقع الحالي", locating: "جاري تحديد موقعك...", location_fallback: "تم تحديد الموقع الجغرافي بنجاح",
                location_denied: "لم يتم السماح بالموقع، حرّك العلامة يدوياً على الخريطة.",
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء", success_t: "تم بنجاح", success_b: "تم حفظ بيانات المغسلة."
            },
            en: {
                nav_main: "Dashboard", nav_orders: "Orders", add_st_page_title: "Station Setup", services_list: "Services", drivers_page_title: "Team", recent_reviews: "Reviews", serv_wallet: "Wallet", nav_notifications: "Notifications", nav_logout: "Logout",
                add_st_title: "Station Setup", label_name: "Station Name", label_location: "Location", label_open_time: "Open", label_close_time: "Close", label_image: "Cover Image", file_upload_hint: "Drag an image or click here to choose", save_btn: "Save & Publish",
                placeholder_name: "Enter station name", placeholder_location: "Click to select and confirm your map location", status_active_now: "Active Now", status_closed_now: "Closed Now",
                map_help: "Move the pin or tap the map to adjust the station location.", confirm_location_btn: "Confirm current location", locating: "Getting your location...", location_fallback: "Location selected successfully",
                location_denied: "Location was not allowed. Move the pin manually on the map.",
                logout_title: "Logout Confirmation", logout_msg: "Confirm logout?", btn_confirm_logout: "Yes", btn_cancel: "Cancel", success_t: "Success", success_b: "Data saved."
            }
        };

        let tempLat = <?= is_numeric($existingStation['Lat'] ?? null) ? (float) $existingStation['Lat'] : '31.9522' ?>;
        let tempLon = <?= is_numeric($existingStation['Lon'] ?? null) ? (float) $existingStation['Lon'] : '35.9114' ?>;
        let stationMap = null;
        let stationMarker = null;
        let mapModalInstance = null;

        function openMapModal() {
            mapModalInstance = bootstrap.Modal.getOrCreateInstance(document.getElementById('mapModal'));
            mapModalInstance.show();
            setTimeout(() => {
                initStationMap();
                if (!document.getElementById('stLat').value && !document.getElementById('stLon').value) {
                    useCurrentLocation(false);
                }
            }, 180);
        }

        function initStationMap() {
            if (typeof L === 'undefined') return;
            const savedLat = parseFloat(document.getElementById('stLat').value);
            const savedLon = parseFloat(document.getElementById('stLon').value);
            if (Number.isFinite(savedLat) && Number.isFinite(savedLon)) {
                tempLat = savedLat;
                tempLon = savedLon;
            }
            if (!stationMap) {
                stationMap = L.map('stationMap', { zoomControl: false }).setView([tempLat, tempLon], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(stationMap);
                stationMarker = L.marker([tempLat, tempLon], { draggable: true }).addTo(stationMap);
                stationMarker.on('dragend', () => {
                    const pos = stationMarker.getLatLng();
                    setTempLocation(pos.lat, pos.lng);
                });
                stationMap.on('click', (event) => setTempLocation(event.latlng.lat, event.latlng.lng));
            }
            setTempLocation(tempLat, tempLon);
            setTimeout(() => stationMap.invalidateSize(), 80);
        }

        function setTempLocation(lat, lon) {
            tempLat = lat;
            tempLon = lon;
            if (stationMarker) stationMarker.setLatLng([lat, lon]);
            if (stationMap) stationMap.setView([lat, lon], Math.max(stationMap.getZoom(), 16));
        }

        function useCurrentLocation(showStatus = true) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            if (showStatus) document.getElementById('stLocation').value = translations[lang].locating;
            if (!navigator.geolocation) return;
            navigator.geolocation.getCurrentPosition((pos) => {
                setTempLocation(pos.coords.latitude, pos.coords.longitude);
            }, () => {
                if (showStatus) document.getElementById('stLocation').value = translations[lang].location_denied;
            });
        }

        function confirmMapLocation() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            document.getElementById('stLat').value = tempLat;
            document.getElementById('stLon').value = tempLon;
            document.getElementById('stLocationUrl').value = `https://www.google.com/maps?q=${tempLat},${tempLon}`;
            
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${tempLat}&lon=${tempLon}&accept-language=${lang}`)
            .then(res => res.json()).then(data => {
                const address = data.address || {};
                const street = address.road || address.neighbourhood || address.suburb || address.city || translations[lang].location_fallback;
                document.getElementById('stLocation').value = street;
            }).catch(() => {
                document.getElementById('stLocation').value = `${tempLat.toFixed(4)}, ${tempLon.toFixed(4)}`;
            });

            const modal = bootstrap.Modal.getInstance(document.getElementById('mapModal'));
            if (modal) modal.hide();
        }

        function saveData() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const formData = new FormData(document.getElementById('addStationForm'));
            formData.append('ajax_save', '1');
            fetch(window.location.href, { method: 'POST', body: formData })
            .then(res => res.json()).then(data => {
                if(data.status === 'success') {
                    document.getElementById('modalIcon').innerHTML = '<i class="fas fa-check-circle text-success fa-4x"></i>';
                    document.getElementById('modalTitle').innerText = translations[lang].success_t;
                    document.getElementById('modalBody').innerText = translations[lang].success_b;
                    new bootstrap.Modal(document.getElementById('statusModal')).show();
                }
            });
        }

        function confirmLogout() { new bootstrap.Modal(document.getElementById('logoutModal')).show(); }
        function toggleLanguage() { localStorage.setItem('app_lang', (localStorage.getItem('app_lang') || 'ar') === 'ar' ? 'en' : 'ar'); location.reload(); }
        function toggleGlobalTheme() { localStorage.setItem('global_theme', document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); location.reload(); }
        function setStationImageName(input) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            document.getElementById('fileName').innerText = input.files && input.files[0]
                ? input.files[0].name
                : translations[lang].file_upload_hint;
        }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.getElementById('bootstrap-css').href = lang === 'ar'
                ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css"
                : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (key.includes('[placeholder]')) {
                    const cleanKey = key.replace('[placeholder]', '');
                    if (translations[lang][cleanKey]) el.placeholder = translations[lang][cleanKey];
                } else if (translations[lang][key]) {
                    el.innerText = translations[lang][key];
                }
            });
            document.title = translations[lang].add_st_page_title + ' | Clevo';
            if(lang === 'en') document.getElementById('backIcon').className = "fas fa-arrow-left";
            document.getElementById('langBtnText').innerText = lang === 'ar' ? 'EN' : 'AR';
            document.getElementById('themeIcon').className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        };
    </script>
</body>
</html>
