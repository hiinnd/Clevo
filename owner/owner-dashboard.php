<?php
// إعدادات الباك اند ولاتغير ولاتحذف ولاتعدل اي اشي تاني ابدا 
session_start();
include __DIR__ . '/../includes/config.php';

// التحقق من تسجيل الدخول
$owner_id = (int) ($_SESSION['userID'] ?? 0);

// التحقق من صلاحية المستخدم (اختياري)
if ($owner_id === 0) {
    header('Location: ../general/login.php');
    exit;
}

if (isset($_GET['change_status'])) {
    $allowed = ['Active', 'Busy', 'Closed'];
    $newStatus = in_array($_GET['change_status'], $allowed, true) ? $_GET['change_status'] : 'Active';
    $stmt = $conn->prepare("UPDATE stations SET Status = ? WHERE UserID = ? LIMIT 1");
    $stmt->bind_param("si", $newStatus, $owner_id);
    $stmt->execute();
    header('Location: owner-dashboard.php');
    exit;
}

// 1. جلب إحصائيات طلبات اليوم
$today = date('Y-m-d');
$today_res = mysqli_query($conn, "SELECT COUNT(*) as total FROM booking b JOIN stations s ON b.StationID = s.StationID WHERE b.Booking_Date = '$today' AND s.UserID = '$owner_id'");
$today_count = mysqli_fetch_assoc($today_res)['total'];

// 2. الطلبات النشطة حالياً
$active_res = mysqli_query($conn, "SELECT COUNT(*) as total FROM booking b JOIN stations s ON b.StationID = s.StationID WHERE b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning') AND s.UserID = '$owner_id'");
$active_count = mysqli_fetch_assoc($active_res)['total'];

// 3. أرباح اليوم
$earnings_res = mysqli_query($conn, "SELECT SUM(b.Total_Price) as total FROM booking b JOIN stations s ON b.StationID = s.StationID WHERE b.Booking_Date = '$today' AND b.Status = 'Completed' AND s.UserID = '$owner_id'");
$today_earnings = mysqli_fetch_assoc($earnings_res)['total'] ?? 0;

// 4. جلب عدد الإشعارات غير المقروءة (للدائرة الحمراء)
$notif_count_res = mysqli_query($conn, "SELECT COUNT(*) as total FROM notifications WHERE user_id = '$owner_id' AND is_read = 0");
$unread_count = mysqli_fetch_assoc($notif_count_res)['total'] ?? 0;

// 5. جلب آخر 5 طلبات
$recent_orders = mysqli_query($conn, "SELECT b.*, u.UserName FROM booking b JOIN user u ON b.UserID = u.UserID JOIN stations s ON b.StationID = s.StationID WHERE s.UserID = '$owner_id' ORDER BY b.created_at DESC LIMIT 5");
$recent_reviews = mysqli_query($conn, "SELECT r.Rating, r.Comment, u.UserName FROM reviews r JOIN booking b ON r.BookingID = b.BookingID JOIN stations s ON b.StationID = s.StationID JOIN user u ON b.UserID = u.UserID WHERE s.UserID = '$owner_id' ORDER BY r.ReviewID DESC LIMIT 2");
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="owner_dash_title">لوحة تحكم المالك | Clevo</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --accent: #007bff; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #495057; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #007bff;
            --border-color: #dee2e6; --input-bg: #ffffff; --input-text: #000000;
        }

        [data-theme="dark"] {
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #0f172a; --text-main: #ffffff !important; --text-muted: #cbd5e1 !important; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --input-bg: #1a2236; --input-text: #ffffff !important;
        }

        body {
            font-family: 'Cairo', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            transition: 0.3s ease;
            margin: 0;
            overflow-x: hidden;
        }

        .main-wrapper { display: flex; min-height: 100vh; }

        /* Sidebar المطور والمثبت بجميع الصفحات لـ Clevo */
        .sidebar { width: 260px; background: var(--bg-sidebar); padding: 30px 15px; position: sticky; top: 0; min-height: 100vh; display: flex; flex-direction: column; transition: all 0.3s ease; }
        .nav-link { color: rgba(255,255,255,0.8) !important; padding: 12px; border-radius: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; transition: 0.2s; font-size: 0.95rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent); color: white !important; }
        .btn-ctrl { width: 35px; height: 35px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); color: white; border: 0; display: inline-flex; align-items: center; justify-content: center; }

        /* Main Content */
        .content-area { flex: 1; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-back-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0, 123, 255, 0.3); border: none; }

        /* زر الإشعارات الدائري */
        .notif-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--bg-card);
            color: var(--text-main);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            text-decoration: none;
            border: 1px solid var(--border-color);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: 0.3s;
        }

        .notif-badge {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #ff3b30;
            color: white;
            font-size: 10px;
            padding: 2px 5px;
            border-radius: 10px;
            border: 2px solid var(--bg-body);
        }

        .stat-card, .table-container, .review-item {
            background-color: var(--bg-card) !important;
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 25px;
            color: var(--text-main) !important;
            height: 100%;
        }

        .icon-shape {
            width: 50px;
            height: 50px;
            background: rgba(0, 123, 255, 0.15);
            color: var(--primary-blue);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
        }

        .modal-content { border-radius: 30px; background-color: var(--bg-card) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); }

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
        (function() {
            const savedTheme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>

    <div class="container-fluid p-0">
        <div class="main-wrapper">
            
            <nav class="sidebar shadow text-center">
                <div class="d-flex gap-2 justify-content-center mb-4">
                    <button onclick="toggleGlobalTheme()" class="btn-ctrl"><i id="themeIcon" class="fas fa-moon"></i></button>
                    <button onclick="toggleLanguage()" class="btn-ctrl"><span id="langBtnText" class="fw-bold">EN</span></button>
                </div>
                <h4 class="fw-bold mb-4 text-primary">CLEVO</h4>
                <div class="nav flex-column flex-grow-1">
                    <a href="owner-dashboard.php" class="nav-link active"><i class="fas fa-th-large"></i> <span data-i18n="nav_main">الرئيسية</span></a>
                    <a href="incoming-orders.php" class="nav-link"><i class="fas fa-shopping-cart"></i> <span data-i18n="nav_orders">الطلبات</span></a>
                    <a href="add-station.php" class="nav-link"><i class="fas fa-store"></i> <span data-i18n="add_st_page_title">إعداد المغسلة</span></a>
                    <a href="manage-services.php" class="nav-link"><i class="fas fa-concierge-bell"></i> <span data-i18n="services_list">الخدمات</span></a>
                    <a href="manage-drivers.php" class="nav-link"><i class="fas fa-users"></i> <span data-i18n="drivers_page_title">طاقم العمل</span></a>
                    <a href="owner-reviews.php" class="nav-link"><i class="fas fa-star"></i> <span data-i18n="recent_reviews">التقييمات</span></a>
                    <a href="owner-wallet.php" class="nav-link"><i class="fas fa-wallet"></i> <span data-i18n="serv_wallet">المحفظة</span></a>
                    <a href="notifications.php" class="nav-link"><i class="fas fa-bell"></i> <span data-i18n="nav_notifications">الإشعارات</span></a>
                </div>
                <div class="mt-auto">
                    <hr class="text-white opacity-25 my-3">
                    <a href="javascript:void(0)" onclick="confirmLogout()" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> <span data-i18n="nav_logout">تسجيل الخروج</span></a>
                </div>
            </nav>

            <main class="content-area">
                <div class="orders-header">
                    <div class="container d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <a href="owner-dashboard.php" class="circle-back-btn shadow-sm">
                                <i class="fas fa-arrow-right" id="backIcon"></i>
                            </a>
                            <div class="text-start">
                                <h4 class="mb-1 fw-bold"><span data-i18n="welcome_back_owner">مرحباً بك يا</span> <span class="uName text-primary fw-bold">...</span> 👋</h4>
                                <p class="small opacity-75 mb-0 id-date" id="dateDisp" style="color:var(--text-main) !important;"></p>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <a href="notifications.php" class="notif-btn">
                                <i class="fas fa-bell"></i>
                                <?php if ($unread_count > 0): ?>
                                    <span class="notif-badge"><?php echo $unread_count; ?></span>
                                <?php endif; ?>
                            </a>

                            <?php
                            $st_res = mysqli_query($conn, "SELECT Status FROM stations WHERE UserID = $owner_id");
                            $st_data = mysqli_fetch_assoc($st_res);
                            $status = $st_data['Status'] ?? 'Active';
                            ?>
                            <div class="dropdown">
                                <button class="btn rounded-pill shadow-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" id="statusBtn" style="background: var(--bg-card); color: var(--text-main); border:1px solid var(--border-color);">
                                    <span id="statusDisplay"></span>
                                </button>
                                <ul class="dropdown-menu shadow">
                                    <li><a class="dropdown-item" href="?change_status=Active" id="opt-active"></a></li>
                                    <li><a class="dropdown-item" href="?change_status=Busy" id="opt-busy"></a></li>
                                    <li><a class="dropdown-item" href="?change_status=Closed" id="opt-closed"></a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div class="row g-3 mb-4 text-start">
                        <div class="col-6 col-md-4">
                            <div class="stat-card d-flex justify-content-between align-items-center shadow-sm">
                                <div class="icon-shape"><i class="fas fa-spinner text-primary"></i></div>
                                <div>
                                    <small class="fw-bold opacity-75" data-i18n="active_orders_label">نشط حالياً</small>
                                    <h2 class="mb-0 fw-bold"><?php echo $active_count; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="stat-card d-flex justify-content-between align-items-center shadow-sm">
                                <div class="icon-shape"><i class="fas fa-calendar-day text-info"></i></div>
                                <div>
                                    <small class="fw-bold opacity-75" data-i18n="today_orders">طلبات اليوم</small>
                                    <h2 class="mb-0 fw-bold"><?php echo $today_count; ?></h2>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="stat-card d-flex justify-content-between align-items-center shadow-sm border-bottom border-success border-4">
                                <div class="icon-shape"><i class="fas fa-coins text-success"></i></div>
                                <div>
                                    <small class="fw-bold opacity-75" data-i18n="your_earnings">أرباح اليوم (JOD)</small>
                                    <h2 class="mb-0 text-success fw-bold"><?php echo number_format($today_earnings, 2); ?></h2>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="table-container shadow-sm">
                                <h6 class="mb-4 fw-bold text-primary text-start"><i class="fas fa-list ms-1"></i> <span data-i18n="recent_orders">آخر الطلبات</span></h6>
                                <div class="table-responsive">
                                    <table class="custom-table align-middle text-start w-100">
                                        <thead>
                                            <tr>
                                                <th data-i18n="th_customer">العميل</th>
                                                <th data-i18n="th_service">المبلغ</th>
                                                <th data-i18n="th_status">الحالة</th>
                                                <th data-i18n="th_action">التاريخ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php while ($row = mysqli_fetch_assoc($recent_orders)): ?>
                                                <tr class="clickable-row text-align-adaptive" style="cursor: pointer;" onclick="location.href='incoming-orders.php'">
                                                    <td class="fw-bold db-cust-name"><?php echo htmlspecialchars($row['UserName']); ?></td>
                                                    <td><?php echo $row['Total_Price']; ?> JOD</td>
                                                    <td>
                                                        <?php
                                                        $status_class = "bg-warning text-dark";
                                                        if ($row['Status'] == 'In Progress') $status_class = "bg-info text-white";
                                                        if ($row['Status'] == 'Completed') $status_class = "bg-success";
                                                        ?>
                                                        <span class="badge <?php echo $status_class; ?> px-3 py-2 rounded-pill"><?php echo $row['Status']; ?></span>
                                                    </td>
                                                    <td><small class="opacity-75"><?php echo $row['Booking_Date']; ?></small></td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="table-container shadow-sm text-start">
                                <h6 class="mb-4 fw-bold text-warning"><i class="fas fa-comment-dots ms-1"></i> <span data-i18n="recent_reviews">تقييمات الناس</span></h6>
                                <?php if ($recent_reviews && mysqli_num_rows($recent_reviews) > 0): ?>
                                    <?php while ($review = mysqli_fetch_assoc($recent_reviews)): ?>
                                        <div class="review-item mb-3 shadow-sm" style="padding: 15px; border-radius: 15px;">
                                            <div class="d-flex justify-content-between mb-1">
                                                <span class="fw-bold small db-rev-user"><?= htmlspecialchars($review['UserName']) ?></span>
                                                <div class="text-warning small">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?><i class="<?= $i <= (int)$review['Rating'] ? 'fas' : 'far' ?> fa-star"></i><?php endfor; ?>
                                                </div>
                                            </div>
                                            <p class="small mb-0 opacity-75 db-rev-comment"><?= nl2br(htmlspecialchars($review['Comment'] ?: '-')) ?></p>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="review-item mb-3 shadow-sm text-center small opacity-75" style="padding: 15px; border-radius: 15px;" data-i18n="no_reviews_yet">لا توجد تقييمات بعد</div>
                                <?php endif; ?>
                                <div class="text-center"><a href="owner-reviews.php" class="btn btn-sm btn-outline-primary rounded-pill px-4" data-i18n="view_all">عرض الكل</a></div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="logoutModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="modal-body p-2">
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                owner_dash_title: "لوحة تحكم المالك | Clevo", nav_main: "الرئيسية", nav_orders: "الطلبات", add_st_page_title: "إعداد المغسلة", services_list: "الخدمات", drivers_page_title: "طاقم العمل", recent_reviews: "التقييمات", serv_wallet: "المحفظة", nav_logout: "خروج", welcome_back_owner: "مرحباً بك يا", today_orders: "طلبات اليوم", your_earnings: "أرباح اليوم (JOD)", recent_orders: "آخر الطلبات", th_customer: "العميل", th_service: "المبلغ", th_status: "الحالة", th_action: "التاريخ", view_all: "عرض الكل", logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء", active_orders_label: "نشط حالياً", status_active: "نظام نشط", status_busy: "مشغول حالياً", status_closed: "مغلق حالياً", status_label_active: "نشط", status_label_busy: "مشغول", status_label_closed: "مغلق", nav_notifications: "الإشعارات", no_reviews_yet: "لا توجد تقييمات بعد"
            },
            en: {
                owner_dash_title: "Owner Dashboard | Clevo", nav_main: "Dashboard", nav_orders: "Orders", add_st_page_title: "Station Setup", services_list: "Services", drivers_page_title: "Team", recent_reviews: "Reviews", serv_wallet: "Wallet", nav_logout: "Logout", welcome_back_owner: "Welcome back,", today_orders: "Today Orders", your_earnings: "Today Profit (JOD)", recent_orders: "Recent Orders", th_customer: "Customer", th_service: "Amount", th_status: "Status", th_action: "Date", view_all: "View All", logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel", active_orders_label: "Live Tasks", status_active: "System Active", status_busy: "Busy Now", status_closed: "Closed Now", status_label_active: "Active", status_label_busy: "Busy", status_label_closed: "Closed", nav_notifications: "Notifications", no_reviews_yet: "No reviews found"
            }
        };

        function toggleLanguage() {
            const currentLang = localStorage.getItem('app_lang') || 'ar';
            localStorage.setItem('app_lang', currentLang === 'ar' ? 'en' : 'ar');
            location.reload();
        }

        function toggleGlobalTheme() {
            const html = document.documentElement;
            const theme = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', theme);
            localStorage.setItem('global_theme', theme);
            location.reload();
        }

        function confirmLogout() { new bootstrap.Modal(document.getElementById('logoutModal')).show(); }

        // دالة الترجمة التبادلية الفورية لنصوص الداتابيز الحرة (الأسماء والتقييمات)
        async function translateDbContent(text) {
            if (!text || !text.trim() || text === '-') return text;
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

            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
            document.getElementById('bootstrap-css').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });

            if (!isRtl && document.getElementById('backIcon')) document.getElementById('backIcon').className = "fas fa-arrow-left";
            document.getElementById('langBtnText').innerText = isRtl ? 'EN' : 'AR';
            const icon = document.getElementById('themeIcon');
            if (icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

            const name = "<?php echo $_SESSION['userName'] ?? 'Owner'; ?>";
            // ترجمة اسم المستخدم حياً لو السيستم إنجليزي
            const displayOwnerName = isRtl ? name : await translateDbContent(name);
            document.querySelectorAll('.uName').forEach(el => el.innerText = displayOwnerName);
            
            document.getElementById('dateDisp').innerText = new Date().toLocaleDateString(isRtl ? 'ar-JO' : 'en-US', {
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
            });

            // تحديث حالة النظام
            const currentStatus = "<?php echo $status; ?>";
            const statusDisplay = document.getElementById('statusDisplay');
            let statusText = '';
            if (currentStatus === 'Active') {
                statusText = '🟢 ' + translations[lang]['status_active'];
            } else if (currentStatus === 'Busy') {
                statusText = '🟡 ' + translations[lang]['status_busy'];
            } else {
                statusText = '🔴 ' + translations[lang]['status_closed'];
            }
            if (statusDisplay) statusDisplay.innerHTML = statusText;

            // تحديث خيارات القائمة المنسدلة
            document.getElementById('opt-active').innerHTML = '🟢 ' + translations[lang]['status_label_active'];
            document.getElementById('opt-busy').innerHTML = '🟡 ' + translations[lang]['status_label_busy'];
            document.getElementById('opt-closed').innerHTML = '🔴 ' + translations[lang]['status_label_closed'];

            // تفعيل الترجمة التبادلية الحية لأسماء العملاء والتعليقات في جدول العرض لو السيستم إنجليزي
            if (lang === 'en') {
                document.querySelectorAll('.db-cust-name').forEach(async (el) => {
                    el.innerText = await translateDbContent(el.innerText);
                });
                document.querySelectorAll('.db-rev-user').forEach(async (el) => {
                    el.innerText = await translateDbContent(el.innerText);
                });
                document.querySelectorAll('.db-rev-comment').forEach(async (el) => {
                    el.innerText = await translateDbContent(el.innerText);
                });
            }
        };
    </script>
    <script src="/Clevo/assets/lamea.js"></script>
</body>
</html>