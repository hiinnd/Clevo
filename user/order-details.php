<?php
session_start();
include(__DIR__ . "/../includes/config.php");
include(__DIR__ . "/../includes/booking-helpers.php");

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
    $result = $check->get_result();

    if (!$rating || !$result->fetch_assoc()) {
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

$stmt = $conn->prepare("SELECT b.*, s.Name AS station_name, s.Location AS station_address, serv.Name AS service_name, u.CarName, u.PhoneNumber, d.UserName AS driver_name, d.PhoneNumber AS driver_phone, r.Rating, r.Comment, r.Owner_Reply
    FROM booking b
    JOIN stations s ON b.StationID = s.StationID
    JOIN services serv ON b.ServiceID = serv.ServiceID
    JOIN user u ON b.UserID = u.UserID
    LEFT JOIN user d ON d.UserID = b.DriverID
    LEFT JOIN reviews r ON r.BookingID = b.BookingID
    WHERE b.BookingID = ? AND b.UserID = ? LIMIT 1");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    header("Location: booking-history.php");
    exit();
}

$isCompleted = $order['Status'] === 'Completed';
$currentRating = (int) ($order['Rating'] ?? 0);
$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title class="lang-node" data-ar="تفاصيل الطلب | Clevo" data-en="Order Details | Clevo">تفاصيل الطلب | Clevo</title>
    <link rel="stylesheet" id="bootstrap-css" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --bg-body: #f8f9fa; 
            --bg-card: #ffffff; 
            --text-main: #111827; 
            --border-color: #dee2e6; 
            --accent-color: #0284c7; 
            --text-label: #64748b;
            --btn-light-bg: #e2e8f0;
            --btn-light-text: #1e293b;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; 
            --bg-card: #0f172a; 
            --text-main: #f8fafc; 
            --border-color: #334155; 
            --text-label: #94a3b8;
            --btn-light-bg: #1e293b;
            --btn-light-text: #f8fafc;
        }
        body { font-family: 'Cairo', sans-serif; background: var(--bg-body); color: var(--text-main); margin: 0; min-height: 100vh; }
        .detail-card { background: var(--bg-card); border-radius: 22px; padding: 24px; border: 1px solid var(--border-color); box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08); color: var(--text-main); }
        .status-badge { border-radius: 30px; padding: 8px 20px; font-size: 0.9rem; font-weight: 700; }
        .info-row { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; color: var(--text-main); }
        .info-label { color: var(--text-label); }
        .rating-stars { font-size: 1.8rem; color: #475569; cursor: pointer; direction: ltr; display: inline-flex; }
        .rating-stars .fa-star.active { color: #f59e0b; }
        .feedback-box { width: 100%; min-height: 120px; border-radius: 16px; border: 1px solid var(--border-color); padding: 14px; resize: vertical; background: var(--bg-body); color: var(--text-main); }
        .btn-confirm { border-radius: 999px; padding: 12px 24px; font-weight: 700; }
        .btn-theme-adaptive { background-color: var(--btn-light-bg); color: var(--btn-light-text); border: 1px solid var(--border-color); }
        .btn-theme-adaptive:hover { background-color: var(--border-color); color: var(--text-main); }
        .text-secondary-adaptive { color: var(--text-label) !important; }
    </style>
</head>
<body class="p-3">
    <div class="container py-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <a href="booking-history.php" class="btn btn-theme-adaptive rounded-circle shadow-sm d-flex align-items-center justify-content-center" style="width:44px; height:44px;">
                <i id="back-arrow-icon" class="fas fa-arrow-right"></i>
            </a>
            <h5 class="mb-0 fw-bold">
                <span class="lang-node" data-ar="تفاصيل الطلب" data-en="Order Details">تفاصيل الطلب</span> 
                #<?= htmlspecialchars($order['Order_Number'] ?: $bookingID) ?>
            </h5>
            <div style="width:44px;"></div>
        </div>

        <div class="detail-card mb-4">
            <div class="d-flex flex-column gap-3">
                <div class="d-flex flex-column align-items-start gap-2">
                    <span class="status-badge bg-primary text-white">
                        <span class="lang-node" data-ar="الحالة:" data-en="Status:">الحالة:</span> 
                        <?= htmlspecialchars($order['Status']) ?>
                    </span>
                    <small class="opacity-75">
                        <span class="lang-node" data-ar="التاريخ:" data-en="Date:">التاريخ:</span> 
                        <?= htmlspecialchars($order['Booking_Date']) ?> | <?= htmlspecialchars($order['Booking_Time']) ?>
                    </small>
                </div>

                <div class="row gy-3">
                    <div class="col-12 col-md-6">
                        <div class="info-row">
                            <span class="info-label lang-node" data-ar="المحطة:" data-en="Station:">المحطة:</span>
                            <strong><?= htmlspecialchars($order['station_name']) ?></strong>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="info-row">
                            <span class="info-label lang-node" data-ar="الخدمة:" data-en="Service:">الخدمة:</span>
                            <strong><?= htmlspecialchars($order['service_name']) ?></strong>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="info-row">
                            <span class="info-label lang-node" data-ar="نوع الخدمة:" data-en="Service Type:">نوع الخدمة:</span>
                            <!-- نوع الخدمة إنجليزي دائماً -->
                            <strong><?= htmlspecialchars($order['Service_Location']) ?></strong>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="info-row">
                            <span class="info-label lang-node" data-ar="طريقة الدفع:" data-en="Payment Method:">طريقة الدفع:</span>
                            <strong>
                                <?php if(empty($order['Payment_Method'])): ?>
                                    <span class="lang-node" data-ar="غير محددة" data-en="Not Specified">غير محددة</span>
                                <?php elseif($order['Payment_Method'] === 'plan'): ?>
                                    <span class="lang-node" data-ar="من الباقة" data-en="From plan">من الباقة</span>
                                <?php else: ?>
                                    <?= htmlspecialchars($order['Payment_Method']) ?>
                                <?php endif; ?>
                            </strong>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="info-row">
                            <span class="info-label lang-node" data-ar="المركبة:" data-en="Vehicle:">المركبة:</span>
                            <strong><?= htmlspecialchars($order['CarName'] ?: '-') ?></strong>
                        </div>
                    </div>
                    <?php if (!empty($order['driver_name'])): ?>
                        <div class="col-12 col-md-6">
                            <div class="info-row">
                                <span class="info-label lang-node" data-ar="السائق:" data-en="Driver:">السائق:</span>
                                <strong><?= htmlspecialchars($order['driver_name']) ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($order['station_address'])): ?>
                        <div class="col-12">
                            <div class="info-row">
                                <span class="info-label lang-node" data-ar="العنوان / المنطقة:" data-en="Address / Area:">العنوان / المنطقة:</span>
                                <!-- اسم المنطقة إنجليزي دائماً -->
                                <strong><?= htmlspecialchars($order['station_address']) ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="info-row mt-3">
                    <span class="info-label lang-node" data-ar="الإجمالي:" data-en="Total:">الإجمالي:</span>
                    <strong class="text-success" style="font-size:1.25rem;">
                        <?php if (($order['Payment_Method'] ?? '') === 'plan'): ?>
                            <span class="lang-node" data-ar="مغطى من الباقة" data-en="Covered by plan">مغطى من الباقة</span>
                        <?php else: ?>
                            <?= number_format((float) $order['Total_Price'], 2) ?> JOD
                        <?php endif; ?>
                    </strong>
                </div>
            </div>
        </div>

        <?php if ($order['Status'] === 'Accepted' && empty($order['Payment_Method'])): ?>
            <div class="detail-card mb-4">
                <h6 class="fw-bold mb-2 lang-node" data-ar="الدفع مطلوب" data-en="Payment Required">الدفع مطلوب</h6>
                <p class="small text-secondary-adaptive mb-3 lang-node" 
                   data-ar="<?= $order['Service_Location'] === 'station' ? 'خدمة المحطة تقبل كاش، فيزا، محفظة، أو نقاط ولاء.' : 'هذه الخدمة تقبل فيزا، محفظة، أو نقاط ولاء فقط.' ?>" 
                   data-en="<?= $order['Service_Location'] === 'station' ? 'Station service accepts Cash, Visa, Wallet, or Loyalty Points.' : 'This service accepts Visa, Wallet, or Loyalty Points only.' ?>">
                    <?= $order['Service_Location'] === 'station' ? 'خدمة المحطة تقبل كاش، فيزا، محفظة، أو نقاط ولاء.' : 'هذه الخدمة تقبل فيزا، محفظة، أو نقاط ولاء فقط.' ?>
                </p>
                <a href="../includes/payment-method.php?id=<?= (int)$bookingID ?>" class="btn btn-primary btn-confirm w-100 lang-node" data-ar="اختيار طريقة الدفع" data-en="Select Payment Method">اختيار طريقة الدفع</a>
            </div>
        <?php endif; ?>

        <?php if (!empty($order['Owner_Reply'])): ?>
            <div class="detail-card mb-4">
                <h6 class="fw-bold mb-2 lang-node" data-ar="رد صاحب المغسلة" data-en="Station Owner Reply">رد صاحب المغسلة</h6>
                <p class="mb-0 small text-secondary-adaptive"><?= nl2br(htmlspecialchars($order['Owner_Reply'])) ?></p>
            </div>
        <?php endif; ?>

        <div class="detail-card mb-4">
            <h6 class="fw-bold mb-3 lang-node" data-ar="معلومات التواصل" data-en="Contact Information">معلومات التواصل</h6>
            <div class="info-row mb-2">
                <span class="info-label lang-node" data-ar="العميل:" data-en="Customer:">العميل:</span>
                <strong><?= htmlspecialchars($_SESSION['userName'] ?? '-') ?></strong>
            </div>
            <div class="info-row">
                <span class="info-label lang-node" data-ar="الهاتف:" data-en="Phone:">الهاتف:</span>
                <strong><?= htmlspecialchars($order['PhoneNumber'] ?: '-') ?></strong>
            </div>
        </div>

        <div class="detail-card mb-4">
            <h6 class="fw-bold mb-3 lang-node" data-ar="تقييم الخدمة" data-en="Service Rating">تقييم الخدمة</h6>
            <?php if ($isCompleted): ?>
                <div class="mb-3 rating-stars" id="ratingStars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star <?= $currentRating >= $i ? 'active' : '' ?>" data-value="<?= $i ?>" onclick="setRating(<?= $i ?>)"></i>
                    <?php endfor; ?>
                </div>
                <textarea id="feedbackInput" class="feedback-box" placeholder="اكتب ملاحظاتك هنا..."></textarea>
                <button onclick="submitRating()" class="btn btn-primary btn-confirm mt-3 w-100 lang-node" data-ar="حفظ التقييم" data-en="Save Rating">حفظ التقييم</button>
            <?php else: ?>
                <p class="small text-secondary-adaptive lang-node" data-ar="يمكنك ترك تقييم بعد اكتمال الطلب." data-en="You can leave a review after the order is completed.">يمكنك ترك تقييم بعد اكتمال الطلب.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="msgModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4" style="background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border-color);">
                <div id="msgIcon" class="mb-3 fs-1"></div>
                <h6 id="msgTitle" class="fw-bold"></h6>
                <p id="msgBody" class="small text-secondary-adaptive mb-0"></p>
                <button class="btn btn-primary rounded-pill w-100 mt-3" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentRating = <?= $currentRating ?>;
        const csrfToken = <?= json_encode($csrf_token) ?>;
        const msgModal = new bootstrap.Modal(document.getElementById('msgModal'));
        
        // جلب الإعدادات الحالية للغة والثيم
        const theme = localStorage.getItem('global_theme') || 'dark';
        const lang = localStorage.getItem('app_lang') || 'ar';

        // نصوص التنبيهات باللغتين
        const translations = {
            ar: {
                warning: 'تنبيه',
                selectStars: 'يرجى اختيار عدد النجوم.',
                success: 'تم بنجاح',
                saved: 'تم حفظ التقييم بنجاح.',
                error: 'تعذر الحفظ',
                failed: 'حدث خطأ أثناء حفظ التقييم.',
                placeholder: 'اكتب ملاحظاتك هنا...'
            },
            en: {
                warning: 'Warning',
                selectStars: 'Please select a rating star.',
                success: 'Success',
                saved: 'Rating saved successfully.',
                error: 'Error',
                failed: 'An error occurred while saving the rating.',
                placeholder: 'Write your comments here...'
            }
        };

        function showMsg(type, title, body) {
            document.getElementById('msgIcon').innerHTML = type === 'success'
                ? '<i class="fas fa-check-circle text-success"></i>'
                : '<i class="fas fa-circle-exclamation text-warning"></i>';
            document.getElementById('msgTitle').innerText = title;
            document.getElementById('msgBody').innerText = body;
            msgModal.show();
        }

        window.onload = function() {
            // تطبيق الثيم واللغة والاتجاهات
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            
            const bootstrapCss = document.getElementById('bootstrap-css');
            const arrowIcon = document.getElementById('back-arrow-icon');
            
            if (lang === 'ar') {
                document.documentElement.setAttribute('dir', 'rtl');
                bootstrapCss.href = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css";
                arrowIcon.className = "fas fa-arrow-right"; // سهم يمين بالعربي ومكانه عاليمين تلقائي بالـ RTL
            } else {
                document.documentElement.setAttribute('dir', 'ltr');
                bootstrapCss.href = "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
                arrowIcon.className = "fas fa-arrow-left"; // سهم شمال بالإنجليزي ومكانه عالشمال تلقائي بالـ LTR
            }

            // ترجمة النصوص الثابتة الحاملة لكلاس lang-node
            document.querySelectorAll('.lang-node').forEach(node => {
                const text = node.getAttribute('data-' + lang);
                if (text) {
                    if (node.tagName === 'TITLE') {
                        document.title = text;
                    } else {
                        node.innerText = text;
                    }
                }
            });

            // ضبط الـ Placeholder للـ Textarea بناء على اللغة
            const feedbackInput = document.getElementById('feedbackInput');
            if (feedbackInput) {
                feedbackInput.placeholder = translations[lang].placeholder;
                feedbackInput.value = <?= json_encode($order['Comment'] ?? '') ?>;
            }
        };

        function setRating(value) {
            currentRating = value;
            document.querySelectorAll('#ratingStars i').forEach((star, index) => {
                star.classList.toggle('active', index < value);
            });
        }

        function submitRating() {
            if (currentRating <= 0) {
                showMsg('warning', translations[lang].warning, translations[lang].selectStars);
                return;
            }
            const params = new URLSearchParams();
            params.append('action', 'submit_rating');
            params.append('rating', currentRating);
            params.append('comment', document.getElementById('feedbackInput').value);
            params.append('csrf_token', csrfToken);
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    showMsg('success', translations[lang].success, translations[lang].saved);
                    document.getElementById('msgModal').addEventListener('hidden.bs.modal', () => location.reload(), { once: true });
                } else {
                    showMsg('warning', translations[lang].error, translations[lang].failed);
                }
            });
        }
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>

