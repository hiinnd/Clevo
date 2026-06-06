<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="loc_page_title">تحديد الموقع | Wash Go</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" />
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <script>
        // Function to calculate distance between two points (Haversine formula)
        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371; // Radius of the Earth in km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon/2) * Math.sin(dLon/2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
            const distance = R * c; // Distance in km
            return distance;
        }

        // Function to estimate ETA (assuming average speed of 30 km/h for car wash service)
        function estimateETA(distance) {
            const speed = 30; // km/h
            const time = distance / speed; // hours
            return Math.round(time * 60); // minutes
        }

        // Function to find nearest station
        function findNearestStation(userLat, userLon, stations) {
            let nearest = null;
            let minDistance = Infinity;
            stations.forEach(station => {
                const dist = calculateDistance(userLat, userLon, station.lat, station.lon);
                if (dist < minDistance) {
                    minDistance = dist;
                    nearest = { ...station, distance: dist, eta: estimateETA(dist) };
                }
            });
            return nearest;
        }
    </script>

    <style>
        :root { 
            --bg-header: rgba(255, 255, 255, 0.95); --text-main: #111827; 
            --box-bg: rgba(241, 243, 245, 0.9); --border-color: #dee2e6; 
        }
        [data-theme="dark"] { 
            --bg-header: rgba(30, 41, 59, 0.95); --text-main: #ffffff; 
            --box-bg: rgba(15, 23, 42, 0.8); --border-color: #334155; 
        }
        
        body { 
            font-family: 'Cairo', sans-serif; 
            background: url('../assets/Images/background.png') no-repeat center center fixed;
            background-size: cover; color: var(--text-main); margin: 0; padding: 0;
            height: 100vh; overflow: hidden;
        }


        .map-header { 
            background: var(--bg-header); padding: 12px 20px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1); 
            position: fixed; top: 0; width: 100%; z-index: 2000; border-bottom: 1px solid var(--border-color); 
            backdrop-filter: blur(15px); display: flex; align-items: center; justify-content: space-between;
        }
        
        .ctrl-btn-circle { 
            background: var(--box-bg); border: 1px solid var(--border-color); color: var(--text-main); 
            width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            text-decoration: none; transition: 0.3s;
        }
        [dir="ltr"] .map-header { flex-direction: row-reverse; }


        #map-container { position: relative; width: 100%; height: 100vh; z-index: 1; }
        #map { width: 100%; height: 100%; }


        .locate-me-btn { 
            position: absolute; bottom: 240px; z-index: 1500; 
            background: var(--bg-header); width: 48px; height: 48px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; color: #3b82f6; 
            border: 2px solid #3b82f6; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); cursor: pointer; 
            backdrop-filter: blur(10px);
        }
        [dir="rtl"] .locate-me-btn { left: 20px; }
        [dir="ltr"] .locate-me-btn { right: 20px; }


        .leaflet-control-geocoder { 
            margin-top: 80px !important; z-index: 1000 !important;
            background: var(--bg-header) !important; border-radius: 12px !important;
            border: 1px solid var(--border-color) !important; width: 280px !important;
        }


        .location-footer { 
            position: fixed; bottom: 0; left: 0; right: 0; background: var(--bg-header); 
            padding: 20px; border-radius: 30px 30px 0 0; box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.2); 
            z-index: 2000; border-top: 1px solid var(--border-color); backdrop-filter: blur(20px);
        }
        .address-box { background: var(--box-bg); padding: 12px; border-radius: 15px; display: flex; align-items: center; margin-bottom: 15px; }
        .btn-confirm { border-radius: 50px; padding: 15px; font-weight: 900; background: #3b82f6; border: none; color: white; width: 100%; }

        .user-location-dot { background-color: #3b82f6; border-radius: 50%; border: 2px solid white; }
        .pulse { display: block; width: 12px; height: 12px; border-radius: 50%; background: #3b82f6; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.7); } 70% { box-shadow: 0 0 0 15px rgba(59, 130, 246, 0); } 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); } }
    </style>
</head>

<body>
    <div class="map-header">
        <a href="../user/home.php" class="ctrl-btn-circle"><i class="fas fa-arrow-right" id="backIcon"></i></a>
        <h6 class="mb-0 fw-bold" data-i18n="loc_page_title">تحديد الموقع</h6>
        <div style="width: 38px;"></div>
    </div>

    <div id="map-container">
        <div id="map"></div>
        <div class="locate-me-btn shadow" onclick="detectUserLocation(true)">
            <i class="fas fa-crosshairs fs-4"></i>
        </div>
    </div>

    <div class="location-footer">
        <div class="container p-0">
            <h6 class="mb-2 small fw-bold" data-i18n="address_label">العنوان المختار</h6>
            <div class="address-box shadow-sm">
                <i class="fas fa-location-dot text-primary mx-2 fs-5"></i>
                <span id="address-text" class="small fw-bold">...</span>
            </div>
            
            <div class="alert py-2 mb-3 border-0 rounded-4 small d-flex align-items-center" style="background: rgba(59, 130, 246, 0.1); color: var(--text-main);">
                <i class="fas fa-credit-card mx-2"></i>
                <span data-i18n="payment_alert">ملاحظة: الخدمة تتطلب دفعاً إلكترونياً بعد الموافقة.</span>
            </div>

            <button onclick="confirmLocation()" class="btn btn-primary btn-confirm shadow">
                <span data-i18n="btn_confirm_loc">تأكيد الموقع والمتابعة</span> <i class="fas fa-circle-check ms-2"></i>
            </button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>
    <script src="../assets/translations.js"></script>

    <script>
        const lang = localStorage.getItem('app_lang') || 'ar';
        const user = localStorage.getItem('userName') || "صديقنا";


        const map = L.map('map', { zoomControl: false }).setView([31.9539, 35.9106], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

        // Geocoder
        const geocoder = L.Control.geocoder({
            defaultMarkGeocode: true,
            placeholder: lang === 'ar' ? "ابحث عن شارع..." : "Search street...",
        }).on('markgeocode', function(e) {
            marker.setLatLng(e.geocode.center);
            map.setView(e.geocode.center, 17);
            fetchAddress(e.geocode.center.lat, e.geocode.center.lng);
        }).addTo(map);

        let marker = L.marker([31.9539, 35.9106], { draggable: true }).addTo(map);
        let userLocationMarker = null;

        window.onload = () => {
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
            document.getElementById('backIcon').className = lang === 'ar' ? 'fas fa-arrow-right' : 'fas fa-arrow-left';
            if (typeof applyTranslations === 'function') applyTranslations(lang);
            

            setTimeout(() => { map.invalidateSize(); }, 500);
            detectUserLocation(false);
        };

        function detectUserLocation(shouldMoveMarker = false) {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition((position) => {
                    const lat = position.coords.latitude, lng = position.coords.longitude;
                    const pulseIcon = L.divIcon({ className: 'user-location-dot', html: '<span class="pulse"></span>', iconSize: [12, 12] });
                    if (userLocationMarker) map.removeLayer(userLocationMarker);
                    userLocationMarker = L.marker([lat, lng], { icon: pulseIcon }).addTo(map);
                    if (shouldMoveMarker) {
                        map.setView([lat, lng], 17);
                        marker.setLatLng([lat, lng]);
                        fetchAddress(lat, lng);
                    }
                }, null, { enableHighAccuracy: true });
            }
        }

        function fetchAddress(lat, lng) {
            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}&accept-language=${lang}`)
                .then(res => res.json())
                .then(data => {
                    document.getElementById('address-text').innerText = data.display_name.split(',').slice(0, 3).join(',') || "---";
                });
        }

        marker.on('dragend', function() {
            const pos = marker.getLatLng();
            fetchAddress(pos.lat, pos.lng);
        });

        function confirmLocation() {
            const selected = marker.getLatLng();
            const mapsUrl = `https://www.google.com/maps?q=${selected.lat},${selected.lng}`;
            localStorage.setItem('selected_lat', selected.lat);
            localStorage.setItem('selected_lng', selected.lng);
            localStorage.setItem('selected_google_maps_url', mapsUrl);

            const body = new URLSearchParams();
            body.append('latitude', selected.lat);
            body.append('longitude', selected.lng);
            body.append('address', document.getElementById('address-text').innerText || '');
            body.append('google_maps_url', mapsUrl);

            fetch('../user/save-location.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).finally(() => {
                location.href = '../user/home.php';
            });
        }
    </script>
</body>
</html>
