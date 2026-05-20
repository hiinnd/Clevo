<?php
session_start();
include(__DIR__ . "/config.php");
include(__DIR__ . "/booking-helpers.php");

if (!isset($_SESSION['userID'])) {
    header("Location: ../general/login.php");
    exit();
}

$userID = (int) $_SESSION['userID'];
$bookingID = (int) ($_GET['id'] ?? 0);
cancelExpiredBookings($conn, $userID);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_rating') {
    header('Content-Type: application/json; charset=utf-8');
    clevo_require_csrf_token(true);

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
    $ok = $save->execute();

    if ($ok) {
        $ownerStmt = $conn->prepare("SELECT s.UserID AS OwnerID, b.Order_Number FROM booking b JOIN stations s ON b.StationID = s.StationID WHERE b.BookingID = ?");
        $ownerStmt->bind_param("i", $bookingID);
        $ownerStmt->execute();
        $owner = $ownerStmt->get_result()->fetch_assoc();
        if ($owner && (int) $owner['OwnerID'] > 0) {
            $title = "تقييم جديد";
            $body = "وصلك تقييم جديد على الطلب #" . ($owner['Order_Number'] ?: $bookingID);
            $type = "info";
            $notice = $conn->prepare("INSERT INTO notifications (user_id, title, body, type) VALUES (?, ?, ?, ?)");
            $notice->bind_param("isss", $owner['OwnerID'], $title, $body, $type);
            $notice->execute();
        }
    }

    echo json_encode(['status' => $ok ? 'success' : 'error']);
    exit;
}

$stmt = $conn->prepare("SELECT b.*, s.Name AS station_name, serv.Name AS service_name, u.CarName, r.Rating, r.Comment, r.Owner_Reply
    FROM booking b
    JOIN stations s ON b.StationID = s.StationID
    JOIN services serv ON b.ServiceID = serv.ServiceID
    JOIN user u ON b.UserID = u.UserID
    LEFT JOIN reviews r ON r.BookingID = b.BookingID
    WHERE b.BookingID = ? AND b.UserID = ? LIMIT 1");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header("Location: ../user/booking-history.php");
    exit();
}
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="order_details_title">تفاصيل الطلب | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { --bg-body: #f8f9fa; --bg-card: #ffffff; --text-main: #111827; --border-color: #dee2e6; --accent-color: #3b82f6; }
        [data-theme="dark"] { --bg-body: #020617; --bg-card: #0f172a; --text-main: #ffffff; --border-color: #334155; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); margin: 0; transition: 0.3s ease; }
        .header-nav { display: flex; align-items: center; justify-content: space-between; padding: 20px 0; }
        .circle-back-btn { width: 42px; height: 42px; border-radius: 50%; background: var(--bg-card); color: var(--text-main); display: flex; align-items: center; justify-content: center; text-decoration: none; border: 1px solid var(--border-color); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }
        .detail-card { background: var(--bg-card); border-radius: 25px; padding: 25px; border: 1px solid var(--border-color); box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        .status-badge { border-radius: 50px; padding: 6px 20px; font-size: 0.85rem; font-weight: 700; }
        .price-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 0.95rem; gap: 16px; }
        .rating-stars { font-size: 1.8rem; color: #475569; cursor: pointer; direction: ltr; }
        .rating-stars .fa-star.active { color: #fbbf24; }
        .feedback-box { border-radius: 15px; background: var(--bg-body); color: var(--text-main); border: 1px solid var(--border-color); padding: 12px; width: 100%; outline: none; resize: none; }
        .btn-confirm { border-radius: 50px; padding: 12px; font-weight: 700; }
    </style>
</head>
<body class="p-3">
    <div class="container pb-5">
        <div class="header-nav">
            <a href="../user/booking-history.php" class="circle-back-btn shadow-sm"><i class="fas fa-arrow-right" id="backIcon"></i></a>
            <h5 class="mb-0 fw-bold"><span data-i18n="booking_id_label">تفاصيل الحجز</span> #<?= htmlspecialchars($order['Order_Number'] ?: $bookingID) ?></h5>
            <div style="width: 42px;"></div>
        </div>

        <div class="detail-card mt-2 shadow-sm">
            <div class="text-center mb-4">
                <div class="status-badge bg-success text-white mb-2 shadow-sm"><?= htmlspecialchars($order['Status']) ?></div>
                <h6 class="opacity-75 small"><?= htmlspecialchars($order['Booking_Date']) ?> | <?= htmlspecialchars($order['Booking_Time']) ?></h6>
            </div>
            <div class="price-row"><span class="opacity-75">المغسلة:</span><strong class="text-primary"><?= htmlspecialchars($order['station_name']) ?></strong></div>
            <div class="price-row"><span class="opacity-75">نوع الخدمة:</span><strong><?= htmlspecialchars($order['service_name']) ?></strong></div>
            <div class="price-row"><span class="opacity-75">المركبة:</span><strong><?= htmlspecialchars($order['CarName'] ?: '-') ?></strong></div>
            <div class="price-row"><span class="opacity-75">طريقة الخدمة:</span><strong><?= htmlspecialchars($order['Service_Location']) ?></strong></div>
            <hr class="my-3 opacity-25">
            <div class="mt-3 d-flex justify-content-between align-items-center border-top pt-3">
                <span class="fw-bold fs-5">الإجمالي:</span>
                <span class="text-primary fw-black" style="font-size: 1.6rem;"><?= number_format((float) $order['Total_Price'], 2) ?> JOD</span>
            </div>
        </div>

        <?php if (!empty($order['Owner_Reply'])): ?>
            <div class="detail-card mt-4 shadow-sm">
                <h6 class="fw-bold">رد صاحب المغسلة</h6>
                <p class="mb-0 small"><?= nl2br(htmlspecialchars($order['Owner_Reply'])) ?></p>
            </div>
        <?php endif; ?>

        <div class="detail-card mt-4 shadow-sm text-center">
            <h6 class="fw-bold mb-1">تقييم الخدمة</h6>
            <p class="small opacity-75 mb-3" id="ratingSub">رأيك يصل لصاحب المغسلة ويساعدنا نتابع أي مشكلة.</p>
            <div class="rating-stars mb-3">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="fas fa-star mx-1 <?= ((int)($order['Rating'] ?? 0) >= $i) ? 'active' : '' ?>" data-value="<?= $i ?>" onclick="setRating(<?= $i ?>)"></i>
                <?php endfor; ?>
            </div>
            <textarea class="feedback-box small" id="feedbackInput" rows="3" placeholder="اكتب تقييمك أو المشكلة هنا..."><?= htmlspecialchars($order['Comment'] ?? '') ?></textarea>
            <button onclick="submitRating()" class="btn btn-primary w-100 mt-3 btn-confirm py-2">إرسال التقييم</button>
        </div>
    </div>

    <div class="modal fade" id="msgModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4">
                <div id="msgIcon" class="mb-3 fs-1"></div>
                <h6 id="msgTitle" class="fw-bold"></h6>
                <p id="msgBody" class="small opacity-75 mb-0"></p>
                <button class="btn btn-primary rounded-pill w-100 mt-3" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentRating = <?= (int) ($order['Rating'] ?? 0) ?>;
        const csrfToken = <?= json_encode($csrf_token) ?>;
        const msgModal = new bootstrap.Modal(document.getElementById('msgModal'));
        function showMsg(type, title, body) {
            document.getElementById('msgIcon').innerHTML = type === 'success' ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-circle-exclamation text-warning"></i>';
            document.getElementById('msgTitle').innerText = title;
            document.getElementById('msgBody').innerText = body;
            msgModal.show();
        }
        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');
        };
        function setRating(val) {
            currentRating = val;
            document.querySelectorAll('.rating-stars i').forEach((s, i) => s.classList.toggle('active', i < val));
        }
        function submitRating() {
            if (currentRating === 0) {
                showMsg('warning', 'تنبيه', 'يرجى اختيار عدد النجوم أولا');
                return;
            }
            const params = new URLSearchParams();
            params.append('action', 'submit_rating');
            params.append('rating', currentRating);
            params.append('comment', document.getElementById('feedbackInput').value);
            params.append('csrf_token', csrfToken);
            fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(res => res.json())
                .then(data => {
                    showMsg(data.status === 'success' ? 'success' : 'warning', data.status === 'success' ? 'تم بنجاح' : 'تعذر الحفظ', data.status === 'success' ? 'تم حفظ تقييمك بنجاح' : 'تعذر حفظ التقييم');
                    if (data.status === 'success') {
                        document.getElementById('msgModal').addEventListener('hidden.bs.modal', () => location.reload(), { once: true });
                    }
                });
        }
    </script>
    <script src="/Clevo/assets/lamea.js"></script>
</body>
</html>
