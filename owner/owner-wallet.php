<?php

session_start();
include __DIR__ . '/../includes/config.php'; 


$userID = clevo_require_role('owner', '../general/login.php');
$balance = 0.00;
$transactions = [];
$monthly_profit = 0.00;

if ($userID > 0) {
    $wallet_query = mysqli_query($conn, "SELECT Balance FROM wallet WHERE UserID = '$userID' LIMIT 1");
    if ($row = mysqli_fetch_assoc($wallet_query)) {
        $balance = number_format($row['Balance'], 2);
    }
    $tx_query = mysqli_query($conn, "SELECT wt.* FROM wallet_transactions wt JOIN wallet w ON wt.WalletID = w.WalletID WHERE w.UserID = '$userID' ORDER BY wt.CreatedAt DESC LIMIT 10");
    while ($tx_query && $tx = mysqli_fetch_assoc($tx_query)) {
        $transactions[] = $tx;
    }
    $monthly_query = mysqli_query($conn, "SELECT COALESCE(SUM(wt.Amount), 0) AS total FROM wallet_transactions wt JOIN wallet w ON wt.WalletID = w.WalletID WHERE w.UserID = '$userID' AND wt.TransactionType = 'credit' AND MONTH(wt.CreatedAt) = MONTH(CURDATE()) AND YEAR(wt.CreatedAt) = YEAR(CURDATE())");
    if ($monthly_row = mysqli_fetch_assoc($monthly_query)) {
        $monthly_profit = (float) $monthly_row['total'];
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="wallet_page_title">محفظتي المالية | Clevo</title>
    <link class="bootstrap-link" rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
        :root { 
            --accent: #007bff; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted-custom: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #007bff;
            --wallet-grad: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            --border-color: #dee2e6;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #0f172a; --text-main: #ffffff !important; --text-muted-custom: #cbd5e1 !important; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --wallet-grad: linear-gradient(135deg, #020617 0%, #1e293b 100%); 
        }
        
        body { background-color: var(--bg-body); color: var(--text-main); font-family: 'Cairo', sans-serif; transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-wrapper { display: flex; min-height: 100vh; }

        /* Sidebar الموحد والمحدث لجميع الصفحات */
        .sidebar { width: 260px; background: var(--bg-sidebar); padding: 30px 15px; position: sticky; top: 0; min-height: 100vh; display: flex; flex-direction: column; transition: all 0.3s ease; }
        .nav-link { color: rgba(255, 255, 255, 0.8) !important; padding: 12px; border-radius: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; font-size: 0.95rem; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { background: var(--accent); color: white !important; }
        .btn-ctrl { width: 35px; height: 35px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); color: white; border: 0; display: inline-flex; align-items: center; justify-content: center; }

        /* هيدر المحفظة المتناسق والموحد بالكامل مع باقي واجهات الأونر */
        .content-area { flex: 1; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-back-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; }
        
        .wallet-card { background: var(--wallet-grad); color: white; border-radius: 24px; padding: 45px 20px; box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15); }
        
        .transaction-item { background: var(--bg-card); border-radius: 24px; padding: 20px; margin-bottom: 15px; border: 1px solid var(--border-color); color: var(--text-main); transition: 0.3s; }
        [dir="rtl"] .transaction-item { border-right: 5px solid #28a745; }
        [dir="ltr"] .transaction-item { border-left: 5px solid #28a745; }
        .transaction-item.debit-item { border-color: #dc3545 !important; }
        [dir="rtl"] .transaction-item.debit-item { border-right: 5px solid #dc3545; }
        [dir="ltr"] .transaction-item.debit-item { border-left: 5px solid #dc3545; }
        
        .stat-box { background: var(--bg-card); padding: 20px; border-radius: 24px; border: 1px solid var(--border-color); color: var(--text-main); height: 100%; }
        
        /* ضبط وتأكيد وضوح المودالات المخصصة للدارك مود */
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
        (function() { document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light'); })();
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
                        <h6 class="mb-0 fw-bold" data-i18n="wallet_page_title">محفظتي المالية</h6>
                        <div style="width: 40px;"></div>
                    </div>
                </div>

                <div class="container mt-4 px-4 mx-auto" style="max-width: 900px;">
                    <div class="wallet-card text-center mb-4 shadow-sm">
                        <small class="d-block mb-2 opacity-75 fw-bold" style="color: white !important;" data-i18n="available_balance">الرصيد المتاح للسحب</small>
                        <h1 class="mb-4 fw-bold" style="color: white !important; font-size: 2.8rem;"><?php echo $balance; ?> JOD</h1>
                        <button class="btn btn-warning rounded-pill px-5 fw-bold shadow-sm" onclick="showM('withdrawModal')" data-i18n="btn_withdraw">سحب الأرباح</button>
                    </div>

                    <div class="row g-3 mb-4 text-center">
                        <div class="col-6"><div class="stat-box border-bottom border-success border-4 shadow-sm"><small class="text-muted-custom d-block mb-1 fw-bold" data-i18n="monthly_profit">أرباح الشهر</small><strong class="text-success fs-5"><?php echo number_format($monthly_profit, 2); ?> JOD</strong></div></div>
                        <div class="col-6"><div class="stat-box border-bottom border-primary border-4 shadow-sm"><small class="text-muted-custom d-block mb-1 fw-bold" data-i18n="commission_label">العمولة</small><strong class="text-primary fs-5">10%</strong></div></div>
                    </div>

                    <h6 class="mb-3 fw-bold text-start" data-i18n="recent_history">السجل الأخير</h6>
                    <div id="transactions">
                        <?php if (!empty($transactions)): ?>
                            <?php foreach ($transactions as $tx): ?>
                                <div class="transaction-item d-flex justify-content-between align-items-center shadow-sm p-3 <?= $tx['TransactionType'] === 'credit' ? '' : 'debit-item' ?>">
                                    <div class="text-start">
                                        <h6 class="mb-0 fw-bold db-tx-desc" data-ar="<?= htmlspecialchars($tx['Description_ar']) ?>" data-en="<?= htmlspecialchars($tx['Description_en']) ?>"><?= htmlspecialchars($tx['Description_ar'] ?: $tx['Description_en']) ?></h6>
                                        <small class="text-muted-custom fw-bold" style="font-size: 12px;"><?= htmlspecialchars($tx['CreatedAt']) ?></small>
                                    </div>
                                    <div class="<?= $tx['TransactionType'] === 'credit' ? 'text-success' : 'text-danger' ?> fw-bold fs-5"><?= $tx['TransactionType'] === 'credit' ? '+' : '-' ?><?= number_format((float)$tx['Amount'], 2) ?> JOD</div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="transaction-item text-center small opacity-75 shadow-sm p-3" data-i18n="no_transactions_yet">لا توجد حركات بعد</div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="modal-body p-2">
                    <div class="text-danger mb-3"><i class="fas fa-sign-out-alt fa-3x"></i></div>
                    <h5 class="fw-bold mb-2" data-i18n="logout_title">تأكيد الخروج</h5>
                    <p class="text-muted-custom small mb-4" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
                    <div class="d-grid gap-2">
                        <a href="../general/logout.php" class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</a>
                        <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="withdrawModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center shadow-lg border-0">
                <div class="modal-body p-2">
                    <div class="mb-3 text-warning"><i class="fas fa-money-check-alt fa-3x"></i></div>
                    <h6 class="fw-bold mb-2" data-i18n="confirm_withdraw_title">تأكيد سحب الأرباح</h6>
                    <p class="text-muted-custom small mb-4" data-i18n="confirm_withdraw_msg">هل تريد سحب الرصيد المتاح لحسابك؟</p>
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_withdraw_now">نعم، سحب الآن</button>
                        <button class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: { 
                nav_main: "الرئيسية", nav_orders: "الطلبات", add_st_page_title: "إعداد المغسلة", services_list: "الخدمات", drivers_page_title: "طاقم العمل", recent_reviews: "التقييمات", serv_wallet: "المحفظة", nav_logout: "خروج", nav_notifications: "الإشعارات",
                wallet_page_title: "محفظتي المالية", available_balance: "الرصيد المتاح للسحب", btn_withdraw: "سحب الأرباح", monthly_profit: "أرباح الشهر", commission_label: "العمولة", recent_history: "السجل الأخير", 
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء", 
                no_transactions_yet: "لا توجد حركات بعد", confirm_withdraw_title: "تأكيد سحب الأرباح", confirm_withdraw_msg: "هل تريد سحب الرصيد المتاح لحسابك؟", btn_withdraw_now: "نعم، سحب الآن" 
            },
            en: { 
                nav_main: "Dashboard", nav_orders: "Orders", add_st_page_title: "Station Setup", services_list: "Services", drivers_page_title: "Team", recent_reviews: "Reviews", serv_wallet: "Wallet", nav_logout: "Logout", nav_notifications: "Notifications",
                wallet_page_title: "My Wallet", available_balance: "Available Balance", btn_withdraw: "Withdraw", monthly_profit: "Monthly Profit", commission_label: "Commission", recent_history: "Recent History", 
                logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel", 
                no_transactions_yet: "No transactions found", confirm_withdraw_title: "Confirm Withdrawal", confirm_withdraw_msg: "Do you want to withdraw the available balance to your account?", btn_withdraw_now: "Yes, Withdraw Now" 
            }
        };

        const showM = (id) => new bootstrap.Modal(document.getElementById(id)).show();
        function confirmLogout() { showM('logoutModal'); }
        function toggleLanguage() { localStorage.setItem('app_lang', (localStorage.getItem('app_lang') || 'ar') === 'ar' ? 'en' : 'ar'); location.reload(); }
        function toggleGlobalTheme() { localStorage.setItem('global_theme', document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); location.reload(); }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'light';
            const isRtl = lang === 'ar';
            
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
            document.getElementById('bootstrap-css').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            
            document.querySelectorAll('[data-i18n]').forEach(el => { const key = el.getAttribute('data-i18n'); if (translations[lang][key]) el.innerText = translations[lang][key]; });
            
            // ترجمة تبادلية لسجل المعاملات المجلوبة من قاعدة البيانات
            document.querySelectorAll('.db-tx-desc').forEach(el => {
                const arText = el.getAttribute('data-ar');
                const enText = el.getAttribute('data-en');
                el.innerText = isRtl ? arText : (enText ? enText : arText);
            });

            if(!isRtl && document.getElementById('backIcon')) document.getElementById('backIcon').className = "fas fa-arrow-left";
            const langBtnText = document.getElementById('langBtnText');
            if(langBtnText) langBtnText.innerText = isRtl ? 'EN' : 'AR';
            const icon = document.getElementById('themeIcon');
            if(icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        };
    </script>
    <script src="../assets/lamea.js"></script>
</body>
</html>
