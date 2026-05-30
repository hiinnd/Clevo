<?php

session_start();
include __DIR__ . '/../includes/config.php'; 
$owner_id = clevo_require_role('owner', '../general/login.php');


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'submit_reply') {
    clevo_require_csrf_token(true);
    $review_id = mysqli_real_escape_string($conn, $_POST['review_id']);
    $reply_text = mysqli_real_escape_string($conn, $_POST['reply_text']);
    
    
    $update = $conn->prepare("UPDATE reviews r JOIN booking b ON r.BookingID = b.BookingID JOIN stations s ON b.StationID = s.StationID SET r.Owner_Reply = ? WHERE r.ReviewID = ? AND s.UserID = ?");
    $update->bind_param("sii", $reply_text, $review_id, $owner_id);
    
    if ($update->execute() && $update->affected_rows > 0) {
        
        $user_query = mysqli_query($conn, "SELECT b.UserID FROM booking b JOIN reviews r ON b.BookingID = r.BookingID WHERE r.ReviewID = '$review_id'");
        $user_data = mysqli_fetch_assoc($user_query);
        $customer_id = $user_data['UserID'];

        
        $sql_notify = "INSERT INTO notifications (user_id, title, body, type) 
                       VALUES ('$customer_id', 'رد جديد من الإدارة', 'قام صاحب المغسلة بالرد على تقييمك الأخير.', 'info')";
        mysqli_query($conn, $sql_notify);

        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
    }
    exit;
}
?>
<?php
$reviews_query = $conn->prepare("SELECT r.*, b.Order_Number, u.UserName, u.ProfilePic
    FROM reviews r
    JOIN booking b ON r.BookingID = b.BookingID
    JOIN stations s ON b.StationID = s.StationID
    JOIN user u ON b.UserID = u.UserID
    WHERE s.UserID = ?
    ORDER BY r.ReviewID DESC");
$reviews_query->bind_param("i", $owner_id);
$reviews_query->execute();
$reviews_res = $reviews_query->get_result();

$stats_query = $conn->prepare("SELECT COUNT(*) AS total_reviews, COALESCE(AVG(r.Rating), 0) AS avg_rating, SUM(CASE WHEN r.Rating >= 4 THEN 1 ELSE 0 END) AS positive_reviews, SUM(CASE WHEN r.Owner_Reply IS NULL OR r.Owner_Reply = '' THEN 1 ELSE 0 END) AS needs_reply
    FROM reviews r
    JOIN booking b ON r.BookingID = b.BookingID
    JOIN stations s ON b.StationID = s.StationID
    WHERE s.UserID = ?");
$stats_query->bind_param("i", $owner_id);
$stats_query->execute();
$reviewStats = $stats_query->get_result()->fetch_assoc() ?: ['total_reviews' => 0, 'avg_rating' => 0, 'positive_reviews' => 0, 'needs_reply' => 0];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="recent_reviews">التقييمات والردود | Clevo</title>
    <link class="bootstrap-css" rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --accent: #007bff; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #495057; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #007bff;
            --star-color: #ffc107; --border-color: #dee2e6; --input-bg: #ffffff; --input-text: #000000;
        }

        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #0f172a; --text-main: #ffffff !important; --text-muted: #cbd5e1 !important; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --input-bg: #1a2236; --input-text: #ffffff !important;
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-wrapper { display: flex; min-height: 100vh; }

        /* Sidebar الموحد الجديد من صفحة الإشعارات */
        .sidebar { width: 260px; background: var(--bg-sidebar); padding: 30px 15px; position: sticky; top: 0; min-height: 100vh; display: flex; flex-direction: column; transition: all 0.3s ease; }
        .nav-link { color: rgba(255, 255, 255, 0.8) !important; padding: 12px; border-radius: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; font-size: 0.95rem; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { background: var(--accent); color: white !important; }
        .btn-ctrl { width: 35px; height: 35px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); color: white; border: 0; display: inline-flex; align-items: center; justify-content: center; }
        
        /* Content Area & Header */
        .content-area { flex: 1; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-back-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; }

        .stat-box { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 24px; padding: 20px; text-align: center; height: 100%; color: var(--text-main); }
        
        .review-card { background: var(--bg-card); border-radius: 24px; padding: 25px; border: 1px solid var(--border-color); margin-bottom: 20px; transition: 0.3s; color: var(--text-main); position: relative; }
        [dir="rtl"] .review-card { border-right: 5px solid var(--primary-blue); }
        [dir="ltr"] .review-card { border-left: 5px solid var(--primary-blue); }

        .user-img { width: 50px; height: 50px; border-radius: 50%; background: var(--primary-blue); color: white; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 1.2rem; }
        .stars { color: var(--star-color); font-size: 0.9rem; }
        
        .reply-box { background: rgba(0, 123, 255, 0.05); border-radius: 18px; padding: 20px; margin-top: 15px; border-right: 5px solid var(--primary-blue); }
        [dir="ltr"] .reply-box { border-right: none; border-left: 5px solid var(--primary-blue); }
        
        /* وضوح البوكس والخط بالكامل بالثيمين */
        .reply-input { border-radius: 12px; border: 1px solid var(--border-color); padding: 12px; font-size: 0.95rem; width: 100%; background: var(--input-bg); color: var(--input-text) !important; outline: none; font-family: 'Cairo', sans-serif; }
        .reply-input::placeholder { color: var(--text-muted); opacity: 0.6; }
        .owner-badge { background: var(--primary-blue); color: white; font-size: 0.75rem; padding: 4px 12px; border-radius: 50px; margin-bottom: 8px; display: inline-block; font-weight: 800; }

        /* وضوح مودالات الأكشن والخروج */
        .modal-content { border-radius: 30px; background-color: var(--bg-card) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); }

        @media (max-width: 768px) {
            .main-wrapper { display: block; }
            .sidebar { width: 100%; height: auto; min-height: auto; position: relative; border-radius: 0 0 20px 20px; padding: 15px; margin-bottom: 10px; }
            .sidebar .nav { flex-direction: row !important; overflow-x: auto; white-space: nowrap; gap: 5px; }
            .sidebar h4 { display: none; }
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

    <div class="container-fluid p-0">
        <div class="main-wrapper">
            
            <?php include __DIR__ . '/../includes/shared_owner_sidebar.php'; ?>

            <main class="content-area">
                <div class="orders-header">
                    <div class="container d-flex justify-content-between align-items-center">
                        <a href="owner-dashboard.php" class="circle-back-btn shadow-sm">
                            <i class="fas fa-arrow-right" id="backIcon"></i>
                        </a>
                        <h6 class="mb-0 fw-bold" data-i18n="reviews_center_title">مركز إدارة التقييمات</h6>
                        <div style="width: 40px;"></div>
                    </div>
                </div>

                <div class="container mt-4 px-4 mx-auto" style="max-width: 900px;">
                    <div class="row g-3 mb-4 text-start">
                        <div class="col-md-4">
                            <div class="stat-box shadow-sm">
                                <h2 class="fw-bold text-primary mb-0"><?= number_format((float)$reviewStats['avg_rating'], 1) ?></h2>
                                <div class="stars"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i></div>
                                <small class="fw-bold d-block mt-1 opacity-75" data-i18n="overall_rating">المعدل العام</small>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="stat-box d-flex align-items-center justify-content-around shadow-sm">
                                <div><h4 class="fw-bold mb-0"><?= (int)$reviewStats['total_reviews'] ?></h4><small class="fw-bold opacity-75" data-i18n="total_reviews">إجمالي التقييمات</small></div>
                                <div class="vr opacity-25"></div>
                                <div><h4 class="fw-bold mb-0 text-success"><?= (int)$reviewStats['positive_reviews'] ?></h4><small class="fw-bold opacity-75" data-i18n="positive_rev">إيجابي</small></div>
                                <div class="vr opacity-25"></div>
                                <div><h4 class="fw-bold mb-0 text-danger"><?= (int)$reviewStats['needs_reply'] ?></h4><small class="fw-bold opacity-75" data-i18n="needs_reply">تحتاج رد</small></div>
                            </div>
                        </div>
                    </div>

                    <div id="reviewsList">
                        <?php if($reviews_res->num_rows > 0): while($review = $reviews_res->fetch_assoc()): ?>
                            <div class="review-card shadow-sm text-start" id="rev-<?= (int)$review['ReviewID'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="user-img"><?= strtoupper(substr($review['UserName'], 0, 1)) ?></div>
                                        <div>
                                            <h6 class="mb-0 fw-bold user-name-el" data-name="<?php echo htmlspecialchars($review['UserName']); ?>"><?= htmlspecialchars($review['UserName']) ?> <span class="badge bg-light text-dark border ms-2 fw-normal small">#<?= htmlspecialchars($review['Order_Number']) ?></span></h6>
                                            <div class="stars"><?php for($i = 1; $i <= 5; $i++): ?><i class="<?= $i <= (int)$review['Rating'] ? 'fas' : 'far' ?> fa-star"></i><?php endfor; ?></div>
                                        </div>
                                    </div>
                                </div>
                                <p class="mt-3 mb-2 fw-bold opacity-100 db-comment-text"><?= nl2br(htmlspecialchars($review['Comment'])) ?></p>
                                
                                <?php if(!empty($review['Owner_Reply'])): ?>
                                    <div class="reply-box shadow-sm" style="display:block"><span class="owner-badge" data-i18n="owner_reply_label">رد الإدارة</span><p class="mb-0 small db-reply-text"><?= nl2br(htmlspecialchars($review['Owner_Reply'])) ?></p></div>
                                <?php endif; ?>
                                
                                <div class="d-flex gap-2 mt-3"><button onclick="toggleReply('<?= (int)$review['ReviewID'] ?>')" class="btn btn-sm btn-outline-primary rounded-pill px-4 fw-bold shadow-sm"><i class="fas fa-reply ms-1"></i> <span data-i18n="btn_add_reply">إضافة رد</span></button></div>
                                
                                <div class="reply-box shadow-sm" id="reply-box-<?= (int)$review['ReviewID'] ?>" style="display:none;">
                                    <span class="owner-badge" data-i18n="owner_reply_label">رد الإدارة</span>
                                    <textarea class="reply-input mb-3" rows="3" placeholder="اكتب ردك هنا..." data-i18n="[placeholder]reply_placeholder"></textarea>
                                    <div class="d-flex justify-content-end gap-2">
                                        <button onclick="handleReply('<?= (int)$review['ReviewID'] ?>')" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold" data-i18n="btn_send_reply">إرسال الرد</button>
                                        <button onclick="toggleReply('<?= (int)$review['ReviewID'] ?>')" class="btn btn-light btn-sm rounded-pill px-3" data-i18n="btn_cancel">إلغاء</button>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; else: ?>
                            <div class="review-card text-center opacity-75" data-i18n="no_reviews_yet">لا توجد تقييمات بعد</div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center border-0 shadow-lg">
                <div id="modalIcon" class="mb-3"></div>
                <h5 id="modalTitle" class="fw-bold mb-2"></h5>
                <p id="modalBody" class="small opacity-75 mb-3"></p>
                <button class="btn btn-primary w-100 rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="logoutModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="text-danger mb-3"><i class="fas fa-sign-out-alt fa-3x"></i></div>
                <h5 class="fw-bold" data-i18n="logout_title">تأكيد الخروج</h5>
                <p class="small opacity-75" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
                <div class="d-grid gap-2 mt-4">
                    <a href="../general/logout.php" class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</a>
                    <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                nav_main: "الرئيسية", nav_orders: "الطلبات", add_st_page_title: "إعداد المغسلة", services_list: "الخدمات", drivers_page_title: "طاقم العمل", recent_reviews: "التقييمات", serv_wallet: "المحفظة", nav_logout: "خروج", nav_notifications: "الإشعارات",
                reviews_center_title: "مركز إدارة التقييمات", overall_rating: "المعدل العام", total_reviews: "إجمالي التقييمات", positive_rev: "إيجابي", needs_reply: "تحتاج رد",
                owner_reply_label: "رد الإدارة", logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء",
                reply_success_title: "تم الإرسال", reply_success_body: "تم إرسال الرد للزبون بنجاح.", btn_ok: "موافق",
                btn_add_reply: "إضافة رد", reply_placeholder: "اكتب ردك هنا...", btn_send_reply: "إرسال الرد", no_reviews_yet: "لا توجد تقييمات بعد"
            },
            en: {
                nav_main: "Dashboard", nav_orders: "Orders", add_st_page_title: "Station Setup", services_list: "Services", drivers_page_title: "Team", recent_reviews: "Reviews", serv_wallet: "Wallet", nav_logout: "Logout", nav_notifications: "Notifications",
                reviews_center_title: "Reviews Management", overall_rating: "Overall Rate", total_reviews: "Total Reviews", positive_rev: "Positive", needs_reply: "Needs Reply",
                owner_reply_label: "Management Reply", logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel",
                reply_success_title: "Sent Successfully", reply_success_body: "Your reply has been sent.", btn_ok: "OK",
                btn_add_reply: "Add Reply", reply_placeholder: "Write your reply...", btn_send_reply: "Send Reply", no_reviews_yet: "No reviews found"
            }
        };

        const showM = (id) => new bootstrap.Modal(document.getElementById(id)).show();

        function toggleReply(id) {
            const box = document.getElementById('reply-box-' + id);
            box.style.display = (box.style.display === 'block') ? 'none' : 'block';
        }

        function handleReply(id) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const replyInput = document.querySelector(`#reply-box-${id} .reply-input`);
            const replyText = replyInput.value.trim();

            if (!replyText) return;

            const formData = new URLSearchParams();
            formData.append('action', 'submit_reply');
            formData.append('csrf_token', <?= json_encode(generate_csrf_token()) ?>);
            formData.append('review_id', id);
            formData.append('reply_text', replyText);

            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    document.getElementById('modalIcon').innerHTML = '<i class="fas fa-check-circle text-success fa-4x"></i>';
                    document.getElementById('modalTitle').innerText = translations[lang].reply_success_title;
                    document.getElementById('modalBody').innerText = translations[lang].reply_success_body;
                    document.getElementById('reply-box-' + id).style.display = 'none';
                    showM('statusModal');
                    setTimeout(() => location.reload(), 1500);
                }
            })
            .catch(error => console.error('Error:', error));
        }

        function toggleLanguage() {
            const currentLang = localStorage.getItem('app_lang') || 'ar';
            localStorage.setItem('app_lang', currentLang === 'ar' ? 'en' : 'ar');
            location.reload();
        }

        function toggleGlobalTheme() {
            const theme = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            localStorage.setItem('global_theme', theme);
            location.reload();
        }

        // دالة الترجمة الحية لنصوص الكومنتات والأسماء القادمة من قاعدة البيانات دون لمس الـ Backend
        async function translateTextLive(text) {
            if (!text || !text.trim()) return text;
            try {
                const res = await fetch(`https://translate.googleapis.com/translate_a/single?client=gtx&sl=ar&tl=en&dt=t&q=${encodeURIComponent(text)}`);
                const data = await res.json();
                return data[0].map(item => item[0]).join('');
            } catch (e) {
                return text;
            }
        }

        window.onload = async () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'light';
            const isRtl = lang === 'ar';
            
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
            document.getElementById('bootstrap-css').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) {
                    if (key.includes('[placeholder]')) el.setAttribute('placeholder', translations[lang][key.replace('[placeholder]', '')]);
                    else el.innerText = translations[lang][key];
                }
            });

            // إذا تحول النظام للغة الإنجليزية، قم بترجمة الكومنتات والأسماء والردود حياً تلقائياً
            if (!isRtl) {
                document.querySelectorAll('.user-name-el').forEach(async (el) => {
                    const rawName = el.getAttribute('data-name');
                    const badgeHtml = el.querySelector('span') ? el.querySelector('span').outerHTML : '';
                    const translatedName = await translateTextLive(rawName);
                    el.innerHTML = translatedName + ' ' + badgeHtml;
                });

                document.querySelectorAll('.db-comment-text, .db-reply-text').forEach(async (el) => {
                    el.innerText = await translateTextLive(el.innerText);
                });
            }

            if(!isRtl) document.getElementById('backIcon').className = "fas fa-arrow-left";
            const langBtnText = document.getElementById('langBtnText');
            if(langBtnText) langBtnText.innerText = isRtl ? 'EN' : 'AR';
            const icon = document.getElementById('themeIcon');
            if(icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        };
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>
