<?php
// CLI-only, additive equivalents of the former request-time schema repairs.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
(static function (mysqli $conn): void {
if (!clevo_stmt($conn, 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', 's', ['booking_services'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
CREATE TABLE `booking_services` (
  `BookingServiceID` int(11) NOT NULL AUTO_INCREMENT,
  `BookingID` int(11) NOT NULL,
  `ServiceID` int(11) DEFAULT NULL,
  `ServiceName` varchar(255) DEFAULT NULL,
  `Price` decimal(10,2) DEFAULT 0.00,
  PRIMARY KEY (`BookingServiceID`),
  KEY `BookingID` (`BookingID`),
  KEY `ServiceID` (`ServiceID`),
  CONSTRAINT `booking_services_booking_fk` FOREIGN KEY (`BookingID`) REFERENCES `booking` (`BookingID`) ON DELETE CASCADE,
  CONSTRAINT `booking_services_service_fk` FOREIGN KEY (`ServiceID`) REFERENCES `services` (`ServiceID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
}
if (!clevo_stmt($conn, 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', 's', ['owner_bank_accounts'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
CREATE TABLE `owner_bank_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `account_holder_name` varchar(150) NOT NULL,
  `bank_name` varchar(150) NOT NULL,
  `iban` varchar(34) NOT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `swift_code` varchar(20) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `owner_id` (`owner_id`),
  CONSTRAINT `owner_bank_accounts_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `user` (`UserID`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
}
if (!clevo_stmt($conn, 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', 's', ['owner_withdrawal_requests'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
CREATE TABLE `owner_withdrawal_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `wallet_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','paid','rejected') DEFAULT 'pending',
  `bank_snapshot` text DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `owner_id` (`owner_id`),
  KEY `wallet_id` (`wallet_id`),
  CONSTRAINT `owner_withdrawal_owner_fk` FOREIGN KEY (`owner_id`) REFERENCES `user` (`UserID`) ON DELETE CASCADE,
  CONSTRAINT `owner_withdrawal_wallet_fk` FOREIGN KEY (`wallet_id`) REFERENCES `wallet` (`WalletID`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
}
if (!clevo_stmt($conn, 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', 's', ['support_messages'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
CREATE TABLE `support_messages` (
  `MessageID` int(11) NOT NULL AUTO_INCREMENT,
  `TicketID` int(11) NOT NULL,
  `SenderRole` enum('user','admin') NOT NULL,
  `SenderID` int(11) DEFAULT NULL,
  `Message` text NOT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`MessageID`),
  KEY `TicketID` (`TicketID`),
  KEY `SenderRole` (`SenderRole`),
  CONSTRAINT `support_messages_ticket_fk` FOREIGN KEY (`TicketID`) REFERENCES `support_tickets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['user', 'reset_otp'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `user` ADD COLUMN `reset_otp` varchar(10) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['user', 'reset_otp_expiry'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `user` ADD COLUMN `reset_otp_expiry` datetime DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['user', 'LastLatitude'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `user` ADD COLUMN `LastLatitude` decimal(10,8) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['user', 'LastLongitude'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `user` ADD COLUMN `LastLongitude` decimal(11,8) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['user', 'LastAddress'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `user` ADD COLUMN `LastAddress` text DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['user', 'LastLocationUrl'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `user` ADD COLUMN `LastLocationUrl` varchar(255) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['user', 'LastLocationUpdatedAt'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `user` ADD COLUMN `LastLocationUpdatedAt` timestamp NULL DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['booking', 'Driver_Lat'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `booking` ADD COLUMN `Driver_Lat` decimal(10,8) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['booking', 'Driver_Lon'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `booking` ADD COLUMN `Driver_Lon` decimal(11,8) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['booking', 'DriverAccessCode'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `booking` ADD COLUMN `DriverAccessCode` varchar(10) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['booking', 'Points_Used'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `booking` ADD COLUMN `Points_Used` decimal(10,2) DEFAULT 0.00
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['booking', 'Points_Discount'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `booking` ADD COLUMN `Points_Discount` decimal(10,2) DEFAULT 0.00
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['booking', 'Payable_Amount'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `booking` ADD COLUMN `Payable_Amount` decimal(10,2) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['booking', 'Accepted_At'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `booking` ADD COLUMN `Accepted_At` datetime DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['booking', 'UserPlanID'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `booking` ADD COLUMN `UserPlanID` int(11) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['booking', 'Plan_Washes_Used'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `booking` ADD COLUMN `Plan_Washes_Used` tinyint(1) DEFAULT 0
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['notifications', 'target_role'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `notifications` ADD COLUMN `target_role` varchar(50) DEFAULT NULL, -- الحقل المضاف لربط برمجة الإشعارات والتحقق من صلاحيات المشاهدة
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['notifications', 'target_url'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `notifications` ADD COLUMN `target_url` varchar(255) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['notifications', 'title_en'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `notifications` ADD COLUMN `title_en` varchar(255) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['notifications', 'body_en'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `notifications` ADD COLUMN `body_en` text DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['services', 'Name_ar'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `services` ADD COLUMN `Name_ar` varchar(100) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['services', 'Name_en'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `services` ADD COLUMN `Name_en` varchar(100) DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['services', 'Description_ar'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `services` ADD COLUMN `Description_ar` text DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['services', 'Description_en'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `services` ADD COLUMN `Description_en` text DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['plans', 'wash_count'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `plans` ADD COLUMN `wash_count` int(11) DEFAULT 0
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['plans', 'included_services_ar'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `plans` ADD COLUMN `included_services_ar` text DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['plans', 'included_services_en'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `plans` ADD COLUMN `included_services_en` text DEFAULT NULL
SQL);
}
if (!clevo_stmt($conn, 'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', 'ss', ['support_tickets', 'guest_email'])->get_result()->fetch_assoc()) {
    $conn->query(<<<'SQL'
ALTER TABLE `support_tickets` ADD COLUMN `guest_email` varchar(100) DEFAULT NULL
SQL);
}
$status = clevo_stmt($conn, "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='booking' AND COLUMN_NAME='Status'")->get_result()->fetch_assoc()['COLUMN_TYPE'];
if (str_starts_with($status, 'enum(')) {
    preg_match_all("/'([^']*)'/", $status, $matches);
    $required = ['Pending','Accepted','Arrived','In Progress','Pickup','Washing','Returning','Rejected','Cancelled','Cancelled_Timeout','Completed'];
    $merged = array_values(array_unique(array_merge($matches[1], $required)));
    if (count($merged) !== count($matches[1])) {
        $quoted = array_map(static fn(string $value): string => "'" . $conn->real_escape_string($value) . "'", $merged);
        $conn->query("ALTER TABLE booking MODIFY Status ENUM(" . implode(',', $quoted) . ") DEFAULT 'Pending'");
    }
}
if (!clevo_stmt($conn, "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='reviews' AND INDEX_NAME='uq_reviews_booking'")->get_result()->fetch_assoc()) {
    if ($conn->query('SELECT BookingID FROM reviews WHERE BookingID IS NOT NULL GROUP BY BookingID HAVING COUNT(*)>1 LIMIT 1')->num_rows) throw new RuntimeException('Duplicate reviews require manual reconciliation before migration.');
    $conn->query('ALTER TABLE reviews ADD UNIQUE KEY uq_reviews_booking (BookingID)');
}
foreach (['Name_ar'=>'Name','Name_en'=>'Name','Description_ar'=>'Description','Description_en'=>'Description'] as $target=>$source) {
    $conn->query("UPDATE services SET $target = $source WHERE $target IS NULL OR $target = ''");
}
})($conn);
