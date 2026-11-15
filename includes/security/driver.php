<?php
declare(strict_types=1);

function clevo_driver_transition(mysqli $conn, int $driver, int $job, string $target): void
{
    $transitions = ['Accepted' => ['In Progress', 'Arrived'], 'In Progress' => ['Arrived', 'Pickup'],
        'Arrived' => ['In Progress', 'Pickup'], 'Pickup' => ['Washing'], 'Washing' => ['Returning'], 'Returning' => ['Completed']];
    clevo_db_begin($conn);
    try {
        $account = clevo_account($conn, $driver, true);
        if (!clevo_account_active($conn, $account) || clevo_role($account['Role']) !== 'driver') throw new ClevoSecurityException('invalid_transition', 409);
        $row = clevo_stmt($conn, "SELECT b.* FROM booking b JOIN stations s ON s.StationID = b.StationID WHERE b.BookingID = ? AND b.DriverID = ? AND b.StationID = ? AND s.admin_status = 'approved' AND b.Service_Location = 'delivery' FOR UPDATE", 'iii', [$job, $driver, (int) $account['StationID']])->get_result()->fetch_assoc();
        if (!$row || !in_array($target, $transitions[$row['Status']] ?? [], true)) throw new ClevoSecurityException('invalid_transition', 409);
        if (empty($row['Payment_Method'])) throw new ClevoSecurityException('payment_required', 409);
        $receipt=clevo_stmt($conn,'SELECT Status FROM payments WHERE BookingID=?','i',[$job])->get_result()->fetch_assoc();
        if ($receipt&&$receipt['Status']==='refunded') throw new ClevoSecurityException('payment_refunded',409);
        clevo_stmt($conn, 'UPDATE booking SET Status = ? WHERE BookingID = ? AND DriverID = ? AND Status = ?', 'siis', [$target, $job, $driver, $row['Status']]);
        if ($target === 'Completed') {
            if (clevo_db_effect($conn,$job,'completion_reward')) clevoAddLoyaltyPoints($conn, (int) $row['UserID'], (float) $row['Total_Price']);
            if ($row['Payment_Method'] === 'plan') clevoCreditOwnerForBooking($conn, $job);
            clevo_stmt($conn, 'UPDATE auth_sessions SET RevokedAt = UTC_TIMESTAMP() WHERE DriverBookingID = ?', 'i', [$job]);
        }
        clevo_stmt($conn,'UPDATE driver_assignments SET EndedAt=UTC_TIMESTAMP(6) WHERE BookingID=? AND EndedAt IS NULL AND ?=\'Completed\'','is',[$job,$target]);
        clevo_db_audit($conn,$driver,'driver.status_changed','booking',$job);
        clevo_db_commit($conn);
    } catch (Throwable $e) { clevo_db_rollback($conn); throw $e; }
}

function clevo_issue_driver_code(mysqli $conn, int $owner, int $job): string
{
    $code = (string) random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $stmt = clevo_stmt($conn, "UPDATE booking b JOIN stations s ON s.StationID = b.StationID
        JOIN user d ON d.UserID = b.DriverID AND d.StationID = b.StationID
        SET b.DriverAccessCode = NULL, b.DriverAccessCodeHash = ?, b.DriverAccessCodeExpiresAt = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 15 MINUTE), b.DriverAccessCodeUsedAt = NULL
        WHERE b.BookingID = ? AND s.UserID = ? AND s.admin_status = 'approved' AND LOWER(d.Role) = 'driver' AND d.AccountStatus = 'active'
        AND b.Status IN ('Accepted','Arrived','In Progress','Pickup','Washing','Returning') AND b.Service_Location = 'delivery'", 'sii', [$hash, $job, $owner]);
    if ($stmt->affected_rows !== 1) throw new ClevoSecurityException('not_found', 404);
    return $code;
}
