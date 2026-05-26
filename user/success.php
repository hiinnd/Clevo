<?php
session_start();
include(__DIR__ . "/../includes/config.php");
include(__DIR__ . "/../includes/booking-helpers.php");
include(__DIR__ . "/../includes/app-helpers.php");

if (!isset($_SESSION['userID'])) {
    header("Location: ../general/login.php");
    exit();
}

$userID = (int) $_SESSION['userID'];
$bookingID = (int) ($_GET['id'] ?? 0);
$orderParam = trim((string) ($_GET['order'] ?? ''));

cancelExpiredBookings($conn, $userID);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    clevo_require_csrf_token(true);

    $action = $_POST['action'] ?? '';
    $postBookingID = (int) ($_POST['booking_id'] ?? 0);

    if ($action === 'cancel_booking') {
        echo json_encode(clevoCancelBookingForUser($conn, $postBookingID, $userID), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'reschedule_booking') {
        $newDate = (string) ($_POST['booking_date'] ?? '');
        $newTime = (string) ($_POST['booking_time'] ?? '');
        echo json_encode(clevoRescheduleBookingForUser($conn, $postBookingID, $userID, $newDate, $newTime), JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'invalid_action'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($bookingID > 0) {
    $stmt = $conn->prepare("SELECT b.*, s.Name AS station_name, serv.Name AS service_name
        FROM booking b
        JOIN stations s ON b.StationID = s.StationID
        JOIN services serv ON b.ServiceID = serv.ServiceID
        WHERE b.BookingID = ? AND b.UserID = ? LIMIT 1");
    $stmt->bind_param("ii", $bookingID, $userID);
} elseif ($orderParam !== '') {
    $stmt = $conn->prepare("SELECT b.*, s.Name AS station_name, serv.Name AS service_name
        FROM booking b
        JOIN stations s ON b.StationID = s.StationID
        JOIN services serv ON b.ServiceID = serv.ServiceID
        WHERE b.Order_Number = ? AND b.UserID = ? LIMIT 1");
    $stmt->bind_param("si", $orderParam, $userID);
} else {
    $stmt = $conn->prepare("SELECT b.*, s.Name AS station_name, serv.Name AS service_name
        FROM booking b
        JOIN stations s ON b.StationID = s.StationID
        JOIN services serv ON b.ServiceID = serv.ServiceID
        WHERE b.UserID = ?
        ORDER BY b.created_at DESC, b.BookingID DESC
        LIMIT 1");
    $stmt->bind_param("i", $userID);
}

$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: booking-history.php");
    exit();
}

$bookingID = (int) $booking['BookingID'];
$orderNumber = clevoBookingReference($booking, $bookingID);
$status = (string) ($booking['Status'] ?? 'Pending');
$canModify = clevoBookingCanBeChanged($status);
$paymentMethod = (string) ($booking['Payment_Method'] ?? '');
$paymentLabelAr = clevoPaymentMethodLabel($paymentMethod, 'ar');
$paymentLabelEn = clevoPaymentMethodLabel($paymentMethod, 'en');
$csrf_token = generate_csrf_token();

$statusLabels = [
    'Pending' => ['ar' => 'بانتظار موافقة المحطة', 'en' => 'Waiting for station approval', 'class' => 'bg-warning text-dark'],
    'Accepted' => ['ar' => 'تم قبول الطلب', 'en' => 'Accepted', 'class' => 'bg-primary'],
    'Arrived' => ['ar' => 'وصل السائق', 'en' => 'Driver arrived', 'class' => 'bg-info'],
    'In Progress' => ['ar' => 'قيد التنفيذ', 'en' => 'In progress', 'class' => 'bg-info'],
    'Pickup' => ['ar' => 'قيد الاستلام', 'en' => 'Pickup', 'class' => 'bg-info'],
    'Washing' => ['ar' => 'قيد الغسيل', 'en' => 'Washing', 'class' => 'bg-info'],
    'Returning' => ['ar' => 'قيد الإرجاع', 'en' => 'Returning', 'class' => 'bg-info'],
    'Rejected' => ['ar' => 'مرفوض', 'en' => 'Rejected', 'class' => 'bg-danger'],
    'Cancelled' => ['ar' => 'ملغي', 'en' => 'Cancelled', 'class' => 'bg-danger'],
    'Cancelled_Timeout' => ['ar' => 'ملغي لانتهاء المهلة', 'en' => 'Cancelled by timeout', 'class' => 'bg-danger'],
    'Completed' => ['ar' => 'مكتمل', 'en' => 'Completed', 'class' => 'bg-success'],
];
$statusMeta = $statusLabels[$status] ?? ['ar' => $status, 'en' => $status, 'class' => 'bg-secondary'];
$userName = $_SESSION['userName'] ?? 'User';
?>
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
        :root {
            --bg-body: #f8f9fa;
            --bg-card: #ffffff;
            --text-main: #000000;
            --text-muted: #1a1a1a;
            --badge-bg: #f1f3f5;
            --border-color: #dee2e6;
            --check-bg: #d1e7dd;
            --check-color: #198754;
            --danger: #dc2626;
            --accent: #007bff;
        }

        [data-theme="dark"] {
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --text-main: #ffffff;
            --text-muted: #f1f5f9;
            --badge-bg: #2d3748;
            --border-color: #475569;
            --check-bg: rgba(25, 135, 84, 0.3);
            --check-color: #2ecc71;
            --danger: #ef4444;
            --accent: #38bdf8;
        }

        body {
            font-family: 'Cairo', sans-serif;
            background-color: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 78px 14px 34px;
            transition: 0.3s ease;
        }

        .back-nav {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        html[dir="ltr"] .back-nav {
            right: auto;
            left: 20px;
        }

        .circle-btn {
            background: var(--bg-card);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            text-decoration: none;
            transition: 0.3s;
        }

        .circle-btn:hover {
            transform: scale(1.1);
            color: var(--accent);
        }

        .success-card {
            background: var(--bg-card);
            border-radius: 30px;
            padding: 34px 22px;
            width: 100%;
            max-width: 520px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border-color);
            position: relative;
        }

        .check-container {
            width: 76px;
            height: 76px;
            background: var(--check-bg);
            color: var(--check-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.4rem;
            margin: 0 auto 18px;
        }

        .order-id {
            background: var(--badge-bg);
            padding: 8px 20px;
            border-radius: 50px;
            display: inline-block;
            margin-bottom: 20px;
            color: var(--text-main);
            font-size: 0.86rem;
            border: 1px dashed var(--accent);
        }

        .summary-box {
            background: var(--badge-bg);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 16px;
            text-align: start;
            margin-bottom: 16px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 9px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .summary-row:last-child {
            border-bottom: 0;
        }

        .summary-label {
            color: var(--text-muted);
            font-size: .84rem;
        }

        .summary-value {
            font-weight: 900;
            text-align: end;
            overflow-wrap: anywhere;
        }

        .status-badge {
            border-radius: 999px;
            padding: 7px 14px;
            color: #fff;
            font-weight: 800;
            font-size: .78rem;
            display: inline-block;
        }

        .btn-main,
        .btn-soft,
        .btn-danger-soft {
            border-radius: 999px;
            padding: 13px 16px;
            font-weight: 800;
            border: 0;
            width: 100%;
            transition: .2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-main {
            background: var(--accent);
            color: white;
        }

        .btn-soft {
            background: var(--badge-bg);
            color: var(--text-main);
            border: 1px solid var(--border-color);
        }

        .btn-danger-soft {
            background: rgba(220, 38, 38, .11);
            color: var(--danger);
            border: 1px solid rgba(220, 38, 38, .22);
        }

        .btn-main:hover,
        .btn-soft:hover,
        .btn-danger-soft:hover {
            transform: translateY(-1px);
        }

        .action-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .text-muted {
            color: var(--text-muted) !important;
            opacity: 1 !important;
        }

        .modal-content {
            background: var(--bg-card);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            border-radius: 24px;
        }

        .form-control {
            background: var(--bg-body);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 12px;
        }

        .form-control:focus {
            background: var(--bg-body);
            color: var(--text-main);
        }

        @media (max-width: 460px) {
            .action-grid {
                grid-template-columns: 1fr;
            }

            .success-card {
                border-radius: 24px;
                padding-inline: 18px;
            }
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

    <div class="back-nav">
        <a href="home.php" class="circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="success-card shadow-lg">
        <div class="check-container shadow-sm"><i class="fas fa-check-circle"></i></div>
        <h2 class="mb-2 fw-bold" data-i18n="order_sent_title">تم إرسال الطلب!</h2>
        <p class="small mb-3 text-muted">
            <span data-i18n="hello_thanks">شكراً لك يا</span>
            <span class="text-primary fw-bold"><?= e($userName) ?></span>،
            <span data-i18n="order_sent_msg">تم استلام طلبك بنجاح وهو الآن بانتظار موافقة المحطة.</span>
        </p>

        <div class="order-id">
            <span data-i18n="order_id_label">رقم الطلب:</span> <strong>#<?= e($orderNumber) ?></strong>
        </div>

        <div class="summary-box">
            <div class="summary-row">
                <span class="summary-label" data-i18n="status_label">الحالة</span>
                <span class="summary-value"><span class="status-badge <?= e($statusMeta['class']) ?>" data-status-label data-ar="<?= e($statusMeta['ar']) ?>" data-en="<?= e($statusMeta['en']) ?>"><?= e($statusMeta['ar']) ?></span></span>
            </div>
            <div class="summary-row">
                <span class="summary-label" data-i18n="station_label">المحطة</span>
                <span class="summary-value"><?= e($booking['station_name']) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label" data-i18n="service_label">الخدمة</span>
                <span class="summary-value"><?= e($booking['service_name']) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label" data-i18n="appointment_label">الموعد</span>
                <span class="summary-value"><?= e($booking['Booking_Date']) ?> | <?= e(substr((string) $booking['Booking_Time'], 0, 5)) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label" data-i18n="payment_label">الدفع</span>
                <span class="summary-value" data-payment-label data-ar="<?= e($paymentLabelAr) ?>" data-en="<?= e($paymentLabelEn) ?>"><?= e($paymentLabelAr) ?></span>
            </div>
            <div class="summary-row">
                <span class="summary-label" data-i18n="total_label">الإجمالي</span>
                <span class="summary-value text-success"><?= number_format((float) $booking['Total_Price'], 2) ?> JOD</span>
            </div>
        </div>

        <a href="order-details.php?id=<?= $bookingID ?>" class="btn-main mb-2 shadow">
            <span data-i18n="view_details">تفاصيل الطلب</span> <i class="fas fa-circle-info"></i>
        </a>

        <?php if ($canModify): ?>
            <div class="action-grid mb-2">
                <button type="button" onclick="openRescheduleModal()" class="btn-soft">
                    <span data-i18n="btn_reschedule">تعديل الموعد</span> <i class="fas fa-calendar-days"></i>
                </button>
                <button type="button" onclick="cancelBooking(this)" class="btn-danger-soft">
                    <span data-i18n="btn_cancel_booking">إلغاء الحجز</span> <i class="fas fa-ban"></i>
                </button>
            </div>
            <?php if ($paymentMethod === 'visa'): ?>
                <p class="small text-muted mb-2" data-i18n="visa_refund_hint">في حال الإلغاء، يرجع مبلغ الفيزا إلى محفظة Clevo داخل التطبيق.</p>
            <?php endif; ?>
        <?php else: ?>
            <p class="small text-muted mb-2" data-i18n="locked_hint">لا يمكن تعديل أو إلغاء هذا الطلب من هذه المرحلة.</p>
        <?php endif; ?>

        <div class="pt-3 border-top mt-3" style="border-color: var(--border-color) !important;">
            <button onclick="location.href='booking-history.php'" class="btn-soft">
                <span data-i18n="view_history">عرض سجل الحجوزات</span> <i class="fas fa-history"></i>
            </button>
        </div>
    </div>

    <div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4">
                <h6 class="fw-bold mb-3 text-center" data-i18n="reschedule_title">تعديل موعد الحجز</h6>
                <div class="mb-3">
                    <label class="small fw-bold mb-1" data-i18n="date_label">التاريخ</label>
                    <input type="date" id="newBookingDate" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= e($booking['Booking_Date']) ?>">
                </div>
                <div class="mb-4">
                    <label class="small fw-bold mb-1" data-i18n="time_label">الوقت</label>
                    <input type="time" id="newBookingTime" class="form-control" value="<?= e(substr((string) $booking['Booking_Time'], 0, 5)) ?>">
                </div>
                <button type="button" class="btn-main" onclick="submitReschedule(this)">
                    <span data-i18n="btn_save_reschedule">حفظ الموعد</span> <i class="fas fa-check"></i>
                </button>
                <button type="button" class="btn-soft mt-2" data-bs-dismiss="modal" data-i18n="btn_close">إغلاق</button>
            </div>
        </div>
    </div>

    <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">
                <div id="statusIcon" class="fs-1 mb-3"></div>
                <h6 id="statusTitle" class="fw-bold"></h6>
                <p id="statusBody" class="small text-muted mb-0"></p>
                <button class="btn-main mt-3" data-bs-dismiss="modal" data-i18n="btn_ok">موافق</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/translations.js"></script>
    <script>
        const csrfToken = <?= json_encode($csrf_token) ?>;
        const bookingID = <?= (int) $bookingID ?>;
        const lang = localStorage.getItem('app_lang') || 'ar';
        const rescheduleModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
        const statusModalEl = document.getElementById('statusModal');
        const statusModal = new bootstrap.Modal(statusModalEl);
        const cancelModal = new bootstrap.Modal(document.getElementById('cancelModal'));

        const pageTranslations = {
            ar: {
                status_label: "الحالة",
                station_label: "المحطة",
                service_label: "الخدمة",
                appointment_label: "الموعد",
                payment_label: "الدفع",
                total_label: "الإجمالي",
                view_details: "تفاصيل الطلب",
                btn_reschedule: "تعديل الموعد",
                btn_cancel_booking: "إلغاء الحجز",
                visa_refund_hint: "في حال الإلغاء، يرجع مبلغ الفيزا إلى محفظة Clevo داخل التطبيق.",
                locked_hint: "لا يمكن تعديل أو إلغاء هذا الطلب من هذه المرحلة.",
                reschedule_title: "تعديل موعد الحجز",
                date_label: "التاريخ",
                time_label: "الوقت",
                btn_save_reschedule: "حفظ الموعد",
                btn_close: "إغلاق",
                btn_ok: "موافق",
                cancel_confirm: "هل أنت متأكد من إلغاء الحجز؟",
                loading: "جاري المعالجة...",
                success_title: "تم بنجاح",
                cancel_success: "تم إلغاء الحجز. إذا كان مدفوعاً بفيزا أو محفظة، تم حفظ المبلغ في محفظتك.",
                reschedule_success: "تم حفظ الموعد الجديد وإرسال الطلب للمحطة للمراجعة.",
                error_title: "تعذر تنفيذ الطلب",
                booking_locked: "لا يمكن تعديل هذا الطلب بعد بدء التنفيذ أو اكتماله.",
                invalid_datetime: "اختر تاريخاً ووقتاً صحيحين.",
                generic_error: "حاول مرة أخرى بعد قليل."
            },
            en: {
                status_label: "Status",
                station_label: "Station",
                service_label: "Service",
                appointment_label: "Appointment",
                payment_label: "Payment",
                total_label: "Total",
                view_details: "Order Details",
                btn_reschedule: "Reschedule",
                btn_cancel_booking: "Cancel Booking",
                visa_refund_hint: "If cancelled, Visa payments are returned to your Clevo wallet.",
                locked_hint: "This order can no longer be changed or cancelled.",
                reschedule_title: "Reschedule Booking",
                date_label: "Date",
                time_label: "Time",
                btn_save_reschedule: "Save Appointment",
                btn_close: "Close",
                btn_ok: "OK",
                cancel_confirm: "Are you sure you want to cancel this booking?",
                loading: "Processing...",
                success_title: "Success",
                cancel_success: "Booking cancelled. Visa or wallet payments were kept in your wallet.",
                reschedule_success: "The new appointment was saved and sent to the station for review.",
                error_title: "Action failed",
                booking_locked: "This order cannot be changed after work starts or completes.",
                invalid_datetime: "Choose a valid date and time.",
                generic_error: "Please try again shortly."
            }
        };

        function t(key) {
            return pageTranslations[lang]?.[key] || key;
        }

        function applyLocalTranslations() {
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (pageTranslations[lang] && pageTranslations[lang][key]) {
                    el.innerText = pageTranslations[lang][key];
                }
            });
            document.querySelectorAll('[data-status-label], [data-payment-label]').forEach(el => {
                const text = el.getAttribute('data-' + lang);
                if (text) el.innerText = text;
            });
        }

        function showStatus(type, title, body, afterClose) {
            document.getElementById('statusIcon').innerHTML = type === 'success' ?
                '<i class="fas fa-check-circle text-success"></i>' :
                '<i class="fas fa-circle-exclamation text-warning"></i>';
            document.getElementById('statusTitle').innerText = title;
            document.getElementById('statusBody').innerText = body;
            if (afterClose) {
                statusModalEl.addEventListener('hidden.bs.modal', afterClose, {
                    once: true
                });
            }
            statusModal.show();
        }

        function withLoading(btn, work) {
            const original = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${t('loading')}`;
            }
            return work().finally(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = original;
                }
            });
        }

        function postBookingAction(action, extra = {}) {
            const params = new URLSearchParams();
            params.append('action', action);
            params.append('booking_id', bookingID);
            params.append('csrf_token', csrfToken);
            Object.entries(extra).forEach(([key, value]) => params.append(key, value));
            return fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: params.toString()
            }).then(res => res.json());
        }

        function cancelBooking(btn) {

            const cancelModal = new bootstrap.Modal(
                document.getElementById('cancelModal')
            );

            cancelModal.show();

            document.getElementById('confirmCancelBtn').onclick = function() {

                cancelModal.hide();

                postBookingAction('cancel_booking')

                    .then(data => {

                        if (data.status === 'success') {
                            document.querySelector('[data-status-label]').innerText =
                                lang === 'ar' ? 'ملغي' : 'Cancelled';

                            document.querySelector('[data-status-label]')
                                .className = 'status-badge bg-danger';

                            document.querySelector('.action-grid').remove();
                            showStatus(
                                'success',
                                t('success_title'),
                                t('cancel_success'),
                            );


                        } else {

                            const body =
                                data.message === 'booking_locked' ?
                                t('booking_locked') :
                                t('generic_error');

                            showStatus(
                                'error',
                                t('error_title'),
                                body
                            );

                        }

                    })

                    .catch(() => {

                        showStatus(
                            'error',
                            t('error_title'),
                            t('generic_error')
                        );

                    });

            };

        }

        function openRescheduleModal() {
            rescheduleModal.show();
        }

        function submitReschedule(btn) {
            const booking_date = document.getElementById('newBookingDate').value;
            const booking_time = document.getElementById('newBookingTime').value;
            withLoading(btn, () => postBookingAction('reschedule_booking', {
                booking_date,
                booking_time
            }).then(data => {
                if (data.status === 'success') {
                    rescheduleModal.hide();
                    showStatus('success', t('success_title'), t('reschedule_success'), () => location.reload());
                } else {
                    const body = data.message === 'booking_locked' ?
                        t('booking_locked') :
                        (data.message === 'invalid_datetime' ? t('invalid_datetime') : t('generic_error'));
                    showStatus('error', t('error_title'), body);
                }
            }).catch(() => showStatus('error', t('error_title'), t('generic_error'))));
        }

        document.addEventListener('DOMContentLoaded', () => {
            const isRtl = lang === 'ar';
            document.documentElement.setAttribute('lang', lang);
            document.documentElement.setAttribute('dir', isRtl ? 'rtl' : 'ltr');
            if (typeof applyTranslations === 'function') applyTranslations(lang);
            applyLocalTranslations();
            if (!isRtl) document.getElementById('backIcon').className = "fas fa-arrow-left";
            localStorage.setItem('clevo_order_status', <?= json_encode($status) ?>);
        });
    </script>
    <script src="../assets/lamea.js"></script>
    <div class="modal fade" id="cancelModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content p-4 text-center">

                <div class="mb-3 fs-1 text-danger">
                    <i class="fas fa-ban"></i>
                </div>

                <h5 class="fw-bold mb-2">
                    إلغاء الحجز
                </h5>

                <p class="small text-muted mb-4">
                    هل أنتِ متأكدة من إلغاء الحجز؟
                </p>

                <div class="d-flex gap-2">

                    <button type="button"
                        class="btn-soft"
                        data-bs-dismiss="modal">
                        رجوع
                    </button>

                    <button type="button"
                        class="btn-danger-soft"
                        id="confirmCancelBtn">
                        نعم، إلغاء
                    </button>

                </div>

            </div>
        </div>
    </div>
</body>

</html>