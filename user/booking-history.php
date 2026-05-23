<?php
session_start();
include(__DIR__ . "/../includes/config.php");
include(__DIR__ . "/../includes/booking-helpers.php");

// 1. التحقق من تسجيل الدخول
if (!isset($_SESSION['userID'])) {
    header("Location: login.php");
    exit();
}

$userID = (int) $_SESSION['userID'];
cancelExpiredBookings($conn, $userID);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_rating') {
    header('Content-Type: application/json; charset=utf-8');
    clevo_require_csrf_token(true);
    $bookingID = (int) ($_POST['booking_id'] ?? 0);
    $rating = max(1, min(5, (int) ($_POST['rating'] ?? 0)));
    $comment = trim($_POST['comment'] ?? '');

    $check = $conn->prepare("SELECT BookingID FROM booking WHERE BookingID = ? AND UserID = ? AND Status = 'Completed'");
    $check->bind_param("ii", $bookingID, $userID);
    $check->execute();
    if (!$rating || !$check->get_result()->fetch_assoc()) {
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

    echo json_encode(['status' => $save->execute() ? 'success' : 'error']);
    exit;
}

// 2. جلب سجل الحجوزات من القاعدة مع تفاصيل المحطة والخدمة
$query = "SELECT b.*, s.Name as station_name, serv.Name as service_name, b.Service_Location, b.DriverID
          FROM `booking` b
          JOIN `stations` s ON b.StationID = s.StationID
          JOIN `services` serv ON b.ServiceID = serv.ServiceID
          WHERE b.UserID = ? 
          ORDER BY b.Booking_Date DESC, b.Booking_Time DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $userID);
$stmt->execute();
$history = $stmt->get_result();
$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="history_page_title">سجل الحجوزات | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.7);
            --text: #0369a1; --accent: #0ea5e9; --border: rgba(255, 255, 255, 0.1);
            --card-text-main: #0369a1;
            --modal-bg: #ffffff;
            --input-bg: #ffffff;
        }
        [data-theme="dark"] { 
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.5);
            --text: #f1f5f9; --accent: #38bdf8; --border: rgba(255, 255, 255, 0.05);
            --card-text-main: #f1f5f9;
            --modal-bg: #1e293b;
            --input-bg: #334155;
        }
        
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 40px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; }

        .history-header { 
            padding: 20px; display: flex; align-items: center; justify-content: space-between; 
            position: relative; height: 140px; border-radius: 0 0 40px 40px; 
            background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 30px; 
        }
        
        .back-circle-btn { 
            width: 40px; height: 40px; border-radius: 50%; display: flex; 
            align-items: center; justify-content: center; text-decoration: none; 
            background: rgba(255,255,255,0.2); color: #fff; transition: 0.3s; z-index: 10;
        }
        .spacer-div { width: 40px; }
        [dir="rtl"] .back-circle-btn { order: 1 ; } 
        [dir="rtl"] .spacer-div { order: 3; }
        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] .spacer-div { order: 3; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }

        h4.fw-black { order: 2; flex-grow: 1; text-align: center; color: white !important; margin: 0; font-weight: 900; }

        .filter-container { padding: 0 15px; margin-bottom: 20px; display: flex; gap: 10px; overflow-x: auto; scrollbar-width: none; }
        .filter-btn { background: var(--glass); border: 1px solid var(--border); color: var(--text); padding: 8px 20px; border-radius: 50px; font-size: 0.85rem; cursor: pointer; transition: 0.2s; white-space: nowrap; }
        .filter-btn.active { background: var(--accent); color: white; border-color: var(--accent); font-weight: 700; }

        .history-card { padding: 20px; margin-bottom: 12px; transition: 0.3s; border: 1px solid var(--border); color: var(--card-text-main); }
        .history-card h6 { color: var(--card-text-main); }
        .status-badge { font-size: 0.75rem; padding: 5px 15px; border-radius: 50px; font-weight: 700; display: inline-block; }
        .price-tag { font-weight: 900; color: #22c55e; font-size: 1.1rem; }

        .btn-track { 
            background: var(--accent); color: white; border: none; border-radius: 12px; 
            padding: 8px 20px; font-size: 0.85rem; font-weight: 700; margin-top: 10px;
            display: inline-flex; align-items: center; gap: 8px; transition: 0.3s;
            text-decoration: none;
        }
        .btn-track:hover { background: #0284c7; color: white; transform: scale(1.05); }

        /* Dark/Light Compatibility for Modal */
        .modal-content { background-color: var(--modal-bg) !important; color: var(--card-text-main) !important; border: 1px solid var(--border); }
        .modal-content textarea { background-color: var(--input-bg) !important; color: var(--card-text-main) !important; border: 1px solid var(--border); }
        .modal-content textarea::placeholder { color: var(--card-text-main); opacity: 0.6; }
    </style>
</head>
<body>
    <div class="history-header shadow">
        <div class="spacer-div"></div>
        <h4 class="fw-black" data-i18n="history_title">سجل الحجوزات</h4>
        <a href="home.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="filter-container">
        <button class="filter-btn active" onclick="filterHistory('all', this)" data-i18n="filter_all"></button>
        <button class="filter-btn" onclick="filterHistory('In Progress', this)" data-i18n="filter_ongoing"></button>
        <button class="filter-btn" onclick="filterHistory('Completed', this)" data-i18n="filter_completed"></button>
        <button class="filter-btn" onclick="filterHistory('Pending', this)" data-i18n="filter_pending"></button>
    </div>

    <main class="container px-3">
        <div id="historyList">
            <?php if ($history->num_rows > 0): ?>
                <?php while($row = $history->fetch_assoc()): ?>
                    <div class="glass history-card shadow-sm" data-status="<?= $row['Status'] ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div style="flex: 1;">
                                <h6 class="fw-bold mb-1"><?= htmlspecialchars($row['station_name']) ?></h6>
                                <p class="small mb-2 opacity-75"><i class="fas fa-soap me-1"></i> <?= htmlspecialchars($row['service_name']) ?></p>
                                <small class="opacity-75 d-block mb-2">
                                    <i class="far fa-calendar-alt me-1"></i> <?= $row['Booking_Date'] ?> | <?= $row['Booking_Time'] ?>
                                </small>
                                
                                <a href="order-details.php?id=<?= $row['BookingID'] ?>" class="btn-track shadow-sm">
                                    <i class="fas fa-info-circle"></i> <span data-i18n="btn_details">تفاصيل الطلب</span>
                                </a>
                                <?php if($row['Service_Location'] === 'delivery' && !empty($row['DriverID']) && in_array($row['Status'], ['Accepted', 'Arrived', 'In Progress', 'Pickup', 'Washing', 'Returning'])): ?>
                                    <a href="live-tracking.php?id=<?= $row['BookingID'] ?>" class="btn-track shadow-sm">
                                        <i class="fas fa-map-marker-alt"></i> <span data-i18n="btn_track">تتبع الآن</span>
                                    </a>
                                <?php endif; ?>
                                <?php if($row['Status'] == 'Completed'): ?>
                                    <button type="button" class="btn-track shadow-sm" onclick="openRatingModal(<?= (int)$row['BookingID'] ?>)" style="border:0;">
                                        <i class="fas fa-star"></i> <span data-i18n="btn_rate">تقييم</span>
                                    </button>
                                <?php endif; ?>
                                <a href="support.php?subject=مشكلة%20في%20الطلب%20#<?= rawurlencode($row['Order_Number']) ?>" class="btn-track shadow-sm" style="background: #e11d48;">
                                    <i class="fas fa-envelope"></i> <span data-i18n="btn_complaint">أرسل شكوى</span>
                                </a>
                            </div>

                            <div class="text-end">
                                <?php 
                                    $status = $row['Status'];
                                    $badgeClass = 'bg-secondary';
                                    if($status == 'Completed') $badgeClass = 'bg-success';
                                    elseif($status == 'Pending') $badgeClass = 'bg-warning text-dark';
                                    elseif($status == 'In Progress') $badgeClass = 'bg-info text-white';
                                    elseif(in_array($status, ['Canceled', 'Cancelled', 'Cancelled_Timeout', 'Rejected'], true)) $badgeClass = 'bg-danger';
                                ?>
                                <span class="status-badge <?= $badgeClass ?>"><?= $status ?></span>
                                <div class="price-tag mt-2">
                                    <?php if (($row['Payment_Method'] ?? '') === 'plan'): ?>
                                        <span data-i18n="paid_from_plan">من الباقة</span>
                                    <?php else: ?>
                                        <?= number_format($row['Total_Price'], 2) ?> JOD
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="text-center py-5 opacity-50">
                    <i class="fas fa-history fa-3x mb-3"></i>
                    <h6 data-i18n="no_history"></h6>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div class="modal fade" id="ratingModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <h6 class="fw-bold mb-3" data-i18n="rate_service">تقييم الخدمة</h6>
                <div class="rating-stars mb-3" id="quickStars" style="font-size:1.8rem;color:#f59e0b;direction:ltr;">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="far fa-star" data-value="<?= $i ?>" onclick="setQuickRating(<?= $i ?>)"></i>
                    <?php endfor; ?>
                </div>
                <textarea id="quickComment" class="form-control mb-3" rows="3" data-i18n-holder="rate_placeholder" placeholder="اكتب ملاحظتك..."></textarea>
                <button class="btn btn-primary rounded-pill fw-bold" onclick="submitQuickRating()" data-i18n="btn_save_rate">حفظ التقييم</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                history_page_title: "سجل الحجوزات | Clevo",
                history_title: "سجل الحجوزات", filter_all: "الكل", filter_completed: "مكتمل", 
                filter_pending: "قيد الانتظار", filter_ongoing: "قيد التنفيذ", 
                no_history: "لا يوجد حجوزات حالياً", btn_track: "تتبع الآن",
                btn_details: "تفاصيل الطلب", btn_rate: "تقييم", btn_complaint: "أرسل شكوى",
                rate_service: "تقييم الخدمة", rate_placeholder: "اكتب ملاحظتك...", btn_save_rate: "حفظ التقييم", paid_from_plan: "من الباقة"
            },
            en: {
                history_page_title: "Booking History | Clevo",
                history_title: "Booking History", filter_all: "All", filter_completed: "Completed", 
                filter_pending: "Pending", filter_ongoing: "Ongoing", 
                no_history: "No bookings right now", btn_track: "Track Now",
                btn_details: "Order Details", btn_rate: "Rate", btn_complaint: "Send Complaint",
                rate_service: "Rate Service", rate_placeholder: "Write your comment...", btn_save_rate: "Save Rating", paid_from_plan: "From plan"
            }
        };
        const csrfToken = <?= json_encode($csrf_token) ?>;
        let quickBookingId = 0;
        let quickRating = 0;
        const ratingModal = new bootstrap.Modal(document.getElementById('ratingModal'));

        function openRatingModal(bookingId) {
            quickBookingId = bookingId;
            quickRating = 0;
            document.getElementById('quickComment').value = '';
            setQuickRating(0);
            ratingModal.show();
        }

        function setQuickRating(value) {
            quickRating = value;
            document.querySelectorAll('#quickStars i').forEach((star, index) => {
                star.className = index < value ? 'fas fa-star' : 'far fa-star';
            });
        }

        function submitQuickRating() {
            if (!quickBookingId || quickRating < 1) return;
            const params = new URLSearchParams();
            params.append('action', 'quick_rating');
            params.append('booking_id', quickBookingId);
            params.append('rating', quickRating);
            params.append('comment', document.getElementById('quickComment').value);
            params.append('csrf_token', csrfToken);
            fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(res => res.json())
                .then(data => { if (data.status === 'success') location.reload(); });
        }

        function filterHistory(status, btn) {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.history-card').forEach(card => {
                if (status === 'all' || card.dataset.status === status) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            
            // ترجمة النصوص العادية وثنائية العناوين
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });

            // ترجمة التلميحات / Placeholders داخل الحقول
            document.querySelectorAll('[data-i18n-holder]').forEach(el => {
                const key = el.getAttribute('data-i18n-holder');
                if (translations[lang][key]) el.setAttribute('placeholder', translations[lang][key]);
            });

            // تحديث عنوان الصفحة التلقائي
            if(translations[lang]['history_page_title']) {
                document.title = translations[lang]['history_page_title'];
            }
        };
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>

