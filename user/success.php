<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="order_success_page_title">تم الطلب بنجاح | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-rtl">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">

    <style>
        :root { --bg-body: #f8f9fa; --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; --badge-bg: #f1f3f5; --border-color: #dee2e6; --check-bg: #d1e7dd; --check-color: #198754; }
        [data-theme="dark"] { --bg-body: #0f172a; --bg-card: #1e293b; --text-main: #ffffff; --text-muted: #f1f5f9; --badge-bg: #2d3748; --border-color: #475569; --check-bg: rgba(25, 135, 84, 0.3); --check-color: #2ecc71; }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; transition: 0.3s ease; }
        
        /* زر الرجوع المطور: سهم فقط */
        .back-nav { position: fixed; top: 20px; right: 20px; z-index: 1000; }
        html[dir="ltr"] .back-nav { right: auto; left: 20px; }
        .circle-btn { 
            background: var(--bg-card); width: 40px; height: 40px; border-radius: 50%; 
            display: flex; align-items: center; justify-content: center; 
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); border: 1px solid var(--border-color); 
            color: var(--text-main); text-decoration: none; transition: 0.3s;
        }
        .circle-btn:hover { transform: scale(1.1); color: #007bff; }

        .success-card { background: var(--bg-card); border-radius: 40px; padding: 50px 25px; width: 90%; max-width: 450px; text-align: center; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1); border: 1px solid var(--border-color); position: relative; }
        .check-container { width: 80px; height: 80px; background: var(--check-bg); color: var(--check-color); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; margin: 0 auto 20px; }
        .order-id { background: var(--badge-bg); padding: 8px 20px; border-radius: 50px; display: inline-block; margin-bottom: 25px; color: var(--text-main); font-size: 0.85rem; border: 1px dashed #007bff; }
        .btn-track { border-radius: 50px; padding: 14px; font-weight: 700; background: #007bff; border: none; color: white; width: 100%; transition: 0.3s; }
        .nav-item-link { font-size: 0.85rem; text-decoration: none; color: #007bff !important; font-weight: 700; background: none; border: none; margin-top: 15px; cursor: pointer; display: inline-block; }
        .text-muted { color: var(--text-muted) !important; opacity: 1 !important; }
    </style>
</head>

<body>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>

    <div class="back-nav">
        <a href="home.php" class="circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="success-card shadow-lg">
        <div class="check-container shadow-sm"><i class="fas fa-check-circle"></i></div>
        <h2 class="mb-2 fw-bold" data-i18n="order_sent_title">تم إرسال الطلب!</h2>
        <p class="small mb-4 text-muted">
            <span data-i18n="hello_thanks">شكراً لكِ يا</span> <span class="uName text-primary fw-bold">...</span>،
            <span data-i18n="order_sent_msg">تم استلام طلبكِ بنجاح وهو الآن بانتظار موافقة المحطة.</span>
        </p>

        <div class="order-id">
            <span data-i18n="order_id_label">رقم الطلب:</span> <strong>#CL-9920</strong>
        </div>

        <button onclick="location.href='home.php'" class="btn-track mb-3 shadow">
            <span data-i18n="back_to_home">العودة للرئيسية</span> <i class="fas fa-home ms-1"></i>
        </button>

        <div class="pt-3 border-top" style="border-color: var(--border-color) !important;">
            <button onclick="location.href='booking-history.php'" class="nav-item-link">
                <span data-i18n="view_history">عرض سجل الحجوزات</span> <i class="fas fa-history ms-1"></i>
            </button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/translations.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const isRtl = lang === 'ar';
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', isRtl ? 'rtl' : 'ltr');

            if (typeof applyTranslations === 'function') applyTranslations(lang);

            // تدوير السهم حسب اللغة
            if (!isRtl) document.getElementById('backIcon').className = "fas fa-arrow-left";

            const name = localStorage.getItem('userName') || (isRtl ? "هند" : "User");
            document.querySelectorAll('.uName').forEach(el => el.innerText = name);

            // تحديث حالات الطلب برمجياً
            localStorage.setItem('clevo_order_status', 'Pending');
            // عرض رقم الطلب إن أُرسل عبر GET param ?order=
            try {
                const params = new URLSearchParams(window.location.search);
                const orderParam = params.get('order');
                if (orderParam) {
                    document.querySelectorAll('.order-id strong').forEach(el => el.innerText = '#' + orderParam);
                }
            } catch (e) { /* ignore */ }
        });
    </script>
    <script src="/Clevo/assets/lamea.js"></script>
</body>
</html>
