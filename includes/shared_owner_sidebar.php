<?php
// Shared owner sidebar — include this in owner pages
$__owner_cur = basename($_SERVER['PHP_SELF'] ?? '');
function owner_act($p) { global $__owner_cur; return $__owner_cur === $p ? 'active' : ''; }
?>
<style>
/* Owner-specific sidebar overrides to ensure consistent, mobile-friendly layout */
.owner-sidebar{width:260px;background:var(--bg-sidebar,#0f172a);padding:30px 15px;position:sticky;top:0;min-height:100vh;display:flex;flex-direction:column;transition:all .3s ease}
.owner-sidebar .nav-link{color:rgba(255,255,255,.9)!important;padding:12px;border-radius:12px;margin-bottom:8px;display:flex;align-items:center;gap:10px;text-decoration:none;transition:.15s;font-size:.95rem;white-space:nowrap}
.owner-sidebar .nav-link:hover,.owner-sidebar .nav-link.active{background:var(--accent,#007bff);color:#fff!important}
.owner-sidebar .btn-ctrl{width:35px;height:35px;border-radius:50%;background:rgba(255,255,255,.15);color:#fff;border:0;display:inline-flex;align-items:center;justify-content:center}
.owner-sidebar h4{margin-bottom:1rem}
@media (max-width:768px){
  .owner-sidebar{width:100%;height:auto;min-height:auto;position:relative;border-radius:0 0 18px 18px;padding:12px 8px}
  .owner-sidebar .nav{flex-direction:row!important;overflow-x:auto;white-space:nowrap;gap:6px;-webkit-overflow-scrolling:touch}
  .owner-sidebar .nav .nav-link{padding:8px 10px;font-size:.85rem;min-width:70px;display:inline-flex;align-items:center;justify-content:center;flex-direction:column}
  .owner-sidebar .nav .nav-link span{display:block;font-size:.72rem;margin-top:4px}
  .owner-sidebar h4{display:none}
  .owner-sidebar .mt-auto hr{display:none}
}
</style>

<nav class="sidebar owner-sidebar shadow text-center">
    <div class="d-flex gap-2 justify-content-center mb-4">
        <button onclick="toggleGlobalTheme()" class="btn-ctrl"><i id="themeIcon" class="fas fa-moon"></i></button>
        <button onclick="toggleLanguage()" class="btn-ctrl"><span id="langBtnText" class="fw-bold">EN</span></button>
    </div>
    <h4 class="fw-bold mb-4 text-primary">CLEVO</h4>
    <div class="nav flex-column flex-grow-1">
        <a href="owner-dashboard.php" class="nav-link <?php echo owner_act('owner-dashboard.php'); ?>"><i class="fas fa-th-large"></i> <span data-i18n="nav_main">الرئيسية</span></a>
        <a href="incoming-orders.php" class="nav-link <?php echo owner_act('incoming-orders.php'); ?>"><i class="fas fa-shopping-cart"></i> <span data-i18n="nav_orders">الطلبات</span></a>
        <a href="add-station.php" class="nav-link <?php echo owner_act('add-station.php'); ?>"><i class="fas fa-store"></i> <span data-i18n="add_st_page_title">إعداد المغسلة</span></a>
        <a href="manage-services.php" class="nav-link <?php echo owner_act('manage-services.php'); ?>"><i class="fas fa-concierge-bell"></i> <span data-i18n="services_list">الخدمات</span></a>
        <a href="manage-drivers.php" class="nav-link <?php echo owner_act('manage-drivers.php'); ?>"><i class="fas fa-users"></i> <span data-i18n="drivers_page_title">طاقم العمل</span></a>
        <a href="owner-reviews.php" class="nav-link <?php echo owner_act('owner-reviews.php'); ?>"><i class="fas fa-star"></i> <span data-i18n="recent_reviews">التقييمات</span></a>
        <a href="owner-wallet.php" class="nav-link <?php echo owner_act('owner-wallet.php'); ?>"><i class="fas fa-wallet"></i> <span data-i18n="serv_wallet">المحفظة</span></a>
        <a href="notifications.php" class="nav-link <?php echo owner_act('notifications.php'); ?>"><i class="fas fa-bell"></i> <span data-i18n="nav_notifications">الإشعارات</span></a>
    </div>
    <div class="mt-auto">
        <hr class="text-white opacity-25 my-3">
        <a href="javascript:void(0)" onclick="confirmLogout()" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> <span data-i18n="nav_logout">تسجيل الخروج</span></a>
    </div>
</nav>
