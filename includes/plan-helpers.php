<?php
function clevoEnsurePlanColumns(mysqli $conn): void
{
    clevo_require_schema($conn, 'plans', ['wash_count','included_services_ar','included_services_en']);
    clevo_require_schema($conn, 'booking', ['UserPlanID','Plan_Washes_Used']);
}

function clevoPlanWashCount(array $plan): int
{
    $configured = (int) ($plan['wash_count'] ?? 0);
    if ($configured > 0) {
        return $configured;
    }

    $price = (float) ($plan['price'] ?? 0);
    return max(1, (int) floor($price / 5));
}

function clevoPlanEndDate(array $plan, ?DateTimeImmutable $start = null): string
{
    $start = $start ?: new DateTimeImmutable('today');
    $duration = max(1, (int) ($plan['duration'] ?? 1));
    $type = (string) ($plan['duration_type'] ?? 'month');

    $interval = match ($type) {
        'day' => new DateInterval('P' . $duration . 'D'),
        'year' => new DateInterval('P' . $duration . 'Y'),
        default => new DateInterval('P' . $duration . 'M'),
    };

    return $start->add($interval)->format('Y-m-d');
}

function clevoPlanFeatures(array $plan, string $lang = 'ar'): array
{
    $primaryKey = $lang === 'en' ? 'included_services_en' : 'included_services_ar';
    $fallbackKey = $lang === 'en' ? 'description_en' : 'description_ar';
    $text = trim((string) ($plan[$primaryKey] ?? ''));
    if ($text === '') {
        $text = trim((string) ($plan[$fallbackKey] ?? ''));
    }

    $items = preg_split('/[\r\n،,+;|]+/u', $text) ?: [];
    $features = [];
    foreach ($items as $item) {
        $item = trim($item);
        $length = function_exists('mb_strlen') ? mb_strlen($item) : strlen($item);
        if ($item !== '' && $length <= 80) {
            $features[] = $item;
        }
    }

    if (!$features) {
        $features = $lang === 'en'
            ? ['Monthly washes', 'Flexible booking', 'Plan payment']
            : ['غسلات شهرية', 'حجز مرن', 'الدفع من الباقة'];
    }

    return array_slice(array_values(array_unique($features)), 0, 10);
}

function clevoExpireOldUserPlans(mysqli $conn, int $userID): void
{
    clevoEnsurePlanColumns($conn);
    $stmt = $conn->prepare("UPDATE user_plans SET status = 'expired' WHERE user_id = ? AND status = 'active' AND end_date IS NOT NULL AND end_date < CURDATE()");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
}

function clevoGetActiveUserPlan(mysqli $conn, int $userID, bool $lock = false): ?array
{
    clevoExpireOldUserPlans($conn, $userID);
    $lockSql = $lock ? " FOR UPDATE" : "";
    $stmt = $conn->prepare("SELECT up.*, p.name_ar, p.name_en, p.price, p.duration, p.duration_type, p.description_ar, p.description_en,
            COALESCE(p.wash_count, 0) AS wash_count,
            COALESCE(p.included_services_ar, '') AS included_services_ar,
            COALESCE(p.included_services_en, '') AS included_services_en
        FROM user_plans up
        JOIN plans p ON p.id = up.plan_id
        WHERE up.user_id = ?
          AND up.status = 'active'
          AND up.remaining_washes > 0
          AND (up.end_date IS NULL OR up.end_date >= CURDATE())
        ORDER BY up.created_at DESC, up.id DESC
        LIMIT 1" . $lockSql);
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    return $plan ?: null;
}

function clevoCreateUserPlanSubscription(mysqli $conn, int $userID, int $planID): ?array
{
    clevoEnsurePlanColumns($conn);
    $stmt = $conn->prepare("SELECT * FROM plans WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param("i", $planID);
    $stmt->execute();
    $plan = $stmt->get_result()->fetch_assoc();
    if (!$plan) {
        return null;
    }

    $washes = clevoPlanWashCount($plan);
    $start = (new DateTimeImmutable('today'))->format('Y-m-d');
    $end = clevoPlanEndDate($plan);
    $insert = $conn->prepare("INSERT INTO user_plans (user_id, plan_id, start_date, end_date, status, remaining_washes) VALUES (?, ?, ?, ?, 'active', ?)");
    $insert->bind_param("iissi", $userID, $planID, $start, $end, $washes);
    if (!$insert->execute()) {
        return null;
    }

    $plan['user_plan_id'] = $conn->insert_id;
    $plan['remaining_washes'] = $washes;
    $plan['start_date'] = $start;
    $plan['end_date'] = $end;
    return $plan;
}

function clevoUsePlanForBooking(mysqli $conn, int $userID): ?array
{
    $plan = clevoGetActiveUserPlan($conn, $userID, true);
    if (!$plan) {
        return null;
    }

    $userPlanID = (int) $plan['id'];
    $stmt = $conn->prepare("UPDATE user_plans SET remaining_washes = remaining_washes - 1 WHERE id = ? AND user_id = ? AND status = 'active' AND remaining_washes > 0");
    $stmt->bind_param("ii", $userPlanID, $userID);
    $stmt->execute();
    if ($stmt->affected_rows <= 0) {
        return null;
    }

    $plan['remaining_washes'] = max(0, (int) $plan['remaining_washes'] - 1);
    return $plan;
}

function clevoRestorePlanWashForBooking(mysqli $conn, int $bookingID): void
{
    clevoEnsurePlanColumns($conn);
    $stmt = $conn->prepare("SELECT UserPlanID, Plan_Washes_Used, Payment_Method FROM booking WHERE BookingID = ? LIMIT 1");
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();

    $userPlanID = (int) ($booking['UserPlanID'] ?? 0);
    $used = (int) ($booking['Plan_Washes_Used'] ?? 0);
    if (!$booking || $userPlanID <= 0 || $used <= 0 || ($booking['Payment_Method'] ?? '') !== 'plan') {
        return;
    }

    $restore = $conn->prepare("UPDATE user_plans SET remaining_washes = remaining_washes + ?, status = IF(status = 'expired' AND end_date >= CURDATE(), 'active', status) WHERE id = ?");
    $restore->bind_param("ii", $used, $userPlanID);
    $restore->execute();

    $clear = $conn->prepare("UPDATE booking SET Plan_Washes_Used = 0 WHERE BookingID = ?");
    $clear->bind_param("i", $bookingID);
    $clear->execute();
}

function clevoPaymentMethodLabel(?string $method, string $lang = 'ar'): string
{
    return match ((string) $method) {
        'plan' => $lang === 'en' ? 'From plan' : 'من الباقة',
        'wallet' => $lang === 'en' ? 'Wallet' : 'المحفظة',
        'points' => $lang === 'en' ? 'Loyalty points' : 'نقاط الولاء',
        'visa' => $lang === 'en' ? 'Visa card' : 'بطاقة بنكية',
        'cash' => $lang === 'en' ? 'Cash' : 'كاش',
        default => $lang === 'en' ? 'Not specified' : 'غير محددة',
    };
}
?>
