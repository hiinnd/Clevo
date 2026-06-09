<?php

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/app-helpers.php';

clevoEnsureNotificationsTargetColumn($conn);

$daysBefore = 7;
$notified = 0;
$expiredNotified = 0;

$sql = "SELECT up.user_id, up.end_date, p.name_ar AS plan_name, p.name_en AS plan_name_en FROM user_plans up JOIN plans p ON p.id = up.plan_id WHERE up.status = 'active' AND DATE(up.end_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $daysBefore);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $user_id = (int) $row['user_id'];
    $plan_name = $row['plan_name'] ?? '';
    $plan_name_en = trim((string) ($row['plan_name_en'] ?? '')) ?: $plan_name;
    $end_date = $row['end_date'];
    $daysLeft = max(0, (int) ceil((strtotime($end_date) - strtotime(date('Y-m-d'))) / 86400));

    $title = "قرب انتهاء الباقة";
    $body = "ستنتهي باقتك \"$plan_name\" خلال $daysLeft يوم(أيام). يرجى التجديد لتجنب توقف الخدمة.";

    $chk = $conn->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND title = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1");
    $chk->bind_param('isi', $user_id, $title, $daysBefore);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) {
        continue;
    }

    if (clevoCreateNotification(
        $conn,
        $user_id,
        $title,
        $body,
        'warning',
        'customer-plans.php',
        'Plan expiring soon',
        "Your plan \"$plan_name_en\" will expire in $daysLeft day(s). Open your plans page to renew before it ends."
    )) {
        $notified++;
    }
}

$expiredSql = "SELECT up.id, up.user_id, up.end_date, p.name_ar AS plan_name, p.name_en AS plan_name_en, u.UserName, u.PhoneNumber
    FROM user_plans up
    JOIN plans p ON p.id = up.plan_id
    JOIN `user` u ON u.UserID = up.user_id
    WHERE up.status IN ('active', 'expired')
      AND up.end_date IS NOT NULL
      AND DATE(up.end_date) < CURDATE()";
$expiredRes = $conn->query($expiredSql);

while ($expiredRes && $plan = $expiredRes->fetch_assoc()) {
    $userPlanID = (int) $plan['id'];
    $userID = (int) $plan['user_id'];
    $userName = trim((string) ($plan['UserName'] ?? 'User'));
    $phone = trim((string) ($plan['PhoneNumber'] ?? ''));
    $planName = trim((string) ($plan['plan_name'] ?? ''));
    $planNameEn = trim((string) ($plan['plan_name_en'] ?? '')) ?: $planName;
    $endDate = (string) ($plan['end_date'] ?? '');
    $titleEn = 'Plan expired';
    $bodyEn = "Subscription #$userPlanID for $userName expired on $endDate. Plan: $planNameEn. Contact the user to renew.";

    $exists = $conn->prepare("SELECT 1 FROM notifications WHERE target_role = 'Admin' AND title_en = ? AND body_en LIKE ? LIMIT 1");
    $like = '%Subscription #' . $userPlanID . '%';
    $exists->bind_param('ss', $titleEn, $like);
    $exists->execute();
    if (!$exists->get_result()->fetch_assoc()) {
        $adminBody = "انتهت خطة المستخدم $userName" . ($phone !== '' ? " ($phone)" : "") . " بتاريخ $endDate. الخطة: $planName. يرجى المتابعة للتجديد.";
        if (clevoCreateRoleNotification(
            $conn,
            'Admin',
            'انتهاء خطة مستخدم',
            $adminBody,
            'warning',
            'manage-users.php',
            $titleEn,
            $bodyEn
        )) {
            $expiredNotified++;
        }
    }

    $expire = $conn->prepare("UPDATE user_plans SET status = 'expired' WHERE id = ? AND status = 'active'");
    $expire->bind_param('i', $userPlanID);
    $expire->execute();
}

echo "Done. User pre-expiry notified: $notified. Admin expired notified: $expiredNotified\n";
