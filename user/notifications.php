<?php

include_once(__DIR__ . "/../includes/config.php");
include_once(__DIR__ . "/../includes/booking-helpers.php");
include_once(__DIR__ . "/../includes/app-helpers.php");


$userID = clevo_require_role(['customer', 'user'], '../general/login.php');
cancelExpiredBookings($conn, (int) $userID);


// Ensure optional `target_url` column exists before selecting it
clevoEnsureNotificationsTargetColumn($conn);

// Support marking a single notification as read via ?read=ID (used by JS fetch)
if (isset($_POST['read']) && is_numeric($_POST['read'])) {
    $notif_id = intval($_POST['read']);
    $stmtRead = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmtRead->bind_param("ii", $notif_id, $userID);
    $stmtRead->execute();
    // If called via fetch, return a small JSON response
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok']);
        exit;
    }
    header("Location: notifications.php");
    exit;
}



$stmt = $conn->prepare("SELECT id, title, title_en, body, body_en, type, is_read, created_at, target_url FROM `notifications` WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $userID);
$stmt->execute();
$notifications = $stmt->get_result();

function clevo_notification_contains(string $text, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($text, $needle)) {
            return true;
        }
    }
    return false;
}

function clevo_notification_variants(string $title, string $body): array
{
    $titleAr = $title;
    $bodyAr = $body;
    $titleEn = $title;
    $bodyEn = $body;
    preg_match('/#[A-Za-z0-9]+/', $title . ' ' . $body, $matches);
    $orderNum = $matches[0] ?? '';

    if (clevo_notification_contains($title, ['طلب جديد'])) {
        $titleEn = 'New Order';
    } elseif (clevo_notification_contains($title, ['تم قبول الطلب'])) {
        $titleEn = 'Order Accepted';
    } elseif (clevo_notification_contains($title, ['موعدك القادم'])) {
        $titleEn = 'Upcoming Appointment';
    } elseif (clevo_notification_contains($title, ['تم إلغاء الطلب'])) {
        $titleEn = 'Order Cancelled';
    } elseif (clevo_notification_contains($title, ['تم إنهاء الطلب'])) {
        $titleEn = 'Order Completed';
    } elseif (clevo_notification_contains($title, ['نشاط تجريبي'])) {
        $titleEn = 'Demo Activity';
    } elseif (clevo_notification_contains($title, ['رد', 'الدعم'])) {
        $titleEn = 'Support Reply';
    }

    if (clevo_notification_contains($body, ['وصلك طلب'])) {
        $bodyEn = trim("You received a new order $orderNum.");
    } elseif (clevo_notification_contains($body, ['تم قبول طلبك'])) {
        $bodyEn = trim("Your order $orderNum was accepted.");
    } elseif (clevo_notification_contains($body, ['مقبول ومجدول'])) {
        $bodyEn = trim("Your order $orderNum is accepted and scheduled.");
    } elseif (clevo_notification_contains($body, ['وجاري الاستلام'])) {
        $bodyEn = trim("Your order $orderNum was accepted and pickup is in progress.");
    } elseif (clevo_notification_contains($body, ['الدفع لم يكتمل', 'لم يكتمل'])) {
        $bodyEn = trim("Your order $orderNum was cancelled because payment was not completed on time.");
    } elseif (clevo_notification_contains($body, ['يمكنك الآن تقييم الخدمة'])) {
        $bodyEn = trim("You can now rate the service. Your order $orderNum is completed.");
    } elseif (clevo_notification_contains($body, ['لم يتم الاستجابة'])) {
        $bodyEn = 'Sorry, your request was not responded to. You can choose another station now.';
    } elseif (clevo_notification_contains($body, ['تم تحميل بيانات تجريبية'])) {
        $bodyEn = 'Demo data for Amman stations and bookings was loaded.';
    }

    return [$titleAr, $bodyAr, $titleEn, $bodyEn];
}

function clevo_user_notification_fallback_en(string $text, bool $isTitle = false): string
{
    $text = trim($text);
    if ($text === '') return '';
    if (preg_match('/^[\x20-\x7E\s]+$/', $text)) return $text;
    preg_match('/#[A-Za-z0-9]+/', $text, $matches);
    $orderNum = $matches[0] ?? '';

    $pairs = [
        'تم قبول الطلب' => 'Order accepted',
        'تم إلغاء الطلب' => 'Order cancelled',
        'تم إنهاء الطلب' => 'Order completed',
        'موعدك القادم' => 'Upcoming appointment',
        'طلب جديد' => 'New order',
        'نشاط تجريبي' => 'Demo activity',
        'رد الدعم' => 'Support reply',
    ];
    foreach ($pairs as $ar => $en) {
        if (str_contains($text, $ar)) return $en;
    }
    if ($isTitle) return 'Notification';
    if (str_contains($text, 'تم قبول')) return trim("Your order $orderNum was accepted.");
    if (str_contains($text, 'مجدول')) return trim("Your order $orderNum is scheduled.");
    if (str_contains($text, 'لم يكتمل') || str_contains($text, 'الدفع')) return trim("Your order $orderNum was cancelled because payment was not completed on time.");
    if (str_contains($text, 'تقييم')) return trim("You can now rate the service for order $orderNum.");
    return $text;
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title data-i18n="noti_page_title">الإشعارات | Clevo</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" id="bootstrap-css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;700;900&display=swap" rel="stylesheet">
    <style>
        :root { 
            --main-bg: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
            --glass: rgba(255, 255, 255, 0.85);
            --text: #0369a1; 
            --text-muted: #475569;
            --accent: #0ea5e9; 
            --border: rgba(3, 105, 161, 0.15);
        }
        [data-theme="dark"] { 
            --main-bg: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            --glass: rgba(30, 41, 59, 0.75);
            --text: #f8fafc; 
            --text-muted: #cbd5e1;
            --accent: #38bdf8; 
            --border: rgba(255, 255, 255, 0.1);
        }
        
        body { font-family: 'Cairo', sans-serif; background: var(--main-bg); color: var(--text); min-height: 100vh; transition: 0.5s; margin: 0; padding-bottom: 20px; }
        .glass { background: var(--glass); backdrop-filter: blur(15px); border: 1px solid var(--border); border-radius: 25px; }

        .noti-header { 
            padding: 20px; display: flex; align-items: center; justify-content: space-between; 
            position: relative; height: 140px; border-radius: 0 0 40px 40px; 
            background: linear-gradient(135deg, var(--accent), #0284c7); margin-bottom: 30px; 
        }
        
        .back-circle-btn { 
            width: 40px; height: 40px; border-radius: 50%; display: flex; 
            align-items: center; justify-content: center; text-decoration: none; 
            background: rgba(255,255,255,0.2); color: #fff; transition: 0.3s; z-index: 10;
        }

        .spacer-div { width: 40px; }
        [dir="rtl"] .back-circle-btn { order: 1; }
        [dir="rtl"] .spacer-div { order: 3; }
        [dir="rtl"] #backIcon { transform: rotate(0deg); }
        [dir="ltr"] .back-circle-btn { order: 1; }
        [dir="ltr"] .spacer-div { order: 3; }
        [dir="ltr"] #backIcon { transform: rotate(180deg); }
        h4.text-white { order: 2; flex-grow: 1; text-align: center; }

        .noti-item { 
            padding: 20px; border-radius: 20px; margin-bottom: 12px; 
            display: flex; align-items: start; border: 1px solid var(--border); 
            transition: 0.3s; position: relative; overflow: hidden;
        }
        
        .noti-title-text { color: var(--text) !important; font-weight: 700; }
        .noti-body-text { color: var(--text-muted) !important; text-align: start; }

        .noti-icon { 
            width: 45px; height: 45px; border-radius: 15px; 
            display: flex; align-items: center; justify-content: center; 
            font-size: 1.2rem; flex-shrink: 0; margin-inline-end: 15px;
        }
        
        .type-info { background: rgba(56, 189, 248, 0.25); color: #0284c7; }
        [data-theme="dark"] .type-info { color: #38bdf8; }

        .type-success { background: rgba(34, 197, 94, 0.25); color: #16a34a; }
        [data-theme="dark"] .type-success { color: #4ade80; }

        .type-warning { background: rgba(245, 158, 11, 0.25); color: #d97706; }
        [data-theme="dark"] .type-warning { color: #fbbf24; }

        .type-danger { background: rgba(239, 68, 68, 0.25); color: #dc2626; }
        [data-theme="dark"] .type-danger { color: #f87171; }

        .noti-time { font-size: 0.75rem; color: var(--text-muted); opacity: 0.8; margin-top: 8px; display: block; text-align: start; }

        .btn-another-station {
            margin-top: 10px;
            padding: 6px 15px;
            border-radius: 50px;
            background: var(--accent);
            color: white !important;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-block;
            transition: 0.3s;
        }
        .btn-another-station:hover { opacity: 0.9; transform: scale(1.05); }


        [dir="ltr"] .noti-item { text-align: left; }
        [dir="ltr"] .noti-icon { margin-inline-end: 0; margin-right: 15px; }
        
    </style>
</head>
<body>
    <div class="noti-header shadow">
        <div class="spacer-div"></div>
        <h4 class="text-white fw-black m-0" data-i18n="noti_title">الإشعارات</h4>
        <a href="home.php" class="back-circle-btn">
            <i class="fas fa-arrow-right" id="backIcon"></i>
        </a>
    </div>

    <div class="container">
        <div class="px-2" id="notiList">
            <?php if ($notifications->num_rows > 0): ?>
                <?php while($noti = $notifications->fetch_assoc()): 
                    $typeClass = "type-" . ($noti['type'] ?? 'info');
                    $icon = "fa-bell";
                    $isDanger = ($noti['type'] == 'danger');
                    
                    if($noti['type'] == 'success') $icon = "fa-check-circle";
                    if($noti['type'] == 'warning') $icon = "fa-gift"; 
                    if($noti['type'] == 'danger') $icon = "fa-times-circle";
                    if($noti['type'] == 'info' && clevo_notification_contains((string) $noti['title'], ['رد', 'الدعم'])) $icon = "fa-headset";

                    [$titleRawAr, $bodyRawAr, $titleRawEn, $bodyRawEn] = clevo_notification_variants((string) $noti['title'], (string) $noti['body']);
                    $titleRawEn = trim((string) ($noti['title_en'] ?? '')) !== ''
                        ? (string) $noti['title_en']
                        : clevo_user_notification_fallback_en($titleRawEn, true);
                    $bodyRawEn = trim((string) ($noti['body_en'] ?? '')) !== ''
                        ? (string) $noti['body_en']
                        : clevo_user_notification_fallback_en($bodyRawEn, false);
                    $title_ar = e($titleRawAr);
                    $body_ar = e($bodyRawAr);
                    $title_en = e($titleRawEn);
                    $body_en = e($bodyRawEn);
                ?>
                    <div class="glass noti-item shadow-sm" data-id="<?= $noti['id'] ?>" data-target="<?= htmlspecialchars($noti['target_url'] ?? '') ?>" onclick="handleNotifClick(this)">
                        <div class="noti-icon <?= $typeClass ?>">
                            <i class="fas <?= $icon ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h6 class="noti-title-text mb-1 small" data-db-ar="<?= $title_ar ?>" data-db-en="<?= $title_en ?>"><?= $title_ar ?></h6>
                            <p class="noti-body-text mb-0 small" data-db-ar="<?= $body_ar ?>" data-db-en="<?= $body_en ?>">
                                <?php 
                                    if ($isDanger && clevo_notification_contains((string) $noti['body'], ['لم يتم الاستجابة'])) {
                                        echo '<span data-i18n="no_response_msg">عذراً، لم يتم الاستجابة لطلبك، يمكنك اختيار محطة أخرى الآن</span>';
                                    } else {
                                        echo $body_ar;
                                    }
                                ?>
                            </p>
                            
                            <?php if ($isDanger): ?>
                                <a href="search-stations.php" class="btn-another-station shadow-sm" data-i18n="search_another">البحث عن محطة أخرى</a>
                            <?php endif; ?>

                            <span class="noti-time">
                                <i class="far fa-clock me-1"></i> 
                                <?= date('Y/m/d | h:i A', strtotime($noti['created_at'])) ?>
                            </span>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="text-center py-5 opacity-75">
                    <i class="fas fa-bell-slash fa-4x mb-3" style="color: var(--text);"></i>
                    <h6 class="fw-bold" data-i18n="no_noti" style="color: var(--text);">لا توجد إشعارات حالياً</h6>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function handleNotifClick(el) {
            try {
                const id = el.dataset.id;
                const target = (el.dataset.target || '').trim();
                if (target) {
                    fetch(window.location.pathname, {method:'POST', credentials:'same-origin', headers:{'X-CSRF-Token': <?= clevo_js(generate_csrf_token()) ?>, 'Content-Type':'application/x-www-form-urlencoded'}, body:'read='+encodeURIComponent(id)}).finally(() => {
                        window.location.href = target;
                    });
                } else {
                    fetch(window.location.pathname, {method:'POST',headers:{'X-CSRF-Token': <?= clevo_js(generate_csrf_token()) ?>,'Content-Type':'application/x-www-form-urlencoded'},body:'read='+encodeURIComponent(id)}).then(()=>location.reload());
                }
            } catch (e) {
                console.error(e);
            }
        }
        const translations = {
            ar: {
                noti_title: "الإشعارات",
                no_noti: "لا توجد إشعارات حالياً",
                noti_page_title: "الإشعارات | Clevo",
                search_another: "البحث عن محطة أخرى",
                no_response_msg: "عذراً، لم يتم الاستجابة لطلبك، يمكنك اختيار محطة أخرى الآن"
            },
            en: {
                noti_title: "Notifications", 
                no_noti: "No notifications right now", 
                noti_page_title: "Notifications | Clevo",
                search_another: "Search Another Station",
                no_response_msg: "Sorry, your request was not responded to. You can choose another station now"
            }
        };

        function translateLeftoverNotificationText(text, isTitle = false) {
            const value = (text || '').trim();
            if (!/[\u0600-\u06FF]/.test(value)) return value;
            const orderMatch = value.match(/#[A-Za-z0-9]+/);
            const orderNum = orderMatch ? orderMatch[0] : '';

            if (value.includes('انتهت مهلة الطلب')) return 'Request Timed Out';
            if (value.includes('تم إنهاء الطلب')) return 'Order Completed';
            if (value.includes('تم قبول الطلب')) return 'Order Accepted';
            if (value.includes('موعدك القادم')) return 'Upcoming Appointment';
            if (value.includes('طلب جديد')) return 'New Order';
            if (value.includes('نشاط تجريبي')) return 'Demo Activity';
            if (isTitle) return 'Notification';

            if (value.includes('لم يتم الرد') || value.includes('خلال 10 دقائق')) {
                return `Your order ${orderNum} was not answered within 10 minutes. You can book another station now.`;
            }
            if (value.includes('يمكنك الآن تقييم الخدمة')) {
                return `Your order ${orderNum} was completed. You can now rate the service.`;
            }
            if (value.includes('وجاري الاستلام')) {
                return `Your order ${orderNum} was accepted and pickup is in progress.`;
            }
            if (value.includes('مقبول ومجدول')) {
                return `Your order ${orderNum} is accepted and scheduled.`;
            }
            return value;
        }

        window.onload = () => {
            const lang = localStorage.getItem('app_lang') || 'ar';
            const theme = localStorage.getItem('global_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
            document.documentElement.lang = lang;
            document.title = translations[lang].noti_page_title;
            
            const bootstrapLink = document.getElementById('bootstrap-css');
            if (bootstrapLink) {
                bootstrapLink.href = lang === 'ar'
                    ? "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css"
                    : "https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css";
            }


            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (translations[lang][key]) el.innerText = translations[lang][key];
            });


            document.querySelectorAll('[data-db-ar]').forEach(el => {
                if(lang === 'en') {
                    const isTitle = el.classList.contains('noti-title-text');
                    const englishText = el.getAttribute('data-db-en') || el.innerText;
                    el.innerText = translateLeftoverNotificationText(englishText, isTitle);
                } else {
                    el.innerText = el.getAttribute('data-db-ar');
                }
            });

            if (lang === 'en') {
                document.querySelectorAll('.noti-title-text').forEach(el => {
                    el.innerText = translateLeftoverNotificationText(el.innerText, true);
                });
                document.querySelectorAll('.noti-body-text').forEach(el => {
                    el.innerText = translateLeftoverNotificationText(el.innerText, false);
                });
            }
        };
    </script>
    <script>window.clevoCsrfToken = <?= clevo_js(generate_csrf_token()) ?>;</script>
    <script src="../assets/lamea.js?v=20260605b"></script>
</body>
</html>
