<?php

include_once(__DIR__ . "/../includes/config.php");
include_once(__DIR__ . "/../includes/app-helpers.php");

$userID = clevo_require_role(['customer', 'user'], '../general/login.php');
clevoEnsureServiceLanguageColumns($conn);

$query = "SELECT s.*,
                 MIN(sv.Price) AS min_price,
                 MAX(sv.Price) AS max_price,
                 GROUP_CONCAT(DISTINCT CONCAT_WS('::', COALESCE(NULLIF(sv.Name_ar, ''), sv.Name), COALESCE(NULLIF(sv.Name_en, ''), sv.Name)) ORDER BY sv.ServiceID SEPARATOR ' || ') AS service_pairs,
                 MAX(COALESCE(sv.is_station, 0)) AS has_station_service,
                 MAX(COALESCE(sv.is_home, 0)) AS has_home_service,
                 MAX(COALESCE(sv.is_pickup, 0)) AS has_pickup_service,
                 COALESCE(rr.user_rating, s.Rating, 0) AS user_rating,
                 COALESCE(rr.review_count, 0) AS review_count
          FROM stations s
          LEFT JOIN services sv ON sv.StationID = s.StationID AND sv.ArchivedAt IS NULL
          LEFT JOIN (
              SELECT b.StationID, AVG(r.Rating) AS user_rating, COUNT(r.ReviewID) AS review_count
              FROM booking b
              JOIN reviews r ON r.BookingID = b.BookingID
              GROUP BY b.StationID
          ) rr ON rr.StationID = s.StationID
          WHERE s.admin_status = 'approved' AND s.ArchivedAt IS NULL
          GROUP BY s.StationID
          ORDER BY s.StationID ASC";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();

function clevo_search_aliases(string $text): string {
    $aliases = [
        'full wash' => 'غسيل شامل',
        'interior' => 'داخلي تنظيف داخلي',
        'exterior' => 'خارجي تنظيف خارجي',
        'polish' => 'تلميع',
        'wax' => 'شمع حماية',
        'oil change' => 'غيار زيت تغيير زيت',
        'pickup' => 'استلام وتسليم',
        'home' => 'منزلي موقع العميل',
        'delivery' => 'توصيل',
        'abdoun' => 'عبدون',
        'sweifieh' => 'الصويفية',
        'khalda' => 'خلدا',
        'jubeiha' => 'الجبيهة',
        'marj al hamam' => 'مرج الحمام',
        'tabarbour' => 'طبربور',
        'tla al ali' => 'تلاع العلي',
        'dabouq' => 'دابوق',
        'amman' => 'عمان',
    ];
    $haystack = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $extra = [];
    foreach ($aliases as $needle => $alias) {
        if (str_contains($haystack, $needle)) {
            $extra[] = $alias;
        }
    }
    return trim($text . ' ' . implode(' ', $extra));
}

function clevo_search_location_ar(string $location): string {
    $map = [
        'Abdoun' => 'عبدون',
        'Sweifieh' => 'الصويفية',
        'Khalda' => 'خلدا',
        'Al Jubeiha' => 'الجبيهة',
        'Jubeiha' => 'الجبيهة',
        'Marj Al Hamam' => 'مرج الحمام',
        'Tabarbour' => 'طبربور',
        'Tla Al Ali' => 'تلاع العلي',
        'Dabouq' => 'دابوق',
        'Amman' => 'عمان',
    ];
    $translated = $location;
    foreach ($map as $en => $ar) {
        $translated = str_ireplace($en, $ar, $translated);
    }
    $translated = str_replace(',', '،', $translated);
    return $translated;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="serv_book">البحث عن محطة | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f8fafc 100%);
            --glass: rgba(255, 255, 255, 0.88);
            --text: #075985;
            --text-muted: #475569;
            --accent: #0ea5e9;
            --border: rgba(3, 105, 161, 0.16);
        }
        [data-theme="dark"] {
            --main-bg: linear-gradient(135deg, #07111f 0%, #111827 100%);
            --glass: rgba(17, 24, 39, 0.82);
            --text: #f8fafc;
            --text-muted: #cbd5e1;
            --accent: #38bdf8;
            --border: rgba(255, 255, 255, 0.12);
        }

        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.3s; margin: 0; padding-bottom: 24px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); }
        .search-header { padding: 20px; display: flex; align-items: center; justify-content: space-between; position: relative; height: 140px; border-radius: 0 0 36px 36px; background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .back-circle-btn { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; background: rgba(255,255,255,0.2); color: #fff; transition: 0.3s; z-index: 10; }
        .spacer-div { width: 40px; }
        [dir="rtl"] .back-circle-btn { order: 1; }
        [dir="rtl"] .spacer-div { order: 3; }
        [dir="rtl"] #backIcon { transform: rotate(0deg); }
        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] .spacer-div { order: 3; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }
        h4.fw-black { order: 2; flex-grow: 1; text-align: center; color: #fff !important; margin: 0; }
        .search-container-box { padding: 0 15px; margin-top: -45px; z-index: 20; position: relative; }
        .search-input { border-radius: 999px; padding: 15px 25px; border: 1px solid var(--border); background: var(--glass) !important; color: var(--text) !important; width: 100%; outline: none; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .tag-btn { background: var(--glass); border: 1px solid var(--border); color: var(--text); padding: 8px 16px; border-radius: 999px; font-size: 0.85rem; cursor: pointer; transition: 0.2s; white-space: nowrap; display: inline-flex; align-items: center; gap: 7px; }
        .tag-btn.active { background: var(--accent); color: white !important; border-color: var(--accent); font-weight: 700; }
        .filter-tags { display: flex; gap: 10px; overflow-x: auto; padding: 15px 5px; scrollbar-width: none; }
        .filter-tags::-webkit-scrollbar { display: none; }
        .station-card { background: var(--glass); border-radius: 22px; overflow: hidden; border: 1px solid var(--border); transition: 0.25s; height: 100%; cursor: pointer; position: relative; }
        .station-card:hover { transform: translateY(-3px); box-shadow: 0 14px 25px rgba(14, 165, 233, 0.16); }
        .badge-rating, .status-pill, .distance-pill { position: absolute; top: 10px; padding: 4px 12px; border-radius: 999px; font-weight: 800; font-size: 0.75rem; box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
        .badge-rating { color: #1e293b; background: rgba(255, 255, 255, 0.95); }
        .status-pill { background: #ef4444; color: white !important; }
        .distance-pill { display: none; bottom: 10px; top: auto; background: rgba(3, 105, 161, 0.92); color: white; }
        [dir="rtl"] .badge-rating { left: 10px; } [dir="ltr"] .badge-rating { right: 10px; }
        [dir="rtl"] .status-pill { right: 10px; } [dir="ltr"] .status-pill { left: 10px; }
        [dir="rtl"] .distance-pill { right: 10px; } [dir="ltr"] .distance-pill { left: 10px; }
        .station-info { padding: 18px; text-align: start; }
        .station-title { color: var(--text) !important; font-weight: 800; }
        .station-loc-text { color: var(--text-muted) !important; }
        .service-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
        .service-chip { border: 1px solid var(--border); color: var(--text); background: rgba(14, 165, 233, 0.10); border-radius: 999px; padding: 4px 9px; font-size: 0.72rem; font-weight: 800; }
        .map-link { font-weight: 900; color: var(--accent); font-size: 0.9rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        .map-link:hover { color: #0284c7; text-decoration: underline; }
        .btn-book-now { background: var(--accent); color: white; border: none; border-radius: 999px; padding: 8px 18px; font-weight: 800; transition: 0.3s; white-space: nowrap; }
        .btn-book-now:hover:not([disabled]) { opacity: 0.9; transform: scale(1.03); }
        .btn-book-now[disabled] { background: #64748b !important; opacity: 0.65; cursor: not-allowed; }
        .empty-state { background: var(--glass); border: 1px dashed var(--border); border-radius: 22px; padding: 42px 20px; }
        .modal-content { background: var(--glass); color: var(--text); border: 1px solid var(--border); border-radius: 22px; backdrop-filter: blur(15px); }
        [dir="ltr"] .station-info { text-align: left; }
    </style>
</head>

<body>
    <div class="search-header">
        <div class="spacer-div d-none d-md-block"></div>
        <h4 class="fw-black" data-i18n="serv_book">البحث عن محطة</h4>
        <a href="home.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="search-container-box">
        <input type="text" id="searchInput" class="search-input" data-i18n-placeholder="placeholder_search" oninput="applyFilters()">

        <div class="filter-tags mt-3">
            <button class="tag-btn active" type="button" onclick="setFilter(this, 'all')"><i class="fas fa-border-all"></i><span data-i18n="filter_all">الكل</span></button>
            <button class="tag-btn" type="button" onclick="setFilter(this, 'price')"><i class="fas fa-tags"></i><span data-i18n="filter_cheapest">الأرخص</span></button>
            <button class="tag-btn" type="button" onclick="setFilter(this, 'rating')"><i class="fas fa-star"></i><span data-i18n="filter_top_rated">الأعلى تقييماً</span></button>
            <button class="tag-btn" type="button" onclick="sortByNearest(this)"><i class="fas fa-location-crosshairs"></i><span data-i18n="filter_nearest">الأقرب إلي</span></button>
            <button class="tag-btn" type="button" onclick="setFilter(this, 'station')"><i class="fas fa-store"></i><span data-i18n="filter_station">في المحطة</span></button>
            <button class="tag-btn" type="button" onclick="setFilter(this, 'home')"><i class="fas fa-house"></i><span data-i18n="filter_home">خدمة منزلية</span></button>
            <button class="tag-btn" type="button" onclick="setFilter(this, 'pickup')"><i class="fas fa-car-side"></i><span data-i18n="filter_pickup">استلام وتسليم</span></button>
            <button class="tag-btn" type="button" onclick="setFilter(this, 'active')"><i class="fas fa-circle-check"></i><span data-i18n="filter_open">المتاح فقط</span></button>
        </div>
    </div>

    <div class="container mt-2">
        <div class="row g-3 px-2" id="stationsContainer">
            <?php
            if ($result->num_rows > 0) {
                $default_index = 0;
                while ($row = $result->fetch_assoc()) {
                    $default_index++;
                    $min_price = $row['min_price'] !== null ? (float) $row['min_price'] : 0;
                    $max_price = $row['max_price'] !== null ? (float) $row['max_price'] : 0;
                    $rating = number_format((float) ($row['user_rating'] ?? 0), 1);
                    $servicePairsRaw = trim((string) ($row['service_pairs'] ?? ''));
                    $servicePairs = [];
                    $serviceNamesArParts = [];
                    $serviceNamesEnParts = [];
                    foreach (array_filter(array_map('trim', explode('||', $servicePairsRaw))) as $pairRaw) {
                        [$pairAr, $pairEn] = array_pad(explode('::', $pairRaw, 2), 2, '');
                        $pairAr = trim($pairAr);
                        $pairEn = trim($pairEn);
                        if ($pairAr === '') $pairAr = $pairEn;
                        if ($pairEn === '') $pairEn = $pairAr;
                        if ($pairAr !== '' || $pairEn !== '') {
                            $servicePairs[] = ['ar' => $pairAr, 'en' => $pairEn];
                            $serviceNamesArParts[] = $pairAr;
                            $serviceNamesEnParts[] = $pairEn;
                        }
                    }
                    $serviceNamesAr = implode(' ', $serviceNamesArParts);
                    $serviceNamesEn = implode(' ', $serviceNamesEnParts);
                    $locationEn = (string) ($row['Location'] ?? '');
                    $locationAr = clevo_search_location_ar($locationEn);
                    $searchText = clevo_search_aliases(($row['Name'] ?? '') . ' ' . $locationEn . ' ' . $locationAr . ' ' . $serviceNamesAr . ' ' . $serviceNamesEn);
                    $hasStation = ((int) ($row['has_station_service'] ?? 0) === 1 || (int) ($row['Has_At_Station'] ?? 0) === 1) ? '1' : '0';
                    $hasHome = ((int) ($row['has_home_service'] ?? 0) === 1 || (int) ($row['Has_Home_Service'] ?? 0) === 1) ? '1' : '0';
                    $hasPickup = ((int) ($row['has_pickup_service'] ?? 0) === 1 || (int) ($row['Has_Delivery'] ?? 0) === 1) ? '1' : '0';
                    $map_url = trim((string) ($row['Location_URL'] ?? ''));
                    if ($map_url === '' && is_numeric($row['Lat'] ?? null) && is_numeric($row['Lon'] ?? null)) {
                        $map_url = 'https://www.google.com/maps?q=' . rawurlencode($row['Lat'] . ',' . $row['Lon']);
                    }
                    $servicePreview = array_slice($servicePairs, 0, 3);
            ?>
                <div class="col-12 col-md-6 col-lg-4 station-item"
                     data-price="<?php echo htmlspecialchars((string) $min_price); ?>"
                     data-rating="<?php echo htmlspecialchars($rating); ?>"
                     data-lat="<?php echo htmlspecialchars((string) $row['Lat']); ?>"
                     data-lon="<?php echo htmlspecialchars((string) $row['Lon']); ?>"
                     data-status="<?php echo htmlspecialchars((string) $row['Status']); ?>"
                     data-station="<?php echo $hasStation; ?>"
                     data-home="<?php echo $hasHome; ?>"
                     data-pickup="<?php echo $hasPickup; ?>"
                     data-search="<?php echo htmlspecialchars(function_exists('mb_strtolower') ? mb_strtolower($searchText, 'UTF-8') : strtolower($searchText)); ?>"
                     data-index="<?php echo $default_index; ?>">
                    <div class="station-card glass shadow-sm" onclick='goToStation(<?php echo (int) $row['StationID']; ?>, <?php echo json_encode((string) $row['Name'], JSON_UNESCAPED_UNICODE); ?>, <?php echo json_encode((string) $row['Status']); ?>)'>
                       <div style="background-image: url('<?php echo htmlspecialchars(clevoStationImageUrl($row['Image'])); ?>'); height: 160px; background-size: cover; background-position: center; position: relative;">
                            <div class="badge-rating"><i class="fas fa-star text-warning me-1"></i> <?php echo htmlspecialchars($rating); ?></div>
                            <?php if ($row['Status'] !== 'Active'): ?>
                                <div class="status-pill" data-status-val="<?php echo htmlspecialchars((string) $row['Status']); ?>"><?= $row['Status'] === 'Busy' ? 'مشغول' : 'مغلق' ?></div>
                            <?php endif; ?>
                            <div class="distance-pill"></div>
                        </div>
                        <div class="station-info">
                            <h6 class="station-title mb-1"><?php echo htmlspecialchars((string) $row['Name']); ?></h6>
                            <p class="small station-loc-text mb-2"><i class="fas fa-map-marker-alt text-primary me-1"></i> <span class="db-location" data-ar="<?php echo htmlspecialchars($locationAr); ?>" data-en="<?php echo htmlspecialchars($locationEn); ?>"><?php echo htmlspecialchars($locationAr); ?></span></p>
                            <p class="small station-loc-text mb-2">
                                <i class="fas fa-clock text-primary me-1"></i>
                                <?php echo htmlspecialchars(substr((string) $row['OpeningTime'], 0, 5)); ?> - <?php echo htmlspecialchars(substr((string) $row['ClosingTime'], 0, 5)); ?>
                                <?php if ($min_price > 0): ?>
                                    <span class="mx-1">|</span><span data-i18n="from_price">من</span> <?php echo number_format($min_price, 2); ?> JOD
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($servicePreview)): ?>
                                <div class="service-chips">
                                    <?php foreach ($servicePreview as $servicePair): ?>
                                        <?php
                                            $serviceAr = trim((string) ($servicePair['ar'] ?? ''));
                                            $serviceEn = trim((string) ($servicePair['en'] ?? ''));
                                            if ($serviceAr === '') $serviceAr = $serviceEn;
                                            if ($serviceEn === '') $serviceEn = $serviceAr;
                                        ?>
                                        <span class="service-chip" data-service-ar="<?php echo htmlspecialchars($serviceAr); ?>" data-service-en="<?php echo htmlspecialchars($serviceEn); ?>"><?php echo htmlspecialchars($serviceAr); ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($servicePairs) > 3): ?>
                                        <span class="service-chip">+<?php echo count($servicePairs) - 3; ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center gap-2">
                                <?php if ($map_url !== ''): ?>
                                    <a class="map-link" href="<?php echo htmlspecialchars($map_url); ?>" target="_blank" rel="noopener" onclick="event.stopPropagation();" data-i18n="station_map_link"><i class="fas fa-location-dot"></i> موقع المحطة</a>
                                <?php else: ?>
                                    <span class="small station-loc-text" data-i18n="station_map_unavailable">موقع المحطة غير متاح</span>
                                <?php endif; ?>
                                <button class="btn-book-now" data-i18n="<?= $row['Status'] === 'Active' ? 'btn_book_now' : 'btn_unavailable' ?>" <?= $row['Status'] === 'Active' ? '' : 'disabled' ?>><?= $row['Status'] === 'Active' ? 'احجز الآن' : 'غير متاح' ?></button>
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
        <div id="noResults" class="empty-state text-center mt-4 shadow-sm d-none">
            <i class="fas fa-magnifying-glass fa-3x mb-3 text-primary"></i>
            <h6 class="fw-bold" data-i18n="no_results">لا توجد محطات تطابق البحث الحالي</h6>
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
    <script src="../assets/service-translations.js"></script>
    <script>
        const translations = {
            ar: {
                serv_book: "البحث عن محطة",
                placeholder_search: "ابحث باسم المحطة أو المنطقة أو الخدمة...",
                filter_all: "الكل",
                filter_cheapest: "الأرخص",
                filter_top_rated: "الأعلى تقييماً",
                filter_nearest: "الأقرب إلي",
                filter_station: "في المحطة",
                filter_home: "خدمة منزلية",
                filter_pickup: "استلام وتسليم",
                filter_open: "المتاح فقط",
                from_price: "من",
                btn_book_now: "احجز الآن",
                btn_ok: "موافق",
                no_stations: "لا توجد محطات متاحة حالياً",
                no_results: "لا توجد محطات تطابق البحث الحالي",
                gps_unsupported: "GPS غير مدعوم على جهازك",
                location_required: "يرجى تفعيل صلاحية الموقع حتى نرتب المحطات حسب الأقرب إليك",
                location_loading: "جاري تحديد موقعك...",
                unavailable_title: "تنبيه",
                station_busy_msg: "المحطة مشغولة حالياً",
                station_closed_msg: "المحطة مغلقة حالياً",
                status_busy: "مشغول",
                status_closed: "مغلق",
                btn_unavailable: "غير متاح",
                station_map_link: "موقع المحطة",
                station_map_unavailable: "موقع المحطة غير متاح",
                km: "كم"
            },
            en: {
                serv_book: "Search Stations",
                placeholder_search: "Search by station, area, or service...",
                filter_all: "All",
                filter_cheapest: "Cheapest",
                filter_top_rated: "Top Rated",
                filter_nearest: "Nearest to Me",
                filter_station: "At Station",
                filter_home: "Home Service",
                filter_pickup: "Pickup & Drop",
                filter_open: "Available Only",
                from_price: "From",
                btn_book_now: "Book Now",
                btn_ok: "OK",
                no_stations: "No stations available right now",
                no_results: "No stations match the current search",
                gps_unsupported: "GPS is not supported on your device",
                location_required: "Please allow location permission to sort stations by distance",
                location_loading: "Getting your location...",
                unavailable_title: "Notice",
                station_busy_msg: "The station is busy right now",
                station_closed_msg: "The station is closed right now",
                status_busy: "Busy",
                status_closed: "Closed",
                btn_unavailable: "Unavailable",
                station_map_link: "Station location",
                station_map_unavailable: "Station location unavailable",
                km: "km"
            }
        };

        let activeFilter = 'all';
        let userPosition = null;
        const nearestVisibleLimit = 1;

        function lang() {
            return localStorage.getItem('app_lang') || 'ar';
        }

        function tr(key) {
            const current = lang();
            return translations[current][key] || key;
        }

        function normalizeText(value) {
            return (value || '').toString().toLowerCase().trim();
        }

        function getStationItems() {
            return Array.from(document.querySelectorAll('.station-item'));
        }

        function matchesFilter(item) {
            if (activeFilter === 'nearest') {
                const rank = parseInt(item.dataset.nearestRank || '0', 10);
                return rank >= 1 && rank <= nearestVisibleLimit;
            }
            if (activeFilter === 'station') return item.dataset.station === '1';
            if (activeFilter === 'home') return item.dataset.home === '1';
            if (activeFilter === 'pickup') return item.dataset.pickup === '1';
            if (activeFilter === 'active') return item.dataset.status === 'Active';
            return true;
        }

        function matchesSearch(item) {
            const val = normalizeText(document.getElementById('searchInput').value);
            if (!val) return true;
            return normalizeText(item.dataset.search).includes(val) || normalizeText(item.innerText).includes(val);
        }

        function sortItems(items) {
            if (activeFilter === 'price') {
                return items.sort((a, b) => (parseFloat(a.dataset.price) || Number.MAX_SAFE_INTEGER) - (parseFloat(b.dataset.price) || Number.MAX_SAFE_INTEGER));
            }
            if (activeFilter === 'rating') {
                return items.sort((a, b) => (parseFloat(b.dataset.rating) || 0) - (parseFloat(a.dataset.rating) || 0));
            }
            if (activeFilter === 'nearest' && userPosition) {
                return items.sort((a, b) => getItemDistance(a) - getItemDistance(b));
            }
            return items.sort((a, b) => (parseInt(a.dataset.index, 10) || 0) - (parseInt(b.dataset.index, 10) || 0));
        }

        function applyFilters() {
            const container = document.getElementById('stationsContainer');
            const items = sortItems(getStationItems());
            let visibleCount = 0;
            items.forEach(item => {
                container.appendChild(item);
                const visible = matchesFilter(item) && matchesSearch(item);
                item.classList.toggle('d-none', !visible);
                if (visible) visibleCount++;
            });
            document.getElementById('noResults').classList.toggle('d-none', visibleCount > 0);
        }

        function setFilter(btn, type) {
            document.querySelectorAll('.tag-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeFilter = type;
            applyFilters();
        }

        function showSearchModal(type, title, body) {
            document.getElementById('searchModalIcon').innerHTML = type === 'warning'
                ? '<i class="fas fa-triangle-exclamation text-warning"></i>'
                : '<i class="fas fa-circle-info text-primary"></i>';
            document.getElementById('searchModalTitle').innerText = title;
            document.getElementById('searchModalBody').innerText = body;
            new bootstrap.Modal(document.getElementById('searchStatusModal')).show();
        }

        function getDistance(lat1, lon1, lat2, lon2) {
            const R = 6371;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) ** 2 +
                      Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                      Math.sin(dLon / 2) ** 2;
            return R * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
        }

        function getItemDistance(item) {
            const lat = parseFloat(item.dataset.lat);
            const lon = parseFloat(item.dataset.lon);
            if (!userPosition || !Number.isFinite(lat) || !Number.isFinite(lon)) return Number.MAX_SAFE_INTEGER;
            return getDistance(userPosition.lat, userPosition.lon, lat, lon);
        }

        function rankNearestStations() {
            const items = getStationItems();
            items.forEach(item => {
                delete item.dataset.nearestRank;
                delete item.dataset.distance;
            });

            const validItems = items.filter(item => getItemDistance(item) < Number.MAX_SAFE_INTEGER);
            const activeValidItems = validItems.filter(item => item.dataset.status === 'Active');
            const rankSource = activeValidItems.length ? activeValidItems : validItems;

            rankSource
                .sort((a, b) => getItemDistance(a) - getItemDistance(b))
                .forEach((item, index) => {
                    item.dataset.nearestRank = String(index + 1);
                    item.dataset.distance = String(getItemDistance(item));
                });
        }

        function renderDistances() {
            getStationItems().forEach(item => {
                const pill = item.querySelector('.distance-pill');
                const distance = getItemDistance(item);
                if (Number.isFinite(distance) && distance < Number.MAX_SAFE_INTEGER) {
                    pill.innerText = distance.toFixed(distance < 10 ? 1 : 0) + ' ' + tr('km');
                    pill.style.display = 'block';
                } else {
                    pill.innerText = '';
                    pill.style.display = 'none';
                }
            });
        }

        function sortByNearest(btn) {
            if (!navigator.geolocation) {
                showSearchModal('warning', tr('unavailable_title'), tr('gps_unsupported'));
                return;
            }
            showSearchModal('info', tr('unavailable_title'), tr('location_loading'));
            navigator.geolocation.getCurrentPosition((pos) => {
                bootstrap.Modal.getInstance(document.getElementById('searchStatusModal'))?.hide();
                userPosition = { lat: pos.coords.latitude, lon: pos.coords.longitude };
                document.querySelectorAll('.tag-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                activeFilter = 'nearest';
                rankNearestStations();
                renderDistances();
                applyFilters();
            }, () => {
                showSearchModal('warning', tr('unavailable_title'), tr('location_required'));
            }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
        }

        function goToStation(id, name, status) {
            if (status !== 'Active') {
                showSearchModal('warning', tr('unavailable_title'), status === 'Busy' ? tr('station_busy_msg') : tr('station_closed_msg'));
                return;
            }
            localStorage.setItem('current_station_name', name);
            window.location.href = 'station-details.php?id=' + id;
        }

        function applyTranslations() {
            const current = lang();
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[current][key]) el.innerText = translations[current][key];
            });
            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                if (translations[current][key]) el.placeholder = translations[current][key];
            });
            document.querySelectorAll('.status-pill').forEach(el => {
                const val = el.getAttribute('data-status-val');
                if (val === 'Busy') el.innerText = translations[current].status_busy;
                if (val === 'Closed') el.innerText = translations[current].status_closed;
            });
            document.querySelectorAll('.db-location').forEach(el => {
                el.innerText = current === 'ar' ? (el.getAttribute('data-ar') || el.innerText) : (el.getAttribute('data-en') || el.innerText);
            });
            document.querySelectorAll('.service-chip[data-service-ar][data-service-en]').forEach(el => {
                el.innerText = current === 'ar'
                    ? (el.getAttribute('data-service-ar') || el.innerText)
                    : (el.getAttribute('data-service-en') || el.innerText);
            });
            if (window.clevoApplyServiceTranslations) {
                window.clevoApplyServiceTranslations(document, current);
            }
            document.documentElement.dir = current === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.lang = current;
            const bootstrapLink = document.getElementById('bootstrap-css');
            bootstrapLink.href = current === 'ar'
                ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css'
                : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
        }

        window.onload = () => {
            document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light');
            applyTranslations();
            applyFilters();
        };
    </script>
    <script>window.clevoCsrfToken = <?= clevo_js(generate_csrf_token()) ?>;</script>
    <script src="../assets/lamea.js?v=20260605b"></script>
</body>
</html>
