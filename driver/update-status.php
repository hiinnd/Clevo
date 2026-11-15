<?php
include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';

$driver_id = (int) ($_SESSION['userID'] ?? 0);
$driver_booking_id = (int) ($_SESSION['driver_booking_id'] ?? 0);
$driver_role = strtolower($_SESSION['role'] ?? $_SESSION['Role'] ?? '');
if (!$driver_id || !$driver_booking_id || $driver_role !== 'driver') {
    header('Location: ../general/login.php');
    exit;
}

clevoEnsureTrackingColumns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_location') {
        $lat = filter_var($_POST['lat'] ?? null, FILTER_VALIDATE_FLOAT); $lng = filter_var($_POST['lng'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false || abs($lat) > 90 || abs($lng) > 180) throw new ClevoSecurityException('invalid_location', 422);
        $updated = clevo_stmt($conn, "UPDATE booking SET Driver_Lat = ?, Driver_Lon = ? WHERE BookingID = ? AND DriverID = ? AND Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning')", 'ddii', [$lat, $lng, $driver_booking_id, $driver_id]);
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

$task_stmt = $conn->prepare("SELECT b.BookingID, b.Order_Number, b.Status, u.UserName AS customer_name
    FROM booking b
    JOIN user u ON b.UserID = u.UserID
    WHERE b.BookingID = ? AND b.DriverID = ? AND b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning')
    ORDER BY b.created_at DESC LIMIT 1");
$task_stmt->bind_param("ii", $driver_booking_id, $driver_id);
$task_stmt->execute();
$task = $task_stmt->get_result()->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="worker_title">تحديث الحالة | Clevo Worker</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-rtl">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { --bg-body: #f8f9fa; --bg-card: #ffffff; --text-main: #000000; --text-muted: #64748b; --header-bg: #212529; --btn-status-bg: #ffffff; --icon-bg: #f1f3f5; --border-color: #dee2e6; --border-active: #198754; }
        [data-theme="dark"] { --bg-body: #0f172a; --bg-card: #1e293b; --text-main: #ffffff; --text-muted: #cbd5e1; --header-bg: #000000; --btn-status-bg: #252525; --icon-bg: #333333; --border-color: #475569; --border-active: #28a745; }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; }
        .status-header { background: var(--header-bg); color: white; padding: 20px 0; border-radius: 0 0 30px 30px; margin-bottom: 25px; }
        .header-container { display: flex; align-items: center; justify-content: space-between; padding: 0 20px; }
        .top-btn-circle { background: rgba(255, 255, 255, 0.2); border: 1px solid rgba(255, 255, 255, 0.3); color: white; width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; }
        .status-btn { background: var(--btn-status-bg); border-radius: 20px; padding: 15px; margin-bottom: 12px; display: flex; align-items: center; width: 100%; border: 2px solid var(--border-color); transition: 0.3s; color: var(--text-main); text-align: inherit; }
        .status-btn.active { border-color: var(--border-active); background: var(--bg-card); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }
        .icon-box { width: 45px; height: 45px; background: var(--icon-bg); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; margin-left: 15px; }
    </style>
</head>
<body>
    <header class="status-header shadow">
        <div class="container header-container">
            <a href="driver-dashboard.php" class="top-btn-circle"><i class="fas fa-arrow-right" id="backIcon"></i></a>
            <h6 class="mb-0 text-white"><span data-i18n="update_order">تحديث حالة الطلب</span> #<?= htmlspecialchars($task['Order_Number'] ?? '-') ?></h6>
            <div style="width: 40px;"></div>
        </div>
    </header>

    <main class="container" style="max-width: 500px;">
        <div class="alert alert-primary border-0 shadow-sm rounded-4 mb-4 text-center" style="background: rgba(0, 123, 255, 0.1);">
            <h6 class="small mb-0" style="color: var(--text-main); font-weight: 700;"><span data-i18n="customer_label">الزبون:</span> <?= htmlspecialchars($task['customer_name'] ?? '-') ?></h6>
        </div>

        <?php if($task): ?>
            <?php foreach (['Arrived' => ['وصلت للموقع', 'status_arrived'], 'Pickup' => ['تم استلام السيارة', 'status_pickup'], 'Washing' => ['بدء الغسيل', 'status_washing'], 'Returning' => ['جاري الإرجاع', 'status_returning']] as $status => [$label, $translationKey]): ?>
                <button class="status-btn <?= $task['Status'] === $status ? 'active' : '' ?>" data-status="<?= $status ?>" onclick="updateStatus(this)">
                    <div class="icon-box shadow-sm"><i class="fas fa-location-dot text-primary"></i></div>
                    <div class="flex-grow-1"><h6 class="mb-0 fw-bold" data-i18n="<?= $translationKey ?>"><?= $label ?></h6></div>
                </button>
            <?php endforeach; ?>
            <button class="btn btn-success w-100 rounded-pill py-3 mt-4 shadow fw-bold" onclick="updateStatus({dataset:{status:'Completed'}})" data-i18n="finish_task">إنهاء وتسليم المهمة</button>
        <?php else: ?>
            <div class="text-center opacity-50 py-5" data-i18n="no_active_task">لا توجد مهمة نشطة</div>
        <?php endif; ?>
    </main>

    <script>
        const csrfToken = <?= json_encode(generate_csrf_token()) ?>;
        const translations = {
            ar: {
                worker_title: "تحديث الحالة | Clevo Worker",
                update_order: "تحديث حالة الطلب",
                customer_label: "الزبون:",
                status_arrived: "وصلت للموقع",
                status_pickup: "تم استلام السيارة",
                status_washing: "بدء الغسيل",
                status_returning: "جاري الإرجاع",
                finish_task: "إنهاء وتسليم المهمة",
                no_active_task: "لا توجد مهمة نشطة"
            },
            en: {
                worker_title: "Update Status | Clevo Worker",
                update_order: "Update Order Status",
                customer_label: "Customer:",
                status_arrived: "Arrived at Location",
                status_pickup: "Vehicle Picked Up",
                status_washing: "Start Washing",
                status_returning: "Returning Vehicle",
                finish_task: "Finish and Deliver Task",
                no_active_task: "No Active Task"
            }
        };

        function updateStatus(btn) {
            const params = new URLSearchParams();
            params.append('action', 'update_booking_status');
            params.append('csrf_token', csrfToken);
            params.append('status', btn.dataset.status);
            params.append('order_id', '<?= (int)($task['BookingID'] ?? 0) ?>');
            fetch(window.location.href, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: params.toString() })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'success') {
                        location.href = data.logout ? '../general/login.php' : 'update-status.php';
                    }
                });
        }
        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'light';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.lang = lang;
            document.getElementById('bootstrap-rtl').href = lang === 'ar'
                ? 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css'
                : 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
            document.getElementById('backIcon').className = lang === 'ar' ? 'fas fa-arrow-right' : 'fas fa-arrow-left';
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) el.innerText = translations[lang][key];
            });
        };
    </script>
</body>
</html>
