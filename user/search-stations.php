<?php
session_start();
include(__DIR__ . "/../includes/config.php");
include(__DIR__ . "/../includes/app-helpers.php");

if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit();
}

// التصحيح المعتمد: فحص حالة التفعيل 'Active' وحالة موافقة الأدمن 'approved' معاً
$query = "SELECT s.*, (SELECT MIN(Price) FROM services WHERE services.StationID = s.StationID) as min_price 
          FROM stations s 
          WHERE s.admin_status = 'approved'";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="serv_book">البحث عن محطة | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root { 
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.85);
            --text: #0369a1; 
            --text-muted: #475569;
            --accent: #0ea5e9; 
            --border: rgba(3, 105, 161, 0.15);
        }
        [data-theme="dark"] { 
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.75);
            --text: #f8fafc; 
            --text-muted: #cbd5e1;
            --accent: #38bdf8; 
            --border: rgba(255, 255, 255, 0.1);
        }
        
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 20px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; }

        .search-header { 
            padding: 20px; 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            position: relative; 
            height: 140px; 
            border-radius: 0 0 40px 40px; 
            background: linear-gradient(135deg, var(--accent), #0284c7); 
            margin-bottom: 30px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .back-circle-btn { 
            width: 40px; height: 40px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            text-decoration: none; background: rgba(255,255,255,0.2); 
            color: #fff; transition: 0.3s; z-index: 10; 
        }
        
        [dir="rtl"] .back-circle-btn { order: 1; } 
        [dir="rtl"] .spacer-div { order: 3; }

        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] .spacer-div { order: 3; }
        
        /* تدوير السهم ليشير إلى الشمال في وضع اللغة الإنجليزية (LTR) */
        [dir="ltr"] #backIcon { transform: scaleX(-1); }

        h4.fw-black { order: 2; flex-grow: 1; text-align: center; color: #fff !important; margin: 0; }

        .search-container-box { padding: 0 15px; margin-top: -45px; z-index: 20; position: relative; }
        .search-input { border-radius: 50px; padding: 15px 25px; border: 1px solid var(--border); background: var(--glass) !important; color: var(--text) !important; width: 100%; outline: none; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        
        .tag-btn { background: var(--glass); border: 1px solid var(--border); color: var(--text); padding: 8px 18px; border-radius: 50px; font-size: 0.85rem; cursor: pointer; transition: 0.2s; white-space: nowrap; }
        .tag-btn.active { background: var(--accent); color: white !important; border-color: var(--accent); font-weight: 700; }
        
        .station-card { background: var(--glass); border-radius: 25px; overflow: hidden; border: 1px solid var(--border); transition: 0.3s; height: 100%; cursor: pointer; position: relative; }
        .badge-rating { position: absolute; top: 10px; padding: 4px 12px; border-radius: 50px; color: #1e293b; background: rgba(255, 255, 255, 0.95); font-weight: 800; font-size: 0.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        [dir="rtl"] .badge-rating { left: 10px; } [dir="ltr"] .badge-rating { right: 10px; }
        
        .status-pill { position: absolute; top: 10px; padding: 4px 12px; border-radius: 50px; background: #ef4444; color: white !important; font-size: 0.75rem; font-weight: 700; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        [dir="rtl"] .status-pill { right: 10px; } [dir="ltr"] .status-pill { left: 10px; }
        
        .station-info { padding: 20px; text-align: start; }
        .station-title { color: var(--text) !important; font-weight: 700; }
        .station-loc-text { color: var(--text-muted) !important; }
        
        .price-start { font-weight: 900; color: #22c55e; font-size: 1.2rem; }
        .filter-tags { display: flex; gap: 10px; overflow-x: auto; padding: 15px 5px; scrollbar-width: none; }
        .filter-tags::-webkit-scrollbar { display: none; }
        
        .btn-book-now { background: var(--accent); color: white; border: none; border-radius: 50px; padding: 8px 20px; font-weight: 700; transition: 0.3s; }
        .btn-book-now:hover:not([disabled]) { opacity: 0.9; transform: scale(1.05); }
        .btn-book-now[disabled] { background: #64748b !important; opacity: 0.6; cursor: not-allowed; }
        
        .empty-state { background: var(--glass); border: 1px dashed var(--border); border-radius: 25px; padding: 45px 20px; }
        .modal-content { background: var(--glass); color: var(--text); border: 1px solid var(--border); border-radius: 25px; backdrop-filter: blur(15px); }
        
        [dir="ltr"] .station-info { text-align: left; }
    </style>
</head>

<body>
    <div class="search-header">
        <div style="width: 40px;" class="spacer-div d-none d-md-block"></div>
        <h4 class="fw-black" data-i18n="serv_book">البحث عن محطة</h4>
        <a href="home.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="search-container-box">
        <input type="text" id="searchInput" class="search-input" data-i18n-placeholder="placeholder_search" onkeyup="filterStations()">
        
        <div class="filter-tags mt-3">
            <div class="tag-btn active" onclick="applyQuickFilter(this, 'all')" data-i18n="filter_all">الكل</div>
            <div class="tag-btn" onclick="applyQuickFilter(this, 'price')" data-i18n="filter_cheapest">الأرخص</div>
            <div class="tag-btn" onclick="applyQuickFilter(this, 'rating')" data-i18n="filter_top_rated">الأعلى تقييماً</div>
            <div class="tag-btn" onclick="sortByNearest(this)" data-i18n="filter_nearest">الأقرب إلي</div>
        </div>
    </div>

    <div class="container mt-2">
        <div class="row g-3 px-2" id="stationsContainer">
            <?php 
            if (mysqli_num_rows($result) > 0) {
                // مصفوفة لتخزين العناصر والعودة لفرزها عند الضغط على زر "الكل" مجدداً كترتيب افتراضي للباك آند
                $default_index = 0;
                while($row = mysqli_fetch_assoc($result)) 
                {
                    $min_price = $row['min_price'] ?? '0';
                    $default_index++;
            ?>
                <div class="col-12 col-md-6 col-lg-4 station-item" 
                     data-price="<?php echo htmlspecialchars($min_price); ?>" 
                     data-rating="<?php echo htmlspecialchars($row['Rating']); ?>" 
                     data-lat="<?php echo htmlspecialchars($row['Lat']); ?>" 
                     data-lon="<?php echo htmlspecialchars($row['Lon']); ?>"
                     data-status="<?php echo htmlspecialchars($row['Status']); ?>"
                     data-index="<?php echo $default_index; ?>">
                    <div class="station-card glass shadow-sm" onclick='goToStation(<?php echo (int) $row['StationID']; ?>, <?php echo json_encode((string) $row['Name']); ?>, <?php echo json_encode((string) $row['Status']); ?>)'>
                       <div style="background-image: url('<?php echo htmlspecialchars(clevoStationImageUrl($row['Image'])); ?>'); height: 160px; background-size: cover; background-position: center; position: relative;">
                            <div class="badge-rating"><i class="fas fa-star text-warning me-1"></i> <?php echo htmlspecialchars($row['Rating']); ?></div>
                            <?php if($row['Status'] !== 'Active'): ?>
                                <div class="status-pill" data-status-val="<?php echo htmlspecialchars($row['Status']); ?>"><?= $row['Status'] === 'Busy' ? 'مشغول' : 'مغلق' ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="station-info">
                            <h6 class="station-title mb-1"><?php echo htmlspecialchars($row['Name']); ?></h6>
                            <p class="small station-loc-text mb-3"><i class="fas fa-map-marker-alt text-primary me-1"></i> <span><?php echo htmlspecialchars($row['Location']); ?></span></p>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="price-start"><?php echo htmlspecialchars($min_price); ?> JOD</span>
                                <button class="btn-book-now" data-i18n="<?= $row['Status'] === 'Active' ? 'btn_book_now' : 'btn_unavailable' ?>" <?= $row['Status'] === 'Active' ? '' : 'disabled' ?>><?= $row['Status'] === 'Active' ? 'حجز' : 'غير متاح' ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php 
                }
            } else {
                echo '<div class="col-12"><div class="empty-state text-center mt-4 shadow-sm"><i class="fas fa-car-wash fa-4x mb-3 text-primary"></i><h6 class="fw-bold" data-i18n="no_stations">لا توجد محطات متاحة حالياً</h6></div></div>';
            }
            ?>
        </div>
    </div>

    <div class="modal fade" id="searchStatusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <div id="searchModalIcon" class="mb-3 fs-1"></div>
                <h6 id="searchModalTitle" class="fw-bold"></h6>
                <p id="searchModalBody" class="small opacity-75 mb-0"></p>
                <button class="btn btn-primary rounded-pill w-100 mt-3" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                serv_book: "البحث عن محطة", placeholder_search: "ابحث عن اسم المغسلة أو المنطقة...", filter_all: "الكل", filter_cheapest: "الأرخص", filter_top_rated: "الأعلى تقييماً", filter_nearest: "الأقرب إلي", btn_book_now: "حجز الآن", btn_ok: "موافق", no_stations: "لا توجد محطات متاحة حالياً", gps_unsupported: "GPS غير مدعوم على جهازك", location_required: "يرجى تفعيل صلاحية خدمات الموقع والـ GPS لعرض المحطات الأقرب إليك", unavailable_title: "المحطة غير متاحة", status_busy: "مشغول", status_closed: "مغلق", btn_unavailable: "غير متاح"
            },
            en: {
                serv_book: "Search Stations", placeholder_search: "Search by name or location...", filter_all: "All", filter_cheapest: "Cheapest", filter_top_rated: "Top Rated", filter_nearest: "Nearest to Me", btn_book_now: "Book Now", btn_ok: "OK", no_stations: "No stations available right now", gps_unsupported: "GPS is not supported on your device", location_required: "Please enable location services and GPS permissions to view nearest stations", unavailable_title: "Station unavailable", status_busy: "Busy", status_closed: "Closed", btn_unavailable: "Unavailable"
            }
        };

        function applyTranslations(lang) {
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });
            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                if (translations[lang][key]) el.placeholder = translations[lang][key];
            });

            document.querySelectorAll('.status-pill').forEach(el => {
                const val = el.getAttribute('data-status-val');
                if(val === 'Busy') el.innerText = lang === 'ar' ? translations.ar.status_busy : translations.en.status_busy;
                if(val === 'Closed') el.innerText = lang === 'ar' ? translations.ar.status_closed : translations.en.status_closed;
            });

            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.lang = lang;
        }

        function tr(key) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            return translations[lang][key] || key;
        }

        function showSearchModal(type, title, body) {
            document.getElementById('searchModalIcon').innerHTML = type === 'warning'
                ? '<i class="fas fa-triangle-exclamation text-warning"></i>'
                : '<i class="fas fa-circle-info text-primary"></i>';
            document.getElementById('searchModalTitle').innerText = title;
            document.getElementById('searchModalBody').innerText = body;
            new bootstrap.Modal(document.getElementById('searchStatusModal')).show();
        }

        function filterStations() {
            const val = document.getElementById('searchInput').value.toLowerCase();
            const items = document.querySelectorAll('.station-item');
            items.forEach(item => {
                const match = item.innerText.toLowerCase().includes(val);
                item.style.display = match ? "block" : "none";
            });
        }

        function getDistance(lat1, lon1, lat2, lon2) {
            const R = 6371; 
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon / 2) * Math.sin(dLon / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            return R * c; 
        }

        function sortByNearest(btn) {
            if (!navigator.geolocation) {
                showSearchModal('warning', tr('unavailable_title'), tr('gps_unsupported'));
                return;
            }
            navigator.geolocation.getCurrentPosition((pos) => {
                const uLat = pos.coords.latitude;
                const uLon = pos.coords.longitude;
                
                document.querySelectorAll('.tag-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                const container = document.getElementById('stationsContainer');
                const items = Array.from(document.querySelectorAll('.station-item'));
                
                items.sort((a, b) => {
                    const distA = getDistance(uLat, uLon, parseFloat(a.dataset.lat), parseFloat(a.dataset.lon));
                    const distB = getDistance(uLat, uLon, parseFloat(b.dataset.lat), parseFloat(b.dataset.lon));
                    return distA - distB;
                });
                items.forEach(item => container.appendChild(item));
            }, () => {
                showSearchModal('warning', tr('unavailable_title'), tr('location_required'));
            });
        }

        function applyQuickFilter(btn, type) {
            document.querySelectorAll('.tag-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            const container = document.getElementById('stationsContainer');
            const items = Array.from(document.querySelectorAll('.station-item'));
            
            if (type === 'all') items.sort((a, b) => a.dataset.index - b.dataset.index);
            if (type === 'price') items.sort((a, b) => parseFloat(a.dataset.price) - parseFloat(b.dataset.price));
            if (type === 'rating') items.sort((a, b) => parseFloat(b.dataset.rating) - parseFloat(a.dataset.rating));
            
            items.forEach(item => container.appendChild(item));
        }

        function goToStation(id, name, status) {
            if (status !== 'Active') {
                const msg = status === 'Busy'
                    ? (localStorage.getItem('app_lang') === 'en' ? 'The station is busy right now' : 'المحطة مشغولة حالياً')
                    : (localStorage.getItem('app_lang') === 'en' ? 'The station is closed right now' : 'المحطة مغلقة حالياً');
                showSearchModal('warning', tr('unavailable_title'), msg);
                return;
            }
            localStorage.setItem('current_station_name', name);
            window.location.href = 'station-details.php?id=' + id;
        }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            
            const bootstrapLink = document.querySelector('link[href*="bootstrap"]');
            if(lang === 'en') {
                if (bootstrapLink) {
                    bootstrapLink.href = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
                }
            } else {
                if (bootstrapLink) {
                    bootstrapLink.href = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css";
                }
            }
            applyTranslations(lang);
        };
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>
