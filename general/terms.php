<?php

session_start();
include_once __DIR__ . '/../includes/config.php';


?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="f_terms">الشروط والأحكام | Clevo</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;700&display=swap" rel="stylesheet">

    <style>
        :root { 
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.7);
            --text: #0369a1; --accent: #0ea5e9; --border: rgba(255, 255, 255, 0.1);
        }
        [data-theme="dark"] { 
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.5);
            --text: #f1f5f9; --accent: #38bdf8; --border: rgba(255, 255, 255, 0.05);
        }
        
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 20px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; }


        .page-header { 
            padding: 20px; display: flex; align-items: center; justify-content: space-between;
            height: 180px; border-radius: 0 0 40px 40px; 
            background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 40px; 
        }
        
        .back-circle-btn { 
            width: 40px; height: 40px; border-radius: 50%; display: flex; 
            align-items: center; justify-content: center; text-decoration: none; 
            background: rgba(255,255,255,0.2); color: #fff; transition: 0.3s; z-index: 10;
        }

        .spacer-div { width: 40px; }
        [dir="rtl"] .back-circle-btn { order: 1; } 
        [dir="rtl"] .spacer-div { order: 3; }
        [dir="rtl"] #backIcon { transform: rotate(0deg); }

        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] .spacer-div { order: 3; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }


        .header-title-light { 
            order: 2; 
            flex-grow: 1; 
            text-align: center; 
            margin: 0; 
            font-family: 'Cairo', sans-serif !important;
            font-weight: 300 !important;
            font-size: 1.6rem;
            color: white !important; 
        }

        /* --- Content Container --- */
        .terms-card { padding: 30px; margin-top: -60px; position: relative; z-index: 20; }
        .term-section { margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border); }
        .term-section h6 { color: var(--accent); font-weight: 700; display: flex; align-items: center; gap: 10px; }


        .custom-footer { margin-top: 40px; text-align: center; padding: 15px; }
        .footer-logo { width: 70px; margin-bottom: 10px; }
        .footer-copy { font-size: 0.75rem; opacity: 0.6; }
    </style>
</head>

<body>
    <div class="page-header shadow">
        <div class="spacer-div"></div>
        <h4 class="header-title-light" data-i18n="f_terms">الشروط والأحكام</h4>
        <a href="landing.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <main class="container">
        <div class="glass terms-card shadow-lg mx-2 mb-5">
            <div class="term-section">
                <h6><i class="fas fa-user-shield"></i> <span data-i18n="terms_sec1_t">الالتزام بالبيانات</span></h6>
                <p class="small opacity-85" data-i18n="terms_sec1_d">يجب تقديم معلومات صحيحة لضمان جودة الخدمة والوصول السريع للموقع.</p>
            </div>

            <div class="term-section">
                <h6><i class="fas fa-calendar-check"></i> <span data-i18n="terms_sec2_t">سياسة الحجز والمواعيد</span></h6>
                <p class="small opacity-85" data-i18n="terms_sec2_d">يلتزم الزبون بالتواجد في الموعد المختار، والتأخير قد يؤدي لإلغاء الطلب.</p>
            </div>

            <div class="term-section">
                <h6><i class="fas fa-fingerprint"></i> <span data-i18n="terms_sec3_t">نظام الأمان</span></h6>
                <p class="small opacity-85" data-i18n="terms_sec3_d">يحق للتطبيق حظر أي حساب يتلاعب بالأسعار أو يقدم بلاغات كاذبة.</p>
            </div>

            <div class="term-section border-0">
                <h6><i class="fas fa-file-invoice-dollar"></i> <span data-i18n="terms_sec5_t">الدفع الإلكتروني</span></h6>
                <p class="small opacity-85" data-i18n="terms_sec5_d">يتم الخصم من المحفظة فور قبول المحطة للطلب مع ضمان تشفير البيانات.</p>
            </div>
        </div>
    </main>

    <footer class="custom-footer">
        <img src="../assets/Images/Logo.png" alt="Logo" class="footer-logo">
        <div class="footer-copy">© 2026 Clevo | <span data-i18n="f_copy">صمم بكل حب لخدمتكم</span></div>
    </footer>

    <script>
        const translations = {
            ar: {
                f_terms: "الشروط والأحكام",
                terms_sec1_t: "الالتزام بالبيانات",
                terms_sec1_d: "يجب تقديم معلومات صحيحة لضمان جودة الخدمة والوصول السريع للموقع.",
                terms_sec2_t: "سياسة الحجز والمواعيد",
                terms_sec2_d: "يلتزم الزبون بالتواجد في الموعد المختار، والتأخير قد يؤدي لإلغاء الطلب.",
                terms_sec3_t: "نظام الأمان",
                terms_sec3_d: "يحق للتطبيق حظر أي حساب يتلاعب بالأسعار أو يقدم بلاغات كاذبة.",
                terms_sec5_t: "الدفع الإلكتروني",
                terms_sec5_d: "يتم الخصم من المحفظة فور قبول المحطة للطلب مع ضمان تشفير البيانات.",
                f_copy: "صمم بكل حب لخدمتكم"
            },
            en: {
                f_terms: "Terms & Conditions",
                terms_sec1_t: "Data Commitment",
                terms_sec1_d: "Correct information must be provided to ensure service quality.",
                terms_sec2_t: "Booking Policy",
                terms_sec2_d: "Customers must be present at the chosen time.",
                terms_sec3_t: "Security System",
                terms_sec3_d: "The app may ban accounts for price manipulation.",
                terms_sec5_t: "Electronic Payment",
                terms_sec5_d: "Payment is deducted upon order acceptance.",
                f_copy: "Designed with love"
            }
        };

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.setAttribute('data-theme', theme);
            
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });
        };
    </script>
</body>
</html>
