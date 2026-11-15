<?php

include_once __DIR__ . '/../includes/config.php'; 
include_once __DIR__ . '/../includes/booking-helpers.php';
include_once __DIR__ . '/../includes/app-helpers.php';

$owner_id = clevo_require_role('owner');
clevoEnsureTrackingColumns($conn);
clevoEnsureServiceLanguageColumns($conn);
cancelExpiredBookings($conn);


if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    clevo_require_csrf_token(true);
    $action = $_POST['action'];

    
    if ($action == 'fetch_drivers_list') {
        $stmt = $conn->prepare("SELECT u.UserID as id, u.UserName as name, u.PhoneNumber as phone
            FROM user u
            JOIN stations s ON u.StationID = s.StationID
            WHERE LOWER(u.Role) = 'driver' AND u.AccountStatus = 'active' AND s.UserID = ?");
        $stmt->bind_param("i", $owner_id);
        $stmt->execute();
        $drivers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode(['status' => 'success', 'data' => ['drivers' => $drivers], 'message' => '']);
        exit;
    }

    if ($action==='accept_order') {
        clevo_accept_owner_booking($conn,$owner_id,(int)($_POST['order_id']??0));
        clevo_json_response('success',[],'accepted');
    }

    if ($action === 'reissue_driver_code') {
        $code = clevo_issue_driver_code($conn, $owner_id, (int) ($_POST['order_id'] ?? 0));
        clevo_json_response('success', ['driver_code' => $code]);
    }
    if($action==='assign_driver') {
        $order_id=(int)($_POST['order_id']??0);$driver_id=(int)($_POST['driver_id']??0);
        $code=clevo_db_atomic($conn,function()use($conn,$owner_id,$order_id,$driver_id){clevo_market_assign($conn,$owner_id,$order_id,$driver_id);return clevo_issue_driver_code($conn,$owner_id,$order_id);});
        clevo_json_response('success',['driver_code'=>$code],'driver_assigned');
    }
    if($action==='reject_order') {
        clevo_market_reject($conn,$owner_id,(int)($_POST['order_id']??0));
        clevo_json_response('success',[],'rejected');
    }
    if ($action==='complete_order') {
        clevo_complete_owner_booking($conn,$owner_id,(int)($_POST['order_id']??0));
        clevo_json_response('success',[],'completed');
    }
}


$orders_stmt = $conn->prepare("SELECT b.*, u.UserName as customer_name, u.PhoneNumber as customer_phone, serv.Name as service_name, serv.Name_ar as service_name_ar, serv.Name_en as service_name_en, s.Name as station_name, d.UserName as driver_name, b.DriverAccessCode as d_code
    FROM booking b
    JOIN user u ON b.UserID = u.UserID
    JOIN stations s ON b.StationID = s.StationID
    JOIN services serv ON b.ServiceID = serv.ServiceID
    LEFT JOIN user d ON b.DriverID = d.UserID
    WHERE b.Status IN ('Pending', 'Accepted', 'Arrived', 'In Progress', 'Pickup', 'Washing', 'Returning') AND s.UserID = ?
    ORDER BY b.created_at DESC");
$orders_stmt->bind_param("i", $owner_id);
$orders_stmt->execute();
$orders_query = $orders_stmt->get_result();


$pending_count_stmt = $conn->prepare("SELECT COUNT(*) as total_pending FROM booking b JOIN stations s ON b.StationID = s.StationID WHERE b.Status = 'Pending' AND s.UserID = ?");
$pending_count_stmt->bind_param("i", $owner_id);
$pending_count_stmt->execute();
$real_pending_count = $pending_count_stmt->get_result()->fetch_assoc()['total_pending'] ?? 0;

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="orders_page_title">الطلبات الواردة | Clevo </title>
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
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s; margin: 0; overflow-x: hidden; }
        .main-wrapper { display: flex; min-height: 100vh; }
        

        .sidebar { width: 260px; background: var(--bg-sidebar); padding: 30px 15px; position: sticky; top: 0; min-height: 100vh; display: flex; flex-direction: column; transition: all 0.3s ease; }
        .nav-link { color: rgba(255,255,255,0.8) !important; padding: 12px; border-radius: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 10px; text-decoration: none; transition: 0.2s; font-size: 0.95rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent); color: white !important; }
        
        /* Main Content */
        .content-area { flex: 1; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-back-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; }
        .order-card { background: var(--bg-card); border-radius: 24px; padding: 25px; margin-bottom: 15px; border: 1px solid var(--border-card); position: relative; color: var(--text-main); }
        [dir="rtl"] .order-card { border-right: 5px solid var(--primary-blue); }
        [dir="ltr"] .order-card { border-left: 5px solid var(--primary-blue); }
        .order-type-badge { position: absolute; top: 20px; padding: 4px 12px; border-radius: 50px; font-size: 0.75rem; background: var(--badge-bg); color: var(--primary-blue); font-weight: 800; }
        [dir="rtl"] .order-type-badge { left: 20px; }
        [dir="ltr"] .order-type-badge { right: 20px; }
        .btn-action { border-radius: 50px; padding: 10px; font-size: 0.9rem; font-weight: 800; width: 100%; text-decoration: none; display: inline-block; text-align: center;}
        .modal-content { border-radius: 30px; background-color: var(--bg-card); color: var(--text-main); border: 1px solid var(--border-card); }
        .driver-item { padding: 12px; border-radius: 15px; border: 1px solid var(--border-card); margin-bottom: 10px; cursor: pointer; transition: 0.2s; }
        .driver-item:hover { background: var(--badge-bg); border-color: var(--primary-blue); }
        .empty-state { background: var(--bg-card); border: 1px dashed var(--border-card); border-radius: 24px; padding: 48px 20px; color: var(--text-muted); }
        .btn-ctrl { width: 35px; height: 35px; border-radius: 50%; background: rgba(255, 255, 255, 0.2); color: white; border: 0; display: inline-flex; align-items: center; justify-content: center; }

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
            
            <?php include __DIR__ . '/../includes/shared_owner_sidebar.php'; ?>

            <main class="content-area">
                <div class="orders-header">
                    <div class="container d-flex justify-content-between align-items-center">
                        <a href="owner-dashboard.php" class="circle-back-btn shadow-sm">
                            <i class="fas fa-arrow-right" id="backIcon"></i>
                        </a>
                        <h6 class="mb-0 fw-bold" data-i18n="header_title_orders">طلبات الزبائن الواردة</h6>
                        <span class="badge bg-danger rounded-pill px-3" id="pendingCount"><?php echo $real_pending_count; ?></span>
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div id="ordersContainer">
                        <?php if ($orders_query && $orders_query->num_rows > 0): ?>
                        <?php while($order = $orders_query->fetch_assoc()): ?>
                        <div class="order-card shadow-sm" id="card-<?= (int) $order['BookingID'] ?>">
                            <span class="order-type-badge">
                                <i class="fas fa-<?= $order['Service_Location'] == 'station' ? 'car' : 'home' ?>"></i>
                                <span data-i18n="<?= e($order['Service_Location']) ?>_service"><?= e($order['Service_Location']) ?></span>
                            </span>
                            <div class="row align-items-center text-start">
                                <div class="col-md-8">
                                    <h6 class="mb-1"><span data-i18n="customer_label">الزبون:</span> <span><?= e($order['customer_name']) ?></span> <span class="small text-primary">#<?= e($order['Order_Number']) ?></span></h6>
                                    <p class="mb-0 small opacity-75">
                                        <?php if ($order['Status'] === 'Accepted'): ?>
                                            <span data-i18n="status_in_progress">جاري التنفيذ</span>
                                            <?php if ($order['driver_name']): ?>
                                                <span data-i18n="by_driver">بواسطة:</span> <?= e($order['driver_name']) ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span data-i18n="status_waiting_accept">بانتظار قبول الطلب</span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="mb-0 small fw-bold"><i class="fas fa-soap me-1"></i><span class="db-service-name" data-ar="<?= e($order['service_name_ar'] ?: $order['service_name']) ?>" data-en="<?= e($order['service_name_en'] ?: $order['service_name']) ?>"><?= e($order['service_name_ar'] ?: $order['service_name']) ?></span> | <?= e($order['Booking_Date']) ?> <?= date('h:i A', strtotime($order['Booking_Time'])) ?></p>
                                    <p class="mb-0 small opacity-75">
                                        <i class="fas fa-phone me-1"></i><?= e($order['customer_phone']) ?>
                                        <?php if ($order['Payment_Method']): ?>
                                            | <span data-i18n="payment_label">دفع:</span>
                                            <?php if ($order['Payment_Method'] === 'plan'): ?>
                                                <span class="badge bg-success-subtle text-success border" data-i18n="payment_plan">من الباقة</span>
                                            <?php else: ?>
                                                <?= e($order['Payment_Method']) ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </p>
                                    <?php if($order['Service_Location'] != 'station'): ?>
                                        <p class="mb-0 small text-danger fw-bold"><i class="fas fa-location-dot me-1"></i><?= htmlspecialchars($order['Address_Details'] ?: 'تم إرسال موقع على الخريطة') ?></p>
                                        <?php if($order['Latitude'] && $order['Longitude']): ?>
                                            <a class="small fw-bold text-decoration-none" target="_blank" href="https://www.google.com/maps?q=<?= e($order['Latitude']) ?>,<?= e($order['Longitude']) ?>" data-i18n="open_map">فتح الموقع على الخريطة</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if(in_array($order['Status'], ['Accepted', 'Arrived', 'In Progress', 'Pickup', 'Washing', 'Returning'], true) && !empty($order['DriverID'])): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="reissueDriverCode(<?= (int) $order['BookingID'] ?>)">رمز دخول جديد للسائق / New driver sign-in code</button>
                                    <?php endif; ?>
                                    <?php if(!empty($order['Driver_Lat']) && !empty($order['Driver_Lon'])): ?>
                                        <a class="small fw-bold text-decoration-none" target="_blank" href="https://www.google.com/maps?q=<?= e($order['Driver_Lat']) ?>,<?= e($order['Driver_Lon']) ?>" data-i18n="driver_live_location">موقع السائق المباشر</a>
                                    <?php endif; ?>
                                    <?php if($order['Service_Location'] === 'delivery' && !empty($order['DriverID'])): ?>
                                        <a class="small fw-bold text-decoration-none d-block" href="../user/live-tracking.php?id=<?= (int)$order['BookingID'] ?>" data-i18n="open_live_tracking">فتح التتبع المباشر</a>
                                    <?php endif; ?>
                                    <p class="mb-0 small text-primary fw-bold mt-1"><span data-i18n="amount_label">المبلغ:</span> <?= e(number_format((float) $order['Total_Price'], 2)) ?> JOD</p>
                                </div>
                                <div class="col-md-4 mt-3 mt-md-0">
                                    <?php if($order['Status'] == 'Pending'): ?>
                                    <div class="d-flex gap-2">
                                        <?php if($order['Service_Location'] == 'delivery'): ?>
                                            <button class="btn btn-success btn-action shadow-sm" onclick="openAssignModal(<?= (int) $order['BookingID'] ?>, <?= e(json_encode((string) $order['Order_Number'])) ?>, this)" data-i18n="btn_assign_driver">قبول وتعيين</button>
                                        <?php else: ?>
                                            <button class="btn btn-success btn-action shadow-sm" onclick="acceptOrder(<?= (int) $order['BookingID'] ?>, this)" data-i18n="btn_accept">قبول</button>
                                        <?php endif; ?>
                                        <button class="btn btn-outline-danger btn-action" onclick="rejectOrder(<?= (int) $order['BookingID'] ?>, this)" data-i18n="btn_reject">رفض</button>
                                    </div>
                                    <?php else: ?>
                                        <?php if($order['Service_Location'] === 'home' && empty($order['Payment_Method'])): ?>
                                            <button class="btn btn-warning btn-action shadow-sm" disabled data-i18n="btn_wait_payment">بانتظار الدفع</button>
                                        <?php elseif(in_array($order['Service_Location'], ['station','home']) && in_array($order['Status'], ['Accepted','In Progress'])): ?>
                                            <button class="btn btn-success btn-action shadow-sm" onclick="completeOrder(<?= (int) $order['BookingID'] ?>, this)" data-i18n="btn_complete">إنهاء الطلب</button>
                                        <?php else: ?>
                                            <button class="btn btn-primary btn-action shadow-sm" disabled data-i18n="btn_accepted">تم القبول</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                        <?php else: ?>
                        <div class="empty-state text-center shadow-sm">
                            <i class="fas fa-calendar-check fa-4x mb-3 text-primary"></i>
                            <h6 class="fw-bold mb-1" data-i18n="no_orders">لا يوجد حجوزات حالياً</h6>
                            <p class="small mb-0 opacity-75" data-i18n="no_orders_hint">ستظهر الطلبات الجديدة هنا فور وصولها.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="assignDriverModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <h6 class="fw-bold mb-3" data-i18n="modal_assign_title">اختيار سائق للمهمة</h6>
                <div id="driversListContainer" class="text-start"></div>
                <button class="btn btn-light rounded-pill w-100 mt-3" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <div id="statusModalIcon" class="mb-3 fs-1"></div>
                <h6 id="statusModalTitle" class="fw-bold"></h6>
                <p id="statusModalBody" class="small opacity-75 mb-0"></p>
                <button class="btn btn-primary rounded-pill w-100 mt-3" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="rejectConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <i class="fas fa-triangle-exclamation text-warning fa-3x mb-3"></i>
                <h6 class="fw-bold" data-i18n="reject_confirm_title">تأكيد الرفض</h6>
                <p class="small opacity-75" data-i18n="reject_confirm_body">هل أنت متأكد من رفض الطلب؟</p>
                <div class="d-grid gap-2">
                    <button class="btn btn-danger rounded-pill fw-bold" id="confirmRejectBtn" data-i18n="btn_reject">رفض</button>
                    <button class="btn btn-light border rounded-pill fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </div>
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
                    <form method="POST" action="../general/logout.php" class="m-0"><?= csrf_input(); ?><button type="submit"  class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</button></form>
                    <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: { 
                orders_page_title: "الطلبات الواردة | Clevo", nav_main: "الرئيسية", nav_orders: "الطلبات", add_st_page_title: "إعداد المغسلة", services_list: "الخدمات", drivers_page_title: "طاقم العمل", recent_reviews: "التقييمات", serv_wallet: "المحفظة", nav_logout: "خروج", header_title_orders: "طلبات الزبائن الواردة", home_service: "منزلية", station_service: "بالمغسلة", delivery_service: "استلام وتسليم", customer_label: "الزبون:", amount_label: "المبلغ:", payment_label: "دفع:", payment_plan: "من الباقة", btn_assign_driver: "قبول وتعيين", btn_accept: "قبول", btn_complete: "إنهاء الطلب", btn_wait_payment: "بانتظار الدفع", btn_reject: "رفض", modal_assign_title: "اختيار سائق للمهمة", btn_cancel: "إلغاء", btn_ok: "موافق", no_orders: "لا يوجد حجوزات حالياً", no_orders_hint: "ستظهر الطلبات الجديدة هنا فور وصولها.", reject_confirm_title: "تأكيد الرفض", reject_confirm_body: "هل أنت متأكد من رفض الطلب؟", loading_text: "جاري المعالجة...", error_title: "تعذر تنفيذ الطلب", error_body: "حاول مرة أخرى بعد قليل.", no_drivers: "لا يوجد سائقين نشطين حالياً", logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", nav_notifications: "الإشعارات", status_in_progress: "جاري التنفيذ", by_driver: "بواسطة:", status_waiting_accept: "بانتظار قبول الطلب", open_map: "فتح الموقع على الخريطة", driver_code: "كود السائق:", driver_live_location: "موقع السائق المباشر", open_live_tracking: "فتح التتبع المباشر", btn_accepted: "تم القبول"
            },
            en: { 
                orders_page_title: "Incoming Orders | Clevo", nav_main: "Dashboard", nav_orders: "Orders", add_st_page_title: "Station Setup", services_list: "Services", drivers_page_title: "Team", recent_reviews: "Reviews", serv_wallet: "Wallet", nav_logout: "Logout", header_title_orders: "Customer Requests", home_service: "Home", station_service: "At Station", delivery_service: "Pickup & Delivery", customer_label: "Customer:", amount_label: "Amount:", payment_label: "Payment:", payment_plan: "From plan", btn_assign_driver: "Accept & Assign", btn_accept: "Accept", btn_complete: "Complete Order", btn_wait_payment: "Waiting for payment", btn_reject: "Reject", modal_assign_title: "Select Driver", btn_cancel: "Cancel", btn_ok: "OK", no_orders: "No bookings right now", no_orders_hint: "New customer requests will appear here.", reject_confirm_title: "Confirm Rejection", reject_confirm_body: "Are you sure you want to reject this order?", loading_text: "Processing...", error_title: "Action failed", error_body: "Please try again shortly.", no_drivers: "No active drivers right now", logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", nav_notifications: "Notifications", status_in_progress: "In progress", by_driver: "by:", status_waiting_accept: "Waiting for acceptance", open_map: "Open location on map", driver_code: "Driver code:", driver_live_location: "Driver live location", open_live_tracking: "Open live tracking", btn_accepted: "Accepted"
            }
        };

        const showM = (id) => new bootstrap.Modal(document.getElementById(id)).show();
        const csrfToken = <?= json_encode($csrf_token) ?>;
        let pendingReject = { orderId: 0, button: null };

        function t(key) {
            const lang = localStorage.getItem('app_lang') || 'ar';
            return translations[lang][key] || key;
        }

        function withLoading(btn, work) {
            if (!btn) return work();
            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>${t('loading_text')}`;
            return work().finally(() => {
                btn.disabled = false;
                btn.innerHTML = original;
            });
        }

        function showStatus(type, title, body) {
            document.getElementById('statusModalIcon').innerHTML = type === 'success'
                ? '<i class="fas fa-check-circle text-success"></i>'
                : '<i class="fas fa-circle-exclamation text-warning"></i>';
            document.getElementById('statusModalTitle').innerText = title;
            document.getElementById('statusModalBody').innerText = body;
            showM('statusModal');
        }

        function postAction(params) {
            params.append('csrf_token', csrfToken);
            return fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            }).then(res => res.json());
        }

        function acceptOrder(orderId, btn) {
            const params = new URLSearchParams();
            params.append('action', 'accept_order');
            params.append('order_id', orderId);
            withLoading(btn, () => postAction(params).then(data => {
                if (data.status === 'success') { if (data.data?.driver_code) { showStatus('success', 'Driver code (15 minutes, one use)', data.data.driver_code); } else location.reload(); }
                else showStatus('error', t('error_title'), t('error_body'));
            }));
        }

        function openAssignModal(orderId, orderNum, btn) {
            const container = document.getElementById('driversListContainer');
            container.innerHTML = `<p class="text-center small opacity-50">${t('loading_text')}</p>`;
            const params = new URLSearchParams();
            params.append('action', 'fetch_drivers_list');
            withLoading(btn, () => postAction(params)).then(response => {
                const drivers = response.data?.drivers || [];
                container.innerHTML = '';
                if (!drivers.length) {
                    container.innerHTML = `<p class="text-center small opacity-50">${t('no_drivers')}</p>`;
                    showM('assignDriverModal');
                    return;
                }
                drivers.forEach(d => {
                    const nameNode = document.createElement('span'); nameNode.textContent = String(d.name); const safeName = nameNode.innerHTML;
                    container.innerHTML += `<div class="driver-item shadow-sm" onclick="finalAssign('${orderId}', '${String(orderNum).replace(/'/g, "\\'")}', '${d.id}')"><div class="fw-bold small">${safeName}</div></div>`;
                });
                showM('assignDriverModal');
            });
        }

        function reissueDriverCode(orderId) { postAction(new URLSearchParams({action:'reissue_driver_code',order_id:orderId})).then(data => { if(data.status === 'success') showStatus('success', 'Driver code (15 minutes, one use)', data.data.driver_code); }); }
        function finalAssign(orderId, orderNum, driverId) {
            const params = new URLSearchParams();
            params.append('action', 'assign_driver');
            params.append('order_id', orderId);
            params.append('order_number', orderNum);
            params.append('driver_id', driverId);
            postAction(params).then(data => {
                if (data.status === 'success') location.reload();
                else showStatus('error', t('error_title'), t('error_body'));
            });
        }

        function rejectOrder(orderId, btn) {
            pendingReject = { orderId, button: btn };
            showM('rejectConfirmModal');
        }

        document.getElementById('confirmRejectBtn').addEventListener('click', () => {
            const params = new URLSearchParams();
            params.append('action', 'reject_order');
            params.append('order_id', pendingReject.orderId);
            const modal = bootstrap.Modal.getInstance(document.getElementById('rejectConfirmModal'));
            if (modal) modal.hide();
            withLoading(pendingReject.button, () => postAction(params).then(data => {
                if (data.status === 'success') location.reload();
                else showStatus('error', t('error_title'), t('error_body'));
            }));
        });

        function completeOrder(orderId, btn) {
            const params = new URLSearchParams();
            params.append('action', 'complete_order');
            params.append('order_id', orderId);
            withLoading(btn, () => postAction(params).then(data => {
                if (data.status === 'success') location.reload();
                else showStatus('error', t('error_title'), t('error_body'));
            }));
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
            document.documentElement.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
            document.getElementById('bootstrap-css').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            
            document.querySelectorAll('[data-i18n]').forEach(el => { const key = el.getAttribute('data-i18n'); if(translations[lang][key]) el.innerText = translations[lang][key]; });
            document.querySelectorAll('.db-service-name').forEach(el => {
                el.innerText = el.getAttribute(isRtl ? 'data-ar' : 'data-en') || el.getAttribute('data-ar') || el.innerText;
            });
            document.title = translations[lang].orders_page_title;
            if(!isRtl) document.getElementById('backIcon').className = "fas fa-arrow-left";
            document.getElementById('langBtnText').innerText = isRtl ? 'EN' : 'AR';
            document.getElementById('themeIcon').className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            

            if (document.getElementById('pendingCount').innerText == '0') {
                document.getElementById('pendingCount').innerText = document.querySelectorAll('.order-card').length;
            }
        };
    </script>
</body>
</html>
