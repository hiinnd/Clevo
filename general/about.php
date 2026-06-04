<?php
session_start();
include_once __DIR__ . '/../includes/config.php';
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="nav_about">من نحن | Clevo</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;700;900&display=swap" rel="stylesheet">
    
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
            font-weight: 400 !important;
            font-size: 1.5rem;
            color: white !important; 
        }

        /* --- Content --- */
        .content-card { padding: 30px; margin-top: -50px; position: relative; z-index: 20; }
        .vision-card { border-radius: 25px; background: var(--glass); border: 1px solid var(--border); padding: 25px; height: 100%; transition: 0.4s; }
        .vision-card:hover { transform: translateY(-8px); }
        .icon-box { width: 60px; height: 60px; background: rgba(14, 165, 233, 0.1); color: var(--accent); border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; margin: 0 auto 15px; }
        .img-story { border-radius: 25px; border: 4px solid var(--glass); width: 100%; object-fit: cover; }

        /* --- Footer --- */
        .custom-footer { margin-top: 40px; text-align: center; padding: 15px; }
        .footer-logo { width: 70px; margin-bottom: 10px; }
        .footer-copy { font-size: 0.75rem; opacity: 0.6; margin-top: 5px; }
    </style>
</head>

<body>
    <div class="page-header shadow">
        <div class="spacer-div"></div>
        <h4 class="header-title-light" data-i18n="about_title">قصة Clevo</h4>
        <a href="landing.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="container">
        <div class="glass content-card shadow-lg mx-2 mb-5">
            <div class="row align-items-center g-4">
                <div class="col-lg-6">
                    <h2 class="fw-bold mb-3" data-i18n="our_story_title">كيف بدأنا؟</h2>
                    <p class="mb-3 fs-6 opacity-85" data-i18n="our_story_p1">بدأت فكرة Clevo من قلب عمان، عندما لاحظنا الصعوبة في العثور على مغاسل موثوقة وإضاعة الوقت في الانتظار.</p>
                    <p class="fs-6 opacity-85" data-i18n="our_story_p2">قررنا بناء منصة تجمع أفضل المغاسل لتوفر للزبائن الراحة، ولأصحاب الأعمال التنظيم والنمو.</p>
                </div>
                <div class="col-lg-6 text-center">
                    <img src="../assets/Images/wash.jpg" alt="Story" class="img-fluid img-story shadow-sm">
                </div>
            </div>
        </div>

        <div class="row g-4 justify-content-center text-center">
            <div class="col-12 col-md-4">
                <div class="vision-card shadow-sm">
                    <div class="icon-box"><i class="fas fa-eye"></i></div>
                    <h5 class="fw-bold" data-i18n="vision_t">رؤيتنا</h5>
                    <p class="opacity-75 small" data-i18n="vision_d">أن نكون الخيار الأول والوحيد الذي يثق به كل أردني للعناية بمركبته.</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="vision-card shadow-sm">
                    <div class="icon-box"><i class="fas fa-rocket"></i></div>
                    <h5 class="fw-bold" data-i18n="mission_t">مهمتنا</h5>
                    <p class="opacity-75 small" data-i18n="mission_d">تسهيل حياة الناس بتوفير خدمات احترافية ودعم المشاريع المحلية.</p>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="vision-card shadow-sm">
                    <div class="icon-box"><i class="fas fa-shield-alt"></i></div>
                    <h5 class="fw-bold" data-i18n="values_t">قيمنا</h5>
                    <p class="opacity-75 small" data-i18n="values_d">الشفافية في الأسعار، الأمان، والجودة التي تجعل سيارتك تلمع دائماً.</p>
                </div>
            </div>
        </div>
    </div>

    <footer class="custom-footer">
        <img src="../assets/Images/Logo.png" alt="Logo" class="footer-logo">
        <div class="footer-copy">© 2026 Clevo | <span data-i18n="f_copy">صمم بكل حب لخدمتكم</span></div>
    </footer>

    <script>
        const translations = {
            ar: {
                nav_about: "من نحن | Clevo", about_title: "قصة Clevo", our_story_title: "كيف بدأنا؟",
                our_story_p1: "بدأت فكرة Clevo من قلب عمان، عندما لاحظنا الصعوبة في العثور على مغاسل موثوقة.",
                our_story_p2: "قررنا بناء منصة تجمع أفضل المغاسل لتوفر للزبائن الراحة ولأصحاب الأعمال التنظيم.",
                vision_t: "رؤيتنا", vision_d: "أن نكون الخيار الأول الذي يثق به كل أردني للعناية بمركبته.",
                mission_t: "مهمتنا", mission_d: "تسهيل حياة الناس بتوفير خدمات احترافية ودعم المشاريع المحلية.",
                values_t: "قيمنا", values_d: "الشفافية، الأمان، والجودة التي تجعل سيارتك تلمع دائماً.",
                f_copy: "صمم بكل حب لخدمتكم"
            },
            en: {
                nav_about: "About Us | Clevo", about_title: "Clevo Story", our_story_title: "How did we start?",
                our_story_p1: "Clevo's idea started from the heart of Amman, noticing the difficulty in finding reliable car washes.",
                our_story_p2: "We decided to build a platform that brings together the best car washes for convenience and growth.",
                vision_t: "Our Vision", vision_d: "To be the first and only choice every Jordanian trusts for vehicle care.",
                mission_t: "Our Mission", mission_d: "Facilitating lives by providing professional services and supporting local businesses.",
                values_t: "Our Values", values_d: "Transparency, security, and quality that always makes your car shine.",
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
