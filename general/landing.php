alo
<?php 
session_start();
include __DIR__ . '/../includes/config.php';

// جلب المحطات والخدمات المرتبطة بها من قاعدة البيانات
$stations_query = mysqli_query($conn, "
    SELECT 
        stations.StationID,
        stations.Name AS StationName,
        services.Name AS ServiceName
    FROM stations
    INNER JOIN services 
        ON stations.StationID = services.StationID
");

$stations = [];
while ($row = mysqli_fetch_assoc($stations_query)) {
    $stations[] = $row;
}

$is_logged_in = isset($_SESSION['userID']);
$user_name = $is_logged_in ? ($_SESSION['userName'] ?? '') : '';
// جلب الرول من السيشن (تأكد أنك تخزنه عند تسجيل الدخول باسم 'user_role' أو 'role')
$user_role = $_SESSION['role'] ?? 'User';

?> 

<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> Clevo | الرئيسية</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-color: #f8faff;
            --text-color: #333;
            --primary-blue: #007bff;
            --card-bg: #ffffff;
            --section-bg: #e6f0ff;
        }

        [data-theme="dark"] {
            --bg-color: #121212;
            --text-color: #f1f1f1;
            --card-bg: #1e1e1e;
            --section-bg: #1a1a1a;
        }

        body {
            font-family: 'Cairo', sans-serif;
            overflow-x: hidden;
            color: var(--text-color);
            background-color: var(--bg-color);
            transition: 0.3s ease;
        }

        /* --- Navbar Logic --- */
        .navbar {
            transition: 0.4s;
            padding: 15px 0;
            z-index: 1050;
        }

        .navbar-logo {
            width: 110px;
            height: auto;
            pointer-events: none;
        }

        .navbar-collapse {
            display: flex !important;
            flex-basis: auto;
        }

        .navbar-nav {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
        }

        [dir="rtl"] .header-controls-wrapper {
            margin-right: auto;
            margin-left: 0;
        }

        [dir="ltr"] .header-controls-wrapper {
            margin-left: auto;
            margin-right: 0;
        }

        [dir="ltr"] .navbar-brand {
            margin-right: auto;
            margin-left: 0;
        }

        [dir="ltr"] .navbar>.container {
            flex-direction: row !important;
        }

        .nav-link,
        #lang-toggle,
        #theme-toggle,
        .btn-login-nav,
        .btn-primary.shadow,
        .footer-link-animated,
        .social-icon {
            transition: 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .nav-link:hover,
        #lang-toggle:hover,
        #theme-toggle:hover,
        .btn-login-nav:hover,
        .btn-primary.shadow:hover {
            transform: translateY(-3px) scale(1.05);
        }

        .nav-link {
            color: white !important;
            font-weight: 600;
            padding: 0 15px !important;
            text-decoration: none !important;
        }

        #lang-toggle {
            border: 2px solid white !important;
            color: white !important;
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-login-nav {
            border: 1.5px solid rgba(255, 255, 255, 0.6);
            color: white !important;
            text-decoration: none !important;
        }

        /* --- Hero --- */
        .main-hero {
            height: 100vh;
            min-height: 600px;
            background: url('../assets/Images/carwash.png?v=<?php echo time(); ?>') center/cover no-repeat !important;
            position: relative;
            display: flex;
            align-items: center;
        }

        .hero-overlay {
            height: 100%;
            width: 100%;
            background: rgba(0, 26, 51, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .hero-logo {
            width: 160px !important;
            height: auto;
            margin-bottom: 20px;
        }

        /* --- Services & Stations --- */
        #services,
        #stations-section {
            background-color: var(--section-bg);
            padding: 80px 0;
        }

        .service-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            height: 100%;
            cursor: pointer;
            text-align: center;
            border: 1px solid rgba(0, 0, 0, 0.02);
            color: var(--text-color);
        }

        .service-card:hover {
            transform: translateY(-15px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 123, 255, 0.2);
        }

        .service-card img {
            height: 220px;
            width: 100%;
            object-fit: cover;
            border-radius: 20px;
        }

        .theme-muted {
            color: var(--text-color);
            opacity: 0.7;
        }

        .theme-text {
            color: var(--text-color);
        }

        /* --- CTA Banner --- */
        .cta-banner {
            border-top: 2px solid rgba(255, 255, 255, 0.1);
            background: linear-gradient(rgba(0, 30, 60, 0.85), rgba(0, 30, 60, 0.85)), url('../assets/Images/wash.jpg') center/cover fixed !important;
            color: white !important;
            padding: 80px 0;
            text-align: center;
        }

        /* --- Footer --- */
        .footer-dark {
            border-top: 5px solid var(--primary-blue);
            background: linear-gradient(rgba(0, 30, 60, 0.95), rgba(0, 30, 60, 0.95)), url('../assets/Images/wash.jpg') center/cover fixed !important;
            color: white;
            padding: 50px 0 30px;
        }

        [dir="rtl"] .footer-content {
            text-align: right;
        }

        [dir="ltr"] .footer-content {
            text-align: left;
        }

        .footer-logo {
            width: 120px !important;
            height: auto;
            margin-bottom: 10px;
            margin-top: -30px;
        }

        .footer-desc {
            margin-bottom: 25px;
            max-width: 350px;
            opacity: 0.85;
        }

        .footer-link-animated {
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none !important;
            margin-bottom: 8px;
            display: inline-block;
        }

        .footer-link-animated:hover {
            color: var(--primary-blue) !important;
            transform: scale(1.05);
        }

        .social-icon {
            width: 42px;
            height: 42px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 6px;
            transition: 0.4s;
            color: white;
            text-decoration: none;
        }

        .social-icon.facebook:hover {
            background: #1877F2 !important;
            transform: translateY(-5px);
        }

        .social-icon.instagram:hover {
            background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%) !important;
            transform: translateY(-5px);
        }

        .social-icon.whatsapp:hover {
            background: #25D366 !important;
            transform: translateY(-5px);
        }

        /* --- Chat Bot CSS --- */
        .lamea-chat-wrapper {
            position: fixed;
            bottom: 20px;
            z-index: 9999;
        }

        [dir="rtl"] .lamea-chat-wrapper  {
            left: 20px;
        }

        [dir="ltr"] .lamea-chat-wrapper  {
            right: 20px;
        }

        .lamea-avatar-btn {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(0, 123, 255, 0.3);
            border: 3px solid #fff;
            overflow: hidden;
            transition: 0.3s ease-in-out;
        }

        .lamea-avatar-btn:hover{
            transform: scale(1.1);
        }

        .lamea-avatar-btn img{ 
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .chat-window {
            width: 340px;
            height: 500px;
            max-height: 80vh;
            background: #fff;
            border-radius: 25px;
            position: absolute;
            bottom: 80px;
            left: 0;
            display: none;
            flex-direction: column;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            animation: fadeInUp 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
        }

        [dir="rtl"] .chat-window {
            left: 0;
        }

        [dir="ltr"] .chat-window {
            right: 0;
        }

        .chat-header {
            background: var(--primary-blue);
            color: #fff;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
        }

        .chat-header img {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            border: 2px solid #fff;
        }

        .chat-header bg-primary {
            background-color: var(--primary-blue) !important;
        }

        .chat-body {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            background: #f8f9fa;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .msg-bubble {
            padding: 10px 14px;
            border-radius: 15px;
            max-width: 85%;
            font-size: 0.88rem;
            line-height: 1.4;
        }

        .bot-msg {
            background: #fff;
            color: #333;
            align-self: flex-start;
            border-bottom-left-radius: 4px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }

        .user-msg {
            background: var(--primary-blue);
            color: #fff;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .chat-footer {
            padding: 12px;
            background: #fff;
            border-top: 1px solid #eee;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chat-footer input {
            flex: 1;
            border: none;
            background: #f1f3f5;
            padding: 8px 15px;
            border-radius: 20px;
            outline: none;
            color: #333;
            font-size: 0.9rem;
        }

        .chat-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: 0.2s;
        }

        .voice-btn {
            background: #f1f3f5;
            color: #555;
        }

        .send-btn {
            background: var(--primary-blue);
            color: #fff;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(15px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Modal Theme Adjustments */
        .modal-content {
            background-color: var(--card-bg);
            color: var(--text-color);
            border-radius: 20px;
            border: none;
        }

        @media (max-width: 991.98px) {
            .navbar-nav {
                position: static;
                transform: none;
                margin: 20px 0;
            }

            .navbar-collapse {
                background: rgba(10, 15, 28, 0.98);
                border-radius: 20px;
                padding: 25px;
                margin-top: 20px;
                flex-direction: column !important;
            }

            .header-controls-wrapper {
                justify-content: center !important;
            }
        }

        /* قسم المحطات المضاف */
        #stations-section {
            display: none;
            padding: 80px 0;
            background-color: var(--section-bg);
        }
    </style>
</head>

<body>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            const savedLang = localStorage.getItem('app_lang') || 'ar';
            document.documentElement.setAttribute('lang', savedLang);
            document.documentElement.setAttribute('dir', savedLang === 'ar' ? 'rtl' : 'ltr');
        })();
    </script>

    <nav class="navbar navbar-expand-lg navbar-dark bg-transparent position-absolute w-100">
        <div class="container">
            <a class="navbar-brand" href="home.php">
                <img src="../assets/Images/Logo.png" alt="Clevo Logo" class="navbar-logo">
            </a>

            <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav mx-auto">
                    <li class="nav-item"><a class="nav-link" href="#services" data-i18n="nav_services">خدماتنا</a></li>
                    <li class="nav-item"><a class="nav-link" href="about.php" data-i18n="nav_about">من نحن</a></li>
                    <li class="nav-item"><a class="nav-link" href="contact.php" data-i18n="nav_contact">تواصل معنا</a></li>
                </ul>

                <div class="d-flex align-items-center gap-3 header-controls-wrapper">
                    <button onclick="toggleLanguage()" class="btn rounded-pill px-3 fw-bold shadow-none" id="lang-toggle">
                        <span id="langText">EN</span>
                    </button>
                    <button onclick="toggleGlobalTheme()" class="btn btn-link p-0 shadow-none text-white" id="theme-toggle">
                        <i id="globalThemeIcon" class="fas fa-moon"></i>
                    </button>
                    <div class="d-flex gap-2">
                        <?php if (!$is_logged_in): ?>
                            <a href="login.php" class="btn btn-login-nav btn-sm rounded-pill px-4" data-i18n="btn_login">دخول</a>
                            <a href="register.php" class="btn btn-primary btn-sm rounded-pill px-4 fw-bold shadow" data-i18n="btn_register">تسجيل</a>
                        <?php else: ?>
                            <div class="dropdown">
                                <button class="btn btn-primary btn-sm rounded-pill px-4 dropdown-toggle fw-bold" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-user-circle me-1"></i> <?php echo htmlspecialchars($user_name); ?>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-dark rounded-3 shadow border-0 mt-2 text-end">
                                    <li>
                                        <?php
                                        // فحص شامل وثلاثي الأبعاد للرتب المتاحة وتوجيه اللوج إن لصفحته المخصصة بالنص
                                        $current_role = isset($_SESSION['role']) ? trim($_SESSION['role']) : 'User';

                                        if (strcasecmp($current_role, 'Admin') === 0) {
                                            $profile_link = '../admin/admin-dashboard.php';
                                        } elseif (strcasecmp($current_role, 'Owner') === 0) {
                                            $profile_link = '../owner/owner-dashboard.php';
                                        } else {
                                            $profile_link = '../user/home.php';
                                        }
                                        ?>
                                        <a class="dropdown-item small py-2" href="<?php echo $profile_link; ?>" data-i18n="home_page">لوحة التحكم</a>
                                    </li>
                                    <li>
                                        <hr class="dropdown-divider opacity-10">
                                    </li>
                                    <li>
                                        <a class="dropdown-item small text-danger py-2 fw-bold" href="../general/logout.php">
                                            خروج
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <header class="main-hero" id="main-hero">
        <div class="hero-overlay">
            <div class="container text-center text-white">
                <img src="../assets/Images/Loogo.png" alt="Clevo Logo" class="hero-logo d-block mx-auto animate__animated animate__fadeIn">
                <p class="lead mb-4 fs-5 fw-bold" data-i18n="hero_desc"> دلّل سيارتك.. ونحن نهتم بالباقي. </p>
                <button onclick="location.href='#services'" class="btn btn-primary btn-lg rounded-pill px-5 shadow fw-bold" data-i18n="btn_discover">اكتشف خدماتنا</button>
            </div>
        </div>
    </header>

    <section id="services" class="py-5">
        <div class="container">
            <div class="mb-5 text-center">
                <span class="text-primary fw-bold d-block mb-2" data-i18n="s_subtitle">احترافية في العمل</span>
                <h2 class="fw-bold display-6 theme-text" data-i18n="s_title">خدماتنا المميزة</h2>
                <div class="bg-primary mx-auto mt-2 rounded-pill" style="width: 60px; height: 4px;"></div>
            </div>
            <div class="row g-4 px-2">
                <?php
                $services = [
                    ['img' => 'Car-Wash.jpg', 't' => 'ser_1_t', 'd' => 'ser_1_d'],
                    ['img' => 'Inside.jpg', 't' => 'ser_2_t', 'd' => 'ser_2_d'],
                    ['img' => 'Outside.jpg', 't' => 'ser_3_t', 'd' => 'ser_3_d'],
                    ['img' => 'OilChanges.jpg', 't' => 'ser_4_t', 'd' => 'ser_4_d'],
                    ['img' => 'AtHome.jpg', 't' => 'ser_5_t', 'd' => 'ser_5_d'],
                    ['img' => 'Delivary.jpg', 't' => 'ser_6_t', 'd' => 'ser_6_d']
                ];
                foreach ($services as $s): ?>
                    <div class="col-12 col-md-6 col-lg-4">
                        <div class="service-card" onclick="showStations('<?php echo $s['t']; ?>')">
                            <img src="../assets/Images/<?php echo $s['img']; ?>" alt="Service">
                            <div class="card-body pt-3">
                                <h5 class="fw-bold text-primary" data-i18n="<?php echo $s['t']; ?>"></h5>
                                <p class="theme-muted small mb-0" data-i18n="<?php echo $s['d']; ?>"></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section id="stations-section" class="py-5">
        <div class="container">
            <div class="mb-5 text-center">
                <h2 class="fw-bold display-6 theme-text" id="station-title-display"></h2>
                <p class="theme-muted" data-i18n="station_subtitle">المحطات التي توفر هذه الخدمة</p>
                <div class="bg-primary mx-auto mt-2 rounded-pill" style="width: 60px; height: 4px;"></div>
            </div>
            <div class="row g-4 px-2" id="stations-container">
                </div>
            <div class="text-center mt-5">
                <button class="btn btn-primary rounded-pill px-4 fw-bold" onclick="hideStations()" data-i18n="back_btn">العودة للخدمات</button>
            </div>
        </div>
    </section>

    <section class="cta-banner">
        <div class="container">
            <h2 class="fw-bold mb-4" data-i18n="cta_title">احجز الآن واحصل على خصم 15%</h2>
            <button onclick="location.href='login.php'" class="btn btn-light btn-lg rounded-pill px-5 fw-bold text-primary shadow" data-i18n="cta_btn">احجز موعدك الآن</button>
        </div>
    </section>

    <footer class="footer-dark">
        <div class="container footer-content">
            <div class="row g-4">
                <div class="col-lg-4">
                    <img src="../assets/Images/Logo.png" alt="Clevo Logo" class="footer-logo">
                    <p class="small opacity-85 footer-desc" data-i18n="footer_about">
                        نحن الخيار الأول في الأردن للعناية بالسيارات. نجمع بين السرعة، الأمان، واللمعان المثالي للجميع.
                    </p>
                </div>
                <div class="col-lg-2">
                    <h6 class="fw-bold text-primary mb-4" data-i18n="f_links">روابط سريعة</h6>
                    <ul class="list-unstyled footer-nav">
                        <li><a href="about.php" class="footer-link-animated" data-i18n="nav_about">من نحن</a></li>
                        <li><a href="#services" class="footer-link-animated" data-i18n="nav_services">خدماتنا</a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h6 class="fw-bold text-primary mb-4" data-i18n="f_support">الدعم الفني</h6>
                    <ul class="list-unstyled footer-nav">
                        <li><a href="terms.php" class="footer-link-animated" data-i18n="f_terms">الشروط والأحكام</a></li>
                        <li><a href="contact.php" class="footer-link-animated" data-i18n="nav_contact">تواصل معنا</a></li>
                    </ul>
                </div>
                <div class="col-lg-3 text-center">
                    <h6 class="fw-bold text-primary mb-4" data-i18n="f_follow">تابعنا</h6>
                    <div class="social-icons d-flex justify-content-center gap-3">
                        <a href="#" class="social-icon facebook"><i class="fab fa-facebook-f"></i></a>
                        <a href="https://www.instagram.com/clevo.jo" target="_blank" class="social-icon instagram"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="social-icon whatsapp"><i class="fab fa-whatsapp"></i></a>
                    </div>
                </div>
            </div>
            <hr class="mt-4 opacity-10">
            <p class="text-center small opacity-50 mb-0">© 2026 Clevo | <span data-i18n="f_copy">صمم بكل حب لخدمتكم</span></p>
        </div>
    </footer>

    <div class="modal fade" id="systemModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content text-center p-4">
                <div class="modal-body">
                    <i class="fas fa-exclamation-circle text-primary mb-3" style="font-size: 3rem;"></i>
                    <h4 class="fw-bold theme-text mb-3" id="modalTitle">تنبيه</h4>
                    <p class="theme-muted mb-4" id="modalMessage"></p>
                    <div id="modalButtons" class="d-flex justify-content-center gap-2">
                        <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal">حسنًا</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const isLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
        const stations = <?php echo json_encode($stations); ?>;

        const translations = {
            ar: {
                nav_services: "خدماتنا",
                nav_about: "من نحن",
                nav_contact: "تواصل معنا",
                btn_login: "دخول",
                btn_register: "تسجيل",
                hero_desc: "دلّل سيارتك.. ونحن نهتم بالباقي.",
                btn_discover: "اكتشف خدماتنا",
                s_subtitle: "احترافية في العمل",
                s_title: "خدماتنا المميزة",
                cta_title: "احجز الآن واحصل على خصم 15%",
                cta_btn: "احجز موعدك الآن",
                footer_about: "نحن الخيار الأول في الأردن للعناية بالسيارات. نجمع بين السرعة، الأمان، واللمعان المثالي للجميع.",
                f_links: "روابط سريعة",
                f_support: "الدعم الفني",
                f_terms: "الشروط والأحكام",
                f_follow: "تابعنا",
                f_copy: "صمم بكل حب لخدمتكم",
                chat_h: "لامعة - مساعدة ذكية",
                chat_welcome: "أهلاً بك في كليفوا! كيف يمكنني مساعدتك اليوم؟",
                home_page: "لوحة التحكم",
                logout: "خروج",
                ser_1_t: "غسيل شامل",
                ser_1_d: "تنظيف متكامل من الداخل والخارج.",
                ser_2_t: "غسيل داخلي",
                ser_2_d: "تنظيف عميق للمقصورة وتعقيمها.",
                ser_3_t: "غسيل خارجي",
                ser_3_d: "إزالة الأوساخ وحماية طلاء السيارة.",
                ser_4_t: "تغيير الزيت",
                ser_4_d: "فحص وتغيير الزيت بأفضل الأنواع.",
                ser_5_t: "خدمة منزلية",
                ser_5_d: "نصلكم أينما كنتم لتقديم الخدمة.",
                ser_6_t: "استلام وتسليم",
                ser_6_d: "نأخذ سيارتكم ونعيدها لكم لامعة.",
                station_subtitle: "المحطات التي توفر هذه الخدمة",
                back_btn: "العودة للخدمات",
                book_btn: "حجز موعد",
                modal_alert: "تنبيه الحجز",
                modal_msg: "يجب عليك تسجيل الدخول أولاً لتتمكن من إتمام عملية الحجز.",
                login_btn: "تسجيل الدخول",
                ok_btn: "حسنًا"
            },
            en: {
                nav_services: "Services",
                nav_about: "About Us",
                nav_contact: "Contact Us",
                btn_login: "Login",
                btn_register: "Register",
                hero_desc: "Pamper your car.. we take care of the rest.",
                btn_discover: "Our Services",
                s_subtitle: "Professionalism",
                s_title: "Featured Services",
                cta_title: "Book Now & Get 15% Off",
                cta_btn: "Book Your Appointment",
                footer_about: "Jordan's #1 car care choice. Speed, safety, and perfection.",
                f_links: "Quick Links",
                f_support: "Support",
                f_terms: "Terms",
                f_follow: "Follow Us",
                f_copy: "Designed with love",
                chat_h: "Lamea - AI Assistant",
                chat_welcome: "Welcome to Clevo! How can I help you?",
                home_page: "Dashboard",
                logout: "Logout",
                ser_1_t: "Full Wash",
                ser_1_d: "Full cleaning inside and out.",
                ser_2_t: "Interior Wash",
                ser_2_d: "Deep cabin cleaning.",
                ser_3_t: "Exterior Wash",
                ser_3_d: "Removing dirt and paint protection.",
                ser_4_t: "Oil Change",
                ser_4_d: "Oil check and change.",
                ser_5_t: "Home Service",
                ser_5_d: "We come to your place.",
                ser_6_t: "Pick & Drop",
                ser_6_d: "We return your car shiny.",
                station_subtitle: "Stations providing this service",
                back_btn: "Back to Services",
                book_btn: "Book Now",
                modal_alert: "Booking Alert",
                modal_msg: "You must login first to complete the booking process.",
                login_btn: "Login Now",
                ok_btn: "OK"
            }
        };

        function showStations(serviceKey) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const serviceTitle = translations[lang][serviceKey];

            document.getElementById('main-hero').style.display = 'none';
            document.getElementById('services').style.display = 'none';
            document.querySelector('.navbar-nav').style.setProperty('display', 'none', 'important');
            document.querySelector('.header-controls-wrapper').style.setProperty('display', 'none', 'important');

            const section = document.getElementById('stations-section');
            section.style.display = 'block';
            document.getElementById('station-title-display').innerText = serviceTitle;

            const container = document.getElementById('stations-container');
            container.innerHTML = '';

            const filteredStations = stations.filter(st => {
                if (serviceKey === 'ser_1_t' && st.ServiceName.includes('شامل')) return true;
                if (serviceKey === 'ser_2_t' && st.ServiceName.includes('داخلي')) return true;
                if (serviceKey === 'ser_3_t' && st.ServiceName.includes('خارجي')) return true;
                if (serviceKey === 'ser_4_t' && st.ServiceName.includes('زيت')) return true;
                return false;
            });

            filteredStations.forEach(st => {
                container.innerHTML += `
                    <div class="col-12 col-md-4">
                        <div class="service-card p-4">
                           <i class="fas fa-car-side text-primary mb-3" style="font-size: 4rem;"></i>
                            <h5 class="fw-bold theme-text">${st.StationName}</h5>
                            <p class="theme-muted small">متوفر الآن - خدمة سريعة</p>
                            <button class="btn btn-primary w-100 rounded-pill mt-3 fw-bold" onclick="attemptBooking(${st.StationID})">
                                ${translations[lang].book_btn}
                            </button>
                        </div>
                    </div>
                `;
            });
            window.scrollTo(0, 0);
        }

        function hideStations() {
            document.getElementById('stations-section').style.display = 'none';
            document.getElementById('main-hero').style.display = 'flex';
            document.getElementById('services').style.display = 'block';
            document.querySelector('.navbar-nav').style.display = 'flex';
            document.querySelector('.header-controls-wrapper').style.display = 'flex';
        }

        function attemptBooking(stationId) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            if (!isLoggedIn) {
                showModal(
                    translations[lang].modal_alert,
                    translations[lang].modal_msg,
                    `<a href="login.php" class="btn btn-primary rounded-pill px-4">${translations[lang].login_btn}</a>
                     <button class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">${translations[lang].ok_btn}</button>`
                );
            } else {
                location.href = `booking.php?station_id=${stationId}`;
            }
        }

        function showModal(title, msg, buttons) {
            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalMessage').innerText = msg;
            if (buttons) document.getElementById('modalButtons').innerHTML = buttons;
            const myModal = new bootstrap.Modal(document.getElementById('systemModal'));
            myModal.show();
        }

        function toggleChatWindow() {
            const win = document.getElementById('chatWindow');
            win.style.display = (win.style.display === 'flex' ? 'none' : 'flex');
        }

        function sendMessage() {
            const input = document.getElementById('chatInput');
            const body = document.getElementById('chatBody');
            if (input.value.trim() !== "") {
                const userMsg = document.createElement('div');
                userMsg.className = 'msg-bubble user-msg';
                userMsg.innerText = input.value;
                body.appendChild(userMsg);
                input.value = "";
                body.scrollTop = body.scrollHeight;
                setTimeout(() => {
                    const botMsg = document.createElement('div');
                    botMsg.className = 'msg-bubble bot-msg';
                    botMsg.innerText = "شكراً لتواصلك معنا، سيقوم أحد موظفينا بالرد عليك قريباً.";
                    body.appendChild(botMsg);
                    body.scrollTop = body.scrollHeight;
                }, 1000);
            }
        }

        function toggleLanguage() {
            const currentLang = localStorage.getItem('app_lang') || 'ar';
            const newLang = (currentLang === 'ar' ? 'en' : 'ar');
            localStorage.setItem('app_lang', newLang);
            location.reload();
        }

        function toggleGlobalTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = (currentTheme === 'dark' ? 'light' : 'dark');
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('global_theme', newTheme);
            updateThemeIcon(newTheme);
        }

        function updateThemeIcon(theme) {
            const icon = document.getElementById('globalThemeIcon');
            if (icon) icon.className = (theme === 'dark' ? 'fas fa-sun fs-6' : 'fas fa-moon fs-6');
        }

        window.onload = () => {
            const savedTheme = localStorage.getItem('global_theme') || 'light';
            updateThemeIcon(savedTheme);
            const savedLang = localStorage.getItem('app_lang') || 'ar';
            const langText = document.getElementById('langText');
            if (langText) langText.innerText = (savedLang === 'ar' ? 'EN' : 'AR');
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[savedLang] && translations[savedLang][key]) el.innerText = translations[savedLang][key];
            });
        };
    </script>
    <script src="../assets/lamea.js"></script>
</body>

</html>
