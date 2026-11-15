<?php

include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';


$owner_id = clevo_require_role('owner', '../general/login.php');
clevoEnsureServiceLanguageColumns($conn);
$station_query = $conn->query("SELECT * FROM stations WHERE UserID = $owner_id AND ArchivedAt IS NULL ORDER BY StationID LIMIT 1");
$station_data = $station_query->fetch_assoc();
$station_id = $station_data['StationID'] ?? 0;


if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_booking_policy'])) {
    $input=[];
    foreach(['capacity','home_capacity','default_service_minutes','delivery_buffer_minutes'] as $key){
        $value=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT);
        if($value===false)throw new ClevoSecurityException('invalid_booking_policy',422);$input[$key]=$value;
    }
    foreach(['station_fee','home_fee','delivery_fee'] as $key)$input[$key]=(string)($_POST[$key]??'');
    clevo_market_availability($conn,$owner_id,(int)$station_id,$input);
    header('Location: manage-services.php?msg=success');exit;
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_service_id'])){
    clevo_require_csrf_token();
    $id = intval($_POST['delete_service_id']);
    clevo_archive_catalog($conn,$owner_id,'service',$id);
    header("Location: manage-services.php?msg=deleted");
    exit();
}


if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_service'])){
    clevo_require_csrf_token();
    $name_ar = trim((string) ($_POST['name_ar'] ?? ''));
    $name_en = trim((string) ($_POST['name_en'] ?? ''));
    $description_ar = trim((string) ($_POST['description_ar'] ?? ''));
    $description_en = trim((string) ($_POST['description_en'] ?? ''));
    if ($name_ar === '') {
        $name_ar = trim((string) ($_POST['service_name'] ?? $_POST['name'] ?? ''));
    }
    if ($name_en === '') {
        $name_en = $name_ar;
    }
    if ($description_ar === '') {
        $description_ar = trim((string) ($_POST['service_description'] ?? ''));
    }
    if ($description_en === '') {
        $description_en = $description_ar;
    }
    $price = (string)($_POST['price'] ?? '');
    clevo_db_money($price);
    if ($name_ar === '' || !is_numeric($price) || (float)$price > 100000) throw new ClevoSecurityException('invalid_service', 422);
    $edit_id = intval($_POST['edit_id']);
    
    $is_station = isset($_POST['type_station']) ? 1 : 0;
    $is_home = isset($_POST['type_home']) ? 1 : 0;
    $is_pickup = isset($_POST['type_pickup']) ? 1 : 0;

    
    clevo_market_service($conn,$owner_id,(int)$station_id,$edit_id,['name_ar'=>$name_ar,'name_en'=>$name_en,'description_ar'=>$description_ar,'description_en'=>$description_en,'price'=>$price,'duration'=>isset($_POST['duration'])?(int)$_POST['duration']:(int)($station_data['DefaultServiceMinutes']??30),'is_station'=>(bool)$is_station,'is_home'=>(bool)$is_home,'is_pickup'=>(bool)$is_pickup]);
    header('Location: manage-services.php?msg=success'); exit();
}


$services_res = $conn->query("SELECT * FROM services WHERE StationID = $station_id AND ArchivedAt IS NULL ORDER BY ServiceID DESC");
$db_services = [];
while($row = $services_res->fetch_assoc()){
    $db_services[] = [
        'id' => $row['ServiceID'],
        'name_ar' => $row['Name_ar'] ?: $row['Name'],
        'name_en' => $row['Name_en'] ?: $row['Name'],
        'description_ar' => $row['Description_ar'] ?: $row['Description'],
        'description_en' => $row['Description_en'] ?: $row['Description'],
        'price' => $row['Price'],
        'duration' => $row['Duration'] ?: $station_data['DefaultServiceMinutes'],
        'types' => array_merge(
            $row['is_station'] ? ['station'] : [],
            $row['is_home'] ? ['home'] : [],
            $row['is_pickup'] ? ['pickup'] : []
        )
    ];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="manage_services_title">إدارة الخدمات | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">

    <style>
         :root { 
            --accent: #007bff; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #007bff;
            --border-color: #dee2e6; --input-bg: #ffffff;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #0f172a; --text-main: #ffffff !important; --text-muted: #cbd5e1; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --input-bg: #1a2236;
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s; margin: 0; overflow-x: hidden; }
        .main-wrapper { display: flex; min-height: 100vh; }


        .sidebar { width: 260px; background: var(--bg-sidebar); padding: 30px 15px; position: sticky; top: 0; min-height: 100vh; display: flex; flex-direction: column; transition: all 0.3s ease; }
        .nav-link { color: rgba(255,255,255,0.8) !important; padding: 12px; border-radius: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; transition: 0.2s; font-size: 0.95rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent); color: white !important; }
        .btn-ctrl { width: 35px; height: 35px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); color: white; border: 0; display: inline-flex; align-items: center; justify-content: center; }
        
        /* Content */
        .content-area { flex: 1; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-back-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; }
        
        .card-custom { background: var(--bg-card); border-radius: 24px; padding: 25px; border: 1px solid var(--border-color); box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .service-item { background: var(--bg-card); border-radius: 24px; padding: 20px; margin-bottom: 15px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; color: var(--text-main); transition: 0.3s; }
        [dir="rtl"] .service-item { border-right: 5px solid var(--primary-blue); }
        [dir="ltr"] .service-item { border-left: 5px solid var(--primary-blue); }
        
        .form-control { background-color: var(--input-bg) !important; border: 1px solid var(--border-color) !important; color: var(--text-main) !important; border-radius: 12px; font-weight: 600; padding: 12px; }
        label { color: var(--text-main) !important; font-weight: 800; margin-bottom: 8px; display: block; }
        .type-badge { font-size: 0.75rem; padding: 4px 12px; border-radius: 50px; background: var(--badge-bg); color: var(--primary-blue); font-weight: 800; margin-top: 5px; display: inline-block; border: 1px solid var(--border-color); }
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
        (function() {
            document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light');
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
                        <h6 class="mb-0 fw-bold" data-i18n="manage_services_header">إدارة الخدمات المتاحة</h6>
                        <div style="width: 40px;"></div> 
                    </div>
                </div>
                
                <div class="container mt-4 px-4">
                    <div class="row g-4 text-start">
                        <div class="col-lg-5">
                            <div class="card-custom shadow-sm">
                                <h6 id="formTitle" class="mb-3 text-primary fw-bold" data-i18n="add_new_service">إضافة خدمة جديدة</h6>
                                <form id="serviceForm" method="POST">
                                    <?= csrf_input(); ?>
                                    <input type="hidden" name="edit_id" id="editId" value="0">
                                    <input type="hidden" name="name_ar" id="sNameAr">
                                    <input type="hidden" name="name_en" id="sNameEn">
                                    <input type="hidden" name="description_ar" id="sDescAr">
                                    <input type="hidden" name="description_en" id="sDescEn">
                                    <div class="mb-3">
                                        <label class="small mb-1" data-i18n="service_name_label">اسم الخدمة</label>
                                        <input type="text" name="service_name" id="sNameInput" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="small mb-1" data-i18n="service_description_label">وصف الخدمة</label>
                                        <textarea name="service_description" id="sDescInput" class="form-control" rows="3" data-i18n-placeholder="service_description_placeholder"></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label class="small mb-1" data-i18n="service_price_label">السعر (JOD)</label>
                                        <input type="number" step="0.01" name="price" id="sPrice" class="form-control" required>
                                        <label class="small mt-2 mb-1" data-i18n="service_duration_label">مدة الخدمة بالدقائق</label>
                                        <input type="number" min="1" max="1440" name="duration" id="sDuration" class="form-control" value="<?= (int)($station_data['DefaultServiceMinutes']??30) ?>" required>
                                    </div>
                                    <div class="mb-4 border p-3 rounded-3" style="border-color: var(--border-color) !important;">
                                        <label class="small mb-2 d-block text-primary fw-bold" data-i18n="available_options">خيارات تقديم الخدمة:</label>
                                        <div class="form-check mb-1">
                                            <input class="form-check-input" type="checkbox" name="type_station" id="typeStation" checked>
                                            <label class="form-check-label small" for="typeStation" data-i18n="type_station">في المحطة (Station)</label>
                                        </div>
                                        <div class="form-check mb-1">
                                            <input class="form-check-input" type="checkbox" name="type_home" id="typeHome">
                                            <label class="form-check-label small" for="typeHome" data-i18n="type_home">خدمة منزلية (At Home)</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="type_pickup" id="typePickup">
                                            <label class="form-check-label small" for="typePickup" data-i18n="type_pickup">استلام وتسليم (Pickup)</label>
                                        </div>
                                    </div>
                                    <button type="submit" name="save_service" class="btn btn-primary w-100 rounded-pill py-3 fw-bold shadow" data-i18n="btn_save_service">حفظ الخدمة ونشرها</button>
                                    <button type="button" id="cancelEditBtn" onclick="resetForm()" class="btn btn-light rounded-pill w-100 mt-2 border d-none" data-i18n="btn_cancel">إلغاء</button>
                                </form>
                            </div>
                        </div>

                        <div class="col-lg-7">
                            <h6 class="mb-3 text-secondary fw-bold" data-i18n="current_services_list">قائمة خدماتك النشطة</h6>
                            <div id="services-container">
                                <?php foreach($db_services as $s): ?>
                                    <div class="service-item shadow-sm">
                                        <div class="text-start ms-3">
                                            <h6 class="mb-0 fw-bold db-service-name" data-ar="<?php echo htmlspecialchars($s['name_ar']); ?>" data-en="<?php echo htmlspecialchars($s['name_en']); ?>"><?php echo htmlspecialchars($s['name_ar']); ?></h6>
                                            <p class="mb-1 small opacity-75 db-service-desc" data-ar="<?php echo htmlspecialchars($s['description_ar']); ?>" data-en="<?php echo htmlspecialchars($s['description_en']); ?>"><?php echo htmlspecialchars($s['description_ar']); ?></p>
                                            <span class="text-success fw-bold small"><?php echo number_format($s['price'], 2); ?> JOD</span><br>
                                            <?php foreach($s['types'] as $t): ?>
                                                <span class="type-badge me-1" data-i18n="<?php echo $t; ?>_service"></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="d-flex gap-2 me-3">
                                            <button class="btn btn-sm btn-outline-primary rounded-circle" style="width:35px; height:35px;" onclick='editService(<?php echo e(clevo_js($s)); ?>)'><i class="fas fa-edit"></i></button>
                                            <button type="button" class="btn btn-sm text-danger d-flex align-items-center justify-content-center" onclick="confirmDeleteService(<?php echo (int) $s['id']; ?>)"><i class="fas fa-trash-alt"></i></button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <?php if ($station_id): ?>
    <div class="container mb-4"><form method="POST" class="glass-card p-3">
        <?= csrf_input(); ?><h6 data-i18n="booking_policy_title">السعة ورسوم الخدمة</h6><div class="row g-2">
        <?php foreach(['capacity'=>['BookingCapacity','booking_capacity',1,1000], 'home_capacity'=>['HomeCapacity','home_capacity',1,1000], 'default_service_minutes'=>['DefaultServiceMinutes','default_minutes',1,1440], 'delivery_buffer_minutes'=>['DeliveryBufferMinutes','delivery_minutes',0,1440], 'station_fee'=>['StationFee','station_fee',0,99999999.99], 'home_fee'=>['HomeFee','home_fee',0,99999999.99], 'delivery_fee'=>['DeliveryFee','delivery_fee',0,99999999.99]] as $name=>$field): ?>
            <div class="col-sm-6 col-lg-4"><label class="small" data-i18n="<?= e($field[1]) ?>"><?= e($field[1]) ?></label><input class="form-control" type="number" name="<?= e($name) ?>" min="<?= e($field[2]) ?>" max="<?= e($field[3]) ?>" step="<?= str_ends_with($name,'_fee')?'0.01':'1' ?>" value="<?= e($station_data[$field[0]]??'') ?>" required></div>
        <?php endforeach; ?></div><button class="btn btn-primary mt-3" name="save_booking_policy" value="1" data-i18n="btn_save_service">حفظ</button>
    </form></div><?php endif; ?>

    <div class="modal fade" id="logoutModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div class="text-danger mb-3"><i class="fas fa-sign-out-alt fa-3x"></i></div>
                <h5 class="fw-bold" data-i18n="logout_title">تأكيد الخروج</h5>
                <p class="small opacity-75" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
                <div class="d-grid gap-2 mt-4">
                    <form method="POST" action="../general/logout.php" class="m-0"><?= csrf_input(); ?><button type="submit"  class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</button></form>
                    <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteServiceModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <i class="fas fa-triangle-exclamation text-warning fa-4x mb-3"></i>
                <h5 class="fw-bold" data-i18n="delete_service_title">تأكيد حذف الخدمة</h5>
                <p class="small opacity-75" data-i18n="delete_service_msg">هل أنت متأكد من حذف هذه الخدمة؟</p>
                <div class="d-grid gap-2 mt-3">
                    <form method="POST" class="m-0">
                        <?= csrf_input(); ?>
                        <input type="hidden" name="delete_service_id" id="deleteServiceId">
                        <button type="submit" class="btn btn-danger rounded-pill py-2 fw-bold w-100" data-i18n="btn_delete">حذف</button>
                    </form>
                    <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="serviceStatusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4 shadow-lg border-0">
                <div id="serviceStatusIcon" class="mb-3"></div>
                <h5 id="serviceStatusTitle" class="fw-bold mb-2"></h5>
                <p id="serviceStatusBody" class="small opacity-75 mb-3"></p>
                <button type="button" class="btn btn-primary rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/service-translations.js"></script>
    <script>
        const translations = {
            ar: {
                manage_services_title: "إدارة الخدمات | Clevo",
                nav_main: "الرئيسية", nav_orders: "الطلبات", add_st_page_title: "إعداد المغسلة", services_list: "الخدمات", drivers_page_title: "طاقم العمل", recent_reviews: "التقييمات", serv_wallet: "المحفظة", nav_logout: "خروج", nav_notifications: "الإشعارات",
                manage_services_header: "إدارة الخدمات المتاحة", add_new_service: "إضافة خدمة جديدة", edit_service: "تعديل الخدمة", service_name_label: "اسم الخدمة", service_description_label: "وصف الخدمة", service_description_placeholder: "", service_price_label: "السعر (JOD)", service_duration_label: "مدة الخدمة بالدقائق", booking_policy_title: "السعة ورسوم الخدمة", booking_capacity: "سعة الغسيل المتزامن", home_capacity: "فرق الخدمة المنزلية", default_minutes: "مدة الخدمة الافتراضية بالدقائق", delivery_minutes: "وقت الاستلام والإرجاع الإضافي بالدقائق", station_fee: "رسوم المحطة (JOD)", home_fee: "رسوم منزلية (JOD)", delivery_fee: "رسوم الاستلام والإرجاع (JOD)",
                available_options: "خيارات تقديم الخدمة:", type_station: "في المحطة (Station)", type_home: "خدمة منزلية (At Home)", type_pickup: "استلام وتسليم (Pickup)",
                home_service: "منزلية", station_service: "بالمغسلة", delivery_service: "استلام وتسليم", pickup_service: "استلام وتسليم",
                btn_save_service: "حفظ الخدمة ونشرها", current_services_list: "قائمة خدماتك النشطة", btn_cancel: "إلغاء", btn_ok: "موافق",
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج",
                delete_service_title: "تأكيد حذف الخدمة", delete_service_msg: "هل أنت متأكد من حذف هذه الخدمة؟", btn_delete: "حذف",
                service_saved_title: "تم الحفظ", service_saved_body: "تم حفظ الخدمة بنجاح.", service_deleted_title: "تم الحذف", service_deleted_body: "تم حذف الخدمة بنجاح."
            },
            en: {
                manage_services_title: "Services Management | Clevo",
                nav_main: "Dashboard", nav_orders: "Orders", add_st_page_title: "Station Setup", services_list: "Services", drivers_page_title: "Team", recent_reviews: "Reviews", serv_wallet: "Wallet", nav_logout: "Logout", nav_notifications: "Notifications",
                manage_services_header: "Services Management", add_new_service: "Add New Service", edit_service: "Edit Service", service_name_label: "Service Name", service_description_label: "Service Description", service_description_placeholder: "", service_price_label: "Price (JOD)", service_duration_label: "Service duration in minutes", booking_policy_title: "Capacity and service fees", booking_capacity: "Concurrent wash capacity", home_capacity: "Home service teams", default_minutes: "Default service minutes", delivery_minutes: "Additional pickup/return minutes", station_fee: "Station fee (JOD)", home_fee: "Home fee (JOD)", delivery_fee: "Pickup/return fee (JOD)",
                available_options: "Delivery Options:", type_station: "At Station", type_home: "At Home Service", type_pickup: "Pickup & Delivery",
                home_service: "Home", station_service: "At Station", delivery_service: "Pickup & Delivery", pickup_service: "Pickup & Delivery",
                btn_save_service: "Publish Service", current_services_list: "Active Services List", btn_cancel: "Cancel", btn_ok: "OK",
                logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout",
                delete_service_title: "Delete Service", delete_service_msg: "Are you sure you want to delete this service?", btn_delete: "Delete",
                service_saved_title: "Saved", service_saved_body: "Service saved successfully.", service_deleted_title: "Deleted", service_deleted_body: "Service deleted successfully."
            }
        };

        function toggleLanguage() { localStorage.setItem('app_lang', (localStorage.getItem('app_lang') || 'ar') === 'ar' ? 'en' : 'ar'); location.reload(); }
        function toggleGlobalTheme() { localStorage.setItem('global_theme', document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'); location.reload(); }
        function confirmLogout() { new bootstrap.Modal(document.getElementById('logoutModal')).show(); }
        function confirmDeleteService(id) {
            document.getElementById('deleteServiceId').value = id;
            new bootstrap.Modal(document.getElementById('deleteServiceModal')).show();
        }

        function syncServiceLanguageFields() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const payload = window.clevoServicePayloadFromInput
                ? window.clevoServicePayloadFromInput(
                    document.getElementById('sNameInput').value,
                    document.getElementById('sDescInput').value,
                    lang
                )
                : {
                    name_ar: document.getElementById('sNameInput').value,
                    name_en: document.getElementById('sNameInput').value,
                    description_ar: document.getElementById('sDescInput').value,
                    description_en: document.getElementById('sDescInput').value
                };
            document.getElementById('sNameAr').value = payload.name_ar || '';
            document.getElementById('sNameEn').value = payload.name_en || payload.name_ar || '';
            document.getElementById('sDescAr').value = payload.description_ar || '';
            document.getElementById('sDescEn').value = payload.description_en || payload.description_ar || '';
        }

        function editService(s) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            document.getElementById('editId').value = s.id;
            document.getElementById('sNameAr').value = s.name_ar || '';
            document.getElementById('sNameEn').value = s.name_en || s.name_ar || '';
            document.getElementById('sDescAr').value = s.description_ar || '';
            document.getElementById('sDescEn').value = s.description_en || s.description_ar || '';
            document.getElementById('sNameInput').value = lang === 'ar' ? (s.name_ar || s.name_en || '') : (s.name_en || s.name_ar || '');
            document.getElementById('sDescInput').value = lang === 'ar' ? (s.description_ar || s.description_en || '') : (s.description_en || s.description_ar || '');
            document.getElementById('sPrice').value = s.price;
            document.getElementById('sDuration').value = s.duration;
            document.getElementById('typeStation').checked = s.types.includes('station');
            document.getElementById('typeHome').checked = s.types.includes('home');
            document.getElementById('typePickup').checked = s.types.includes('pickup');
            
            const titleEl = document.getElementById('formTitle');
            titleEl.setAttribute('data-i18n', 'edit_service');
            titleEl.innerText = translations[lang]['edit_service'];
            
            document.getElementById('cancelEditBtn').classList.remove('d-none');
        }

        function resetForm() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            document.getElementById('serviceForm').reset();
            document.getElementById('editId').value = '0';
            document.getElementById('sNameAr').value = '';
            document.getElementById('sNameEn').value = '';
            document.getElementById('sDescAr').value = '';
            document.getElementById('sDescEn').value = '';
            
            const titleEl = document.getElementById('formTitle');
            titleEl.setAttribute('data-i18n', 'add_new_service');
            titleEl.innerText = translations[lang]['add_new_service'];
            
            document.getElementById('cancelEditBtn').classList.add('d-none');
        }

        document.getElementById('serviceForm').addEventListener('submit', syncServiceLanguageFields);

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
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });
            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                if (translations[lang][key]) el.setAttribute('placeholder', translations[lang][key]);
            });

            document.querySelectorAll('.db-service-name, .db-service-desc').forEach(el => {
                const localized = el.getAttribute(isRtl ? 'data-ar' : 'data-en') || el.getAttribute('data-ar') || el.textContent;
                el.textContent = localized;
            });

            if(!isRtl) {
                const bIcon = document.getElementById('backIcon');
                if(bIcon) bIcon.className = "fas fa-arrow-left";
            }
            const langBtn = document.getElementById('langBtnText');
            if(langBtn) langBtn.innerText = isRtl ? 'EN' : 'AR';
            const themeIcon = document.getElementById('themeIcon');
            if(themeIcon) themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';

            const pageMsg = new URLSearchParams(window.location.search).get('msg');
            if (pageMsg === 'success' || pageMsg === 'deleted') {
                document.getElementById('serviceStatusIcon').innerHTML = '<i class="fas fa-check-circle text-success fa-4x"></i>';
                document.getElementById('serviceStatusTitle').innerText = translations[lang][pageMsg === 'deleted' ? 'service_deleted_title' : 'service_saved_title'];
                document.getElementById('serviceStatusBody').innerText = translations[lang][pageMsg === 'deleted' ? 'service_deleted_body' : 'service_saved_body'];
                new bootstrap.Modal(document.getElementById('serviceStatusModal')).show();
                history.replaceState(null, '', window.location.pathname);
            }
        };
    </script>
</body>
</html>
