<?php

include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';

if (!isset($_SESSION['userID']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../general/login.php");
    exit();
}

$admin_name = $_SESSION['userName'] ?? "Admin";
$admin_id = $_SESSION['userID'] ?? 0;
$page_lang = $_GET['lang'] ?? $_COOKIE['app_lang'] ?? 'ar';
$page_lang = $page_lang === 'en' ? 'en' : 'ar';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    clevo_require_csrf_token();
    $file = $_FILES['admin_pic_upload'];
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        $stmt = $conn->prepare("UPDATE `user` SET ProfilePic = ? WHERE UserID = ?");
        $stmt->bind_param("si", $newName, $admin_id);
        if ($stmt->execute()) {
            header("Location: admin-dashboard.php?upload=success");
            exit();
        }
    }
}

$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$user_data = $res_pic->fetch_assoc();
$admin_pic = ($user_data && !empty($user_data['ProfilePic'])) ? $user_data['ProfilePic'] : 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);

$res_stations = $conn->query("SELECT COUNT(*) as total FROM stations WHERE admin_status = 'approved'");
$total_stations = $res_stations->fetch_assoc()['total'] ?? 0;

$res_users = $conn->query("SELECT COUNT(*) as total FROM `user` WHERE LOWER(Role) IN ('customer', 'user')");
$total_users = $res_users->fetch_assoc()['total'] ?? 0;

$res_pending_stations = $conn->query("SELECT COUNT(*) as total FROM stations WHERE admin_status = 'pending'");
$pending_stations_count = $res_pending_stations->fetch_assoc()['total'] ?? 0;

$total_pending = $pending_stations_count;

$res_profit = $conn->query("SELECT SUM(Admin_Commission) as total FROM booking WHERE Status = 'Completed' AND DATE(created_at) = CURDATE()");
$total_profit = $res_profit->fetch_assoc()['total'] ?? 0;

$res_tickets = $conn->query("SELECT st.*, u.UserName as user_display_name 
                             FROM support_tickets st 
                             LEFT JOIN `user` u ON st.user_id = u.UserID 
                             WHERE st.id IN (SELECT MAX(id) FROM support_tickets GROUP BY COALESCE(user_id, user_name)) 
                             ORDER BY st.id DESC LIMIT 5");

$stmt_notif = $conn->prepare("SELECT COUNT(*) AS total FROM notifications n LEFT JOIN notification_reads nr ON nr.NotificationID = n.id AND nr.UserID = ? WHERE (n.user_id = ? AND n.is_read = 0) OR (n.user_id IS NULL AND LOWER(n.target_role) = 'admin' AND nr.ReadAt IS NULL)");
$stmt_notif->bind_param("ii", $admin_id, $admin_id);
$stmt_notif->execute();
$res_un_notif = $stmt_notif->get_result();
$unread_count = $res_un_notif->fetch_assoc()['total'] ?? 0;
$stmt_notif->close();

function clevoAdminServiceNameAr(string $name): string
{
    $map = [
        'Full Wash' => 'غسيل شامل',
        'Interior Deep Clean' => 'تنظيف داخلي عميق',
        'Exterior Polish' => 'تلميع خارجي',
        'Oil Change' => 'تغيير زيت',
        'Interior Wash' => 'غسيل داخلي',
        'Exterior Wash' => 'غسيل خارجي',
        'Ceramic Quick Coat' => 'طبقة سيراميك سريعة',
        'Interior Detailing' => 'تنظيف داخلي تفصيلي',
        'Interior Steam Clean' => 'تنظيف داخلي بالبخار',
        'Headlight Restoration' => 'تجديد المصابيح',
        'Home Interior Wash' => 'غسيل داخلي منزلي',
        'Pickup Full Service' => 'خدمة كاملة مع استلام وتسليم',
        'Premium Wax' => 'شمع ممتاز',
        'Not specified' => 'غير محدد',
    ];

    return $map[$name] ?? $name;
}

$res_most_service = $conn->query("
    SELECT
        COALESCE(NULLIF(MAX(s.Name_ar), ''), MAX(s.Name), 'غير محدد') AS ServiceNameAr,
        COALESCE(NULLIF(MAX(s.Name_en), ''), MAX(s.Name), 'Not specified') AS ServiceNameEn,
        COUNT(*) AS count
    FROM booking b
    JOIN services s ON b.ServiceID = s.ServiceID
    GROUP BY COALESCE(NULLIF(s.Name_en, ''), s.Name)
    ORDER BY count DESC, ServiceNameEn ASC
    LIMIT 1
");
$most_service = ($res_most_service) ? $res_most_service->fetch_assoc() : null;
$most_service_ar = $most_service['ServiceNameAr'] ?? 'غير محدد';
$most_service_en = $most_service['ServiceNameEn'] ?? 'Not specified';
$most_service_ar = clevoAdminServiceNameAr($most_service_ar);

$res_monthly_profit = $conn->query("SELECT SUM(Admin_Commission) as total FROM booking WHERE Status = 'Completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$monthly_profit = $res_monthly_profit->fetch_assoc()['total'] ?? 0;

$res_avg_rating = $conn->query("SELECT AVG(Rating) as avg_rating FROM reviews");
$avg_rating = $res_avg_rating->fetch_assoc()['avg_rating'] ?? 0;

$res_daily_bookings = $conn->query("SELECT COUNT(*) as total FROM booking WHERE DATE(created_at) = CURDATE()");
$daily_bookings = $res_daily_bookings->fetch_assoc()['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="admin_title">لوحة تحكم الأدمن | Clevo</title>
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-rtl">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-card: #ffffff; 
            --text-main: #111827; --border-color: #dee2e6; --box-bg: #ffffff; 
            --accent-blue: #3b82f6; 
        }
        
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-card: #1e293b; 
            --text-main: #ffffff; --border-color: #334155; --box-bg: #0f172a; 
            --accent-blue: #60a5fa; 
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; }
        .main-row { display: flex; flex-wrap: nowrap; min-height: 100vh; }
        
        .admin-sidebar { 
            background: var(--bg-sidebar); 
            color: white; 
            padding: 20px 15px; 
            position: sticky; 
            top: 0; 
            height: 100vh; 
            z-index: 1000; 
            width: 260px; 
            display: flex; 
            flex-direction: column; 
            overflow: visible;
        }
        
        .admin-pic-container { position: relative; display: inline-block; margin-bottom: 10px; cursor: pointer; }
        .admin-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-blue); transition: 0.3s; }
        .upload-overlay { position: absolute; bottom: 0; right: 0; background: var(--accent-blue); color: white; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid var(--bg-sidebar); }

        @media (min-width: 992px) {
            [dir="rtl"] .admin-sidebar { order: 1; }
            [dir="rtl"] .admin-main-content { order: 2; }
            [dir="ltr"] .admin-sidebar { order: 1; }
            [dir="ltr"] .admin-main-content { order: 2; }
        }

        .sidebar-nav-container { flex-grow: 1; }
        
        .nav-link { color: #ffffff !important; padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; text-decoration: none; font-weight: 600; transition: 0.3s; font-size: 0.9rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent-blue); color: white !important; }
        [dir="rtl"] .nav-link i { margin-left: 10px; }
        [dir="ltr"] .nav-link i { margin-right: 10px; }

        .admin-main-content { flex: 1; overflow-x: hidden; padding: 25px; }
        .btn-ctrl-circle { width: 38px; height: 38px; border-radius: 50%; border: 1px solid var(--border-color); background: var(--bg-card); color: var(--text-main); display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.3s; }
        
        .stat-card { border: none; border-radius: 24px; padding: 20px; color: white !important; transition: 0.3s; box-shadow: 0 4px 15px rgba(0,0,0,0.1); text-decoration: none; display: block; cursor: pointer; }
        .bg-blue { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }
        .bg-green { background: linear-gradient(135deg, #10b981, #047857); }
        .bg-purple { background: linear-gradient(135deg, #8b5cf6, #6d28d9); }
        .bg-orange { background: linear-gradient(135deg, #f59e0b, #d97706); }

        .table-container { background-color: var(--box-bg) !important; border-radius: 24px; padding: 25px; margin-top: 30px; border: 1px solid var(--border-color); }
        .custom-table { width: 100%; color: inherit !important; border-collapse: separate; border-spacing: 0 10px; }

        .modal-content { background-color: var(--bg-card) !important; color: var(--text-main) !important; border-radius: 25px; border: 1px solid var(--border-color); }

        @media (max-width: 991px) {
            .main-row { flex-direction: column; }
            .admin-main-content { padding: 20px 15px 100px 15px; }
        }
    </style>
</head>
<body>

    <div class="container-fluid p-0">
        <div class="row g-0 main-row">
            
            <?php include __DIR__ . '/../includes/shared_admin_sidebar.php'; ?>

            <main class="admin-main-content">
                <header class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
                    <div class="text-adaptive">
                        <h4 class="mb-1 fw-bold" data-i18n="welcome_admin">لوحة الإدارة الشاملة</h4>
                        <p class="small mb-0 opacity-75"><span data-i18n="hello">أهلاً</span> <span class="u-name-raw text-primary fw-bold"><?php echo htmlspecialchars($admin_name); ?></span></p>
                    </div>
                    <div class="d-flex gap-2 align-items-center">

                        <a href="notifications.php" class="btn-ctrl-circle position-relative">
                            <i class="fas fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.65rem;">
                                    <?php echo $unread_count > 99 ? '99+' : $unread_count; ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <button onclick="toggleGlobalTheme()" class="btn-ctrl-circle"><i id="adminThemeIcon" class="fas fa-moon"></i></button>
                        <button onclick="toggleLanguage()" class="btn-ctrl-circle fw-bold"><span id="adminLangText">EN</span></button>
                    </div>
                </header>

                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3">
                        <a href="manage-stations.php" class="stat-card bg-blue">
                            <small class="fw-bold" data-i18n="total_stations">إجمالي المحطات</small>
                            <h2 class="mt-2 fw-bold"><?php echo $total_stations; ?></h2>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="manage-users.php" class="stat-card bg-green">
                            <small class="fw-bold" data-i18n="active_users_count">إجمالي الزبائن</small>
                            <h2 class="mt-2 fw-bold"><?php echo number_format($total_users); ?></h2>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="station-approvals.php" class="stat-card bg-purple">
                            <small class="fw-bold" data-i18n="pending_orders">طلبات قيد المعالجة</small>
                            <h2 class="mt-2 fw-bold"><?php echo $total_pending; ?></h2>
                        </a>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-orange" style="cursor: default;">
                            <small class="fw-bold" data-i18n="today_profit">أرباح اليوم</small>
                            <h2 class="mt-2 fw-bold"><?php echo number_format($total_profit, 2); ?> JOD</h2>
                        </div>
                    </div>
                </div>

                <h5 class="mt-4 mb-3 fw-bold" data-i18n="extra_analytics">تحليلات إضافية</h5>
                <div class="row g-3 text-center">
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-blue" style="cursor: default;">
                            <small class="fw-bold" data-i18n="most_requested_service">الخدمة الأكثر طلباً</small>
                            <h4 id="mostRequestedServiceValue" class="mt-2 fw-bold" data-service-ar="<?php echo htmlspecialchars($most_service_ar); ?>" data-service-en="<?php echo htmlspecialchars($most_service_en); ?>"><?php echo htmlspecialchars($most_service_ar); ?></h4>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-green" style="cursor: default;">
                            <small class="fw-bold" data-i18n="monthly_profit">أرباح الشهر</small>
                            <h2 class="mt-2 fw-bold"><?php echo number_format($monthly_profit, 2); ?> JOD</h2>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-purple" style="cursor: default;">
                            <small class="fw-bold" data-i18n="avg_station_rating">متوسط تقييم المحطات</small>
                            <h2 class="mt-2 fw-bold"><?php echo number_format($avg_rating, 1); ?>/5</h2>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card bg-orange" style="cursor: default;">
                            <small class="fw-bold" data-i18n="today_bookings">حجوزات اليوم</small>
                            <h2 class="mt-2 fw-bold"><?php echo $daily_bookings; ?></h2>
                        </div>
                    </div>
                </div>

                <div class="table-container shadow-sm">
                    <h5 class="mb-4 fw-bold"><i class="fas fa-headset text-primary me-2"></i> <span data-i18n="support_tickets">تذاكر الدعم الواردة</span></h5>
                    <div class="table-responsive">
                        <table class="custom-table align-middle">
                            <thead>
                                <tr>
                                    <th data-i18n="th_user">المستخدم</th>
                                    <th data-i18n="th_subject">الموضوع</th>
                                    <th data-i18n="th_status">الحالة</th>
                                    <th data-i18n="th_action">الإجراء</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($res_tickets && $res_tickets->num_rows > 0): ?>
                                    <?php while($ticket = $res_tickets->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($ticket['user_display_name'] ?? $ticket['user_name']); ?></td>
                                        <td><?php echo htmlspecialchars($ticket['subject']); ?></td>
                                        <td>
                                            <?php if($ticket['status'] == 'New'): ?>
                                                <span class="badge bg-danger rounded-pill px-3" data-i18n="status_new">جديد</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary rounded-pill px-3" data-i18n="status_replied">تم الرد</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><a href="support-chat.php?ticket_id=<?php echo $ticket['id']; ?>" class="btn btn-sm btn-primary rounded-pill px-4" data-i18n="btn_reply">رد</a></td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-4" data-i18n="no_tickets">لا توجد تذاكر حالياً</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                admin_title: "لوحة تحكم الأدمن | Clevo", nav_main: "الرئيسية", nav_approvals: "الموافقات", nav_security: "الأمان", 
                nav_reports: "التقارير", nav_plans: "الخطط", nav_notif: "الإشعارات", nav_support_chat: "دردشة الدعم", nav_logout: "خروج",
                welcome_admin: "لوحة الإدارة الشاملة", hello: "أهلاً", total_stations: "إجمالي المحطات",
                active_users_count: "إجمالي الزبائن", pending_orders: "طلبات قيد المعالجة", today_profit: "أرباح اليوم",
                extra_analytics: "تحليلات إضافية", most_requested_service: "الخدمة الأكثر طلباً", monthly_profit: "أرباح الشهر",
                avg_station_rating: "متوسط تقييم المحطات", today_bookings: "حجوزات اليوم",
                support_tickets: "تذاكر الدعم الواردة", th_user: "المستخدم", th_subject: "الموضوع", th_status: "الحالة", 
                th_action: "الإجراء", btn_reply: "رد", no_tickets: "لا توجد تذاكر حالياً",
                status_new: "جديد", status_replied: "تم الرد",
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج؟", 
                btn_confirm_logout: "خروج", btn_cancel: "إلغاء",
                "غسيل داخلي": "غسيل داخلي", "غير محدد": "غير محدد"
            },
            en: {
                admin_title: "Admin Dashboard | Clevo", nav_main: "Dashboard", nav_approvals: "Approvals", nav_security: "Security", 
                nav_reports: "Reports", nav_plans: "Plans", nav_notif: "Notifications", nav_support_chat: "Support Chat", nav_logout: "Logout",
                welcome_admin: "Global Admin Panel", hello: "Welcome", total_stations: "Total Stations",
                active_users_count: "Total Customers", pending_orders: "Pending Orders", today_profit: "Today Profit",
                extra_analytics: "Additional Analytics", most_requested_service: "Most Requested Service", monthly_profit: "Monthly Profit",
                avg_station_rating: "Avg Station Rating", today_bookings: "Today Bookings",
                support_tickets: "Support Tickets", th_user: "User", th_subject: "Subject", th_status: "Status", 
                th_action: "Action", btn_reply: "Reply", no_tickets: "No tickets found",
                status_new: "New", status_replied: "Replied",
                logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?",
                btn_confirm_logout: "Logout", btn_cancel: "Cancel",
                "غسيل داخلي": "Internal Wash", "غير محدد": "Not Specified"
            }
        };

        function applyTranslations(lang) {
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) el.innerText = translations[lang][key];
            });

            updateServiceLanguage(lang);

            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
        }

        const serviceNameArFallbacks = {
            'Full Wash': 'غسيل شامل',
            'Interior Deep Clean': 'تنظيف داخلي عميق',
            'Exterior Polish': 'تلميع خارجي',
            'Exterior Wash': 'غسيل خارجي',
            'Interior Detailing': 'تنظيف داخلي تفصيلي',
            'Oil Change': 'تغيير زيت',
            'Home Interior Wash': 'غسيل داخلي منزلي',
            'Pickup Full Service': 'خدمة كاملة مع استلام وتسليم'
        };

        function updateServiceLanguage(lang) {
            const serviceEl = document.getElementById('mostRequestedServiceValue');
            if (!serviceEl) return;

            const fallback = lang === 'ar' ? 'غير محدد' : 'Not specified';
            let serviceName = lang === 'ar'
                ? (serviceEl.getAttribute('data-service-ar') || fallback)
                : (serviceEl.getAttribute('data-service-en') || fallback);

            if (lang === 'ar' && serviceNameArFallbacks[serviceName]) {
                serviceName = serviceNameArFallbacks[serviceName];
            }

            serviceEl.innerText = serviceName;
        }

        function enforceArabicDashboardService() {
            const serviceEl = document.getElementById('mostRequestedServiceValue');
            const titleEl = document.querySelector('[data-i18n="most_requested_service"]');
            if (!serviceEl || !titleEl) return;

            const pageLooksArabic = document.documentElement.dir === 'rtl'
                || titleEl.innerText.includes('الخدمة')
                || (document.getElementById('adminLangText')?.innerText || '') === 'EN';

            if (pageLooksArabic) {
                serviceEl.innerText = serviceEl.getAttribute('data-service-ar') || 'غسيل شامل';
            }
        }

        function setAdminLanguage(lang) {
            localStorage.setItem('app_lang', lang);
            document.cookie = `app_lang=${lang}; path=/; max-age=31536000; SameSite=Lax`;
            applyTranslations(lang);
            document.getElementById('adminLangText').innerText = lang === 'ar' ? 'EN' : 'AR';
        }

        window.onload = () => {
            const urlLang = new URLSearchParams(window.location.search).get('lang');
            const lang = urlLang || localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            setAdminLanguage(lang);
            enforceArabicDashboardService();
            if(theme === 'light') document.getElementById('adminThemeIcon').className = 'fas fa-sun';
            setTimeout(enforceArabicDashboardService, 150);
        };

        function toggleLanguage() {
            const newLang = (localStorage.getItem('app_lang') || 'ar') === 'ar' ? 'en' : 'ar';
            setAdminLanguage(newLang);
            const url = new URL(window.location.href);
            url.searchParams.set('lang', newLang);
            window.location.href = url.toString();
        }

        function toggleGlobalTheme() {
            const newTheme = (localStorage.getItem('global_theme') || 'dark') === 'dark' ? 'light' : 'dark';
            localStorage.setItem('global_theme', newTheme);
            location.reload();
        }
    </script>
</body>
</html>
