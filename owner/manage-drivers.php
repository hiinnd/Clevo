<?php

session_start();
include_once __DIR__ . '/../includes/config.php';


$owner_id = clevo_require_role('owner', '../general/login.php');


$station_query = mysqli_query($conn, "SELECT StationID FROM stations WHERE UserID = '$owner_id'");
$station_data = mysqli_fetch_assoc($station_query);
$my_station_id = $station_data['StationID'] ?? 0;


if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    clevo_require_csrf_token(true);
    $action = $_POST['action'];

    if ($action == 'fetch_drivers') {
        $query = mysqli_query($conn, "SELECT UserID as id, UserName as name, PhoneNumber as phone FROM user WHERE Role = 'driver' AND StationID = '$my_station_id' ORDER BY UserID DESC");
        $drivers_list = [];
        while($row = mysqli_fetch_assoc($query)) {
            $drivers_list[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'phone' => $row['phone']
            ];
        }
        echo json_encode($drivers_list);
        exit;
    }

    if ($action == 'add_driver') {
        $name = mysqli_real_escape_string($conn, $_POST['name']);
        $phone = mysqli_real_escape_string($conn, $_POST['phone']);
        
        if(strlen($phone) !== 10) {
            echo json_encode(['status' => 'error', 'message' => 'phone_length_error']);
            exit;
        }
        
        $default_pass = password_hash("123456", PASSWORD_DEFAULT);
        $sql = "INSERT INTO user (UserName, PhoneNumber, Password, Role, AccountStatus, StationID) VALUES ('$name', '$phone', '$default_pass', 'driver', 'active', '$my_station_id')";
        if (mysqli_query($conn, $sql)) { 
            echo json_encode(['status' => 'success']); 
        } else {
            echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
        }
        exit;
    }

    if ($action == 'delete_driver') {
        $id = intval($_POST['id']);
        if (mysqli_query($conn, "DELETE FROM user WHERE UserID = '$id' AND Role = 'driver' AND StationID = '$my_station_id'")) { 
            echo json_encode(['status' => 'success']); 
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="drivers_page_title">طاقم العمل | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --accent: #007bff; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #495057; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #007bff;
            --input-bg: #ffffff; --border-color: #dee2e6; --input-text: #000000;
            --btn-delete-bg: #fff5f5;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #0f172a; --text-main: #ffffff !important; --text-muted: #cbd5e1 !important; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --input-bg: #1a2236; --input-text: #ffffff !important;
            --btn-delete-bg: #2d1f24;
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s; margin: 0; overflow-x: hidden; }
        .main-wrapper { display: flex; min-height: 100vh; }

        /* Sidebar */
        .sidebar { width: 260px; background: var(--bg-sidebar); padding: 30px 15px; position: sticky; top: 0; min-height: 100vh; display: flex; flex-direction: column; transition: all 0.3s ease; }
        .nav-link { color: rgba(255,255,255,0.8) !important; padding: 12px; border-radius: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; transition: 0.2s; font-size: 0.95rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent); color: white !important; }
        .btn-ctrl { width: 35px; height: 35px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); color: white; border: 0; display: inline-flex; align-items: center; justify-content: center; }

        /* Content Area & Header */
        .content-area { flex: 1; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-back-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; }

        .card-custom { background: var(--bg-card); border-radius: 24px; padding: 25px; border: 1px solid var(--border-color); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        
        /* Driver Cards */
        .driver-card { background: var(--bg-card); border-radius: 24px; padding: 20px; margin-bottom: 15px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; transition: 0.3s; color: var(--text-main); }
        [dir="rtl"] .driver-card { border-right: 5px solid var(--primary-blue); }
        [dir="ltr"] .driver-card { border-left: 5px solid var(--primary-blue); }

        /* Inputs Styling */
        .form-control { background-color: var(--input-bg) !important; border: 1px solid var(--border-color) !important; color: var(--input-text) !important; border-radius: 12px; padding: 12px; font-weight: 600; }
        .form-control::placeholder { color: var(--text-muted); opacity: 0.6; }
        label { color: var(--text-main) !important; font-weight: 800; margin-bottom: 8px; display: block; }
        
        /* Delete Button */
        .btn-delete-driver { width: 40px; height: 40px; border-radius: 50%; background-color: var(--btn-delete-bg); color: #dc3545 !important; border: 1px solid rgba(220, 53, 69, 0.2); display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .btn-delete-driver:hover { background-color: #dc3545; color: #ffffff !important; box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3); }

        .modal-content { border-radius: 30px; background-color: var(--bg-card) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); }

        @media (max-width: 768px) {
            .main-wrapper { display: block; }
            .sidebar { width: 100%; height: auto; min-height: auto; position: relative; border-radius: 0 0 20px 20px; padding: 15px; }
            .sidebar .nav { flex-direction: row !important; overflow-x: auto; white-space: nowrap; gap: 5px; }
            .sidebar h4 { display: none; }
            .driver-card { flex-direction: column; text-align: center; gap: 15px; }
        }
    </style>
</head>
<body>
    <script>(function() { document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light'); })();</script>
    
    <div class="container-fluid p-0">
        <div class="main-wrapper">
            
            <?php include __DIR__ . '/../includes/shared_owner_sidebar.php'; ?>

            <main class="content-area">
                <div class="orders-header">
                    <div class="container d-flex justify-content-between align-items-center">
                        <a href="owner-dashboard.php" class="circle-back-btn shadow-sm">
                            <i class="fas fa-arrow-right" id="backIcon"></i>
                        </a>
                        <h6 class="mb-0 fw-bold" data-i18n="drivers_management_title">إدارة طاقم العمل</h6>
                        <div style="width: 40px;"></div> 
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div class="mx-auto" style="max-width: 900px;">
                        <div class="card-custom mb-4 shadow-sm">
                            <h6 class="mb-3 text-start fw-bold text-primary"><span data-i18n="add_driver_btn">إضافة كابتن جديد لقسم المغسلة</span></h6>
                            <div class="row g-3">
                                <div class="col-md-5 text-start">
                                    <label class="small mb-1 fw-bold" data-i18n="driver_name_label">اسم الكابتن الكامل</label>
                                    <input type="text" id="dName" class="form-control" data-i18n="[placeholder]placeholder_driver_name" placeholder="الاسم الكامل للسائق">
                                </div>
                                <div class="col-md-5 text-start">
                                    <label class="small mb-1 fw-bold" data-i18n="driver_phone_label">رقم تواصل الكابتن</label>
                                    <input type="tel" id="dPhone" class="form-control" maxlength="10" data-i18n="[placeholder]placeholder_driver_phone" placeholder="رقم الموبايل (10 أرقام)" oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button onclick="addNewDriver()" class="btn btn-primary w-100 rounded-pill py-2.5 fw-bold shadow-sm" style="height: 50px;"><i class="fas fa-plus"></i></button>
                                </div>
                            </div>
                        </div>
                        <div id="driversContainer"></div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="modal-body p-2">
                    <div class="text-danger mb-3"><i class="fas fa-exclamation-triangle fa-3x"></i></div>
                    <h5 class="fw-bold" data-i18n="delete_modal_title">تأكيد الحذف</h5>
                    <p class="small opacity-75" data-i18n="delete_confirm">هل تريد حذف السائق نهائياً من الطاقم؟</p>
                    <input type="hidden" id="targetDriverId" value="0">
                    <div class="d-grid gap-2 mt-4">
                        <button type="button" onclick="executeDeleteDriver()" class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_delete">حذف نهائي</button>
                        <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="alertModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="modal-body p-2">
                    <div class="text-warning mb-3"><i class="fas fa-info-circle fa-3x"></i></div>
                    <h5 class="fw-bold" data-i18n="alert_modal_title">تنبيه</h5>
                    <p class="small opacity-75" id="alertModalMessage" data-i18n="phone_alert">عذراً! يجب أن يكون رقم الموبايل مكون من 10 أرقام تماماً.</p>
                    <div class="d-grid gap-2 mt-4">
                        <button type="button" class="btn btn-primary rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
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
        const csrfToken = <?= json_encode(generate_csrf_token()) ?>;
        const translations = {
            ar: { 
                nav_main: "الرئيسية", nav_orders: "الطلبات", add_st_page_title: "إعداد المغسلة", services_list: "الخدمات", drivers_page_title: "طاقم العمل", recent_reviews: "التقييمات", serv_wallet: "المحفظة", nav_logout: "خروج", nav_notifications: "الإشعارات",
                drivers_management_title: "إدارة طاقم العمل", add_driver_btn: "إضافة كابتن جديد لقسم المغسلة", driver_name_label: "اسم الكابتن الكامل", driver_phone_label: "رقم تواصل الكابتن", placeholder_driver_name: "الاسم الكامل للسائق", placeholder_driver_phone: "رقم الموبايل (10 أرقام)", 
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", btn_cancel: "إلغاء", btn_delete: "حذف نهائي", btn_ok: "موافق",
                delete_modal_title: "تأكيد الحذف", delete_confirm: "هل تريد حذف السائق نهائياً من الطاقم؟", phone_alert: "عذراً! يجب أن يكون رقم الموبايل مكون من 10 أرقام تماماً.", alert_modal_title: "تنبيه"
            },
            en: { 
                nav_main: "Dashboard", nav_orders: "Orders", add_st_page_title: "Station Setup", services_list: "Services", drivers_page_title: "Team", recent_reviews: "Reviews", serv_wallet: "Wallet", nav_logout: "Logout", nav_notifications: "Notifications",
                drivers_management_title: "Staff Management", add_driver_btn: "Add New Captain", driver_name_label: "Captain Full Name", driver_phone_label: "Captain Contact Number", placeholder_driver_name: "Full Driver Name", placeholder_driver_phone: "Mobile Number (10 digits)", 
                logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", btn_cancel: "Cancel", btn_delete: "Delete permanently", btn_ok: "OK",
                delete_modal_title: "Confirm Delete", delete_confirm: "Do you want to delete this driver?", phone_alert: "Sorry! Mobile number must be exactly 10 digits.", alert_modal_title: "Alert"
            }
        };

        // دالة الترجمة التبادلية الفورية والذكية لنصوص الأسماء دون الحاجة لحقول إضافية بالداتابيز
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

        async function renderDrivers() {
            const container = document.getElementById('driversContainer');
            const lang = localStorage.getItem('app_lang') || 'ar';
            const isRtl = lang === 'ar';
            const response = await fetch('', { method: 'POST', body: new URLSearchParams({ 'action': 'fetch_drivers', 'csrf_token': csrfToken }) });
            const drivers = await response.json();
            
            if (drivers.length === 0) { 
                container.innerHTML = `<div class="text-center p-5 opacity-50"><p>${isRtl ? 'لا يوجد سائقين مسجلين بالمغسلة حالياً' : 'No drivers registered at this station yet'}</p></div>`; 
                return; 
            }
            
            container.innerHTML = '';
            
            for (const d of drivers) {
                let displayName = d.name;
                if (!isRtl) {
                    displayName = await translateTextLive(d.name);
                }
                
                container.innerHTML += `
                    <div class="driver-card shadow-sm">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width:50px;height:50px;border-radius:50%;background:var(--primary-blue);color:white;display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-user-tie"></i>
                            </div>
                            <div class="text-start">
                                <h6 class="mb-0 fw-bold">${displayName}</h6>
                                <small class="fw-bold opacity-75 text-secondary">${d.phone}</small>
                            </div>
                        </div>
                        <button class="btn-delete-driver" onclick="openDeleteModal(${d.id})">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>`;
            }
        }

        async function addNewDriver() {
            const name = document.getElementById('dName').value.trim();
            const phone = document.getElementById('dPhone').value.trim();
            
            if (!name) return;
            
            if (phone.length !== 10) {
                new bootstrap.Modal(document.getElementById('alertModal')).show();
                return;
            }
            
            const response = await fetch('', { 
                method: 'POST', 
                body: new URLSearchParams({ 
                    'action': 'add_driver', 
                    'csrf_token': csrfToken,
                    'name': name, 
                    'phone': phone 
                }) 
            });
            const res = await response.json();
            if (res.status === 'success') { 
                document.getElementById('dName').value = ""; 
                document.getElementById('dPhone').value = ""; 
                renderDrivers(); 
            }
        }

        function openDeleteModal(id) {
            document.getElementById('targetDriverId').value = id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        async function executeDeleteDriver() {
            const id = document.getElementById('targetDriverId').value;
            if(id > 0) {
                await fetch('', { method: 'POST', body: new URLSearchParams({ 'action': 'delete_driver', 'id': id, 'csrf_token': csrfToken }) });
                bootstrap.Modal.getInstance(document.getElementById('deleteModal')).hide();
                renderDrivers();
            }
        }

        function confirmLogout() { new bootstrap.Modal(document.getElementById('logoutModal')).show(); }
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

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                
                // إضافة فحص ذكي جداً لترجمة الـ placeholders داخل حقول الـ input فوراً
                if (key.startsWith('[placeholder]')) {
                    const cleanKey = key.replace('[placeholder]', '');
                    if (translations[lang][cleanKey]) {
                        el.setAttribute('placeholder', translations[lang][cleanKey]);
                    }
                } else {
                    if (translations[lang][key]) el.innerText = translations[lang][key];
                }
            });
            
            if(!isRtl && document.getElementById('backIcon')) document.getElementById('backIcon').className = "fas fa-arrow-left";
            const langBtn = document.getElementById('langBtnText');
            if(langBtn) langBtn.innerText = isRtl ? 'EN' : 'AR';
            const icon = document.getElementById('themeIcon');
            if(icon) icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            
            renderDrivers();
        };
    </script>
</body>
</html>
