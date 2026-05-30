<?php
function clevoEnsureTrackingColumns(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $db = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? '';
    if ($db === '') {
        return;
    }

    $columns = [];
    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'booking'");
    $stmt->bind_param("s", $db);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $columns[$row['COLUMN_NAME']] = true;
    }

    if (!isset($columns['Driver_Lat'])) {
        $conn->query("ALTER TABLE booking ADD COLUMN Driver_Lat DECIMAL(10,8) DEFAULT NULL AFTER Longitude");
    }
    if (!isset($columns['Driver_Lon'])) {
        $conn->query("ALTER TABLE booking ADD COLUMN Driver_Lon DECIMAL(11,8) DEFAULT NULL AFTER Driver_Lat");
    }

    $conn->query("ALTER TABLE booking MODIFY Status ENUM('Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning','Rejected','Cancelled','Cancelled_Timeout','Completed') DEFAULT 'Pending'");
}

function clevoEnsureNotificationsTargetColumn(mysqli $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $db = $conn->query("SELECT DATABASE() AS db")->fetch_assoc()['db'] ?? '';
    if ($db === '') return;

    $stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'target_url' LIMIT 1");
    $stmt->bind_param("s", $db);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    if (!$exists) {
        $conn->query("ALTER TABLE notifications ADD COLUMN target_url VARCHAR(255) DEFAULT NULL AFTER body");
    }
}

function clevoStationImageUrl(?string $image): string
{
    $image = trim((string) $image);
    if ($image === '' || $image === 'default_station.png') {
        return '../assets/Images/carwash.png';
    }
    if (preg_match('/^https?:\/\//i', $image)) {
        return $image;
    }
    if (str_contains($image, '/')) {
        return '../' . ltrim($image, '/');
    }
    if (is_file(__DIR__ . '/../uploads/stations/' . $image)) {
        return '../uploads/stations/' . rawurlencode($image);
    }
    return '../assets/uploads/' . rawurlencode($image);
}

function clevoEnsureWallet(mysqli $conn, int $userID): array
{
    $stmt = $conn->prepare("SELECT WalletID, Balance FROM wallet WHERE UserID = ? LIMIT 1");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $wallet = $stmt->get_result()->fetch_assoc();

    if (!$wallet) {
        $insert = $conn->prepare("INSERT INTO wallet (UserID, Balance) VALUES (?, 0)");
        $insert->bind_param("i", $userID);
        $insert->execute();
        return ['WalletID' => $conn->insert_id, 'Balance' => 0];
    }

    return $wallet;
}

function clevoGetLoyaltyPoints(mysqli $conn, int $userID): float
{
    $stmt = $conn->prepare("SELECT COALESCE(SUM(PointsBalance), 0) AS PointsBalance FROM loyalty WHERE UserID = ?");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return (float) ($row['PointsBalance'] ?? 0.0);
}

function clevoAddLoyaltyPoints(mysqli $conn, int $userID, float $points): void
{
    if ($points <= 0.0) {
        return;
    }

    $stmt = $conn->prepare("SELECT LoyaltyID FROM loyalty WHERE UserID = ? ORDER BY LoyaltyID ASC LIMIT 1");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row) {
        $update = $conn->prepare("UPDATE loyalty SET PointsBalance = PointsBalance + ? WHERE LoyaltyID = ?");
        $update->bind_param("di", $points, $row['LoyaltyID']);
        $update->execute();
        return;
    }

    $insert = $conn->prepare("INSERT INTO loyalty (UserID, PointsBalance) VALUES (?, ?)");
    $insert->bind_param("id", $userID, $points);
    $insert->execute();
}

function clevoSpendLoyaltyPoints(mysqli $conn, int $userID, float $points): bool
{
    if ($points <= 0.0 || clevoGetLoyaltyPoints($conn, $userID) < $points) {
        return false;
    }

    $remaining = $points;
    $stmt = $conn->prepare("SELECT LoyaltyID, PointsBalance FROM loyalty WHERE UserID = ? AND PointsBalance > 0 ORDER BY LoyaltyID ASC");
    $stmt->bind_param("i", $userID);
    $stmt->execute();
    $rows = $stmt->get_result();

    while ($row = $rows->fetch_assoc()) {
        $balance = (float) $row['PointsBalance'];
        $deduct = min($remaining, $balance);
        $update = $conn->prepare("UPDATE loyalty SET PointsBalance = PointsBalance - ? WHERE LoyaltyID = ?");
        $update->bind_param("di", $deduct, $row['LoyaltyID']);
        $update->execute();
        $remaining -= $deduct;
        if ($remaining <= 0.0) {
            return true;
        }
    }

    return false;
}

function clevoCalculateAdminCommission(float $totalPrice): float
{
    return round(max(0, $totalPrice) * 0.10, 2);
}

function clevoCalculateOwnerPayout(float $totalPrice, float $adminCommission): float
{
    return max(0, round($totalPrice - $adminCommission, 2));
}

function clevoCreditWallet(mysqli $conn, int $walletID, float $amount): bool
{
    if ($walletID <= 0 || $amount <= 0) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE wallet SET Balance = Balance + ? WHERE WalletID = ?");
    $stmt->bind_param("di", $amount, $walletID);
    return $stmt->execute();
}

function clevoDebitWallet(mysqli $conn, int $walletID, float $amount): bool
{
    if ($walletID <= 0 || $amount <= 0) {
        return false;
    }

    $stmt = $conn->prepare("UPDATE wallet SET Balance = Balance - ? WHERE WalletID = ? AND Balance >= ?");
    $stmt->bind_param("did", $amount, $walletID, $amount);
    $stmt->execute();
    return $stmt->affected_rows > 0;
}

function clevoRecordWalletTransaction(mysqli $conn, int $walletID, float $amount, string $type, string $descriptionAr, string $descriptionEn): bool
{
    $stmt = $conn->prepare("INSERT INTO wallet_transactions (WalletID, Amount, TransactionType, Description_ar, Description_en) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("idsss", $walletID, $amount, $type, $descriptionAr, $descriptionEn);
    return $stmt->execute();
}

function clevoCreateNotification(mysqli $conn, int $userID, string $title, string $body, string $type = 'info', ?string $targetUrl = null): bool
{
    if ($userID <= 0) {
        return false;
    }

    $allowed = ['info', 'success', 'warning', 'danger'];
    if (!in_array($type, $allowed, true)) {
        $type = 'info';
    }

    // Ensure optional column exists (adds it once if missing)
    clevoEnsureNotificationsTargetColumn($conn);

    if ($targetUrl !== null) {
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, body, type, target_url) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param("issss", $userID, $title, $body, $type, $targetUrl);
        return $stmt->execute();
    }

    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, body, type) VALUES (?, ?, ?, ?)");
    if (!$stmt) return false;
    $stmt->bind_param("isss", $userID, $title, $body, $type);
    return $stmt->execute();
}

function clevoSaveUploadedProfileImage(array $file, string $prefix, int $userID, int $maxSize = 2097152): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $userID <= 0) {
        return null;
    }

    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $maxSize) {
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    $mime = is_file($tmpName) && function_exists('mime_content_type') ? mime_content_type($tmpName) : (string) ($file['type'] ?? '');
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        return null;
    }

    $targetDir = __DIR__ . '/../uploads/profiles/';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0777, true);
    }

    $safePrefix = preg_replace('/[^a-z0-9_-]/i', '', $prefix) ?: 'user';
    $newName = $safePrefix . "_" . $userID . "_" . time() . "." . $extensions[$mime];
    return move_uploaded_file($tmpName, $targetDir . $newName) ? $newName : null;
}

function clevoCreditOwnerForBooking(mysqli $conn, int $bookingID): void
{
    $stmt = $conn->prepare("SELECT b.Order_Number, b.Total_Price, b.Admin_Commission, b.Payment_Method, s.UserID AS OwnerID
        FROM booking b
        JOIN stations s ON b.StationID = s.StationID
        WHERE b.BookingID = ? LIMIT 1");
    $stmt->bind_param("i", $bookingID);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    if (!$booking || (int) $booking['OwnerID'] <= 0 || empty($booking['Payment_Method']) || $booking['Payment_Method'] === 'cash') {
        return;
    }

    $ownerID = (int) $booking['OwnerID'];
    $wallet = clevoEnsureWallet($conn, $ownerID);
    $walletID = (int) $wallet['WalletID'];
    $orderNumber = $booking['Order_Number'] ?: $bookingID;
    $descEn = "Owner booking earning #$orderNumber";

    $exists = $conn->prepare("SELECT TransactionID FROM wallet_transactions WHERE WalletID = ? AND Description_en = ? LIMIT 1");
    $exists->bind_param("is", $walletID, $descEn);
    $exists->execute();
    if ($exists->get_result()->fetch_assoc()) {
        return;
    }

    $amount = clevoCalculateOwnerPayout((float) $booking['Total_Price'], (float) $booking['Admin_Commission']);
    if (!clevoCreditWallet($conn, $walletID, $amount)) {
        return;
    }
    $type = "credit";
    $descAr = "أرباح طلب #$orderNumber";
    clevoRecordWalletTransaction($conn, $walletID, $amount, $type, $descAr, $descEn);
}

function clevoProfileImageUrl(?string $image, string $name = 'User'): string
{
    $image = trim((string) $image);
    if ($image === '') {
        return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&background=0ea5e9&color=fff';
    }
    if (preg_match('/^https?:\/\//i', $image)) {
        return $image;
    }
    if (str_contains($image, '/')) {
        return '../' . ltrim($image, '/');
    }
    if (is_file(__DIR__ . '/../uploads/profiles/' . $image)) {
        return '../uploads/profiles/' . rawurlencode($image);
    }
    if (is_file(__DIR__ . '/../assets/Images/' . $image)) {
        return '../assets/Images/' . rawurlencode($image);
    }
    return '../uploads/profiles/' . rawurlencode($image);
}
?>
