<?php

include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../general/login.php");
    exit();
}

$admin_name = $_SESSION['userName'] ?? "Admin";
$admin_id = $_SESSION['userID'] ?? 0;

clevoEnsureOwnerPayoutTables($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_withdrawal_status') {
    clevo_require_csrf_token();
    $requestID = (int) ($_POST['request_id'] ?? 0);
    $newStatus = $_POST['status'] ?? '';
    if ($requestID > 0 && in_array($newStatus, ['paid', 'rejected'], true)) {
        clevo_market_withdrawal_status($conn,(int)$admin_id,$requestID,$newStatus);
    }
    header("Location: financial-reports.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    $file = $_FILES['admin_pic_upload'];
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        $stmt = $conn->prepare("UPDATE `user` SET ProfilePic = ? WHERE UserID = ?");
        $stmt->bind_param("si", $newName, $admin_id);
        if ($stmt->execute()) {
            header("Location: financial-reports.php?upload=success");
            exit();
        }
    }
}

$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$user_data = $res_pic->fetch_assoc();
$admin_pic = ($user_data && !empty($user_data['ProfilePic'])) ? $user_data['ProfilePic'] : 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);

$res_gmv = $conn->query("SELECT SUM(Total_Price) as total FROM booking WHERE Status = 'Completed'");
$total_gmv = $res_gmv->fetch_assoc()['total'] ?? 0;

$res_net = $conn->query("SELECT SUM(Admin_Commission) as total FROM booking WHERE Status = 'Completed'");
$net_profit = $res_net->fetch_assoc()['total'] ?? 0;

$res_count = $conn->query("SELECT COUNT(*) as total FROM booking WHERE Status = 'Completed'");
$completed_orders = $res_count->fetch_assoc()['total'] ?? 0;


$top_stations_query = "
    SELECT s.Name, SUM(b.Total_Price) as revenue 
    FROM stations s 
    JOIN booking b ON s.StationID = b.StationID 
    WHERE b.Status = 'Completed'
    GROUP BY s.StationID 
    ORDER BY revenue DESC 
    LIMIT 3";
$top_stations_res = $conn->query($top_stations_query);
$top_stations = [];
$max_revenue = 1; 
if($top_stations_res) {
    while($row = $top_stations_res->fetch_assoc()) {
        $top_stations[] = $row;
        if($row['revenue'] > $max_revenue) {
            $max_revenue = $row['revenue'];
        }
    }
}

$history_query = "
    SELECT b.Booking_Date, u.UserName, b.Total_Price, b.Admin_Commission, b.Status 
    FROM booking b 
    JOIN user u ON b.UserID = u.UserID 
    ORDER BY b.BookingID DESC 
    LIMIT 10";
$history_res = $conn->query($history_query);

$withdrawal_res = $conn->query("
    SELECT wr.*, u.UserName AS owner_name, u.PhoneNumber AS owner_phone
    FROM owner_withdrawal_requests wr
    JOIN user u ON u.UserID = wr.owner_id
    ORDER BY wr.requested_at DESC
    LIMIT 10");


$days_map = ['Saturday' => 0, 'Sunday' => 1, 'Monday' => 2, 'Tuesday' => 3, 'Wednesday' => 4, 'Thursday' => 5, 'Friday' => 6];
$chart_values = [0, 0, 0, 0, 0, 0, 0];
$weekly_res = $conn->query("SELECT DAYNAME(created_at) as day, SUM(Total_Price) as total FROM booking WHERE Status = 'Completed' GROUP BY DAYNAME(created_at)");
if($weekly_res) {
    while($w = $weekly_res->fetch_assoc()){
        if(isset($days_map[$w['day']])) {
            $chart_values[$days_map[$w['day']]] = (float)$w['total'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="fin_reports_title">التقارير المالية | Clevo</title>
    
    <link class="bootstrap-link" rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    
    <style>
        :root { 
            --accent-blue: #3b82f6; --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #3b82f6;
            --border-color: #dee2e6; --box-bg: #ffffff; --table-row-bg: #ffffff;
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #1e293b; --text-main: #ffffff !important; --text-muted: #cbd5e1; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --accent-blue: #60a5fa; --primary-blue: #60a5fa; --box-bg: #1e293b;
            --table-row-bg: #1e293b; 
        }
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-row { display: flex; flex-wrap: nowrap; min-height: 100vh; }
        
        .admin-sidebar { background: var(--bg-sidebar); color: white; padding: 20px 15px; position: sticky; top: 0; height: 100vh; z-index: 1000; width: 260px; display: flex; flex-direction: column; overflow: visible; }
        .admin-pic-container { position: relative; display: inline-block; margin-bottom: 10px; cursor: pointer; }
        .admin-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-blue); transition: 0.3s; }
        .upload-overlay { position: absolute; bottom: 0; right: 0; background: var(--accent-blue); color: white; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid var(--bg-sidebar); }
        
        .nav-link { color: #ffffff !important; padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; text-decoration: none; font-weight: 600; transition: 0.3s; font-size: 0.9rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent-blue); color: white !important; }
        
        .admin-main-content { flex: 1; overflow-x: hidden; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .circle-btn-back { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; }
        
        .stat-card { background: var(--bg-card); border-radius: 24px; padding: 25px; border: 1px solid var(--border-color); height: 100%; transition: 0.3s; color: var(--text-main); }
        
        .chart-box, .table-container { background-color: var(--box-bg) !important; border-radius: 24px; padding: 25px; margin-top: 20px; border: 1px solid var(--border-color); }
        
        .custom-table { background-color: transparent !important; color: var(--text-main) !important; }
        .custom-table th { color: var(--text-muted) !important; background-color: transparent !important; border-bottom: 1px solid var(--border-color) !important; }
        .custom-table td { color: var(--text-main) !important; background-color: transparent !important; border-bottom: 1px solid var(--border-color) !important; }
        .custom-table tbody tr { background-color: transparent !important; }

        .progress-custom { height: 8px; border-radius: 10px; background-color: var(--border-color); }

        .btn-export-excel { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 50px; font-weight: 700; font-size: 0.85rem; transition: 0.3s; }
        .btn-export-excel:hover { background: #059669; transform: translateY(-2px); color: white; }
        
        .modal-content { border-radius: 25px; background-color: var(--bg-card) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); }
        .modal-sm-custom { max-width: 350px; }

        @media (max-width: 991px) {
            .main-row { flex-direction: column; }
            .admin-main-content { padding-bottom: 100px !important; }
        }
    </style>
</head>
<body>

    <div class="container-fluid p-0">
        <div class="row g-0 main-row">
            
            <?php include __DIR__ . '/../includes/shared_admin_sidebar.php'; ?>

            <main class="admin-main-content">
                <div class="orders-header">
                    <div class="container d-flex justify-content-between align-items-center">
                        <a href="admin-dashboard.php" class="circle-btn-back shadow-sm">
                            <i class="fas fa-arrow-right" id="backIcon"></i>
                        </a>
                        <h6 class="mb-0 fw-bold" data-i18n="header_main_title">التقارير المالية</h6>
                        <button class="btn btn-export-excel shadow-sm" onclick="openExportModal()">
                            <i class="fas fa-file-excel me-1"></i> <span data-i18n="btn_export_excel">تصدير Excel</span>
                        </button>
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="stat-card shadow-sm text-center">
                                <small class="opacity-75 d-block" data-i18n="total_gmv">إجمالي المبيعات (GMV)</small>
                                <h2 class="fw-bold mt-1 text-primary"><?php echo number_format($total_gmv, 2); ?> <small class="fs-6">JOD</small></h2>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card shadow-sm text-center" style="border-inline-start: 5px solid #fd7e14 !important;">
                                <p class="small" data-i18n="financial_basis_notice">قيم الحجوزات الاسمية لا تثبت التحصيل أو صافي الأرباح. تقرير المطابقة يفصل النقد والاختبار والاسترجاع؛ التحويلات غير مفعّلة.</p>
                                <small class="opacity-75 d-block" data-i18n="net_profit">العمولات الاسمية</small>
                                <h2 class="fw-bold mt-1" style="color: #fd7e14 !important;"><?php echo number_format($net_profit, 2); ?> <small class="fs-6">JOD</small></h2>
                                <p class="small mb-0 opacity-75"><span data-i18n="your_earnings">أرباحكِ يا</span> <span class="u-name-display fw-bold text-primary"><?php echo htmlspecialchars($admin_name); ?></span></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="stat-card shadow-sm text-center">
                                <small class="opacity-75 d-block" data-i18n="completed_orders">الطلبات المكتملة</small>
                                <h2 class="text-success fw-bold mt-1"><?php echo $completed_orders; ?></h2>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-lg-8">
                            <div class="chart-box shadow-sm text-start">
                                <h6 class="mb-4 fw-bold"><i class="fas fa-chart-line me-2 text-primary"></i> <span data-i18n="weekly_analysis">تحليل المبيعات الأسبوعي</span></h6>
                                <div style="height: 300px;"><canvas id="revenueChart"></canvas></div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="chart-box shadow-sm text-start">
                                <h6 class="mb-4 fw-bold" data-i18n="top_stations">أعلى المحطات دخلاً</h6>
                                <?php if(!empty($top_stations)): foreach($top_stations as $st): 

                                    $percentage = ($st['revenue'] / $max_revenue) * 100;
                                ?>
                                    <div class="mb-4">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span><?php echo htmlspecialchars($st['Name']); ?></span>
                                            <span class="fw-bold"><?php echo number_format($st['revenue'], 2); ?> JOD</span>
                                        </div>
                                        <div class="progress progress-custom">
                                            <div class="progress-bar bg-primary" style="width: <?php echo $percentage; ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; else: ?>
                                    <p class="small opacity-50 text-center py-3">No data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="table-container shadow-sm text-start">
                        <h6 class="mb-4 fw-bold"><i class="fas fa-money-check-alt me-2 text-primary"></i> <span data-i18n="withdrawal_requests_title">Owner withdrawal requests</span></h6>
                        <div class="table-responsive">
                            <table class="table custom-table align-middle">
                                <thead class="small text-muted">
                                    <tr>
                                        <th data-i18n="th_owner">Owner</th>
                                        <th data-i18n="th_amount">Amount</th>
                                        <th data-i18n="th_bank">Bank details</th>
                                        <th data-i18n="th_requested_at">Requested at</th>
                                        <th data-i18n="th_status">Status</th>
                                        <th data-i18n="th_action">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($withdrawal_res && $withdrawal_res->num_rows > 0): ?>
                                        <?php while($wr = $withdrawal_res->fetch_assoc()): ?>
                                            <?php $bank = json_decode((string) ($wr['bank_snapshot'] ?? ''), true) ?: []; ?>
                                            <tr>
                                                <td class="fw-bold">
                                                    <?= htmlspecialchars($wr['owner_name'] ?? '') ?><br>
                                                    <span class="opacity-75"><?= htmlspecialchars($wr['owner_phone'] ?? '') ?></span>
                                                </td>
                                                <td class="text-success fw-bold"><?= number_format((float) $wr['amount'], 2) ?> JOD</td>
                                                <td>
                                                    <strong><?= htmlspecialchars($bank['bank_name'] ?? '-') ?></strong><br>
                                                    <span><?= htmlspecialchars($bank['account_holder_name'] ?? '-') ?></span><br>
                                                    <span class="opacity-75"><?= htmlspecialchars($bank['iban'] ?? '-') ?></span>
                                                </td>
                                                <td><?= htmlspecialchars($wr['requested_at'] ?? '') ?></td>
                                                <td><span class="badge bg-warning text-dark rounded-pill px-3"><?= htmlspecialchars($wr['status'] ?? 'pending') ?></span></td>
                                                <td>
                                                    <?php if (($wr['status'] ?? '') === 'pending'): ?>
                                                        <div class="d-flex gap-2 flex-wrap">
                                                            <form method="POST">
                                                                <?= csrf_input(); ?>
                                                                <input type="hidden" name="action" value="update_withdrawal_status">
                                                                <input type="hidden" name="request_id" value="<?= (int) $wr['id'] ?>">
                                                                <input type="hidden" name="status" value="paid">
                                                                <button <?= clevo_finance_fixture_allowed($conn) ? '' : 'disabled' ?> class="btn btn-sm btn-success rounded-pill px-3" data-i18n="btn_mark_paid">Paid</button>
                                                            </form>
                                                            <form method="POST">
                                                                <?= csrf_input(); ?>
                                                                <input type="hidden" name="action" value="update_withdrawal_status">
                                                                <input type="hidden" name="request_id" value="<?= (int) $wr['id'] ?>">
                                                                <input type="hidden" name="status" value="rejected">
                                                                <button <?= clevo_finance_fixture_allowed($conn) ? '' : 'disabled' ?> class="btn btn-sm btn-outline-danger rounded-pill px-3" data-i18n="btn_reject">Reject</button>
                                                            </form>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="opacity-75">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="6" class="text-center py-3 opacity-50" data-i18n="no_withdrawals">No withdrawal requests yet</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="table-container shadow-sm text-start">
                        <h6 class="mb-4 fw-bold"><i class="fas fa-history me-2 text-primary"></i> <span data-i18n="payment_history_title">سجل العمليات الأخير</span></h6>
                        <div class="table-responsive">
                            <table class="table custom-table align-middle" id="historyTable">
                                <thead class="small text-muted">
                                    <tr>
                                        <th data-i18n="th_date">التاريخ</th>
                                        <th data-i18n="th_customer">الزبون</th>
                                        <th data-i18n="th_value">القيمة</th>
                                        <th data-i18n="th_commission">عمولة</th>
                                        <th data-i18n="th_status">الحالة</th>
                                    </tr>
                                </thead>
                                <tbody class="small">
                                    <?php if($history_res && $history_res->num_rows > 0): ?>
                                        <?php while($row = $history_res->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo $row['Booking_Date']; ?></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($row['UserName']); ?></td>
                                            <td><?php echo number_format($row['Total_Price'], 2); ?> JOD</td>
                                            <td class="text-primary"><?php echo number_format($row['Admin_Commission'], 2); ?> JOD</td>
                                            <td><span class="badge bg-success rounded-pill px-3"><?php echo $row['Status']; ?></span></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" class="text-center py-3 opacity-50">No operations found</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="modal fade" id="exportModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm modal-sm-custom">
            <div class="modal-content p-4 text-center">
                <div class="text-success fs-1 mb-3"><i class="fas fa-file-excel fa-4x"></i></div>
                <h5 class="fw-bold" data-i18n="export_confirm_title">تصدير التقارير</h5>
                <p class="small opacity-75" data-i18n="export_confirm_msg">تصدير البيانات كملف Excel؟</p>
                <div class="d-grid gap-2 mt-4">
                    <button class="btn btn-primary rounded-pill fw-bold py-2" onclick="exportToExcel()" data-i18n="btn_confirm_export">تأكيد</button>
                    <button class="btn btn-light border rounded-pill py-2" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: {
                fin_reports_title: "التقارير المالية | Clevo", header_main_title: "التقارير المالية", btn_export_excel: "تصدير Excel",
                financial_basis_notice: "قيم الحجوزات الاسمية لا تثبت التحصيل أو صافي الأرباح. تقرير المطابقة يفصل النقد والاختبار والاسترجاع؛ التحويلات غير مفعّلة.", total_gmv: "إجمالي قيم الحجوزات (GMV)", net_profit: "العمولات الاسمية", your_earnings: "أرباحكِ يا", completed_orders: "الطلبات المكتملة",
                weekly_analysis: "تحليل المبيعات الأسبوعي", top_stations: "أعلى المحطات دخلاً", payment_history_title: "سجل العمليات الأخير",
                th_date: "التاريخ", th_customer: "الزبون", th_value: "القيمة", th_commission: "عمولة", th_status: "الحالة",
                withdrawal_requests_title: "طلبات سحب أرباح الأونر", th_owner: "الأونر", th_amount: "المبلغ", th_bank: "بيانات البنك", th_requested_at: "تاريخ الطلب", th_action: "الإجراء", btn_mark_paid: "تم الدفع", btn_reject: "رفض", no_withdrawals: "لا توجد طلبات سحب بعد",
                nav_main: "الرئيسية", nav_approvals: "الموافقات", nav_security: "الأمان", nav_reports: "التقارير", nav_plans: "الخطط", nav_notif: "الإشعارات", nav_support_chat: "دردشة الدعم", nav_logout: "خروج",
                export_confirm_title: "تصدير التقارير", export_confirm_msg: "تصدير البيانات كملف Excel؟", btn_confirm_export: "تأكيد", btn_cancel: "إلغاء",
                logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج"
            },
            en: {
                fin_reports_title: "Financial Reports | Clevo", header_main_title: "Financial Reports", btn_export_excel: "Export Excel",
                financial_basis_notice: "Nominal booking values do not establish collection or net profit. Reconciliation separates cash, sandbox and refunds; transfers are disabled.", total_gmv: "Nominal booking GMV", net_profit: "Nominal commission", your_earnings: "Your Earnings,", completed_orders: "Completed Orders",
                weekly_analysis: "Weekly Sales Analysis", top_stations: "Top Earning Stations", payment_history_title: "Recent Transactions",
                th_date: "Date", th_customer: "Customer", th_value: "Value", th_commission: "Comm.", th_status: "Status",
                withdrawal_requests_title: "Owner Withdrawal Requests", th_owner: "Owner", th_amount: "Amount", th_bank: "Bank details", th_requested_at: "Requested at", th_action: "Action", btn_mark_paid: "Paid", btn_reject: "Reject", no_withdrawals: "No withdrawal requests yet",
                nav_main: "Dashboard", nav_approvals: "Approvals", nav_security: "Security", nav_reports: "Reports", nav_plans: "Plans", nav_notif: "Notifications", nav_support_chat: "Support Chat", nav_logout: "Logout",
                export_confirm_title: "Export Reports", export_confirm_msg: "Export current data as Excel?", btn_confirm_export: "Confirm", btn_cancel: "Cancel",
                logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Logout"
            }
        };

        function openExportModal() { new bootstrap.Modal(document.getElementById('exportModal')).show(); }
        function exportToExcel() { const table = document.getElementById("historyTable"); const wb = XLSX.utils.table_to_book(table, { sheet: "Clevo_Finances" }); XLSX.writeFile(wb, "Clevo_Financial_Report.xlsx"); bootstrap.Modal.getInstance(document.getElementById('exportModal')).hide(); }

        function applySettings() {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            const isRtl = lang === 'ar';
            document.documentElement.setAttribute('data-theme', theme);
            
            document.getElementById('bootstrap-css').href = isRtl ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            document.documentElement.dir = isRtl ? 'rtl' : 'ltr';
            
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang] && translations[lang][key]) el.innerText = translations[lang][key];
            });

            document.getElementById('backIcon').className = isRtl ? 'fas fa-arrow-right' : 'fas fa-arrow-left';
            document.querySelectorAll('.u-name-display').forEach(el => el.innerText = "<?php echo htmlspecialchars($admin_name); ?>");


            const realChartData = <?php echo json_encode($chart_values); ?>;

            new Chart(document.getElementById('revenueChart').getContext('2d'), {
                type: 'line',
                data: {
                    labels: isRtl ? ["السبت", "الأحد", "الاثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة"] : ["Sat", "Sun", "Mon", "Tue", "Wed", "Thu", "Fri"],
                    datasets: [{ label: 'Revenue', data: realChartData, borderColor: '#60a5fa', tension: 0.4, fill: true, backgroundColor: 'rgba(96, 165, 250, 0.1)' }]
                },
                options: { 
                    responsive: true, maintainAspectRatio: false, 
                    plugins: { legend: { display: false } },
                    scales: { 
                        y: { grid: { color: theme==='dark'?'#334155':'#eee' }, ticks: { color: theme==='dark'?'#ffffff':'#000000' } }, 
                        x: { grid: { display: false }, ticks: { color: theme==='dark'?'#ffffff':'#000000' } } 
                    }
                }
            });
        }

        window.onload = applySettings;
    </script>
</body>
</html>
