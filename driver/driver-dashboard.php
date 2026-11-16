<?php

include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';

clevoEnsureTrackingColumns($conn);

$sessionRole = strtolower($_SESSION['role'] ?? $_SESSION['Role'] ?? '');
if (!isset($_SESSION['userID']) || $sessionRole !== 'driver') {
    header('Location: ../general/login.php');
    exit;
}

$driver_id = (int) $_SESSION['userID'];
$driver_booking_id = (int) ($_SESSION['driver_booking_id'] ?? 0);

function driverStatusLabel(string $status): string
{
    return [
        'Accepted' => 'تم قبول المهمة',
        'Arrived' => 'وصلت للموقع',
        'In Progress' => 'في الطريق',
        'Pickup' => 'تم استلام السيارة',
        'Washing' => 'جاري الغسيل',
        'Returning' => 'في طريق الإرجاع',
        'Completed' => 'مكتملة',
    ][$status] ?? 'جاري التنفيذ';
}

function driverStatusLabelEn(string $status): string
{
    return [
        'Accepted' => 'Accepted',
        'Arrived' => 'Driver arrived',
        'In Progress' => 'On the way',
        'Pickup' => 'Vehicle picked up',
        'Washing' => 'Washing',
        'Returning' => 'Returning',
        'Completed' => 'Completed',
    ][$status] ?? 'In progress';
}

function driverServiceLabel(string $serviceLocation): string
{
    return [
        'home' => 'خدمة منزلية',
        'station' => 'في المحطة',
        'delivery' => 'استلام وتوصيل',
    ][$serviceLocation] ?? $serviceLocation;
}

function driverPaymentLabel(?string $method): string
{
    return [
        'cash' => 'كاش',
        'visa' => 'بطاقة',
        'wallet' => 'محفظة',
        'points' => 'نقاط ولاء',
        'plan' => 'من الباقة',
    ][$method ?? ''] ?? ($method ?: '-');
}

if(($_GET['tracking']??'')==='1'&&$_SERVER['REQUEST_METHOD']==='GET')clevo_json_response('success',['tracking'=>clevo_delivery_tracking($conn,$driver_id,$driver_booking_id,$driver_booking_id)]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if($action==='delivery_stage'){
        $result=clevo_delivery_advance($conn,$driver_id,$driver_booking_id,(string)($_POST['stage']??''));clevo_json_response('success',$result);
    }
    if($action==='confirm_handoff'){
        $result=clevo_delivery_confirm($conn,$driver_id,$driver_booking_id,(string)($_POST['kind']??''),(string)($_POST['code']??''),(string)($_POST['idempotency_key']??''));clevo_json_response('success',$result);
    }
    if ($action === 'update_location') {
        $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT); $lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) throw new ClevoSecurityException('invalid_location', 422);
        if (!clevo_record_driver_location($conn,$driver_id,$driver_booking_id,(float)$lat,(float)$lng,['accuracy_m'=>isset($_POST['accuracy_m'])?(float)$_POST['accuracy_m']:null,'captured_at'=>$_POST['captured_at']??gmdate('Y-m-d\TH:i:s\Z'),'assignment_id'=>(int)($_POST['assignment_id']??0),'sequence'=>(int)($_POST['sequence']??0)])) throw new ClevoSecurityException('not_found',404);
        clevo_json_response('success');
    }
    if (in_array($action, ['update_status','update_booking_status'], true)) {
        if ((int) ($_POST['order_id'] ?? 0) !== $driver_booking_id || !$driver_booking_id) throw new ClevoSecurityException('not_found', 404);
        $status = clevo_clean_string($_POST['status'] ?? '', 30);
        clevo_driver_transition($conn, $driver_id, $driver_booking_id, $status);
        if ($status === 'Completed') { $_SESSION = []; session_destroy(); }
        header('Content-Type: application/json'); echo json_encode(['status' => 'success', 'logout' => $status === 'Completed']); exit;
    }
    throw new ClevoSecurityException('invalid_action', 422);
}

$task_stmt = $conn->prepare("SELECT b.*, u.UserName AS CustomerName, u.CarName, u.PhoneNumber AS CustomerPhone
    FROM booking b
    JOIN user u ON b.UserID = u.UserID
    WHERE b.BookingID = ? AND b.DriverID = ? AND b.Status IN ('Accepted', 'Arrived', 'In Progress', 'Pickup', 'Washing', 'Returning')
    ORDER BY b.created_at DESC LIMIT 1");
$task_stmt->bind_param("ii", $driver_booking_id, $driver_id);
$task_stmt->execute();
$task = $task_stmt->get_result()->fetch_assoc();
$deliveryTracking=$task?clevo_delivery_tracking($conn,$driver_id,$driver_booking_id,$driver_booking_id):null;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="driver_tasks_title">مهامي اليومية | Clevo Driver</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { --bg-body: #f4f7fa; --bg-card: #ffffff; --text-main: #0f172a; --border-color: #dee2e6; --header-bg: #198754; }
        [data-theme="dark"] { --bg-body: #020617; --bg-card: #0f172a; --text-main: #ffffff; --border-color: #334155; --header-bg: #064e3b; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: .3s; margin: 0; }
        .worker-header { background: var(--header-bg); color: white; padding: 40px 20px 60px; border-radius: 0 0 36px 36px; position: relative; }
        .header-container { display: flex; align-items: center; justify-content: space-between; position: absolute; top: 20px; left: 20px; right: 20px; }
        .btn-ctrl-driver { background: rgba(255,255,255,.2); color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 1px solid rgba(255,255,255,.3); cursor: pointer; }
        .task-card { background: var(--bg-card); border-radius: 24px; padding: 22px; margin-top: -30px; border: 1px solid var(--border-color); color: var(--text-main); }
        .info-row { display: flex; justify-content: space-between; gap: 16px; padding: 12px 0; border-bottom: 1px solid var(--border-color); }
        .info-row strong { text-align: end; }
        .status-badge { padding: 6px 16px; border-radius: 50px; font-size: .8rem; font-weight: 800; background: #e0f2fe; color: #0369a1; }
        .btn-arrived { background: #0ea5e9; color: white; border: none; border-radius: 50px; padding: 12px; font-weight: 800; width: 100%; margin-bottom: 10px; }
        .btn-finish { background: #198754; color: white; border: none; border-radius: 50px; padding: 15px; font-weight: 900; width: 100%; transition: .25s; }
        .status-grid .btn { min-height: 46px; }
        [dir="ltr"] .fa-phone-alt, [dir="ltr"] .fa-directions, [dir="ltr"] .fa-map-pin, [dir="ltr"] .fa-check-circle { margin-left: 0 !important; margin-right: .25rem; }
    </style>
</head>
<body>
    <script>
        document.documentElement.setAttribute('data-theme', localStorage.getItem('global_theme') || 'light');
    </script>

    <div class="worker-header shadow text-center">
        <div class="header-container">
            <div class="d-flex gap-2">
                <button type="button" onclick="toggleGlobalTheme()" class="btn-ctrl-driver" aria-label="Theme"><i id="themeIcon" class="fas fa-moon"></i></button>
                <button type="button" onclick="toggleLanguage()" class="btn-ctrl-driver fw-bold border-0" id="langBtn">EN</button>
            </div>
            <a href="../general/logout.php" class="btn btn-danger btn-sm rounded-pill px-3 fw-bold shadow-sm" data-i18n="logout_btn">خروج</a>
        </div>
        <h4 class="mb-1 text-white fw-bold"><span data-i18n="hello_driver">أهلاً بك يا</span> <span class="uName"><?= e($_SESSION['userName'] ?? '') ?></span></h4>
        <p class="small text-white opacity-75" data-i18n="task_details_subtitle">تفاصيل المهمة الحالية المكلف بها</p>
    </div>

    <div class="container pb-5">
        <?php if ($task): ?>
            <div class="task-card shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <span class="status-badge" data-status-label="<?= e($task['Status']) ?>"><?= e(driverStatusLabel($task['Status'])) ?></span>
                    <span class="fw-bold text-primary">#<?= e($task['Order_Number']) ?></span>
                </div>

                <div class="mb-4 text-start">
                    <h6 class="fw-bold mb-1"><i class="fas fa-user ms-1"></i><span data-i18n="customer_label">الزبون:</span> <?= e($task['CustomerName']) ?></h6>
                    <p class="small opacity-75 mb-0"><i class="fas fa-map-marker-alt text-danger ms-1"></i><?= e($task['Address_Details'] ?: '-') ?></p>
                </div>

                <hr class="opacity-25">
                <div class="info-row"><span class="opacity-75" data-i18n="car_type_label">المركبة:</span><strong><?= e($task['CarName'] ?: '-') ?></strong></div>
                <div class="info-row"><span class="opacity-75" data-i18n="service_type_label">الخدمة:</span><strong data-service-value="<?= e($task['Service_Location']) ?>"><?= e(driverServiceLabel($task['Service_Location'])) ?></strong></div>
                <div class="info-row"><span class="opacity-75" data-i18n="payment_label">طريقة الدفع:</span><strong data-payment-value="<?= e($task['Payment_Method'] ?: '') ?>"><?= e(driverPaymentLabel($task['Payment_Method'])) ?></strong></div>
                <div class="info-row"><span class="opacity-75" data-i18n="amount_label">المبلغ:</span><strong class="text-success fs-5"><?= $task['Payment_Method'] === 'plan' ? '<span data-i18n="covered_by_plan">مغطى من الباقة</span>' : e(number_format((float) $task['Total_Price'], 2) . ' JOD') ?></strong></div>

                <div class="row g-2 mt-4 mb-3">
                    <div class="col-6"><a href="tel:<?= e($task['CustomerPhone']) ?>" class="btn btn-light border w-100 fw-bold py-2 rounded-3"><i class="fas fa-phone-alt text-success ms-1"></i><span data-i18n="btn_call">اتصال</span></a></div>
                    <div class="col-6"><button type="button" class="btn btn-secondary w-100 py-2 fw-bold rounded-3" onclick="window.open('https://www.google.com/maps?q=<?= e($task['Latitude']) ?>,<?= e($task['Longitude']) ?>')"><i class="fas fa-directions ms-1"></i><span data-i18n="btn_location">الخريطة</span></button></div>
                </div>

                <?php $nextStates=clevo_booking_driver_targets((string)$task['Status']); if (in_array('Arrived',$nextStates,true)): ?>
                    <button type="button" hidden class="btn btn-arrived" onclick="updateTaskStatus('<?= (int) $task['BookingID'] ?>', 'Arrived')">
                        <i class="fas fa-map-pin ms-2"></i><span data-i18n="btn_arrived">وصلت لموقع الزبون</span>
                    </button>
                <?php endif; ?>

                <?php include __DIR__.'/../includes/delivery-controls.php'; ?>
                <div class="row g-2 mb-3 status-grid" hidden>
                    <div class="col-6"><button type="button" class="btn btn-outline-primary w-100 rounded-pill fw-bold" <?= in_array('In Progress',$nextStates,true)?'':'disabled' ?> onclick="updateTaskStatus('<?= (int) $task['BookingID'] ?>', 'In Progress')" data-i18n="status_on_way">في الطريق</button></div>
                    <div class="col-6"><button type="button" class="btn btn-outline-warning w-100 rounded-pill fw-bold" <?= in_array('Pickup',$nextStates,true)?'':'disabled' ?> onclick="updateTaskStatus('<?= (int) $task['BookingID'] ?>', 'Pickup')" data-i18n="status_pickup">استلام السيارة</button></div>
                    <div class="col-6"><button type="button" class="btn btn-outline-info w-100 rounded-pill fw-bold" <?= in_array('Washing',$nextStates,true)?'':'disabled' ?> onclick="updateTaskStatus('<?= (int) $task['BookingID'] ?>', 'Washing')" data-i18n="status_washing">بدء الغسيل</button></div>
                    <div class="col-6"><button type="button" class="btn btn-outline-success w-100 rounded-pill fw-bold" <?= in_array('Returning',$nextStates,true)?'':'disabled' ?> onclick="updateTaskStatus('<?= (int) $task['BookingID'] ?>', 'Returning')" data-i18n="status_returning">إرجاع السيارة</button></div>
                </div>

                <button type="button" hidden class="btn btn-finish" <?= in_array('Completed',$nextStates,true)?'':'disabled' ?> onclick="confirmFinishTask('<?= (int) $task['BookingID'] ?>')"><i class="fas fa-check-circle ms-2"></i><span data-i18n="btn_finish_task">إنهاء المهمة</span></button>
            </div>
        <?php else: ?>
            <div class="task-card text-center py-5">
                <i class="fas fa-tasks fa-3x opacity-25 mb-3"></i>
                <p class="fw-bold opacity-50 mb-0" data-i18n="no_tasks_msg">لا يوجد مهام نشطة حالياً</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="confirmFinishModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4" style="background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border-color); border-radius: 24px;">
                <i class="fas fa-circle-check text-success fa-4x mb-3"></i>
                <h6 class="fw-bold" data-i18n="finish_confirm_title">تأكيد إنهاء المهمة</h6>
                <p class="small opacity-75" data-i18n="finish_confirm_body">هل أنت متأكد من إنهاء وتسليم المهمة؟</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-success rounded-pill fw-bold" id="confirmFinishBtn" data-i18n="btn_confirm_finish">نعم، إنهاء</button>
                    <button type="button" class="btn btn-light border rounded-pill fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="driverStatusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content text-center p-4" style="background: var(--bg-card); color: var(--text-main); border: 1px solid var(--border-color); border-radius: 24px;">
                <div id="driverStatusIcon" class="mb-3 fs-1"></div>
                <h6 id="driverStatusTitle" class="fw-bold"></h6>
                <p id="driverStatusBody" class="small opacity-75 mb-0"></p>
                <button type="button" class="btn btn-primary rounded-pill w-100 mt-3" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = <?= json_encode(generate_csrf_token()) ?>;
        let pendingFinishOrderId = 0;

        const translations = {
            ar: {
                driver_tasks_title: 'مهامي اليومية', logout_btn: 'خروج', hello_driver: 'أهلاً بك يا',
                task_details_subtitle: 'تفاصيل المهمة الحالية المكلف بها', customer_label: 'الزبون:',
                car_type_label: 'المركبة:', service_type_label: 'الخدمة:', payment_label: 'طريقة الدفع:',
                amount_label: 'المبلغ:', covered_by_plan: 'مغطى من الباقة', btn_call: 'اتصال',
                btn_location: 'الخريطة', btn_arrived: 'وصلت لموقع الزبون', status_on_way: 'في الطريق',
                status_pickup: 'استلام السيارة', status_washing: 'بدء الغسيل', status_returning: 'إرجاع السيارة',
                btn_finish_task: 'إنهاء المهمة', no_tasks_msg: 'لا يوجد مهام نشطة حالياً',
                finish_confirm_title: 'تأكيد إنهاء المهمة', finish_confirm_body: 'هل أنت متأكد من إنهاء وتسليم المهمة؟',
                btn_confirm_finish: 'نعم، إنهاء', btn_cancel: 'إلغاء', btn_ok: 'موافق',
                payment_required_t: 'الدفع مطلوب', payment_required_m: 'لا يمكن إنهاء المهمة قبل اختيار طريقة الدفع.',
                error_t: 'تعذر التحديث', error_m: 'حاول مرة أخرى بعد قليل.',
                completed_t: 'تم إنهاء المهمة', completed_m: 'تم إغلاق المهمة وسيتم تحديث صفحة العميل تلقائياً.',
                statusLabels: { Accepted: 'تم قبول المهمة', Arrived: 'وصلت للموقع', 'In Progress': 'في الطريق', Pickup: 'تم استلام السيارة', Washing: 'جاري الغسيل', Returning: 'في طريق الإرجاع' },
                services: { home: 'خدمة منزلية', station: 'في المحطة', delivery: 'استلام وتوصيل' },
                payments: { cash: 'كاش', visa: 'بطاقة', wallet: 'محفظة', points: 'نقاط ولاء', plan: 'من الباقة', '': '-' }
            },
            en: {
                driver_tasks_title: 'My Daily Tasks', logout_btn: 'Logout', hello_driver: 'Welcome,',
                task_details_subtitle: 'Current assigned task details', customer_label: 'Customer:',
                car_type_label: 'Car:', service_type_label: 'Service:', payment_label: 'Payment:',
                amount_label: 'Amount:', covered_by_plan: 'Covered by plan', btn_call: 'Call',
                btn_location: 'Map', btn_arrived: 'Arrived at customer location', status_on_way: 'On the way',
                status_pickup: 'Pick up car', status_washing: 'Start washing', status_returning: 'Return car',
                btn_finish_task: 'Finish Task', no_tasks_msg: 'No active tasks',
                finish_confirm_title: 'Confirm Completion', finish_confirm_body: 'Are you sure you want to finish and hand over this task?',
                btn_confirm_finish: 'Yes, Finish', btn_cancel: 'Cancel', btn_ok: 'OK',
                payment_required_t: 'Payment required', payment_required_m: 'This task cannot be completed before payment is selected.',
                error_t: 'Update failed', error_m: 'Please try again shortly.',
                completed_t: 'Task completed', completed_m: 'The task was closed and the customer tracking page will update automatically.',
                statusLabels: { Accepted: 'Accepted', Arrived: 'Arrived', 'In Progress': 'On the way', Pickup: 'Picked up', Washing: 'Washing', Returning: 'Returning' },
                services: { home: 'Home service', station: 'At station', delivery: 'Pickup and delivery' },
                payments: { cash: 'Cash', visa: 'Card', wallet: 'Wallet', points: 'Loyalty points', plan: 'From plan', '': '-' }
            }
        };

        function langNow() {
            return localStorage.getItem('app_lang') || 'ar';
        }

        function updateTaskStatus(orderId, status) {
            const params = new URLSearchParams();
            params.append('action', 'update_status');
            params.append('csrf_token', csrfToken);
            params.append('order_id', orderId);
            params.append('status', status);
            return fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: params.toString()
            })
                .then(res => res.json())
                .then(data => {
                    const tr = translations[langNow()];
                    if (data.status === 'success') {
                        if (status === 'Completed') {
                            showDriverStatus('success', tr.completed_t, tr.completed_m);
                            setTimeout(() => { window.location.href = '../general/login.php'; }, 900);
                        } else {
                            location.reload();
                        }
                        return;
                    }
                    if (data.status === 'payment_required') {
                        showDriverStatus('warning', tr.payment_required_t, tr.payment_required_m);
                        return;
                    }
                    showDriverStatus('warning', tr.error_t, tr.error_m);
                })
                .catch(() => {
                    const tr = translations[langNow()];
                    showDriverStatus('warning', tr.error_t, tr.error_m);
                });
        }

        function sendDriverLocation() {} // Authorized collector is driver-tracking.js.

        function showDriverStatus(type, title, body) {
            document.getElementById('driverStatusIcon').innerHTML = type === 'success'
                ? '<i class="fas fa-check-circle text-success"></i>'
                : '<i class="fas fa-circle-exclamation text-warning"></i>';
            document.getElementById('driverStatusTitle').innerText = title;
            document.getElementById('driverStatusBody').innerText = body;
            new bootstrap.Modal(document.getElementById('driverStatusModal')).show();
        }

        function confirmFinishTask(orderId) {
            pendingFinishOrderId = orderId;
            new bootstrap.Modal(document.getElementById('confirmFinishModal')).show();
        }

        function toggleGlobalTheme() {
            const html = document.documentElement;
            const newTheme = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('global_theme', newTheme);
            document.getElementById('themeIcon').className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }

        function toggleLanguage() {
            const currentLang = langNow();
            localStorage.setItem('app_lang', currentLang === 'ar' ? 'en' : 'ar');
            location.reload();
        }

        window.onload = () => {
            const lang = langNow();
            const theme = localStorage.getItem('global_theme') || 'light';
            const tr = translations[lang];
            document.documentElement.lang = lang;
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.getElementById('bootstrap-css').href = lang === 'ar'
                ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css'
                : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
            document.getElementById('themeIcon').className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            document.getElementById('langBtn').innerText = lang === 'ar' ? 'EN' : 'AR';
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (tr[key]) el.innerText = tr[key];
            });
            document.querySelectorAll('[data-status-label]').forEach(el => {
                const key = el.getAttribute('data-status-label');
                if (tr.statusLabels[key]) el.innerText = tr.statusLabels[key];
            });
            document.querySelectorAll('[data-service-value]').forEach(el => {
                const key = el.getAttribute('data-service-value');
                if (tr.services[key]) el.innerText = tr.services[key];
            });
            document.querySelectorAll('[data-payment-value]').forEach(el => {
                const key = el.getAttribute('data-payment-value');
                if (tr.payments[key] !== undefined) el.innerText = tr.payments[key];
            });
            document.title = `${tr.driver_tasks_title} | Clevo Driver`;
            const finishBtn = document.getElementById('confirmFinishBtn');
            if (finishBtn) {
                finishBtn.onclick = () => {
                    const modal = bootstrap.Modal.getInstance(document.getElementById('confirmFinishModal'));
                    if (modal) modal.hide();
                    if (pendingFinishOrderId) updateTaskStatus(pendingFinishOrderId, 'Completed');
                };
            }
            sendDriverLocation();
            setInterval(sendDriverLocation, 10000);
        };
    </script>
<script src="../assets/driver-tracking.js"></script>
</body>
</html>
