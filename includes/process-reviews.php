<?php
session_start();
include __DIR__ . '/config.php';
include __DIR__ . '/app-helpers.php';


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit_reply') {
    $review_id = mysqli_real_escape_string($conn, $_POST['review_id']);
    $reply_text = mysqli_real_escape_string($conn, $_POST['reply_text']);
    
    $update_sql = "UPDATE reviews SET Owner_Reply = '$reply_text' WHERE ReviewID = '$review_id'";
    
    if (mysqli_query($conn, $update_sql)) {
        $user_query = mysqli_query($conn, "SELECT b.UserID FROM booking b JOIN reviews r ON b.BookingID = r.BookingID WHERE r.ReviewID = '$review_id'");
        $user_data = mysqli_fetch_assoc($user_query);
        $customer_id = $user_data['UserID'];

        $notif_title = 'رد جديد على تقييمك';
        $notif_body = 'قام صاحب الخدمة بالرد على تعليقك.';
        clevoCreateNotification($conn, (int)$customer_id, $notif_title, $notif_body, 'info', 'booking-history.php');

        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>إدارة التقييمات | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root { 
            --bg-body: #f8f9fa; --bg-card: #ffffff; --text-main: #111827; 
            --text-muted: #4b5563; --border-color: #dee2e6; --accent: #3b82f6;
        }
        /* تباين عالي جداً للـ Dark Mode لضمان وضوح كل كلمة */
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-card: #0f172a; --text-main: #ffffff; 
            --text-muted: #cbd5e1; --border-color: #1e293b; --accent: #3b82f6;
        }
        
        body { 
            font-family: 'Cairo', sans-serif; background-color: var(--bg-body); 
            color: var(--text-main); transition: 0.3s ease; margin: 0; padding-bottom: 50px;
        }

        /* سهم الرجوع - يعود للصفحة السابقة تلقائياً */
        .back-nav-container { padding: 20px; display: flex; align-items: center; gap: 15px; }
        .circle-btn { 
            width: 40px; height: 40px; border-radius: 50%; background: var(--bg-card); 
            color: var(--text-main); display: flex; align-items: center; justify-content: center; 
            border: 1px solid var(--border-color); text-decoration: none; box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }

        .review-card { 
            background: var(--bg-card); border-radius: 20px; padding: 20px; 
            border: 1px solid var(--border-color); margin-bottom: 15px; 
        }

        /* كلمة نقداً منفصلة وواضحة */
        .payment-status { 
            display: inline-block; padding: 4px 12px; border-radius: 8px; 
            font-weight: 900; font-size: 0.8rem; background: rgba(16, 185, 129, 0.1); color: #10b981;
        }

        .text-muted { color: var(--text-muted) !important; }
        .form-control { 
            background: var(--bg-body); border: 1px solid var(--border-color); 
            color: var(--text-main) !important; border-radius: 12px; 
        }
        .form-control:focus { background: var(--bg-body); color: var(--text-main); border-color: var(--accent); box-shadow: none; }

        /* تنسيق المودال ليكون واضحاً */
        .modal-content { 
            border-radius: 25px; background: var(--bg-card); 
            color: var(--text-main); border: 1px solid var(--border-color);
        }
        .btn-submit { background: var(--accent); color: white; border-radius: 50px; font-weight: 700; border: none; padding: 10px 25px; }
    </style>
</head>

<body>
    <div class="back-nav-container">
        <a href="javascript:history.back()" class="circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
        <h5 class="mb-0 fw-bold" data-i18n="reviews_title">إدارة التقييمات</h5>
    </div>

    <div class="container">
        <!-- مثال لكرت تقييم -->
        <div class="review-card shadow-sm">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="fw-bold mb-0">أحمد محمد</h6>
                <div class="payment-status" data-i18n="pay_cash">نقداً</div>
            </div>
            <div class="text-warning small mb-2">
                <i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="far fa-star"></i>
            </div>
            <p class="small text-muted mb-3">الخدمة كانت ممتازة جداً والموظف محترم، شكراً لكم.</p>
            
            <div class="reply-section">
                <textarea class="form-control mb-2" id="replyText_1" rows="2" data-i18n="[placeholder]write_reply"></textarea>
                <button onclick="sendReply(1)" class="btn btn-submit w-100">
                    <span data-i18n="btn_send_reply">إرسال الرد</span>
                </button>
            </div>
        </div>
    </div>

    <!-- المودال الموحد للرسائل -->
    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <div id="modalIcon" class="fs-1 mb-2"></div>
                <h6 id="modalTitle" class="fw-bold"></h6>
                <p id="modalBody" class="small text-muted mb-3"></p>
                <button class="btn btn-submit w-100" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/translations.js"></script>
    <script>
        const statusModal = new bootstrap.Modal(document.getElementById('statusModal'));

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');

            if (typeof applyTranslations === 'function') applyTranslations(lang);
        };

        function showMessage(type, titleAr, titleEn, bodyAr, bodyEn) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const icon = type === 'success' ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-circle-exclamation text-danger"></i>';
            
            document.getElementById('modalIcon').innerHTML = icon;
            document.getElementById('modalTitle').innerText = (lang === 'ar' ? titleAr : titleEn);
            document.getElementById('modalBody').innerText = (lang === 'ar' ? bodyAr : bodyEn);
            statusModal.show();
        }

        function sendReply(reviewId) {
            const replyText = document.getElementById('replyText_' + reviewId).value;

            if (!replyText) {
                showMessage('error', 'تنبيه', 'Alert', 'يرجى كتابة الرد أولاً', 'Please write a reply first');
                return;
            }

            // إرسال البيانات للباك إند
            fetch('manage_reviews.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=submit_reply&review_id=${reviewId}&reply_text=${encodeURIComponent(replyText)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showMessage('success', 'تم الإرسال', 'Success', 'تم إرسال ردك وإشعار الزبون بنجاح', 'Reply sent and customer notified');
                    document.getElementById('replyText_' + reviewId).value = '';
                } else {
                    showMessage('error', 'خطأ', 'Error', 'حدث خطأ أثناء الإرسال', 'Error while sending');
                }
            });
        }
    </script>
</body>
</html>