<?php
session_start();
include __DIR__ . '/../includes/config.php';
include __DIR__ . '/../includes/app-helpers.php';

$owner_id = clevo_require_role('owner');

// جلب بيانات الأونر
$stmtUser = $conn->prepare("SELECT ProfilePic, UserName FROM user WHERE UserID = ?");
$stmtUser->bind_param("i", $owner_id);
$stmtUser->execute();
$userRes = $stmtUser->get_result()->fetch_assoc();
$owner_pic = clevoProfileImageUrl($userRes['ProfilePic'] ?? '', $userRes['UserName'] ?? 'Owner');
$owner_name = $userRes['UserName'] ?? "Owner";

// منطق التحديث كمقروء
if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    $notif_id = intval($_GET['read']);
    $stmtRead = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmtRead->bind_param("ii", $notif_id, $owner_id);
    $stmtRead->execute();
    header("Location: notifications.php");
    exit;
}

// جلب الإشعارات - تم الابقاء على الربط الحالي تماماً
$query = "SELECT n.id, n.title, n.body as message, n.type, n.is_read, n.created_at, s.Name AS station_name
    FROM notifications n
    LEFT JOIN stations s ON s.UserID = n.user_id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 50";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $owner_id);
$stmt->execute();
$notifications = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="notifications_title_page">مركز الإشعارات | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --accent: #007bff; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #007bff;
            --border-color: #dee2e6;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #0f172a; --text-main: #ffffff !important; --text-muted: #cbd5e1; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
        }
        body { background: var(--bg-body); color: var(--text-main); font-family: 'Cairo', sans-serif; transition: 0.3s; margin: 0; overflow-x: hidden; }
        .main-wrapper { display: flex; min-height: 100vh; }
        
        /* Sidebar الأنماط الأساسية المعتمدة مسبقاً */
        .sidebar { width: 260px; background: var(--bg-sidebar); padding: 30px 15px; position: sticky; top: 0; min-height: 100vh; display: flex; flex-direction: column; transition: all 0.3s ease; }
        .nav-link { color: rgba(255,255,255,0.8) !important; padding: 12px; border-radius: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; transition: 0.2s; font-size: 0.95rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent); color: white !important; }
        
        /* الهيدر المتطابق هندسياً وبصرياً مع باقي واجهات الأونر بالنظام */
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-back-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; }
        
        /* Main Content & Cards */
        .content-area { flex: 1; padding: 0; }
        .notif-card { background: var(--bg-card); border-radius: 24px; padding: 25px; margin-bottom: 15px; border: 1px solid var(--border-color); transition: 0.3s; cursor: pointer; color: var(--text-main); }
        .notif-card:hover { transform: translateY(-2px); border-color: var(--accent); }
        
        [dir="rtl"] .notif-card.unread { border-right: 5px solid var(--accent); background: rgba(96, 165, 250, 0.05); }
        [dir="ltr"] .notif-card.unread { border-left: 5px solid var(--accent); background: rgba(96, 165, 250, 0.05); }
        
        .btn-ctrl { width: 35px; height: 35px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); color: white; border: 0; display: inline-flex; align-items: center; justify-content: center; }
        .empty-state { background: var(--bg-card); border: 1px dashed var(--border-color); border-radius: 24px; padding: 48px 20px; color: var(--text-muted); }
        .modal-content { border-radius: 30px; background-color: var(--bg-card); color: var(--text-main); border: 1px solid var(--border-color); }

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
    <nav class="sidebar shadow text-center">
        <div class="d-flex gap-2 justify-content-center mb-4">
            <button onclick="toggleGlobalTheme()" class="btn-ctrl"><i id="themeIcon" class="fas fa-moon"></i></button>
            <button onclick="toggleLanguage()" class="btn-ctrl"><span id="langBtnText" class="fw-bold">EN</span></button>
        </div>
        <h4 class="fw-bold mb-4 text-primary">CLEVO</h4>
        <div class="nav flex-column flex-grow-1">
            <a href="owner-dashboard.php" class="nav-link"><i class="fas fa-th-large"></i> <span data-i18n="nav_main">الرئيسية</span></a>
            <a href="incoming-orders.php" class="nav-link"><i class="fas fa-shopping-cart"></i> <span data-i18n="nav_orders">الطلبات</span></a>
            <a href="add-station.php" class="nav-link"><i class="fas fa-store"></i> <span data-i18n="add_st_page_title">إعداد المغسلة</span></a>
            <a href="manage-services.php" class="nav-link"><i class="fas fa-concierge-bell"></i> <span data-i18n="services_list">الخدمات</span></a>
            <a href="manage-drivers.php" class="nav-link"><i class="fas fa-users"></i> <span data-i18n="drivers_page_title">طاقم العمل</span></a>
            <a href="owner-reviews.php" class="nav-link"><i class="fas fa-star"></i> <span data-i18n="recent_reviews">التقييمات</span></a>
            <a href="owner-wallet.php" class="nav-link"><i class="fas fa-wallet"></i> <span data-i18n="serv_wallet">المحفظة</span></a>
            <a href="notifications.php" class="nav-link active"><i class="fas fa-bell"></i> <span data-i18n="nav_notifications">الإشعارات</span></a>
        </div>
        <div class="mt-auto">
            <hr class="text-white opacity-25 my-3">
            <a href="javascript:void(0)" onclick="confirmLogout()" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> <span data-i18n="nav_logout">تسجيل الخروج</span></a>
        </div>
    </nav>

    <div class="content-area">
        <div class="orders-header">
            <div class="container d-flex justify-content-between align-items-center">
                <a href="owner-dashboard.php" class="circle-back-btn shadow-sm">
                    <i class="fas fa-arrow-right" id="backIcon"></i>
                </a>
                <h6 class="mb-0 fw-bold" data-i18n="notifications_title">تنبيهات النظام</h6>
                <div style="width: 40px;"></div>
            </div>
        </div>

        <div class="container mt-4 px-4">
            <?php if($notifications->num_rows > 0): ?>
                <?php while($row = $notifications->fetch_assoc()): ?>
                    <div class="notif-card <?php echo ($row['is_read'] == 0) ? 'unread' : ''; ?>" 
                         onclick="location.href='?read=<?php echo $row['id']; ?>'">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="d-flex gap-3 text-start">
                                <div class="mt-1 text-primary"><i class="fas fa-circle-info"></i></div>
                                <div>
                                    <h6 class="fw-bold mb-1 db-notif-title"><?php echo htmlspecialchars($row['title']); ?></h6>
                                    <p class="small text-muted mb-2 db-notif-msg" style="color: var(--text-main) !important;"><?php echo htmlspecialchars($row['message']); ?></p>
                                    <?php if (!empty($row['station_name'])): ?>
                                        <span class="badge bg-dark text-info"><i class="fas fa-car-wash me-1"></i> <?php echo htmlspecialchars($row['station_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <small class="text-muted opacity-50"><?php echo date('H:i', strtotime($row['created_at'])); ?></small>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state text-center opacity-75">
                    <i class="fas fa-bell-slash fa-4x mb-3 text-primary"></i>
                    <h6 class="fw-bold" data-i18n="no_notifications">لا توجد إشعارات حالياً</h6>
                    <p class="small mb-0" data-i18n="no_notifications_hint">ستظهر إشعارات الطلبات والتقييمات هنا.</p>
                </div>
            <?php endif; ?>
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
            nav_main: "الرئيسية", nav_orders: "الطلبات", add_st_page_title: "إعداد المغسلة", services_list: "الخدمات", drivers_page_title: "طاقم العمل", recent_reviews: "التقييمات", serv_wallet: "المحفظة", nav_notifications: "الإشعارات", nav_logout: "خروج", 
            notifications_title: "تنبيهات النظام", no_notifications: "لا توجد إشعارات حالياً", no_notifications_hint: "ستظهر إشعارات الطلبات والتقييمات هنا.",
            logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء", notifications_title_page: "مركز الإشعارات | Clevo"
        },
        en: {
            nav_main: "Dashboard", nav_orders: "Orders", add_st_page_title: "Station Setup", services_list: "Services", drivers_page_title: "Team", recent_reviews: "Reviews", serv_wallet: "Wallet", nav_notifications: "Notifications", nav_logout: "Logout", 
            notifications_title: "System Notifications", no_notifications: "No notifications right now", no_notifications_hint: "Order and review notifications will appear here.",
            logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel", notifications_title_page: "Notifications | Clevo"
        }
    };

    function toggleLanguage() {
        const next = (localStorage.getItem('app_lang') || 'ar') === 'ar' ? 'en' : 'ar';
        localStorage.setItem('app_lang', next);
        location.reload();
    }

    function toggleGlobalTheme() {
        const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        localStorage.setItem('global_theme', next);
        document.documentElement.setAttribute('data-theme', next);
        document.getElementById('themeIcon').className = next === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }

    function confirmLogout() { new bootstrap.Modal(document.getElementById('logoutModal')).show(); }

    // دالة الترجمة الفورية والذكية لنصوص الداتابيز الحرة لمنع بقائها بالعربية
    async function translateDatabaseText(text) {
        if (!text || !text.trim()) return text;
        try {
            const res = await fetch(`https://translate.googleapis.com/translate_a/single?client=gtx&sl=ar&tl=en&dt=t&q=${encodeURIComponent(text)}`);
            const data = await res.json();
            return data[0].map(item => item[0]).join('');
        } catch (e) {
            return text; // في حال حدوث خطأ بالشبكة يعود النص الأصلي لسلامة التطبيق
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
            if (translations[lang][key]) el.innerText = translations[lang][key];
        });
        if(!isRtl) document.getElementById('backIcon').className = "fas fa-arrow-left";
        document.getElementById('langBtnText').innerText = isRtl ? 'EN' : 'AR';
        document.getElementById('themeIcon').className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

        // إذا كانت اللغة إنجليزية، قم بترجمة محتوى الإشعارات المجلوبة من قاعدة البيانات فوراً
        if (lang === 'en') {
            document.querySelectorAll('.db-notif-title').forEach(async (el) => {
                const text = el.innerText;
                if (text.includes('طلب جديد')) {
                    el.innerText = 'New Order';
                } else {
                    el.innerText = await translateDatabaseText(text);
                }
            });

            document.querySelectorAll('.db-notif-msg').forEach(async (el) => {
                const text = el.innerText;
                // ترجمة ذكية ومحسنة لتنسيق تفاصيل الحجز ونوع الخدمات المكتوبة بالصورة
                let translated = await translateDatabaseText(text);
                translated = translated.replace(/وصلك طلب/gi, 'You received an order')
                                       .replace(/لخدمات/gi, 'for services')
                                       .replace(/غسيل داخلي/gi, 'Interior Wash')
                                       .replace(/غسيل شامل/gi, 'Full Wash')
                                       .replace(/غيار زيت/gi, 'Oil Change')
                                       .replace(/بتاريخ/gi, 'on date')
                                       .replace(/الساعة/gi, 'at time');
                el.innerText = translated;
            });
        }
    };
</script>
<script src="/Clevo/assets/lamea.js"></script>
</body>
</html>