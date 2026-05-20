<?php
// Run this script periodically (cron / Task Scheduler)
// Example: php /path/to/Clevo/scripts/plan_expiry_notifier.php

require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/app-helpers.php';

$daysBefore = 7; // notify users whose plan ends within this many days
$notified = 0;

$sql = "SELECT up.user_id, up.end_date, p.name_ar AS plan_name FROM user_plans up JOIN plans p ON p.id = up.plan_id WHERE up.status = 'active' AND DATE(up.end_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $daysBefore);
$stmt->execute();
$res = $stmt->get_result();

while ($row = $res->fetch_assoc()) {
    $user_id = (int) $row['user_id'];
    $plan_name = $row['plan_name'] ?? '';
    $end_date = $row['end_date'];
    $daysLeft = max(0, (int) ceil((strtotime($end_date) - strtotime(date('Y-m-d'))) / 86400));

    $title = "قرب انتهاء الباقة";
    $body = "ستنتهي باقتك \"$plan_name\" خلال $daysLeft يوم(أيام). يرجى التجديد لتجنب توقف الخدمة.";

    // avoid duplicate notifications within the same window
    $chk = $conn->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND title = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1");
    $chk->bind_param('isi', $user_id, $title, $daysBefore);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) {
        continue;
    }

    if (clevoCreateNotification($conn, $user_id, $title, $body, 'warning')) {
        $notified++;
    }
}

if ($notified > 0) {
    $adminBody = "تم إرسال $notified إشعار(إشعارات) للمستخدمين بشأن قرب انتهاء باقاتهم.";
    clevoCreateNotification($conn, 1, 'إشعارات قرب انتهاء الباقات', $adminBody, 'info');
}

echo "Done. Notified: $notified\n";
