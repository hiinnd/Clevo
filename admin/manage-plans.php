<?php
session_start(); 
include_once __DIR__ . '/../includes/config.php';
include_once __DIR__ . '/../includes/app-helpers.php';
include_once __DIR__ . '/../includes/plan-helpers.php';


if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: ../general/login.php");
    exit;
}

clevoEnsurePlanColumns($conn);


$admin_name = $_SESSION['userName'] ?? "Admin";
$admin_id = $_SESSION['userID'] ?? 0;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['admin_pic_upload'])) {
    clevo_require_csrf_token();
    $file = $_FILES['admin_pic_upload'];
    $newName = clevoSaveUploadedProfileImage($file, 'admin', (int) $admin_id);
    if ($newName) {
        $stmt = $conn->prepare("UPDATE `user` SET ProfilePic = ? WHERE UserID = ?");
        $stmt->bind_param("si", $newName, $admin_id);
        if ($stmt->execute()) {
            header("Location: manage-plans.php?upload=success");
            exit();
        }
    }
}

$res_pic = $conn->query("SELECT ProfilePic FROM `user` WHERE UserID = $admin_id");
$admin_pic = $res_pic->fetch_assoc()['ProfilePic'] ?? 'default_user.png';
$admin_pic_url = clevoProfileImageUrl($admin_pic, $admin_name);


$show_msg_modal = false;
$msg_type = "";


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_plan'])) {
    clevo_require_csrf_token();
    $name_ar = trim($_POST['name_ar']);
    $name_en = trim($_POST['name_en']);
    $price = floatval($_POST['price']);
    $duration = intval($_POST['duration']);
    $duration_type = $_POST['duration_type'];
    $wash_count = max(1, intval($_POST['wash_count'] ?? 1));
    $desc_ar = trim($_POST['desc_ar']);
    $desc_en = trim($_POST['desc_en']);
    $included_services_ar = trim($_POST['included_services_ar'] ?? '');
    $included_services_en = trim($_POST['included_services_en'] ?? '');
    
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (!empty($name_ar)) {
        $stmt = $conn->prepare("INSERT INTO plans (name_ar, name_en, price, duration, duration_type, wash_count, description_ar, description_en, included_services_ar, included_services_en, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssdisissssi", $name_ar, $name_en, $price, $duration, $duration_type, $wash_count, $desc_ar, $desc_en, $included_services_ar, $included_services_en, $is_active);
        if($stmt->execute()) { $show_msg_modal = true; $msg_type = "added"; }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_plan'])) {
    clevo_require_csrf_token();
    $plan_id = intval($_POST['plan_id']);
    $name_ar = trim($_POST['name_ar']);
    $name_en = trim($_POST['name_en']);
    $price = floatval($_POST['price']);
    $duration = intval($_POST['duration']);
    $duration_type = $_POST['duration_type'];
    $wash_count = max(1, intval($_POST['wash_count'] ?? 1));
    $desc_ar = trim($_POST['desc_ar']);
    $desc_en = trim($_POST['desc_en']);
    $included_services_ar = trim($_POST['included_services_ar'] ?? '');
    $included_services_en = trim($_POST['included_services_en'] ?? '');
    
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $conn->prepare("UPDATE plans SET name_ar=?, name_en=?, price=?, duration=?, duration_type=?, wash_count=?, description_ar=?, description_en=?, included_services_ar=?, included_services_en=?, is_active=? WHERE id=?");
    $stmt->bind_param("ssdisissssii", $name_ar, $name_en, $price, $duration, $duration_type, $wash_count, $desc_ar, $desc_en, $included_services_ar, $included_services_en, $is_active, $plan_id);
    if($stmt->execute()) { $show_msg_modal = true; $msg_type = "updated"; }
}


if (isset($_POST['confirm_delete_id'])) {
    clevo_require_csrf_token();
    $delete_id = intval($_POST['confirm_delete_id']);
    if($conn->query("DELETE FROM plans WHERE id = $delete_id")) { $show_msg_modal = true; $msg_type = "deleted"; }
}


$res_plans = $conn->query("SELECT * FROM plans ORDER BY id DESC");
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title data-i18n="plans_title">إدارة الخطط | Clevo</title>
    
    <link class="bootstrap-style" rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    
    <style>
        :root { 
            --bg-body: #f0f2f5; --bg-sidebar: #0f172a; --bg-header: #ffffff; 
            --bg-card: #ffffff; --text-main: #000000; --text-muted: #1a1a1a; 
            --badge-bg: #e7f1ff; --border-card: #dee2e6; --primary-blue: #3b82f6;
            --border-color: #dee2e6; --box-bg: #ffffff; --accent-blue: #3b82f6; 
        }
        [data-theme="dark"] { 
            --bg-body: #020617; --bg-sidebar: #000000; --bg-header: #000000; 
            --bg-card: #1e293b; --text-main: #ffffff !important; --text-muted: #cbd5e1; 
            --badge-bg: #334155; --border-card: #334155; --border-color: #334155;
            --box-bg: #0f172a; --accent-blue: #60a5fa; --primary-blue: #60a5fa;
        }
        
        body { font-family: 'Cairo', sans-serif; background-color: var(--bg-body); color: var(--text-main); transition: 0.3s ease; margin: 0; overflow-x: hidden; }
        .main-row { display: flex; flex-wrap: nowrap; min-height: 100vh; }
        
        .admin-sidebar { background: var(--bg-sidebar); color: white; padding: 20px 15px; position: sticky; top: 0; height: 100vh; z-index: 1000; width: 260px; display: flex; flex-direction: column; overflow: visible; }
        .admin-pic-container { position: relative; display: inline-block; margin-bottom: 10px; cursor: pointer; }
        .admin-avatar { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--accent-blue); transition: 0.3s; }
        .upload-overlay { position: absolute; bottom: 0; right: 0; background: var(--accent-blue); color: white; width: 25px; height: 25px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; border: 2px solid var(--bg-sidebar); }

        @media (min-width: 768px) { [dir="rtl"] .admin-sidebar { order: 1; } [dir="rtl"] .admin-main-content { order: 2; } }

        .sidebar-nav-container { flex-grow: 1; margin-top: 20px; }
        .nav-link { color: #ffffff !important; padding: 12px 15px; border-radius: 12px; margin-bottom: 5px; display: flex; align-items: center; text-decoration: none; font-weight: 600; transition: 0.3s; font-size: 0.9rem; }
        .nav-link:hover, .nav-link.active { background: var(--accent-blue); color: white !important; }
        
        
        .admin-main-content { flex: 1; overflow-x: hidden; padding: 0; }
        .orders-header { background: var(--bg-header); padding: 15px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); border-bottom: 1px solid var(--border-card); margin-bottom: 25px; }
        .back-btn-circle { width: 40px; height: 40px; border-radius: 50%; background: var(--primary-blue); color: white !important; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,123,255,0.3); border: none; transition: 0.3s; }
        
        .plan-card { background: var(--bg-card); border-radius: 24px; padding: 25px; border: 1px solid var(--border-color); transition: 0.3s; height: 100%; display: flex; flex-direction: column; }
        [dir="rtl"] .plan-card { border-right: 5px solid var(--primary-blue); }
        [dir="ltr"] .plan-card { border-left: 5px solid var(--primary-blue); }
        
        .plan-price { font-size: 1.8rem; font-weight: 900; color: var(--accent-blue); }
        .modal-content { background-color: var(--bg-card) !important; color: var(--text-main) !important; border-radius: 30px; border: 1px solid var(--border-color); }
        .form-control, .form-select { background-color: var(--bg-body) !important; color: var(--text-main) !important; border: 1px solid var(--border-color); border-radius: 12px; }
        .text-muted-custom { color: var(--text-main) !important; opacity: 0.7; }

        @media (max-width: 768px) {
            .main-row { flex-direction: column; }
            .admin-sidebar { height: auto; width: 100%; position: relative; padding: 15px; }
            .nav-link { margin-bottom: 0; justify-content: center; font-size: 0.8rem; }
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
                        <a href="admin-dashboard.php" class="back-btn-circle shadow-sm">
                            <i class="fas fa-arrow-right" id="backIcon"></i>
                        </a>
                        <h6 class="mb-0 fw-bold" data-i18n="manage_plans_h">إدارة الخطط</h6>
                        <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="showAddModal()">
                            <i class="fas fa-plus me-1"></i> <span data-i18n="btn_add">إضافة خطة</span>
                        </button>
                    </div>
                </div>

                <div class="container mt-4 px-4">
                    <div class="row g-4 text-start">
                        <?php if ($res_plans && $res_plans->num_rows > 0): ?>
                            <?php while($plan = $res_plans->fetch_assoc()): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="plan-card shadow-sm">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5 class="fw-bold mb-0 text-primary plan-title-display" data-ar="<?php echo htmlspecialchars($plan['name_ar']); ?>" data-en="<?php echo htmlspecialchars($plan['name_en']); ?>"></h5>
                                        <span class="badge bg-<?php echo ($plan['is_active']) ? 'success' : 'secondary'; ?> rounded-pill" data-i18n="<?php echo ($plan['is_active']) ? 'status_active' : 'status_inactive'; ?>"></span>
                                    </div>
                                    <div class="plan-price"><?php echo number_format($plan['price'], 2); ?> <small class="fs-6">JOD</small></div>
                                    <div class="small opacity-75 mb-3"><?php echo $plan['duration']; ?> <span data-i18n="<?php echo $plan['duration_type']; ?>"></span></div>
                                    <div class="small fw-bold text-primary mb-2"><i class="fas fa-car-side me-1"></i><?php echo (int) clevoPlanWashCount($plan); ?> <span data-i18n="wash_count_label">غسلات</span></div>
                                    <p class="small border-top pt-3 flex-grow-1 plan-desc-display" data-ar="<?php echo htmlspecialchars($plan['description_ar']); ?>" data-en="<?php echo htmlspecialchars($plan['description_en']); ?>"></p>
                                    <div class="small opacity-75 plan-includes-display" data-ar="<?php echo htmlspecialchars($plan['included_services_ar'] ?? ''); ?>" data-en="<?php echo htmlspecialchars($plan['included_services_en'] ?? ''); ?>"></div>
                                    <div class="d-flex gap-2 mt-3 pt-3 border-top">
                                        <button class="btn btn-outline-primary btn-sm flex-fill rounded-pill" onclick='editPlan(<?php echo json_encode($plan); ?>)'><i class="fas fa-edit"></i> <span data-i18n="btn_edit">تعديل</span></button>
                                        <button class="btn btn-outline-danger btn-sm rounded-circle" style="width:35px; height:35px;" onclick="showDeleteModal(<?php echo $plan['id']; ?>)"><i class="fas fa-trash"></i></button>
                                    </div>
                                </div>
                            </div>
                            <?php endwhile; ?>
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
                    <p class="small opacity-75 mb-4" data-i18n="logout_msg">هل أنت متأكد من الخروج؟</p>
                    <div class="d-grid gap-2">
                        <a href="../general/logout.php" class="btn btn-danger rounded-pill py-2 fw-bold" data-i18n="btn_confirm_logout">نعم، خروج</a>
                        <button type="button" class="btn btn-light border rounded-pill py-2 fw-bold" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="planModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-4 shadow-lg border-0">
                <h5 class="fw-bold mb-4 text-primary text-start" id="modalTitle"></h5>
                <form method="POST">
                    <?= csrf_input(); ?>
                    <input type="hidden" name="plan_id" id="p_id">
                    <div class="row g-3 text-start">
                        <div class="col-sm-6"><label class="form-label small fw-bold" data-i18n="label_name_ar">الاسم بالعربي</label><input type="text" name="name_ar" id="p_name_ar" class="form-control" required></div>
                        <div class="col-sm-6"><label class="form-label small fw-bold"><span data-i18n="label_name_en">English Name</span> <span id="sync_name_loader" class="small opacity-50"></span></label><input type="text" name="name_en" id="p_name_en" class="form-control" required></div>
                        <div class="col-sm-6"><label class="form-label small fw-bold" data-i18n="label_desc_ar">الوصف بالعربي</label><textarea name="desc_ar" id="p_desc_ar" class="form-control" rows="2"></textarea></div>
                        <div class="col-sm-6"><label class="form-label small fw-bold"><span data-i18n="label_desc_en">English Description</span> <span id="sync_desc_loader" class="small opacity-50"></span></label><textarea name="desc_en" id="p_desc_en" class="form-control" rows="2"></textarea></div>
                        <div class="col-sm-6"><label class="form-label small fw-bold" data-i18n="label_price">السعر</label><input type="number" step="0.01" name="price" id="p_price" class="form-control" required></div>
                        <div class="col-sm-6">
                            <label class="form-label small fw-bold" data-i18n="label_duration">المدة</label>
                            <div class="input-group"><input type="number" name="duration" id="p_duration" class="form-control" value="1"><select name="duration_type" id="p_type" class="form-select"><option value="day" data-i18n="day">يوم</option><option value="month" data-i18n="month">شهر</option><option value="year" data-i18n="year">سنة</option></select></div>
                        </div>
                        <div class="col-sm-6"><label class="form-label small fw-bold" data-i18n="label_wash_count">عدد الغسلات</label><input type="number" min="1" name="wash_count" id="p_wash_count" class="form-control" value="1" required></div>
                        <div class="col-sm-6"><label class="form-label small fw-bold" data-i18n="label_included_en">Included washes/services</label><input type="text" name="included_services_en" id="p_included_en" class="form-control" data-placeholder-i18n="placeholder_included_en" placeholder="Full wash, Oil change, Polish"></div>
                        <div class="col-12"><label class="form-label small fw-bold" data-i18n="label_included_ar">الخدمات المشمولة بالباقة</label><input type="text" name="included_services_ar" id="p_included_ar" class="form-control" data-placeholder-i18n="placeholder_included_ar" placeholder="غسيل شامل، تغيير زيت، تلميع"></div>
                        <div class="col-12 mt-2">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="p_is_active" value="1" checked>
                                <label class="form-check-label small fw-bold" for="p_is_active" data-i18n="label_is_active">تفعيل الخطة للزبائن (Active)</label>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="add_plan" id="submitBtn" class="btn btn-primary w-100 rounded-pill fw-bold py-2 mt-4 shadow" data-i18n="btn_save">حفظ البيانات</button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content text-center p-4 shadow border-0"><i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i><h6 class="fw-bold" data-i18n="confirm_del_h">تأكيد الحذف</h6><form method="POST"><?= csrf_input(); ?><input type="hidden" name="confirm_delete_id" id="del_id"><div class="d-grid gap-2 mt-3"><button type="submit" class="btn btn-danger rounded-pill fw-bold" data-i18n="btn_delete">نعم، احذف</button><button type="button" class="btn btn-light border rounded-pill" data-bs-dismiss="modal" data-i18n="btn_cancel">إلغاء</button></div></form></div></div></div>
    <div class="modal fade" id="msgModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered modal-sm"><div class="modal-content text-center p-4 shadow border-0"><i class="fas fa-check-circle text-success fa-4x mb-3"></i><h6 class="fw-bold" id="msgTitle"></h6><button type="button" class="btn btn-primary rounded-pill px-5 mt-3" data-bs-dismiss="modal">OK</button></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const translations = {
            ar: { plans_title: "الخطط | Clevo", manage_plans_h: "إدارة خطط النظام", btn_add: "إضافة خطة", nav_main: "الرئيسية", nav_approvals: "الموافقات", nav_plans: "الخطط", nav_security: "الأمان", nav_reports: "التقارير", nav_notif: "الإشعارات", nav_support_chat: "دردشة الدعم", nav_logout: "خروج", status_active: "نشط", status_inactive: "متوقف", day: "يوم", month: "شهر", year: "سنة", btn_edit: "تعديل", modal_add: "إضافة خطة جديدة", modal_edit: "تعديل الخطة", confirm_del_h: "هل أنت متأكد من الحذف؟", btn_delete: "احذف", btn_cancel: "إلغاء", msg_added: "تمت الإضافة بنجاح", msg_updated: "تم التحديث بنجاح", msg_deleted: "تم الحذف بنجاح", logout_title: "تأكيد الخروج", logout_msg: "هل أنت متأكد من الخروج من النظام؟", btn_confirm_logout: "نعم، خروج", wash_count_label: "غسلات" },
            en: { plans_title: "Plans | Clevo", manage_plans_h: "Plan Management", btn_add: "Add Plan", nav_main: "Dashboard", nav_approvals: "Approvals", nav_plans: "Plans", nav_security: "Security", nav_reports: "Reports", nav_notif: "Notifications", nav_support_chat: "Support Chat", nav_logout: "Logout", status_active: "Active", status_inactive: "Inactive", day: "Day", month: "Month", year: "Year", btn_edit: "Edit", modal_add: "Add Plan", modal_edit: "Edit Plan", confirm_del_h: "Are you sure?", btn_delete: "Delete", btn_cancel: "Cancel", msg_added: "Added!", msg_updated: "Updated!", msg_deleted: "Deleted!", logout_title: "Logout Confirmation", logout_msg: "Are you sure you want to log out?", btn_confirm_logout: "Yes, Logout", wash_count_label: "washes" }
        };
        Object.assign(translations.ar, {
            label_name_ar: "الاسم بالعربي",
            label_name_en: "الاسم بالإنجليزي",
            label_desc_ar: "الوصف بالعربي",
            label_desc_en: "الوصف بالإنجليزي",
            label_price: "السعر",
            label_duration: "المدة",
            label_wash_count: "عدد الغسلات",
            label_included_en: "الخدمات المشمولة بالإنجليزي",
            label_included_ar: "الخدمات المشمولة بالباقة",
            label_is_active: "تفعيل الخطة للزبائن (Active)",
            btn_save: "حفظ البيانات",
            placeholder_included_en: "Full wash, Oil change, Polish",
            placeholder_included_ar: "غسيل شامل، تغيير زيت، تلميع",
            translating: "(جاري الترجمة...)"
        });
        Object.assign(translations.en, {
            label_name_ar: "Arabic Name",
            label_name_en: "English Name",
            label_desc_ar: "Arabic Description",
            label_desc_en: "English Description",
            label_price: "Price",
            label_duration: "Duration",
            label_wash_count: "Number of washes",
            label_included_en: "Included washes/services",
            label_included_ar: "Included services in Arabic",
            label_is_active: "Make this plan active for customers",
            btn_save: "Save Plan",
            placeholder_included_en: "Full wash, Oil change, Polish",
            placeholder_included_ar: "غسيل شامل، تغيير زيت، تلميع",
            translating: "(Translating...)"
        });

        function applySettings() {
            const l = localStorage.getItem('app_lang') || 'ar';
            const t = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', t);
            document.documentElement.dir = l === 'ar' ? 'rtl' : 'ltr';
            
            
            document.getElementById('bootstrap-css').href = l === 'ar' ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";

            document.querySelectorAll('[data-i18n]').forEach(el => { const k = el.getAttribute('data-i18n'); if (translations[l][k]) el.innerText = translations[l][k]; });
            document.querySelectorAll('[data-placeholder-i18n]').forEach(el => { const k = el.getAttribute('data-placeholder-i18n'); if (translations[l][k]) el.setAttribute('placeholder', translations[l][k]); });
            document.querySelectorAll('.plan-title-display').forEach(el => el.innerText = el.getAttribute('data-' + l));
            document.querySelectorAll('.plan-desc-display').forEach(el => el.innerText = el.getAttribute('data-' + l));
            document.querySelectorAll('.plan-includes-display').forEach(el => {
                const val = el.getAttribute('data-' + l) || '';
                el.innerText = val ? (l === 'ar' ? 'يشمل: ' : 'Includes: ') + val : '';
            });
            
            const backIcon = document.getElementById('backIcon');
            if (backIcon) backIcon.className = (l === 'ar' ? 'fas fa-arrow-right' : 'fas fa-arrow-left');
            
            <?php if($show_msg_modal): ?>
                document.getElementById('msgTitle').innerText = translations[l]["msg_<?php echo $msg_type; ?>"];
                new bootstrap.Modal(document.getElementById('msgModal')).show();
            <?php endif; ?>
        }

        const pModal = new bootstrap.Modal(document.getElementById('planModal'));
        function showAddModal() { const l = localStorage.getItem('app_lang')||'ar'; document.getElementById('modalTitle').innerText = translations[l].modal_add; document.getElementById('submitBtn').name = "add_plan"; document.querySelector('#planModal form').reset(); document.getElementById('p_wash_count').value = 1; pModal.show(); }
        function editPlan(p) { const l = localStorage.getItem('app_lang')||'ar'; document.getElementById('modalTitle').innerText = translations[l].modal_edit; document.getElementById('submitBtn').name = "update_plan"; document.getElementById('p_id').value = p.id; document.getElementById('p_name_ar').value = p.name_ar; document.getElementById('p_name_en').value = p.name_en; document.getElementById('p_price').value = p.price; document.getElementById('p_duration').value = p.duration; document.getElementById('p_type').value = p.duration_type; document.getElementById('p_wash_count').value = p.wash_count && parseInt(p.wash_count, 10) > 0 ? p.wash_count : Math.max(1, Math.floor(parseFloat(p.price || 0) / 5)); document.getElementById('p_desc_ar').value = p.description_ar || ''; document.getElementById('p_desc_en').value = p.description_en || ''; document.getElementById('p_included_ar').value = p.included_services_ar || ''; document.getElementById('p_included_en').value = p.included_services_en || ''; document.getElementById('p_is_active').checked = (p.is_active == 1); pModal.show(); }
        function showDeleteModal(id) { document.getElementById('del_id').value = id; new bootstrap.Modal(document.getElementById('deleteModal')).show(); }
        function confirmLogout() { new bootstrap.Modal(document.getElementById('logoutModal')).show(); }

        async function translateText(text) {
            if (!text.trim()) return "";
            try {
                const res = await fetch(`https://translate.googleapis.com/translate_a/single?client=gtx&sl=ar&tl=en&dt=t&q=${encodeURIComponent(text)}`);
                const data = await res.json();
                return data[0].map(item => item[0]).join('');
            } catch (e) {
                return "";
            }
        }

        let nameTimeout, descTimeout;
        document.getElementById('p_name_ar').addEventListener('input', function() {
            clearTimeout(nameTimeout);
            const val = this.value;
            const loader = document.getElementById('sync_name_loader');
            if(val.trim()) loader.innerText = translations[localStorage.getItem('app_lang') || 'ar'].translating;
            nameTimeout = setTimeout(async () => {
                if(val.trim()) {
                    const trans = await translateText(val);
                    if(trans) document.getElementById('p_name_en').value = trans;
                }
                loader.innerText = "";
            }, 800);
        });

        document.getElementById('p_desc_ar').addEventListener('input', function() {
            clearTimeout(descTimeout);
            const val = this.value;
            const loader = document.getElementById('sync_desc_loader');
            if(val.trim()) loader.innerText = translations[localStorage.getItem('app_lang') || 'ar'].translating;
            descTimeout = setTimeout(async () => {
                if(val.trim()) {
                    const trans = await translateText(val);
                    if(trans) document.getElementById('p_desc_en').value = trans;
                }
                loader.innerText = "";
            }, 800);
        });

        window.onload = applySettings;
    </script>
</body>
</html>
