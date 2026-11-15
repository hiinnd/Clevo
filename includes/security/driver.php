<?php
declare(strict_types=1);

function clevo_driver_transition(mysqli $conn, int $driver, int $job, string $target): void
{
    clevo_booking_transition($conn,$driver,$job,$target,'driver_progress');
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
