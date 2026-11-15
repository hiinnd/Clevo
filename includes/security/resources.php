<?php
declare(strict_types=1);
function clevo_booking_dto(array $row, string $role): array
{
    $keys = ['BookingID', 'Order_Number', 'StationID', 'Status', 'Service_Location', 'Booking_Date', 'Booking_Time','ScheduledAtUtc','ScheduledEndAtUtc','BookingTimeZone','DurationMinutes'];
    if ($role !== 'driver') $keys = array_merge($keys, ['Total_Price', 'Payable_Amount', 'Payment_Method', 'Points_Discount','ServicesSubtotal','AdditionalFee']);
    if (in_array($role, ['driver', 'owner', 'customer'], true)) $keys = array_merge($keys, ['Latitude', 'Longitude', 'Address_Details']);
    if ($role==='admin') $keys=array_merge($keys,['UserID','DriverID','OwnerIDSnapshot','Admin_Commission','UserPlanID','ScheduledAtUtc','BookingTimeZone']);
    if(in_array($role,['owner','admin'],true))$keys=array_merge($keys,['FinancialVersion','CommissionRateBps','CommissionBasisMinor','CommissionMinor','OwnerAmountMinor']);
    return array_intersect_key($row, array_flip($keys));
}
function clevo_mark_notification(mysqli $conn, int $user, string $role, int $id): void
{
    $row = clevo_stmt($conn, 'SELECT id, user_id FROM notifications WHERE id = ? AND (user_id = ? OR (user_id IS NULL AND LOWER(target_role) = ?))', 'iis', [$id, $user, $role])->get_result()->fetch_assoc();
    if (!$row) throw new ClevoSecurityException('not_found', 404);
    if ($row['user_id'] !== null) clevo_stmt($conn, 'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', 'ii', [$id, $user]);
    else clevo_stmt($conn, 'INSERT IGNORE INTO notification_reads (NotificationID, UserID) VALUES (?, ?)', 'ii', [$id, $user]);
}
function clevo_resend_verification(mysqli $conn, string $email): void
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) throw new ClevoSecurityException('invalid_email', 422);
    $account = clevo_stmt($conn, "SELECT UserID FROM `user` WHERE Email = ? AND EmailVerifiedAt IS NULL AND AccountStatus <> 'rejected'", 's', [$email])->get_result()->fetch_assoc();
    if ($account) clevo_issue_verification($conn, clevo_account($conn, (int) $account['UserID']));
}

function clevo_access_expires_in(string $token): int
{
    $claims = clevo_validate_jwt($token);
    return max(0, (int) ($claims['exp'] ?? 0) - time());
}
